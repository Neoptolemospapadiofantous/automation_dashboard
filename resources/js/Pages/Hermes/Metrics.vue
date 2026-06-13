<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    // null until `composer hermes-metrics` has been run (writes data/hermes_metrics.json)
    metrics: { type: Object, default: null },
});

const W = 280;
const H = 64;

// Polyline points for a KPI's series, scaled to its own min/max so the trend
// fills the chart (matches the dashboard's sparkline approach).
function pointsFor(key) {
    const vals = (props.metrics?.series ?? []).map((s) => Number(s[key]) || 0);
    if (!vals.length) return '';
    const min = Math.min(...vals);
    const max = Math.max(...vals);
    const span = max - min || 1;
    const stepX = W / Math.max(1, vals.length - 1);
    return vals
        .map((v, i) => {
            const x = i * stepX;
            const y = H - 4 - ((v - min) / span) * (H - 8);
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
}

// "good" = the KPI moved in its declared good direction over the window.
function isGood(item) {
    const s = Number(item.start);
    const n = Number(item.now);
    return item.dir === 'down' ? n <= s : n >= s;
}

const cards = computed(() =>
    (props.metrics?.headline ?? []).map((item) => ({
        ...item,
        points: pointsFor(item.key),
        good: isGood(item),
        unit: item.unit ?? '',
    })),
);

const hasRegressions = computed(() => (props.metrics?.regressions?.length ?? 0) > 0);
</script>

<template>
    <AppLayout title="Hermes KPIs">
        <Head title="Hermes KPIs" />
        <PageHeader title="Hermes — effectiveness">
            <template #title>
                <div class="flex items-center gap-3">
                    <h1 class="truncate text-xl font-semibold leading-7 text-ink">Hermes — effectiveness</h1>
                    <span class="bp-ref">HERMES/KPI</span>
                </div>
            </template>
        </PageHeader>

        <div class="mx-auto max-w-6xl space-y-6 px-4 pb-12 pt-8 sm:px-6">
            <!-- Empty state -->
            <div
                v-if="!metrics"
                class="bg-grid bg-grid-fade rounded-none border border-dashed border-border-line bg-bg p-10 text-center"
            >
                <h3 class="text-sm font-medium text-ink-dim">No metrics yet</h3>
                <p class="mt-1 font-mono text-xs text-ink-dim">Run <span class="bg-surface-hi px-1">composer hermes-metrics</span> to generate the report.</p>
            </div>

            <template v-else>
                <!-- Regression banner -->
                <div
                    class="rounded-none border p-4"
                    :class="hasRegressions ? 'border-rose-300 bg-rose-50' : 'border-border-line bg-surface'"
                >
                    <div v-if="!hasRegressions" class="flex items-center gap-2 text-sm font-medium text-emerald-700">
                        <span class="inline-block h-2 w-2 rounded-full bg-emerald-500" /> No regressions — no KPI moved against its good direction in the latest snapshot.
                    </div>
                    <div v-else>
                        <p class="text-sm font-semibold text-rose-700">⚠ Regressions detected</p>
                        <ul class="mt-1.5 space-y-1">
                            <li v-for="(r, i) in metrics.regressions" :key="i" class="font-mono text-xs text-rose-700">— {{ r }}</li>
                        </ul>
                    </div>
                </div>

                <!-- KPI cards -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="c in cards"
                        :key="c.key"
                        class="bp-node relative rounded-none p-4 shadow-sheet"
                    >
                        <p class="font-mono text-xs uppercase tracking-wider text-ink-mute">{{ c.kpi }}</p>
                        <div class="mt-1 flex items-baseline gap-2">
                            <span class="font-mono text-2xl font-semibold leading-none text-ink">{{ c.now }}{{ c.unit }}</span>
                            <span
                                class="font-mono text-xs font-semibold"
                                :class="c.good ? 'text-emerald-600' : 'text-rose-600'"
                            >{{ c.good ? '▲ good' : '▼ watch' }} · {{ c.delta }}</span>
                        </div>
                        <p class="mt-0.5 font-mono text-[10px] text-ink-mute">
                            {{ c.dir === 'down' ? 'lower is better' : 'higher is better' }} · was {{ c.start }}{{ c.unit }}
                        </p>
                        <!-- trend chart -->
                        <svg class="mt-3 w-full" :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" style="height: 56px">
                            <polyline
                                :points="c.points"
                                fill="none"
                                :stroke="c.good ? '#059669' : '#e11d48'"
                                stroke-width="1.5"
                                vector-effect="non-scaling-stroke"
                            />
                        </svg>
                    </div>
                </div>

                <!-- Defects + coverage -->
                <div class="rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                    <p class="font-mono text-xs uppercase tracking-wider text-ink-mute">Defects &amp; coverage</p>
                    <div class="mt-2 flex flex-wrap gap-6">
                        <div>
                            <span class="font-mono text-xl font-semibold text-ink">{{ metrics.escapes }}</span>
                            <span class="ml-1.5 text-xs text-ink-dim">escapes (reactive prod bugfixes)</span>
                        </div>
                        <div>
                            <span class="font-mono text-xl font-semibold text-emerald-600">{{ metrics.catches }}</span>
                            <span class="ml-1.5 text-xs text-ink-dim">catches (bugs the audit found pre-merge)</span>
                        </div>
                        <div>
                            <span
                                class="font-mono text-xl font-semibold"
                                :class="(metrics.untested?.length ?? 0) ? 'text-amber-600' : 'text-emerald-600'"
                            >{{ metrics.untested?.length ?? 0 }}</span>
                            <span class="ml-1.5 text-xs text-ink-dim">
                                untested subsystems<template v-if="metrics.untested?.length"> ({{ metrics.untested.join(', ') }})</template>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- footnote -->
                <p class="font-mono text-[11px] text-ink-dim">
                    {{ metrics.range.start }} → {{ metrics.range.end }} · git-mined · regenerate with
                    <span class="bg-surface-hi px-1">composer hermes-metrics</span>.
                    Escape rate is a firefighting proxy until releases are tagged.
                </p>
            </template>
        </div>
    </AppLayout>
</template>
