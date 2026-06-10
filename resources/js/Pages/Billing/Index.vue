<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import DialogModal from '@/Components/DialogModal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

defineProps({
    transactions: { type: Array, required: true },
    topup_packs: { type: Array, default: () => [] },
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
    selectedPack.value = pack.id;
    topupForm.pack = pack.id;
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
function subscribe(planKey) {
    if (billing.value?.plan_label?.toLowerCase() === planKey) return; // already on it
    if (! billing.value?.is_owner) return; // server enforces; UI matches
    subscribeForm.post(`/subscribe/${planKey}`);
}

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

        <div v-if="!billing" class="py-12 text-center text-sm text-gray-400">
            No billing context — make sure you're on a team.
        </div>
        <div v-else class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Top-up success flash (one-shot after Inertia redirect-back) -->
                <div v-if="topupFlash" class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ topupFlash.message }}
                </div>

                <!-- Past-due / canceled subscription banner -->
                <div
                    v-if="billing?.subscription_status === 'past_due'"
                    class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
                >
                    <strong>Payment failed.</strong> Your last invoice didn't clear. Update your card in the customer portal to keep your subscription active.
                </div>
                <div
                    v-else-if="billing?.subscription_status === 'canceled'"
                    class="rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-700"
                >
                    Your subscription was canceled. You're back on the free tier; subscribe again below to reactivate.
                </div>

                <!-- Current plan + usage -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-xl bg-white p-5 shadow ring-1 ring-black/5">
                        <div class="text-xs uppercase tracking-wide text-gray-400">Current plan</div>
                        <div class="mt-1 flex items-baseline gap-2">
                            <div class="text-2xl font-semibold text-gray-900">{{ billing?.plan_label }}</div>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            <span v-if="billing?.max_agents >= 9999">Unlimited agents</span>
                            <span v-else>{{ billing?.agents_count }} / {{ billing?.max_agents }} agents</span>
                        </div>
                        <!-- Manage subscription — opens Stripe-hosted Customer Portal
                             where the user can cancel, update card, download invoices.
                             Owner-only — RBAC blocks non-owners server-side too. -->
                        <button
                            v-if="billing?.has_stripe_customer && billing?.is_owner"
                            type="button"
                            class="mt-3 inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                            @click="openPortal"
                        >
                            ⚙ Manage subscription
                        </button>
                        <p v-else-if="!billing?.is_owner" class="mt-3 text-xs text-gray-400">
                            Only the team owner can change billing.
                        </p>
                    </div>

                    <!-- Plan upgrade strip: Starter / Operator subscribe buttons.
                         Shows on every plan that's not already maxed out so the
                         customer can see what they could move to. Each button
                         POSTs to /subscribe/{plan} which redirects to Stripe Checkout. -->
                    <div class="rounded-xl bg-white p-5 shadow ring-1 ring-black/5 sm:col-span-2 lg:col-span-2">
                        <div class="text-xs uppercase tracking-wide text-gray-400">Subscription tiers</div>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <button
                                type="button"
                                class="flex flex-col items-start rounded-lg border p-3 text-left transition hover:border-indigo-300 hover:bg-indigo-50"
                                :class="billing?.plan_label === 'Starter' ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200 bg-white'"
                                @click="subscribe('starter')"
                            >
                                <div class="text-sm font-semibold text-gray-900">Starter — $99/mo</div>
                                <div class="mt-1 text-[11px] text-gray-500">1 agent · 2,500 credits / month</div>
                                <div class="mt-2 text-[11px] font-medium text-indigo-600" v-if="billing?.plan_label !== 'Starter'">
                                    Subscribe →
                                </div>
                                <div class="mt-2 text-[11px] font-medium text-gray-400" v-else>
                                    Current plan
                                </div>
                            </button>
                            <button
                                type="button"
                                class="flex flex-col items-start rounded-lg border p-3 text-left transition hover:border-indigo-300 hover:bg-indigo-50"
                                :class="billing?.plan_label === 'Operator' ? 'border-indigo-400 bg-indigo-50' : 'border-gray-200 bg-white'"
                                @click="subscribe('operator')"
                            >
                                <div class="text-sm font-semibold text-gray-900">Operator — $399/mo</div>
                                <div class="mt-1 text-[11px] text-gray-500">5 agents · 25,000 credits / month</div>
                                <div class="mt-2 text-[11px] font-medium text-indigo-600" v-if="billing?.plan_label !== 'Operator'">
                                    Subscribe →
                                </div>
                                <div class="mt-2 text-[11px] font-medium text-gray-400" v-else>
                                    Current plan
                                </div>
                            </button>
                        </div>
                    </div>

                    <!-- Custom plan: credits are negotiated, no monthly cap to display. -->
                    <div v-if="billing?.is_custom" class="rounded-xl bg-white p-5 shadow ring-1 ring-black/5 sm:col-span-2">
                        <div class="text-xs uppercase tracking-wide text-gray-400">Credits</div>
                        <div class="mt-2 flex items-baseline gap-2">
                            <span class="text-2xl font-semibold tabular-nums text-gray-900">{{ fmtNum(billing.credits_remaining) }}</span>
                            <span class="text-sm text-gray-500">credits remaining</span>
                        </div>
                        <p class="mt-3 text-xs text-gray-500">
                            Custom plans run on a negotiated credit budget — there's no fixed monthly cap. Contact your account
                            owner for adjustments.
                        </p>
                    </div>

                    <!-- Starter / Operator: normal usage bar + top-up button. -->
                    <div v-else class="rounded-xl bg-white p-5 shadow ring-1 ring-black/5 sm:col-span-2">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide text-gray-400">Credit usage this period</div>
                            <div class="text-xs tabular-nums text-gray-500">{{ usedPercent }}%</div>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full bg-indigo-500 transition-all" :style="{ width: usedPercent + '%' }" />
                        </div>
                        <div class="mt-2 flex items-baseline justify-between text-sm">
                            <div class="text-gray-700">
                                <span class="font-semibold tabular-nums">{{ fmtNum(billing?.credits_used) }}</span>
                                <span class="text-gray-400"> / {{ fmtNum(billing?.credits_total) }} credits used</span>
                            </div>
                            <div class="text-gray-500">
                                <span class="font-medium tabular-nums">{{ fmtNum(billing?.credits_remaining) }}</span> remaining
                            </div>
                        </div>
                        <div class="mt-3 text-xs text-gray-500">
                            1 credit = 1 message (each user message and each agent reply). Credits reset on renewal — no rollover.
                        </div>
                        <div v-if="billing?.allows_topups" class="mt-4">
                            <PrimaryButton type="button" @click="openTopup">
                                + Add credits
                            </PrimaryButton>
                        </div>
                    </div>
                </div>

                <!-- Transactions -->
                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5">
                    <div class="border-b border-gray-100 px-5 py-3 text-sm font-semibold text-gray-700">
                        Recent credit activity
                    </div>
                    <table v-if="transactions.length" class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-semibold text-gray-600">Time</th>
                                <th class="px-4 py-2.5 text-left font-semibold text-gray-600">Reason</th>
                                <th class="px-4 py-2.5 text-right font-semibold text-gray-600">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="t in transactions" :key="t.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-600">{{ fmt(t.created_at) }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ reasonLabel(t.reason) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums font-medium" :class="t.amount > 0 ? 'text-green-700' : 'text-gray-700'">
                                    {{ t.amount > 0 ? '+' : '' }}{{ t.amount.toLocaleString() }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
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
                <p class="text-sm text-gray-600">
                    Pick a pack. Credits are added immediately and stack on top of your monthly allotment — no rollover concern.
                </p>
                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <button
                        v-for="pack in topup_packs"
                        :key="pack.id"
                        type="button"
                        class="rounded-xl border p-4 text-left transition"
                        :class="selectedPack === pack.id
                            ? 'border-indigo-300 bg-indigo-50 ring-2 ring-indigo-200'
                            : 'border-gray-200 hover:border-indigo-200 hover:bg-indigo-50/30'"
                        :disabled="topupForm.processing"
                        @click="buy(pack)"
                    >
                        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ pack.label }}</div>
                        <div class="mt-2 flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold text-gray-900">{{ pack.credits.toLocaleString() }}</span>
                            <span class="text-xs text-gray-500">credits</span>
                        </div>
                        <div class="mt-1 text-sm font-medium text-indigo-700">${{ pack.price_usd }}</div>
                        <div class="mt-1 text-[10px] text-gray-400 tabular-nums">
                            ${{ (pack.price_usd / pack.credits).toFixed(4) }} / credit
                        </div>
                    </button>
                </div>
                <p class="mt-4 text-xs text-gray-400">
                    Need more than this? Operator gets the cheapest per-credit rate; Custom plans have negotiated budgets.
                </p>
            </template>
            <template #footer>
                <SecondaryButton :disabled="topupForm.processing" @click="showTopup = false">Cancel</SecondaryButton>
            </template>
        </DialogModal>
    </AppLayout>
</template>
