<?php

namespace App\Http\Controllers;

use App\Billing\CreditMeter;
use App\Billing\Plan;
use App\Billing\TopUpPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * Buy a credit pack. DEV-MODE: instant-grant with audit flag.
     * Phase H will swap the grant for Stripe Checkout.
     */
    public function topup(Request $request, CreditMeter $meter): RedirectResponse
    {
        $data = $request->validate([
            'pack' => ['required', 'string', 'in:'.implode(',', array_map(fn ($p) => $p->value, TopUpPack::cases()))],
        ]);

        $team = $request->user()->currentTeam;
        $plan = $team->planObject();

        // CreditMeter::grantTopUp also enforces this, but failing early
        // here means we never hit the meter on a Custom-plan attempt and
        // the user gets a clean validation message.
        abort_unless($plan->allowsTopUps(), 403, "Top-ups aren't available on the {$plan->label()} plan.");

        $pack = TopUpPack::from($data['pack']);

        // DEV-MODE grant. Audit meta records the pack + price so a future
        // reconciliation pass can identify simulated rows when Stripe ships.
        // TODO Phase H: replace this block with a Stripe Checkout session
        // redirect; move the grant call into the invoice.paid webhook.
        $meter->grantTopUp(
            team: $team,
            amount: $pack->credits(),
            meta: [
                'pack' => $pack->value,
                'price_usd' => $pack->priceUsd(),
                'simulated_payment' => true,
            ],
        );

        return back()->with('flash.topup', [
            'pack' => $pack->value,
            'credits' => $pack->credits(),
            'price_usd' => $pack->priceUsd(),
            'message' => "Added {$pack->credits()} credits to your balance.",
        ]);
    }
}
