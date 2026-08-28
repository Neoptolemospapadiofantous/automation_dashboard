<?php

namespace App\Http\Controllers;

use App\Billing\CreditMeter;
use App\Billing\Plan;
use App\Models\CreditTransaction;
use App\Models\Team;
use App\Services\Billing\StripeClient;
use App\Support\BiEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;

/**
 * Receives Stripe webhook events. Routes to a private handler per event
 * type. All handlers are idempotent — Stripe retries failed deliveries,
 * and we don't want a transient hiccup to result in double-credit grants.
 *
 * Critical events we care about:
 *   checkout.session.completed     → subscribe activated OR topup paid
 *   invoice.paid                   → monthly renewal (refresh credits)
 *   customer.subscription.updated  → plan switch / status change
 *   customer.subscription.deleted  → cancellation → downgrade to Free
 *
 * Everything else is logged + 200'd (Stripe expects 2xx; non-2xx triggers
 * retries which we don't want for events we deliberately don't handle).
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        protected StripeClient $stripe,
        protected CreditMeter $meter,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = (string) $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripe->verifyWebhook($payload, $sigHeader);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook signature verification failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'signature_invalid'], 400);
        }

        Log::info('Stripe webhook received', [
            'type' => $event->type,
            'id' => $event->id,
        ]);

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($event),
            'invoice.paid' => $this->handleInvoicePaid($event),
            'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($event),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event),
            default => null, // ignore — Stripe gets a 200 so it doesn't retry
        };

        return response()->json(['ok' => true]);
    }

    /**
     * Fires when a Checkout session finishes (either subscription start or
     * one-off topup). Mode tells us which.
     */
    protected function handleCheckoutCompleted(Event $event): void
    {
        /** @var array<string, mixed> $session */
        $session = $event->data->object->toArray();
        /** @var array<string, mixed> $metadata */
        $metadata = (array) ($session['metadata'] ?? []);
        $teamId = (int) ($metadata['team_id'] ?? 0);
        $team = $teamId > 0 ? Team::find($teamId) : null;

        if ($team === null) {
            Log::warning('Stripe checkout.session.completed without resolvable team', [
                'session_id' => (string) ($session['id'] ?? ''),
                'metadata' => $metadata,
            ]);

            return;
        }

        $mode = (string) ($session['mode'] ?? '');
        if ($mode === 'subscription') {
            $this->activateSubscription($team, $session);
        } elseif ($mode === 'payment') {
            $this->grantTopup($team, $session);
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function activateSubscription(Team $team, array $session): void
    {
        $subscriptionId = (string) ($session['subscription'] ?? '');
        /** @var array<string, mixed> $metadata */
        $metadata = (array) ($session['metadata'] ?? []);
        $planValue = (string) ($metadata['plan_value'] ?? '');

        $plan = $planValue !== '' ? Plan::tryFrom($planValue) : null;
        if ($plan === null) {
            Log::warning('Stripe checkout completed without resolvable plan', [
                'metadata' => $metadata,
            ]);

            return;
        }

        // Idempotent: if the team is already on this subscription, skip the grant.
        if ($team->stripe_subscription_id === $subscriptionId && $team->planObject() === $plan) {
            return;
        }

        $team->forceFill([
            'plan' => $plan->value,
            'stripe_subscription_id' => $subscriptionId,
            'stripe_subscription_status' => 'active',
        ])->save();

        // Grant the plan's monthly credits (resets balance to plan grant).
        $this->meter->grantMonthlyRenewal($team->fresh());

        // BI: subscription became active (best-effort, gated — see BiEmitter).
        // After the idempotency guard above, so retries don't double-fire.
        BiEmitter::emit('stripe', 'subscription_created', [
            'project' => 'automation_dashboard',
            'customer' => 'team-'.$team->id,
            'metric' => 'mrr',
            'value' => $plan->priceEur(),
            'payload' => ['plan' => $plan->label(), 'plan_value' => $plan->value, 'subscription_id' => $subscriptionId],
        ]);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function grantTopup(Team $team, array $session): void
    {
        /** @var array<string, mixed> $metadata */
        $metadata = (array) ($session['metadata'] ?? []);
        $packKey = (string) ($metadata['pack'] ?? '');
        $sessionId = (string) ($session['id'] ?? '');

        if ($packKey === 'custom') {
            // Custom amount: the customer chose the € amount on Stripe's
            // hosted page, so credits aren't in metadata — derive them from
            // what was actually paid. amount_total is in cents (EUR).
            $amountTotalCents = (int) ($session['amount_total'] ?? 0);
            $creditsPerEur = (int) config('billing.topup_custom.credits_per_eur');
            $credits = intdiv($amountTotalCents * $creditsPerEur, 100);
        } else {
            $credits = (int) ($metadata['credits'] ?? 0);
        }

        if ($credits <= 0) {
            Log::warning('Stripe topup completed without resolvable credit amount', [
                'session_id' => $sessionId,
                'metadata' => $metadata,
            ]);

            return;
        }

        // Idempotent: dedupe by stripe session id stamped into the audit row.
        $alreadyGranted = CreditTransaction::query()
            ->where('team_id', $team->id)
            ->where('reason', CreditTransaction::REASON_GRANT_TOPUP)
            ->whereJsonContains('meta->stripe_session_id', $sessionId)
            ->exists();

        if ($alreadyGranted) {
            return;
        }

        $this->meter->grantTopUp($team, $credits, [
            'pack' => $packKey,
            'stripe_session_id' => $sessionId,
            'source' => 'stripe',
        ]);
    }

    /**
     * Monthly invoice paid → renewal grant. Triggered ~monthly by Stripe.
     * Idempotent via the team's `credits_renewed_at` — grantMonthlyRenewal
     * already replaces balance + alert state, so re-running is harmless.
     */
    protected function handleInvoicePaid(Event $event): void
    {
        /** @var array<string, mixed> $invoice */
        $invoice = $event->data->object->toArray();
        $subscriptionId = (string) ($invoice['subscription'] ?? '');
        if ($subscriptionId === '') {
            return;
        }

        $team = Team::where('stripe_subscription_id', $subscriptionId)->first();
        if ($team === null) {
            return;
        }

        // Cache the period end FIRST, for every invoice including the very
        // first one: checkout.session.completed does not know the renewal
        // date, so without this the Billing page had no "Renews on" line
        // until the first monthly renewal — seen on the founder's own
        // purchase (period_end stayed null while everything else landed).
        $this->cachePeriodEnd($team, $invoice);

        // Only grant on actual recurring invoices, not the initial signup
        // (that's already handled by checkout.session.completed). Stripe
        // marks the initial invoice with billing_reason = subscription_create.
        if ((string) ($invoice['billing_reason'] ?? '') === 'subscription_create') {
            return;
        }

        // Resolve the plan from the INVOICE's own price before granting.
        // Stripe does not guarantee that customer.subscription.updated arrives
        // first, so relying on the plan column here would grant the OLD
        // allotment on an upgrade. Reading the price off the invoice makes
        // this handler correct whichever order the two events land in.
        $this->syncPlanFromInvoice($team, $invoice);
        $team->refresh();

        if ((string) ($invoice['billing_reason'] ?? '') === 'subscription_update') {
            // Mid-period plan change: raise the allowance, never lower it.
            // A downgrade's proration invoice is still a paid invoice, and
            // resetting the bucket here would confiscate credits the customer
            // has already paid for.
            $this->meter->raiseMonthlyAllowance($team, ['source' => 'stripe:subscription_update']);
        } else {
            $this->meter->grantMonthlyRenewal($team);
        }

    }

    /**
     * Remember when the paid period ends, from the invoice's first line.
     *
     * @param  array<string, mixed>  $invoice
     */
    protected function cachePeriodEnd(Team $team, array $invoice): void
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = (array) (($invoice['lines']['data'] ?? []));
        $periodEnd = $lines[0]['period']['end'] ?? null;
        if (is_int($periodEnd) || (is_string($periodEnd) && ctype_digit($periodEnd))) {
            $team->forceFill([
                'stripe_current_period_end' => CarbonImmutable::createFromTimestamp((int) $periodEnd),
            ])->save();
        }
    }

    /**
     * Invoice payment failed — Stripe will retry per the dunning settings,
     * but we should immediately mark the subscription as past_due so the
     * dashboard surfaces the banner. The customer.subscription.updated
     * event also fires; we set the status here as a redundant safety net.
     */
    protected function handleInvoicePaymentFailed(Event $event): void
    {
        /** @var array<string, mixed> $invoice */
        $invoice = $event->data->object->toArray();
        $subscriptionId = (string) ($invoice['subscription'] ?? '');
        if ($subscriptionId === '') {
            return;
        }

        $team = Team::where('stripe_subscription_id', $subscriptionId)->first();
        if ($team === null) {
            return;
        }

        $team->forceFill(['stripe_subscription_status' => 'past_due'])->save();
    }

    /**
     * Subscription status changed (e.g. past_due, trialing, active).
     * Mirror the status to our local column so the dashboard reflects it.
     */
    protected function handleSubscriptionUpdated(Event $event): void
    {
        /** @var array<string, mixed> $subscription */
        $subscription = $event->data->object->toArray();
        $team = Team::where('stripe_subscription_id', (string) ($subscription['id'] ?? ''))->first();
        if ($team === null) {
            return;
        }

        $fill = [
            'stripe_subscription_status' => (string) ($subscription['status'] ?? ''),
            'stripe_cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
        ];

        $periodEnd = $subscription['current_period_end'] ?? null;
        if (is_int($periodEnd) || (is_string($periodEnd) && ctype_digit($periodEnd))) {
            $fill['stripe_current_period_end'] = CarbonImmutable::createFromTimestamp((int) $periodEnd);
        }

        // A price swap arrives as an update, so the ENTITLEMENT has to move
        // with it. Without this the team keeps its old rung's agent cap and
        // allotment while paying the new price (or the reverse) — the plan
        // column and Stripe silently disagree.
        $plan = $this->planFromPriceIds([
            (string) ($subscription['items']['data'][0]['price']['id'] ?? ''),
        ]);
        if ($plan instanceof Plan) {
            $fill['plan'] = $plan->value;
        }

        $team->forceFill($fill)->save();
    }

    /**
     * Point a team's plan at whatever price its invoice actually billed.
     *
     * @param  array<string, mixed>  $invoice
     */
    protected function syncPlanFromInvoice(Team $team, array $invoice): void
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = (array) ($invoice['lines']['data'] ?? []);

        $priceIds = [];
        foreach ($lines as $line) {
            foreach ([$line['price']['id'] ?? null, $line['plan']['id'] ?? null] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $priceIds[] = $candidate;
                }
            }
        }

        $plan = $this->planFromPriceIds($priceIds);
        if ($plan instanceof Plan && $plan !== $team->planObject()) {
            $team->forceFill(['plan' => $plan->value])->save();
        }
    }

    /**
     * First price id that maps to a plan we recognise. Proration invoices
     * carry several lines (the credit for the old price, the charge for the
     * new one), so this deliberately scans rather than trusting line zero.
     *
     * @param  array<int, string>  $priceIds
     */
    protected function planFromPriceIds(array $priceIds): ?Plan
    {
        foreach ($priceIds as $priceId) {
            if ($priceId === '') {
                continue;
            }
            $plan = Plan::fromStripePriceId($priceId);
            if ($plan instanceof Plan) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Subscription deleted (canceled, or grace period elapsed). Downgrade
     * the team to Free immediately. Their current credit balance stays —
     * we don't claw back what they already paid for.
     */
    protected function handleSubscriptionDeleted(Event $event): void
    {
        /** @var array<string, mixed> $subscription */
        $subscription = $event->data->object->toArray();
        $team = Team::where('stripe_subscription_id', (string) ($subscription['id'] ?? ''))->first();
        if ($team === null) {
            return;
        }

        $team->forceFill([
            'plan' => Plan::Free->value,
            'stripe_subscription_id' => null,
            'stripe_subscription_status' => 'canceled',
            'stripe_current_period_end' => null,
            'stripe_cancel_at_period_end' => false,
        ])->save();
    }
}
