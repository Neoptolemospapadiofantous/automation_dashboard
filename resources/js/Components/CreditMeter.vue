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
    return 'normal';
});

// The warning bar uses the BRAND's signal yellow, not Tailwind's amber-500.
// Those are two different yellows (#F5C518 vs #f59e0b) and nobody chose to
// have both — the near-miss was Tailwind's default palette leaking in.
const barClass = computed(() => ({
    rose: 'bg-state-bad-solid',
    amber: 'bg-signal',
    normal: 'bg-ink-dim',
}[tone.value]));

const textClass = computed(() => ({
    rose: 'text-state-bad-ink',
    amber: 'text-state-warn-ink',
    normal: 'text-ink-dim',
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
                    {{ billing.credits_remaining.toLocaleString() }} available
                </span>
            </div>
            <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-none bg-surface-hi">
                <div class="h-full rounded-none transition-all" :class="barClass" :style="{ width: percent + '%' }" />
            </div>
            <!-- One fact per line. The old single dotted run-on
                 ("0 / 250 monthly used · +25,000 top-up · 1 / 1 agents")
                 read as one number soup in a 200px sidebar. -->
            <div class="mt-1 space-y-0.5 font-mono text-[10px] text-ink-dim">
                <div>{{ billing.credits_total.toLocaleString() }} monthly · {{ billing.credits_used.toLocaleString() }} used</div>
                <div v-if="billing.topup_balance > 0">+{{ billing.topup_balance.toLocaleString() }} top-up credits</div>
                <div v-if="billing.max_agents < 1000">{{ billing.agents_count }} / {{ billing.max_agents }} agents</div>
            </div>
        </Link>
    </div>
</template>
