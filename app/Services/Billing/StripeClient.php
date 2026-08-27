<?php

namespace App\Services\Billing;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient as StripeSdk;
use Stripe\Subscription;
use Stripe\Webhook;

/**
 * Thin wrapper around the Stripe PHP SDK. Centralises:
 *   - API key + version pinning (read from config/billing.php)
 *   - typed methods our app actually uses (checkout session create + webhook verify)
 *   - lazy Customer creation per team
 *   - error mapping into Laravel logs
 *
 * Everything else (subscription listing, refunds, etc.) call StripeSdk directly
 * via $this->sdk() when needed — this wrapper deliberately stays narrow.
 *
 * NOT a singleton in the container because Stripe's SDK keeps no shared state
 * worth preserving; we spin up a fresh client per call. Cheap.
 */
class StripeClient
{
    public function sdk(): StripeSdk
    {
        return new StripeSdk([
            'api_key' => (string) config('billing.stripe.secret'),
            'stripe_version' => (string) config('billing.stripe.api_version'),
        ]);
    }

    /**
     * Find-or-create the Stripe Customer for a team. Lazy — only created on
     * the first subscribe/topup attempt. Stores cus_* in teams.stripe_customer_id.
     */
    public function ensureCustomer(Team $team): Customer
    {
        if ($team->stripe_customer_id !== null && $team->stripe_customer_id !== '') {
            return $this->sdk()->customers->retrieve($team->stripe_customer_id);
        }

        $owner = $team->owner;
        $ownerEmail = $owner instanceof User ? $owner->email : null;
        $ownerId = $owner instanceof User ? (string) $owner->id : '';

        $customer = $this->sdk()->customers->create([
            'email' => $ownerEmail,
            'name' => $team->name,
            'metadata' => [
                'team_id' => (string) $team->id,
                'owner_user_id' => $ownerId,
            ],
        ]);

        $team->forceFill(['stripe_customer_id' => $customer->id])->save();

        return $customer;
    }

    /**
     * Create a Checkout session for a recurring subscription. Returns the
     * hosted Stripe URL the caller redirects the browser to.
     *
     * @param  string  $priceId  e.g. price_1Q...
     * @param  array<string, string>  $metadata  attached to the session + future invoice rows
     */
    public function createSubscriptionCheckout(
        Team $team,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): CheckoutSession {
        $customer = $this->ensureCustomer($team);

        return $this->sdk()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customer->id,
            'line_items' => [
                ['price' => $priceId, 'quantity' => 1],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            // Carry the team id all the way through to the webhook so the
            // handler doesn't have to reverse-lookup by cus_*.
            'metadata' => array_merge(['team_id' => (string) $team->id], $metadata),
            'subscription_data' => [
                'metadata' => array_merge(['team_id' => (string) $team->id], $metadata),
            ],
            'allow_promotion_codes' => true,
            // Skip card collection when a 100%-off promo brings the total to 0;
            // paid subscriptions (total > 0) still collect a card as normal.
            'payment_method_collection' => 'if_required',
        ]);
    }

    /**
     * Create a Checkout session for a one-off payment (top-up pack).
     *
     * @param  array<string, string>  $metadata
     */
    public function createOneOffCheckout(
        Team $team,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata = [],
    ): CheckoutSession {
        $customer = $this->ensureCustomer($team);

        return $this->sdk()->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $customer->id,
            'line_items' => [
                ['price' => $priceId, 'quantity' => 1],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => array_merge(['team_id' => (string) $team->id], $metadata),
        ]);
    }

    /**
     * Create a Stripe Customer Portal session — a hosted page where the
     * customer manages their subscription (cancel, update card, download
     * invoices). The portal must be configured at
     * dashboard.stripe.com/test/settings/billing/portal first; the SDK call
     * will throw with a clear "configuration not active" error otherwise.
     *
     * Returns the portal session URL. Caller redirects the browser.
     */
    /**
     * Move a live subscription onto a different Price — the self-serve
     * upgrade/downgrade. Stripe requires the existing item id, so the
     * subscription is retrieved first and its single item swapped in place;
     * replacing `items` wholesale would cancel and re-create it, losing the
     * billing anchor and charging a fresh full period.
     *
     * $invoiceImmediately drives proration:
     *   UPGRADE  → true  → Stripe invoices the prorated difference now, so
     *                      money lands before the larger allowance does.
     *   DOWNGRADE→ false → prorated credit sits on the account against the
     *                      next invoice. We never refund and never claw back
     *                      credits already paid for.
     *
     * @throws \InvalidArgumentException when the team has no live subscription
     */
    public function changeSubscriptionPrice(Team $team, string $priceId, bool $invoiceImmediately): Subscription
    {
        $subscriptionId = (string) $team->stripe_subscription_id;
        if ($subscriptionId === '') {
            throw new \InvalidArgumentException('Team has no live subscription to change.');
        }

        $subscription = $this->sdk()->subscriptions->retrieve($subscriptionId);
        $itemId = $subscription->items->data[0]->id ?? null;
        if (! is_string($itemId) || $itemId === '') {
            throw new \InvalidArgumentException("Subscription {$subscriptionId} has no line item to change.");
        }

        return $this->sdk()->subscriptions->update($subscriptionId, [
            'items' => [['id' => $itemId, 'price' => $priceId]],
            'proration_behavior' => $invoiceImmediately ? 'always_invoice' : 'create_prorations',
            // Keep the renewal date. Without this an upgrade would reset the
            // billing anchor and the customer would pay a full period twice.
            'billing_cycle_anchor' => 'unchanged',
            'payment_behavior' => 'error_if_incomplete',
        ]);
    }

    /**
     * The Price a team's live subscription is currently billed on, or null if
     * there is no subscription. Lets the caller reject a no-op switch (same
     * rung AND same cycle) before it reaches Stripe.
     */
    public function subscriptionPriceId(Team $team): ?string
    {
        $subscriptionId = (string) $team->stripe_subscription_id;
        if ($subscriptionId === '') {
            return null;
        }

        $subscription = $this->sdk()->subscriptions->retrieve($subscriptionId);
        $priceId = $subscription->items->data[0]->price->id ?? null;

        return is_string($priceId) && $priceId !== '' ? $priceId : null;
    }

    /**
     * Schedule (or un-schedule) cancellation at the end of the paid period.
     *
     * Deliberately NOT subscriptions->cancel(): that ends the subscription
     * immediately and throws away time the customer already paid for. This
     * keeps them on their plan until the period ends and stays reversible
     * right up to that moment.
     *
     * @throws \InvalidArgumentException when the team has no live subscription
     */
    public function setCancelAtPeriodEnd(Team $team, bool $cancel): Subscription
    {
        $subscriptionId = (string) $team->stripe_subscription_id;
        if ($subscriptionId === '') {
            throw new \InvalidArgumentException('Team has no live subscription to cancel.');
        }

        return $this->sdk()->subscriptions->update($subscriptionId, [
            'cancel_at_period_end' => $cancel,
        ]);
    }

    public function createBillingPortalSession(Team $team, string $returnUrl): BillingPortalSession
    {
        $customerId = (string) $team->stripe_customer_id;
        if ($customerId === '') {
            throw new \InvalidArgumentException(
                'Team has no Stripe customer — they have never subscribed or topped up.'
            );
        }

        return $this->sdk()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Verify the Stripe webhook signature + parse the event. Throws on
     * any tampering. Caller switches on the event type.
     *
     * @throws SignatureVerificationException on signature mismatch
     */
    public function verifyWebhook(string $payload, string $signatureHeader): Event
    {
        $secret = (string) config('billing.stripe.webhook_secret');

        if ($secret === '') {
            // Misconfigured. Logging + throwing so it's not silent. We use the
            // SDK's factory so the exception has correct internal state for
            // anyone catching SignatureVerificationException downstream.
            Log::error('Stripe webhook called but STRIPE_WEBHOOK_SECRET is empty');
            throw SignatureVerificationException::factory(
                'webhook secret not configured',
                $signatureHeader,
                $payload,
            );
        }

        return Webhook::constructEvent($payload, $signatureHeader, $secret);
    }
}
