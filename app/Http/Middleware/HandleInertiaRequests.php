<?php

namespace App\Http\Middleware;

use App\Billing\Plan;
use App\Models\Agent;
use App\Models\PlatformSetting;
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

            // Platform-engineer (Hermes operator) flag for the admin nav.
            // Stricter than team Owner — scoped to the allowlist in
            // config('hermes.operators'), which always includes the founder.
            'isAdmin' => fn () => $request->user() !== null
                && in_array($request->user()->email, config('hermes.operators', []), true),

            // Whether the agent → n8n automation subsystem is live. When off,
            // the Actions surface presents as "coming soon" (the master flag
            // gates the whole feature — see phase-16). Flip
            // RUNTIME_AUTOMATION_ENABLED to turn the real editor back on.
            'automationsEnabled' => fn () => (bool) config('runtime.automation.enabled', false),

            // Surface session flash payloads under a stable key so Vue pages
            // can read them via $page.props.flash. Inertia does NOT auto-
            // share session keys — without this, controllers' ->with('flash.x')
            // calls land in the session but never reach the front end.
            'flash' => fn () => array_filter([
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

            // Latest product-news headline for the top bar. Hand-managed
            // via PlatformSetting (key `latest_headline`, optional
            // `latest_headline_url`); null hides the line. Cheap key/value
            // read — PlatformSetting caches.
            'latestHeadline' => function () {
                $text = PlatformSetting::value('latest_headline');

                return is_string($text) && $text !== ''
                    ? ['text' => $text, 'url' => PlatformSetting::value('latest_headline_url')]
                    : null;
            },

            // Agent picker data for the nav. Shared on every Inertia request
            // so the picker stays in sync without each page re-querying.
            'currentAgent' => function () use ($request) {
                $team = $request->user()?->currentTeam;
                $agent = $team instanceof Team ? $team->currentAgent : null;

                return $agent instanceof Agent
                    ? ['id' => $agent->id, 'name' => $agent->name, 'status' => $agent->status]
                    : null;
            },
            'teamAgents' => function () use ($request) {
                $team = $request->user()?->currentTeam;
                if (! $team instanceof Team) {
                    return [];
                }

                return $team->agents()->orderBy('created_at')->get()
                    ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'status' => $a->status])
                    ->values();
            },

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
                    if (! $team instanceof Team) {
                        return null;
                    }
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

                    // A team is "subscribed" when Stripe says the subscription
                    // exists in a live state. Still the only trustworthy
                    // signal for the Billing UI: the plan value alone says
                    // which rung a team is ON, not whether Stripe is actually
                    // billing it (a past_due or newly-cancelled team keeps its
                    // paid plan value until the webhook downgrades it).
                    // Historically this was worse — before the 2026-08-27
                    // repricing Plan::Free shared the "Starter" label with the
                    // paid entry tier, so comparing labels showed "Current
                    // plan" to unpaid teams and blocked the purchase entirely.
                    // The rungs are distinct now; keep the status check anyway.
                    $subscribed = in_array($subscriptionStatus, ['active', 'trialing', 'past_due'], true);

                    if ($isCustom) {
                        return [
                            'plan' => $plan->value,
                            'plan_label' => $plan->label(),
                            'is_custom' => true,
                            'credits_used' => null,
                            'credits_total' => null,
                            'credits_remaining' => $team->totalCredits(),
                            'topup_balance' => (int) $team->topup_balance,
                            'max_agents' => $plan->maxAgents(),
                            'agents_count' => $team->agents()->count(),
                            'allows_topups' => $plan->allowsTopUps(),
                            'has_stripe_customer' => $hasStripeCustomer,
                            'subscription_status' => $subscriptionStatus,
                            'subscribed' => $subscribed,
                            'is_owner' => $isOwner,
                        ];
                    }

                    // Two-bucket display (policy 2026-06-12): the bar tracks
                    // the MONTHLY allowance (used/total of the plan grant —
                    // a clean denominator that no longer moves when topping
                    // up); rolled-over purchased credits ride alongside as
                    // topup_balance and are included in credits_remaining.
                    $monthlyTotal = $plan->monthlyCredits();

                    return [
                        'plan' => $plan->value,
                        'plan_label' => $plan->label(),
                        'is_custom' => false,
                        'credits_used' => max(0, $monthlyTotal - (int) $team->credit_balance),
                        'credits_total' => $monthlyTotal,
                        'credits_remaining' => $team->totalCredits(),
                        'topup_balance' => (int) $team->topup_balance,
                        'max_agents' => $plan->maxAgents(),
                        'agents_count' => $team->agents()->count(),
                        'allows_topups' => $plan->allowsTopUps(),
                        'has_stripe_customer' => $hasStripeCustomer,
                        'subscription_status' => $subscriptionStatus,
                        'subscribed' => $subscribed,
                        'is_owner' => $isOwner,
                    ];
                })()
                : null,
        ];
    }
}
