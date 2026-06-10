<?php

namespace App\Http\Controllers;

use App\Billing\BillingCycle;
use App\Billing\Plan;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Models\Team;
use App\Services\Billing\StripeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles the subscription Checkout flow:
 *   POST /subscribe/{plan}  → creates Stripe Checkout session, redirects browser
 *   GET  /subscribe/success → success landing page (subscription activates via webhook)
 *   GET  /subscribe/cancel  → cancel landing page
 *
 * The actual subscription activation (set Team->plan, grant credits) happens
 * in StripeWebhookController when checkout.session.completed fires. This
 * controller just orchestrates the redirect to Stripe's hosted Checkout.
 */
class SubscribeController extends Controller
{
    use AuthorizesByTeamRole;

    public function __construct(protected StripeClient $stripe) {}

    /**
     * Create a Checkout session for the chosen plan + redirect the browser.
     */
    public function start(Request $request, string $planKey): RedirectResponse
    {
        $this->requireOwner($request, 'subscribe to a plan');

        $plan = match ($planKey) {
            'starter' => Plan::Free,    // case Free has label "Starter" + $99 price
            'operator' => Plan::Pro,    // case Pro has label "Operator" + $399 price
            default => abort(404, 'Unknown plan'),
        };

        // Billing cycle — monthly (default) or annual. Annual gets a
        // discount via a separate Stripe Price ID with interval=year.
        $cycleRaw = (string) $request->query('cycle', 'monthly');
        $cycle = BillingCycle::tryFrom($cycleRaw) ?? BillingCycle::Monthly;

        $priceId = $plan->stripePriceId($cycle);
        if ($priceId === null) {
            // Stripe price not configured for this plan + cycle combo. The
            // annual price for either tier may be absent during setup —
            // friendly error rather than 500.
            return back()->withErrors([
                'plan' => "Plan {$plan->label()} ({$cycle->label()}) is not yet available for self-serve checkout.",
            ]);
        }

        $team = $request->user()->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'Sign in to a team first.');
        }

        $session = $this->stripe->createSubscriptionCheckout(
            team: $team,
            priceId: $priceId,
            successUrl: route('subscribe.success').'?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: route('subscribe.cancel'),
            metadata: [
                'plan_key' => $planKey,
                'plan_value' => $plan->value,
                'cycle' => $cycle->value,
            ],
        );

        return redirect()->away($session->url);
    }

    /**
     * Landing page after Stripe redirects back successfully. The subscription
     * is NOT necessarily active yet — that depends on the webhook firing.
     * We show a "your subscription is processing" page; the dashboard polls
     * or the user refreshes once the webhook lands.
     */
    public function success(Request $request): Response
    {
        return Inertia::render('Billing/SubscriptionSuccess', [
            'session_id' => $request->query('session_id'),
        ]);
    }

    public function cancel(): Response
    {
        return Inertia::render('Billing/SubscriptionCancel');
    }
}
