<?php

namespace App\Http\Controllers;

use App\Billing\Plan;
use App\Billing\TopUpPack;
use App\Models\Team;
use App\Services\Billing\StripeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Billing UI + credit top-up flow.
 *
 * `index()` was previously an inline closure in routes/web.php; lifted
 * here so the top-up action has somewhere to live and the page render
 * + transaction list can share helper code.
 *
 * `topup()` is the "buy more credits" entry point. Until Phase H wires
 * Stripe Checkout, this runs in DEV-MODE: validates the pack, checks
 * the plan permits top-ups, then grants credits immediately with a
 * `simulated_payment` flag in the audit row's meta. When Stripe ships:
 * swap the grant for a Checkout session redirect; the actual grant
 * moves to the `invoice.paid` webhook handler.
 */
class BillingController extends Controller
{
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;

        return Inertia::render('Billing/Index', [
            'transactions' => $team->creditTransactions()
                ->latest()->take(50)->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'amount' => $t->amount,
                    'reason' => $t->reason,
                    'meta' => $t->meta,
                    'created_at' => $t->created_at->toIso8601String(),
                ]),
            // Top-up pack catalog — the UI renders a picker from this. Empty
            // array when the current plan doesn't allow top-ups so the
            // dialog won't even render the buy buttons.
            'topup_packs' => $team->planObject()->allowsTopUps()
                ? TopUpPack::catalog()
                : [],
        ]);
    }

    /**
     * Buy a credit pack via Stripe Checkout. The actual grant happens in
     * StripeWebhookController when checkout.session.completed fires —
     * this controller just orchestrates the redirect to Stripe.
     */
    public function topup(Request $request, StripeClient $stripe): RedirectResponse
    {
        $data = $request->validate([
            'pack' => ['required', 'string', 'in:'.implode(',', array_map(fn ($p) => $p->value, TopUpPack::cases()))],
        ]);

        $team = $request->user()->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'Sign in to a team first.');
        }
        $plan = $team->planObject();

        abort_unless($plan->allowsTopUps(), 403, "Top-ups aren't available on the {$plan->label()} plan.");

        $pack = TopUpPack::from($data['pack']);
        $priceId = $pack->stripePriceId();
        if ($priceId === null) {
            return back()->withErrors([
                'pack' => 'This top-up pack is not yet available for purchase.',
            ]);
        }

        $session = $stripe->createOneOffCheckout(
            team: $team,
            priceId: $priceId,
            successUrl: route('billing.index').'?topup=success',
            cancelUrl: route('billing.index').'?topup=canceled',
            metadata: [
                'pack' => $pack->value,
                'credits' => (string) $pack->credits(),
            ],
        );

        // Inertia POST → away-redirect to Stripe. The frontend follows.
        return redirect()->away($session->url);
    }

    /**
     * Redirect the customer to Stripe's hosted Customer Portal where they
     * can cancel their subscription, update payment method, download
     * invoices, and update billing info. Stripe handles the entire UX;
     * we just create the session + redirect.
     *
     * Cancellations come back through customer.subscription.deleted in
     * StripeWebhookController which downgrades the team to Free.
     */
    public function portal(Request $request, StripeClient $stripe): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'Sign in to a team first.');
        }

        if ($team->stripe_customer_id === null || $team->stripe_customer_id === '') {
            return back()->withErrors([
                'portal' => 'You need an active subscription before managing billing. Subscribe first.',
            ]);
        }

        try {
            $session = $stripe->createBillingPortalSession(
                team: $team,
                returnUrl: route('billing.index'),
            );
        } catch (\Throwable $e) {
            Log::error('Stripe billing portal session failed', [
                'team_id' => $team->id,
                'message' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'portal' => 'Billing portal is temporarily unavailable. Please try again in a moment.',
            ]);
        }

        return redirect()->away($session->url);
    }
}
