<?php

namespace App\Http\Controllers;

use App\Billing\BillingCycle;
use App\Billing\Plan;
use App\Billing\TopUpPack;
use App\Http\Controllers\Concerns\AuthorizesByTeamRole;
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
    use AuthorizesByTeamRole;

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
            // Custom top-up (customer-chosen € amount). Null when the plan
            // can't top up or the custom price isn't configured — the UI
            // hides the custom card in that case.
            'topup_custom' => $team->planObject()->allowsTopUps() && $this->customTopUpAvailable()
                ? [
                    'min_eur' => (int) config('billing.topup_custom.min_eur'),
                    'max_eur' => (int) config('billing.topup_custom.max_eur'),
                    'credits_per_eur' => (int) config('billing.topup_custom.credits_per_eur'),
                ]
                : null,
            // Plan catalog — surfaces price, max agents, monthly credit
            // grant, AND whether annual is available so the UI can show
            // the monthly/annual toggle and the savings %.
            'plan_catalog' => [
                'starter' => $this->planSummary(Plan::Free, 'starter'),
                'operator' => $this->planSummary(Plan::Pro, 'operator'),
            ],
        ]);
    }

    /**
     * @return array{
     *   key: string, value: string, label: string, monthly_eur: int,
     *   annual_equivalent_monthly_eur: ?int, annual_savings_pct: int,
     *   annual_available: bool, max_agents: int, monthly_credits: int
     * }
     */
    protected function planSummary(Plan $plan, string $key): array
    {
        return [
            'key' => $key,
            'value' => $plan->value,
            'label' => $plan->label(),
            'monthly_eur' => (int) $plan->priceEur(),
            'annual_equivalent_monthly_eur' => $plan->annualEquivalentMonthlyEur(),
            'annual_savings_pct' => $plan->annualSavingsPct(),
            'annual_available' => $plan->stripePriceId(BillingCycle::Annual) !== null,
            'max_agents' => $plan->maxAgents(),
            'monthly_credits' => $plan->monthlyCredits(),
        ];
    }

    /**
     * Buy a credit pack via Stripe Checkout. The actual grant happens in
     * StripeWebhookController when checkout.session.completed fires —
     * this controller just orchestrates the redirect to Stripe.
     */
    public function topup(Request $request, StripeClient $stripe): RedirectResponse
    {
        $this->requireOwner($request, 'buy credit top-ups');

        // 'custom' is a valid pack id alongside the fixed packs — it maps to
        // the custom_unit_amount Stripe price where the customer types their
        // own € amount on the hosted page.
        $allowed = array_merge(array_map(fn ($p) => $p->value, TopUpPack::cases()), ['custom']);
        $data = $request->validate([
            'pack' => ['required', 'string', 'in:'.implode(',', $allowed)],
        ]);

        $team = $request->user()->currentTeam;
        if (! $team instanceof Team) {
            abort(403, 'Sign in to a team first.');
        }
        $plan = $team->planObject();

        abort_unless($plan->allowsTopUps(), 403, "Top-ups aren't available on the {$plan->label()} plan.");

        // Resolve price id + metadata for either a fixed pack or the custom
        // amount. Fixed packs carry their credit count in metadata; the custom
        // amount is unknown until the customer enters it on Stripe, so the
        // webhook derives credits from amount_total instead.
        if ($data['pack'] === 'custom') {
            $priceId = $this->customTopUpPriceId();
            $metadata = ['pack' => 'custom'];
        } else {
            $pack = TopUpPack::from($data['pack']);
            $priceId = $pack->stripePriceId();
            $metadata = ['pack' => $pack->value, 'credits' => (string) $pack->credits()];
        }

        if ($priceId === null) {
            return back()->withErrors([
                'pack' => 'This top-up option is not yet available for purchase.',
            ]);
        }

        $session = $stripe->createOneOffCheckout(
            team: $team,
            priceId: $priceId,
            successUrl: route('billing.index').'?topup=success',
            cancelUrl: route('billing.index').'?topup=canceled',
            metadata: $metadata,
        );

        // Inertia POST → away-redirect to Stripe. The frontend follows.
        return redirect()->away($session->url);
    }

    /**
     * The configured custom top-up Stripe price id, or null when unset.
     */
    protected function customTopUpPriceId(): ?string
    {
        $value = config('billing.topup_custom.price_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function customTopUpAvailable(): bool
    {
        return $this->customTopUpPriceId() !== null;
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
        $this->requireOwner($request, 'manage the subscription');

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
