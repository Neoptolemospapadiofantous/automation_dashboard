<?php

namespace App\Http\Controllers;

use App\Billing\BillingCycle;
use App\Billing\Plan;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
use App\Models\Team;
use App\Services\Billing\StripeClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Handles the subscription lifecycle:
 *   POST /subscribe/{plan}         → FIRST subscription: Stripe Checkout, redirect
 *   POST /subscribe/change/{plan}  → move a LIVE subscription up or down the ladder
 *   POST /subscribe/schedule-cancel→ cancel at period end (reversible)
 *   POST /subscribe/resume         → undo a scheduled cancellation
 *   GET  /subscribe/success        → landing page (activation happens via webhook)
 *   GET  /subscribe/cancel         → Checkout-abandoned landing page
 *
 * Starting and CHANGING are deliberately different endpoints. Sending an
 * already-subscribed team back through Checkout creates a SECOND Stripe
 * subscription and bills them twice — so start() refuses when one is live,
 * and change() edits the existing subscription's price in place instead.
 *
 * Entitlements (Team->plan, credits) are never written here. Stripe's
 * webhooks are the single source of truth: checkout.session.completed,
 * invoice.paid and customer.subscription.updated. This controller only asks
 * Stripe to do something and reports back.
 */
class SubscribeController extends Controller
{
    use AuthorizesByTeamRole;

    public function __construct(protected StripeClient $stripe) {}

    /**
     * Create a Checkout session for the chosen plan + redirect the browser.
     */
    public function start(Request $request, string $planKey): HttpResponse
    {
        $this->requireOwner($request, 'subscribe to a plan');

        // Free is not purchasable — it is the default state, not a checkout.
        $plan = match ($planKey) {
            'starter' => Plan::Starter,   // €9 · 1 agent · 2,500 credits
            'growth' => Plan::Growth,     // €19 · 5 agents · 10,000 credits
            'operator' => Plan::Pro,      // case Pro has label "Operator" · €39
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

        // Checkout is for the FIRST subscription only. A live subscription
        // must be edited in place — a second Checkout would leave the team
        // paying for two subscriptions at once.
        if ($this->hasLiveSubscription($team)) {
            return back()->withErrors([
                'plan' => 'You already have an active subscription — switch plans instead of starting a new one.',
            ]);
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

        // Inertia::location returns a 409 + X-Inertia-Location for XHR visits
        // (so the client does a full-page nav to Stripe), or a 302 otherwise.
        // A plain redirect()->away() would be followed by Inertia's XHR and
        // blocked by CORS at checkout.stripe.com.
        return Inertia::location($session->url);
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

    /**
     * Move a LIVE subscription to another rung (or between monthly/annual).
     *
     * Upgrades invoice the prorated difference immediately, so the larger
     * allowance is only granted once the money is in. Downgrades take the
     * proration as account credit against the next invoice — we never refund
     * and never claw back credits already paid for (see
     * CreditMeter::raiseMonthlyAllowance).
     */
    public function change(Request $request, string $planKey): HttpResponse
    {
        $this->requireOwner($request, 'change your plan');

        $target = $this->resolvePlan($planKey);
        $cycle = BillingCycle::tryFrom((string) $request->input('cycle', 'monthly')) ?? BillingCycle::Monthly;

        $team = $request->user()->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'Sign in to a team first.');
        }

        if (! $this->hasLiveSubscription($team)) {
            return back()->withErrors([
                'plan' => 'You do not have an active subscription to change — pick a plan to get started.',
            ]);
        }

        $priceId = $target->stripePriceId($cycle);
        if ($priceId === null) {
            return back()->withErrors([
                'plan' => "Plan {$target->label()} ({$cycle->label()}) is not yet available.",
            ]);
        }

        $current = $team->planObject();
        if ($this->isAlreadyOnPrice($team, $priceId)) {
            return back()->withErrors([
                'plan' => "You are already on {$target->label()}, billed {$cycle->label()}.",
            ]);
        }

        try {
            $subscription = $this->stripe->changeSubscriptionPrice(
                team: $team,
                priceId: $priceId,
                invoiceImmediately: $current->isUpgradeTo($target),
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'plan' => 'We could not change your plan just now. Nothing was charged — please try again shortly.',
            ]);
        }

        // An upgrade whose proration invoice needs card authentication is
        // parked by Stripe as a pending update. Send the browser to the hosted
        // invoice to authenticate; paying it applies the change and fires the
        // same webhooks as a direct update. (Inertia::location so the client
        // does a full navigation to Stripe rather than an XHR.)
        $hostedInvoiceUrl = $this->hostedInvoiceUrlIfActionNeeded($subscription);
        if ($hostedInvoiceUrl !== null) {
            return Inertia::location($hostedInvoiceUrl);
        }

        // Stripe's customer.subscription.updated / invoice.paid webhooks move
        // the plan and the credits. Nothing is written here on purpose.
        return back()->with('status', "Your plan is moving to {$target->label()}.");
    }

    /**
     * When Stripe parked the change pending payment, the URL the customer
     * must visit to authenticate the proration invoice; null when the change
     * already applied.
     */
    private function hostedInvoiceUrlIfActionNeeded(\Stripe\Subscription $subscription): ?string
    {
        if (empty($subscription->pending_update)) {
            return null;
        }

        $invoice = $subscription->latest_invoice;
        if (! is_object($invoice)) {
            return null;
        }

        $intent = $invoice->payment_intent ?? null;
        $status = is_object($intent) ? (string) ($intent->status ?? '') : '';
        if (! in_array($status, ['requires_action', 'requires_payment_method', 'requires_confirmation'], true)) {
            return null;
        }

        $url = (string) ($invoice->hosted_invoice_url ?? '');

        return $url !== '' ? $url : null;
    }

    /**
     * Cancel at the end of the paid period — reversible until it lands.
     */
    public function scheduleCancel(Request $request): HttpResponse
    {
        return $this->setCancellation($request, true, 'cancel your subscription');
    }

    /**
     * Undo a scheduled cancellation.
     */
    public function resume(Request $request): HttpResponse
    {
        return $this->setCancellation($request, false, 'resume your subscription');
    }

    private function setCancellation(Request $request, bool $cancel, string $action): HttpResponse
    {
        $this->requireOwner($request, $action);

        $team = $request->user()->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'Sign in to a team first.');
        }

        if (! $this->hasLiveSubscription($team)) {
            return back()->withErrors(['plan' => 'There is no active subscription to change.']);
        }

        try {
            $this->stripe->setCancelAtPeriodEnd($team, $cancel);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['plan' => 'We could not update your subscription just now — please try again shortly.']);
        }

        // Mirror locally so the page reflects it without waiting on the
        // webhook; customer.subscription.updated confirms it moments later.
        $team->forceFill(['stripe_cancel_at_period_end' => $cancel])->save();

        return back()->with('status', $cancel
            ? 'Your subscription will end when the current period closes. You keep everything until then.'
            : 'Your subscription will continue as normal.');
    }

    /**
     * @return Plan the self-serve rung for this key
     */
    private function resolvePlan(string $planKey): Plan
    {
        return match ($planKey) {
            'starter' => Plan::Starter,
            'growth' => Plan::Growth,
            'operator' => Plan::Pro,
            // Free is a downgrade, done by cancelling; Custom is negotiated.
            default => abort(404, 'Unknown plan'),
        };
    }

    /**
     * Is Stripe actually billing this team right now? `past_due` counts —
     * the subscription exists and can still be switched or cancelled.
     */
    private function hasLiveSubscription(Team $team): bool
    {
        $id = (string) ($team->stripe_subscription_id ?? '');

        return $id !== '' && in_array(
            (string) ($team->stripe_subscription_status ?? ''),
            ['active', 'trialing', 'past_due'],
            true,
        );
    }

    /**
     * Is the team already billed on exactly this Price? Catches a no-op
     * switch (same rung AND same cycle) before it reaches Stripe. If Stripe
     * cannot be reached we say no and let the update attempt decide — better
     * a redundant API call than a wrongly blocked upgrade.
     */
    private function isAlreadyOnPrice(Team $team, string $priceId): bool
    {
        try {
            return $this->stripe->subscriptionPriceId($team) === $priceId;
        } catch (\Throwable) {
            return false;
        }
    }
}
