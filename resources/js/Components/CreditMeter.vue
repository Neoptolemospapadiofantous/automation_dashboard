<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

/**
 * Sidebar credit-usage indicator. Reads the `billing` prop shared on every
 * Inertia request by HandleInertiaRequests. Renders nothing when there's
 * no billing context (e.g. unauthenticated pages).
 *
 * Click → /billing (placeholder route until Phase H3 wires Stripe).
 */
const page = usePage();
const billing = computed(() => page.props.billing ?? null);

const percent = computed(() => {
    if (!billing.value || !billing.value.credits_total) return 0;
    return Math.min(100, Math.round((billing.value.credits_used / billing.value.credits_total) * 100));
});

const tone = computed(() => {
    const p = percent.value;
    if (p >= 100) return 'rose';
    if (p >= 80) return 'amber';
    return 'indigo';
});

const barClass = computed(() => ({
    rose: 'bg-rose-500',
    amber: 'bg-amber-500',
    indigo: 'bg-violet',
}[tone.value]));

const textClass = computed(() => ({
    rose: 'text-rose-700',
    amber: 'text-amber-700',
    indigo: 'text-ink-dim',
}[tone.value]));
</script>

<template>
    <div v-if="billing" class="border-t border-border-line p-3">
        <Link :href="route('billing.index')" class="block rounded-none px-2 py-2 hover:bg-surface-hi">
            <div class="flex items-center justify-between text-xs">
                <span class="font-mono font-semibold uppercase tracking-wider text-ink-mute">
                    {{ billing.plan_label }} plan
                </span>
                <span class="font-mono font-medium tabular-nums" :class="textClass">
                    {{ billing.credits_remaining.toLocaleString() }} left
                </span>
            </div>
            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-none bg-surface-hi">
                <div class="h-full rounded-none transition-all" :class="barClass" :style="{ width: percent + '%' }" />
            </div>
            <div class="mt-1 font-mono text-[10px] text-ink-mute">
                {{ billing.credits_used.toLocaleString() }} / {{ billing.credits_total.toLocaleString() }} monthly used<span v-if="billing.topup_balance > 0"> · +{{ billing.topup_balance.toLocaleString() }} top-up</span>
                <span v-if="billing.max_agents < 1000"> · {{ billing.agents_count }} / {{ billing.max_agents }} agents</span>
            </div>
        </Link>
    </div>
</template>
