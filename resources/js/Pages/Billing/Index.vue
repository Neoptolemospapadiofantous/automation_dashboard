<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';

defineProps({
    transactions: { type: Array, required: true },
});

const page = usePage();
const billing = computed(() => page.props.billing ?? null);

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
</script>

<template>
    <AppLayout title="Billing">
        <PageHeader title="Billing" description="Your plan, credit usage, and transaction history." />

        <div v-if="!billing" class="py-12 text-center text-sm text-gray-400">
            No billing context — make sure you're on a team.
        </div>
        <div v-else class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Current plan + usage -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-xl bg-white p-5 shadow ring-1 ring-black/5">
                        <div class="text-xs uppercase tracking-wide text-gray-400">Current plan</div>
                        <div class="mt-1 flex items-baseline gap-2">
                            <div class="text-2xl font-semibold text-gray-900">{{ billing?.plan_label }}</div>
                            <button
                                type="button"
                                class="cursor-not-allowed text-xs font-medium text-gray-400 line-through opacity-60"
                                disabled
                                title="Stripe Checkout wiring coming in the next release"
                            >
                                Upgrade →
                            </button>
                        </div>
                        <div class="mt-1 text-xs text-gray-500">
                            <span v-if="billing?.max_agents >= 9999">Unlimited agents</span>
                            <span v-else>{{ billing?.agents_count }} / {{ billing?.max_agents }} agents</span>
                        </div>
                    </div>

                    <div class="rounded-xl bg-white p-5 shadow ring-1 ring-black/5 sm:col-span-2">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide text-gray-400">Credit usage this period</div>
                            <div class="text-xs tabular-nums text-gray-500">{{ usedPercent }}%</div>
                        </div>
                        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100">
                            <div class="h-full bg-indigo-500 transition-all" :style="{ width: usedPercent + '%' }" />
                        </div>
                        <div class="mt-2 flex items-baseline justify-between text-sm">
                            <div class="text-gray-700">
                                <span class="font-semibold tabular-nums">{{ billing?.credits_used.toLocaleString() }}</span>
                                <span class="text-gray-400"> / {{ billing?.credits_total.toLocaleString() }} credits used</span>
                            </div>
                            <div class="text-gray-500">
                                <span class="font-medium tabular-nums">{{ billing?.credits_remaining.toLocaleString() }}</span> remaining
                            </div>
                        </div>
                        <div class="mt-3 text-xs text-gray-500">
                            1 credit = 1 message (each user message and each agent reply). Credits reset on renewal — no rollover.
                        </div>
                        <div v-if="billing?.allows_topups" class="mt-4">
                            <button
                                type="button"
                                class="cursor-not-allowed rounded-md border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-400 opacity-70"
                                disabled
                                title="Stripe Checkout wiring coming in the next release"
                            >
                                Buy top-up credits (coming soon)
                            </button>
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
    </AppLayout>
</template>
