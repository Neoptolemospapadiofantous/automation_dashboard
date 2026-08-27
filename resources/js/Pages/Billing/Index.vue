<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import DialogModal from '@/Components/DialogModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    transactions: { type: Array, required: true },
    topup_packs: { type: Array, default: () => [] },
    topup_custom: { type: Object, default: null },
    plan_catalog: { type: Object, default: () => ({}) },
});

const page = usePage();
const billing = computed(() => page.props.billing ?? null);
const topupFlash = computed(() => page.props.flash?.topup ?? null);

const usedPercent = computed(() => {
    if (!billing.value?.credits_total) return 0;
    return Math.min(100, Math.round((billing.value.credits_used / billing.value.credits_total) * 100));
});

const reasonLabel = (r) => ({
    grant_renewal: 'Monthly renewal',
    grant_topup: 'Top-up purchase',
    consume_message: 'Message',
    refund: 'Refund',
    adjustment: 'Adjustment',
}[r] || r);

const fmt = (d) => new Date(d).toLocaleString();
const fmtNum = (n) => (typeof n === 'number' ? n.toLocaleString() : '—');

// --- Top-up dialog ----------------------------------------------------------
const showTopup = ref(false);
const selectedPack = ref(null);
const topupForm = useForm({ pack: null });

function openTopup() {
    if (!billing.value?.allows_topups) return;
    if (!billing.value?.is_owner) return; // owner-only; server enforces too
    selectedPack.value = null;
    topupForm.reset();
    showTopup.value = true;
}

function buy(pack) {
    submitTopup(pack.id);
}

// Custom amount: the customer enters the actual € figure on Stripe's hosted
// page (custom_unit_amount), so we just post pack=custom and redirect.
function buyCustom() {
    submitTopup('custom');
}

function submitTopup(packId) {
    selectedPack.value = packId;
    topupForm.pack = packId;
    topupForm.post(route('billing.topup'), {
        preserveScroll: true,
        onSuccess: () => {
            showTopup.value = false;
            // Refresh transactions list + billing snapshot.
            router.reload({ only: ['transactions', 'billing', 'flash'] });
        },
    });
}

// --- Subscription ----------------------------------------------------------
// POSTs to /subscribe/{plan} which creates a Stripe Checkout session on the
// server and redirects the browser away. We use a form-style post so CSRF
// is handled automatically; Inertia gets the away-redirect and follows.
const subscribeForm = useForm({});
// Annual toggle — when true, all subscribe CTAs append ?cycle=annual.
// Defaults to monthly. Server falls back to monthly if the env price ID
// for the annual cycle isn't set (UI is also gated by plan.annual_available).
const cycle = ref('monthly');

function subscribe(planKey) {
    // "Already on it" only counts with a live Stripe subscription —
    // Plan::Free shares the "Starter" label, so an unpaid team must
    // still be able to buy Starter.
    if (billing.value?.subscribed && billing.value?.plan_label?.toLowerCase() === planKey) return;
    if (! billing.value?.is_owner) return; // server enforces; UI matches
    const query = cycle.value === 'annual' ? '?cycle=annual' : '';
    subscribeForm.post(`/subscribe/${planKey}${query}`);
}

const anyAnnualAvailable = computed(
    () => Object.values(props.plan_catalog ?? {}).some((p) => p?.annual_available),
);

// --- Customer Portal ------------------------------------------------------
// POSTs to /billing/portal → server creates a Stripe Customer Portal
// session and redirects the browser away. Customer cancels/updates card
// over there; comes back to /billing when done. Cancellation fires
// customer.subscription.deleted which downgrades us via webhook.
const portalForm = useForm({});
function openPortal() {
    portalForm.post(route('billing.portal'));
}
</script>

<template>
    <AppLayout title="Billing">
        <PageHeader title="Billing" description="Your plan, credit usage, and transaction history." />

        <div v-if="!billing" class="py-12 text-center text-sm text-ink-dim">
            No billing context — make sure you're on a team.
        </div>
        <div v-else class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Top-up success flash (one-shot after Inertia redirect-back) -->
                <div v-if="topupFlash" class="rounded-none border border-state-ok-line bg-state-ok-surface px-4 py-3 text-sm text-state-ok-ink">
                    {{ topupFlash.message }}
                </div>

                <!-- Past-due / canceled subscription banner -->
                <div
                    v-if="billing?.subscription_status === 'past_due'"
                    class="rounded-none border border-state-warn-line bg-state-warn-surface px-4 py-3 text-sm text-state-warn-ink"
                >
                    <strong>Payment failed.</strong> Your last invoice didn't clear. Update your card in the customer portal to keep your subscription active.
                </div>
                <div
                    v-else-if="billing?.subscription_status === 'canceled'"
                    class="rounded-none border border-border-hi bg-bg-elev px-4 py-3 text-sm text-ink-dim"
                >
                    Your subscription was canceled. You're back on the free tier; subscribe again below to reactivate.
                </div>

                <!-- Current plan + usage -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-none border border-border-line bg-bg p-5">
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Current plan</div>
                        <div class="mt-1 flex items-baseline gap-2">
                            <div class="text-2xl font-semibold text-ink">{{ billing?.plan_label }}</div>
                        </div>
                        <div class="mt-1 text-xs text-ink-dim">
                            <span v-if="billing?.max_agents >= 9999">Unlimited agents</span>
                            <span v-else>{{ billing?.agents_count }} / {{ billing?.max_agents }} agents</span>
                        </div>
                        <!-- Manage subscription — opens Stripe-hosted Customer Portal
                             where the user can cancel, update card, download invoices.
                             Owner-only — RBAC blocks non-owners server-side too. -->
                        <button
                            v-if="billing?.has_stripe_customer && billing?.is_owner"
                            type="button"
                            class="mt-3 inline-flex items-center gap-1 rounded-none border border-border-line bg-bg px-3 py-1.5 text-xs font-medium text-ink-dim hover:bg-surface-hi"
                            @click="openPortal"
                        >
                            ⚙ Manage subscription
                        </button>
                        <p v-else-if="!billing?.is_owner" class="mt-3 text-xs text-ink-dim">
                            Only the team owner can change billing.
                        </p>
                    </div>

                    <!-- Plan upgrade strip: Starter / Operator subscribe buttons.
                         Shows on every plan that's not already maxed out so the
                         customer can see what they could move to. Each button
                         POSTs to /subscribe/{plan} which redirects to Stripe Checkout.
                         When annual is available, the monthly/annual toggle controls
                         which Stripe Price ID the redirect uses. -->
                    <div class="rounded-none border border-border-line bg-bg p-5 sm:col-span-2 lg:col-span-2">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <div class="flex items-baseline gap-2.5">
                                <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Subscription tiers</div>
                                <span class="bp-ref">BILLING/PLANS</span>
                            </div>

                            <!-- Monthly / Annual cycle toggle. Only renders when
                                 at least one tier has annual pricing configured. -->
                            <div v-if="anyAnnualAvailable" class="flex items-center gap-1 rounded-none bg-surface-hi p-0.5">
                                <button
                                    type="button"
                                    class="rounded-none px-3 py-1 text-xs font-medium transition"
                                    :class="cycle === 'monthly' ? 'bg-violet text-bg' : 'text-ink-dim'"
                                    @click="cycle = 'monthly'"
                                >
                                    Monthly
                                </button>
                                <button
                                    type="button"
                                    class="flex items-center gap-1 rounded-none px-3 py-1 text-xs font-medium transition"
                                    :class="cycle === 'annual' ? 'bg-violet text-bg' : 'text-ink-dim'"
                                    @click="cycle = 'annual'"
                                >
                                    Annual
                                    <span class="rounded-none bg-state-ok-surface px-1.5 py-0.5 text-[10px] font-semibold text-state-ok-ink">
                                        Save {{ Object.values(plan_catalog)[0]?.annual_savings_pct ?? 17 }}%
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <button
                                v-for="p in plan_catalog"
                                :key="p.key"
                                type="button"
                                class="shadow-sheet flex flex-col items-start rounded-none border p-3 text-left transition hover:border-ink hover:bg-surface-hi"
                                :class="billing?.plan_label === p.label ? 'border-violet ring-1 ring-violet bg-surface-hi' : 'border-border-line bg-bg'"
                                :disabled="!billing?.is_owner"
                                @click="subscribe(p.key)"
                            >
                                <div class="flex items-baseline gap-2">
                                    <span class="text-sm font-semibold text-ink">{{ p.label }}</span>
                                    <span
                                        v-if="cycle === 'annual' && p.annual_available"
                                        class="font-mono text-sm font-semibold text-ink"
                                    >
                                        — €{{ p.annual_equivalent_monthly_eur }}/mo
                                    </span>
                                    <span v-else class="font-mono text-sm font-semibold text-ink">
                                        — €{{ p.monthly_eur }}/mo
                                    </span>
                                </div>
                                <div class="mt-1 text-[11px] text-ink-dim">
                                    {{ p.max_agents >= 9999 ? 'Unlimited agents' : `${p.max_agents} agent${p.max_agents === 1 ? '' : 's'}` }}
                                    · {{ p.monthly_credits.toLocaleString() }} credits / month
                                </div>
                                <div
                                    v-if="cycle === 'annual' && p.annual_available"
                                    class="mt-1 text-[10px] text-state-ok-ink"
                                >
                                    Billed yearly · €{{ p.annual_eur.toLocaleString() }} / yr
                                </div>
                                <div
                                    v-else-if="cycle === 'annual' && !p.annual_available"
                                    class="mt-1 text-[10px] text-state-warn-ink"
                                >
                                    Annual not yet available — billed monthly.
                                </div>

                                <div class="mt-2 text-[11px] font-medium text-ink underline" v-if="!(billing?.subscribed && billing?.plan_label === p.label)">
                                    Subscribe →
                                </div>
                                <div class="mt-2 text-[11px] font-medium text-ink-mute" v-else>
                                    Current plan
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Custom plan: credits are negotiated, no monthly cap to display. -->
                    <div v-if="billing?.is_custom" class="rounded-none border border-border-line bg-bg p-5 sm:col-span-2">
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Credits</div>
                        <div class="mt-2 flex items-baseline gap-2">
                            <span class="font-mono text-2xl font-semibold tabular-nums text-ink">{{ fmtNum(billing.credits_remaining) }}</span>
                            <span class="text-sm text-ink-dim">credits remaining</span>
                        </div>
                        <p class="mt-3 text-xs text-ink-dim">
                            Custom plans run on a negotiated credit budget — there's no fixed monthly cap. Contact your account
                            owner for adjustments.
                        </p>
                    </div>

                    <!-- Not subscribed yet: no monthly allowance has been granted,
                         so a usage bar would read "2,500/2,500 used" for a team
                         that used nothing. Point at the subscribe cards instead. -->
                    <div v-else-if="!billing?.subscribed" class="rounded-none border border-border-line bg-bg p-5 sm:col-span-2">
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Conversation credits</div>
                        <p class="mt-2 text-sm text-ink-dim">
                            No active subscription yet — pick a plan above and your monthly
                            conversation credits activate the moment checkout completes.
                        </p>
                        <p v-if="billing?.topup_balance > 0" class="mt-2 text-sm text-ink-dim">
                            You still have <span class="font-mono font-medium tabular-nums">{{ fmtNum(billing.topup_balance) }}</span>
                            rolled-over top-up credits available.
                        </p>
                    </div>

                    <!-- Starter / Operator: normal usage bar + top-up button. -->
                    <div v-else class="rounded-none border border-border-line bg-bg p-5 sm:col-span-2">
                        <div class="flex items-center justify-between">
                            <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Conversation-credit usage this period</div>
                            <div class="font-mono text-xs tabular-nums text-ink-dim">{{ usedPercent }}%</div>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-none bg-surface-hi">
                            <div
                                class="h-full transition-all"
                                :class="usedPercent >= 90 ? 'bg-state-bad-solid' : usedPercent >= 75 ? 'bg-state-warn-solid' : 'bg-ink'"
                                :style="{ width: usedPercent + '%' }"
                            />
                        </div>
                        <div class="mt-2 flex items-baseline justify-between text-sm">
                            <div class="text-ink-dim">
                                <span class="font-mono font-semibold tabular-nums">{{ fmtNum(billing?.credits_used) }}</span>
                                <span class="text-ink-mute"> / {{ fmtNum(billing?.credits_total) }} monthly used</span>
                            </div>
                            <div class="text-ink-dim">
                                <span class="font-mono font-medium tabular-nums">{{ fmtNum(billing?.credits_remaining) }}</span> remaining<template v-if="billing?.topup_balance > 0"> (includes <span class="font-mono font-medium tabular-nums">{{ fmtNum(billing.topup_balance) }}</span> rolled-over top-up)</template>
                            </div>
                        </div>
                        <div class="mt-3 text-xs text-ink-dim">
                            Each user message and each agent reply consumes conversation credits — 1× on Claude Haiku/Gemini, more on smarter models (see the Versions page). Your monthly allowance resets on renewal; purchased top-up credits roll over until used.
                        </div>
                        <div v-if="billing?.allows_topups" class="mt-4">
                            <PrimaryButton type="button" @click="openTopup">
                                + Add credits
                            </PrimaryButton>
                        </div>
                    </div>
                </div>

                <!-- Transactions -->
                <div class="overflow-hidden rounded-none border border-border-line bg-bg">
                    <div class="border-b border-border-line px-5 py-3 text-sm font-semibold text-ink">
                        Recent credit activity
                    </div>
                    <div v-if="transactions.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border-line text-sm">
                        <thead class="bg-bg-elev">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-semibold text-ink-dim">Time</th>
                                <th class="px-4 py-2.5 text-left font-semibold text-ink-dim">Reason</th>
                                <th class="px-4 py-2.5 text-right font-semibold text-ink-dim">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-line">
                            <tr v-for="t in transactions" :key="t.id" class="hover:bg-surface-hi">
                                <td class="px-4 py-3 text-ink-dim">{{ fmt(t.created_at) }}</td>
                                <td class="px-4 py-3 text-ink-dim">{{ reasonLabel(t.reason) }}</td>
                                <td class="px-4 py-3 text-right font-mono tabular-nums font-medium" :class="t.amount > 0 ? 'text-state-ok-ink' : 'text-ink'">
                                    {{ t.amount > 0 ? '+' : '' }}{{ t.amount.toLocaleString() }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <EmptyState
                        v-else
                        title="No credit activity yet"
                        description="Start a chat with your agent to see messages here."
                    />
                </div>
            </div>
        </div>

        <!-- Top-up dialog -->
        <DialogModal :show="showTopup" max-width="lg" @close="showTopup = false">
            <template #title>Add credits</template>
            <template #content>
                <p class="text-sm text-ink-dim">
                    Pick a pack. Credits are added immediately and stack on top of your monthly allotment — no rollover concern.
                </p>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <button
                        v-for="pack in topup_packs"
                        :key="pack.id"
                        type="button"
                        class="rounded-none border p-4 text-left transition"
                        :class="selectedPack === pack.id
                            ? 'border-ink bg-surface-hi ring-2 ring-ink'
                            : 'border-border-line hover:border-ink hover:bg-surface-hi'"
                        :disabled="topupForm.processing"
                        @click="buy(pack)"
                    >
                        <div class="font-mono text-xs font-semibold uppercase tracking-wider text-ink-dim">{{ pack.label }}</div>
                        <div class="mt-2 flex items-baseline gap-1.5">
                            <span class="font-mono text-2xl font-semibold text-ink">{{ pack.credits.toLocaleString() }}</span>
                            <span class="text-xs text-ink-dim">credits</span>
                        </div>
                        <div class="mt-1 font-mono text-sm font-medium text-ink">€{{ pack.price_eur }}</div>
                        <div class="mt-1 font-mono text-[10px] text-ink-mute tabular-nums">
                            €{{ (pack.price_eur / pack.credits).toFixed(4) }} / credit
                        </div>
                    </button>
                </div>

                <!-- Custom amount: redirects to Stripe's hosted page where the
                     customer types any € figure between min and max. -->
                <button
                    v-if="topup_custom"
                    type="button"
                    class="mt-3 flex w-full items-center justify-between rounded-none border p-4 text-left transition"
                    :class="selectedPack === 'custom'
                        ? 'border-ink bg-surface-hi ring-2 ring-ink'
                        : 'border-border-line hover:border-ink hover:bg-surface-hi'"
                    :disabled="topupForm.processing"
                    @click="buyCustom"
                >
                    <div>
                        <div class="font-mono text-xs font-semibold uppercase tracking-wider text-ink-dim">Custom amount</div>
                        <div class="mt-1 text-sm text-ink">
                            Choose your own — €{{ topup_custom.min_eur }} to €{{ topup_custom.max_eur.toLocaleString() }}
                        </div>
                        <div class="mt-1 font-mono text-[10px] text-ink-mute tabular-nums">
                            {{ topup_custom.credits_per_eur }} credits / €1 · you'll enter the amount on the next screen
                        </div>
                    </div>
                    <span class="font-mono text-lg text-ink-dim">→</span>
                </button>

                <p class="mt-4 text-xs text-ink-dim">
                    Top-ups are priced above subscription rates on purpose — if you top up often, upgrading to Operator is the cheaper path. Custom plans have negotiated budgets.
                </p>
            </template>
            <template #footer>
                <SecondaryButton :disabled="topupForm.processing" @click="showTopup = false">Cancel</SecondaryButton>
            </template>
        </DialogModal>
    </AppLayout>
</template>
