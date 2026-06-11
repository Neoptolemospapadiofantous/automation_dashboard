<?php

namespace App\Http\Middleware;

use App\Billing\Plan;
use App\Models\Agent;
use App\Models\CreditTransaction;
use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            // Surface session flash payloads under a stable key so Vue pages
            // can read them via $page.props.flash. Inertia does NOT auto-
            // share session keys — without this, controllers' ->with('flash.x')
            // calls land in the session but never reach the front end.
            'flash' => fn () => array_filter([
                'webhook_secret_rotated' => $request->session()->get('flash.webhook_secret_rotated'),
                'plan_limit' => $request->session()->get('flash.plan_limit'),
            ], fn ($v) => $v !== null),
            // Unread lead notifications for the bell UI.
            'notifications' => fn () => $request->user()
                ? $request->user()->unreadNotifications()->latest()->take(10)->get()
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'lead_id' => $n->data['lead_id'] ?? null,
                        'message' => $n->data['message'] ?? 'Notification',
                        'created_at' => $n->created_at->toIso8601String(),
                    ])->values()
                : [],

            // Agent picker data for the nav. Shared on every Inertia request
            // so the picker stays in sync without each page re-querying.
            'currentAgent' => fn () => $request->user()?->currentTeam?->currentAgent
                ? [
                    'id' => $request->user()->currentTeam->currentAgent->id,
                    'name' => $request->user()->currentTeam->currentAgent->name,
                    'status' => $request->user()->currentTeam->currentAgent->status,
                ]
                : null,
            'teamAgents' => fn () => $request->user()?->currentTeam
                ? $request->user()->currentTeam->agents()->orderBy('created_at')->get()
                    ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'status' => $a->status])
                    ->values()
                : [],

            // Billing snapshot for the credit pill in the sidebar.
            //
            // credits_total = monthly allotment + top-ups granted in the
            // current billing period (since the last grant_monthly_renewal).
            // Without that addition the bar reads "0 / 1,000 used · 1,100
            // remaining" the moment anyone tops up — the denominator stays
            // pinned to the plan's monthly while the balance overshoots it.
            //
            // Custom plan exposes is_custom: true + credits_total: null.
            // Credits are negotiated per engagement, no fixed period grant.
            'billing' => fn () => $request->user()?->currentTeam
                ? (function () use ($request) {
                    $team = $request->user()->currentTeam;
                    $plan = $team->planObject();
                    $isCustom = $plan === Plan::Business;

                    // has_stripe_customer is true once the team has subscribed
                    // OR purchased a topup. That's the precondition for the
                    // Customer Portal session to work (Stripe needs a cus_*).
                    $stripeCustomerId = $team->getAttribute('stripe_customer_id');
                    $hasStripeCustomer = $stripeCustomerId !== null && $stripeCustomerId !== '';
                    $subscriptionStatus = $team->getAttribute('stripe_subscription_status');

                    // is_owner gates the Manage subscription / Subscribe /
                    // Top up buttons. Server-side enforcement is in the
                    // controllers (AuthorizesByTeamRole::requireOwner); this
                    // just keeps the UI honest so non-owners don't see
                    // buttons that 403. The outer closure already guards
                    // on $request->user() before reaching here.
                    $isOwner = (int) $team->getAttribute('user_id') === (int) $request->user()->id;

                    if ($isCustom) {
                        return [
                            'plan' => $plan->value,
                            'plan_label' => $plan->label(),
                            'is_custom' => true,
                            'credits_used' => null,
                            'credits_total' => null,
                            'credits_remaining' => $team->credit_balance,
                            'max_agents' => $plan->maxAgents(),
                            'agents_count' => $team->agents()->count(),
                            'allows_topups' => $plan->allowsTopUps(),
                            'has_stripe_customer' => $hasStripeCustomer,
                            'subscription_status' => $subscriptionStatus,
                            'is_owner' => $isOwner,
                        ];
                    }

                    // Sum of top-up grants since the last renewal. Falls back
                    // to "all time" when credits_renewed_at hasn't been set
                    // yet (fresh teams pre-first-renewal). Single COUNT-style
                    // query per request — cheap.
                    $topupsThisPeriod = (int) CreditTransaction::query()
                        ->where('team_id', $team->id)
                        ->where('reason', CreditTransaction::REASON_GRANT_TOPUP)
                        ->when($team->credits_renewed_at, fn ($q) => $q->where('created_at', '>=', $team->credits_renewed_at))
                        ->sum('amount');

                    $totalGranted = $plan->monthlyCredits() + $topupsThisPeriod;

                    return [
                        'plan' => $plan->value,
                        'plan_label' => $plan->label(),
                        'is_custom' => false,
                        'credits_used' => max(0, $totalGranted - $team->credit_balance),
                        'credits_total' => $totalGranted,
                        'credits_remaining' => $team->credit_balance,
                        'max_agents' => $plan->maxAgents(),
                        'agents_count' => $team->agents()->count(),
                        'allows_topups' => $plan->allowsTopUps(),
                        'has_stripe_customer' => $hasStripeCustomer,
                        'subscription_status' => $subscriptionStatus,
                        'is_owner' => $isOwner,
                    ];
                })()
                : null,
        ];
    }
}
