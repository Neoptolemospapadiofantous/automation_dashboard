<?php

namespace App\Services\Billing;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient as StripeSdk;
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
