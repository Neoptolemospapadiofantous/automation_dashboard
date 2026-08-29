<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    // null until `composer hermes-metrics` has been run (writes data/hermes_metrics.json)
    metrics: { type: Object, default: null },
    // live per-run log, parsed from data/logs/hermes_session.log (newest first)
    runs: { type: Array, default: () => [] },
    ops: { type: Object, default: null },
});

function verdictClass(v) {
    return v === 'PASS' ? 'text-state-ok-ink' : v === 'WARN' ? 'text-state-warn-ink' : 'text-state-bad-ink';
}

const W = 280;
const H = 64;

// Polyline points for a KPI's series, scaled to its own min/max so the trend
// fills the chart (matches the dashboard's sparkline approach). Forward-only
// KPIs (untested nodes) are null before the manifest existed — skip those so
// the line plots only real points, and bail if there aren't ≥2 to draw.
function pointsFor(key) {
    const vals = (props.metrics?.series ?? [])
        .map((s) => s[key])
        .filter((v) => v !== null && v !== undefined && Number.isFinite(Number(v)))
        .map((v) => Number(v));
    if (vals.length < 2) return '';
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
        <PageHeader width="max-w-6xl" title="Hermes — effectiveness">
            <template #title>
                <div class="flex items-center gap-3">
                    <h1 class="truncate text-xl font-semibold leading-7 text-ink">Hermes — effectiveness</h1>
                    <span class="bp-ref">HERMES/KPI</span>
                </div>
            </template>
        </PageHeader>

        <div class="mx-auto max-w-6xl space-y-6 px-4 pb-12 pt-8 sm:px-6 lg:px-8">
            <!-- Empty state -->
            <div
                v-if="!metrics && !ops"
                class="bg-grid bg-grid-fade rounded-none border border-dashed border-border-line bg-bg p-10 text-center"
            >
                <h3 class="text-sm font-medium text-ink-dim">Nothing yet</h3>
                <p class="mt-1 font-mono text-xs text-ink-dim">Run <span class="bg-surface-hi px-1">composer hermes-fast</span> (run log) + <span class="bg-surface-hi px-1">composer hermes-metrics</span> (KPIs).</p>
            </div>

            <!-- Run log — per iteration (live, from the session log) -->
            <div v-if="ops" class="space-y-3">
                <h2 class="font-mono text-xs uppercase tracking-wider text-ink-mute">Run log — per iteration</h2>
                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="bp-node rounded-none p-3 shadow-sheet">
                        <p class="font-mono text-[10px] uppercase tracking-wider text-ink-mute">Last run</p>
                        <p class="mt-0.5 font-mono text-xs text-ink">{{ ops.last_ts }}</p>
                        <p class="font-mono text-[11px] font-semibold" :class="verdictClass(ops.last_overall)">{{ ops.last_overall }}<template v-if="ops.last_dur != null"> · {{ ops.last_dur }}s</template></p>
                    </div>
                    <div class="bp-node rounded-none p-3 shadow-sheet">
                        <p class="font-mono text-[10px] uppercase tracking-wider text-ink-mute">Pass streak</p>
                        <p class="mt-0.5 font-mono text-2xl font-semibold leading-none" :class="ops.streak > 0 ? 'text-state-ok-ink' : 'text-ink'">{{ ops.streak }}</p>
                    </div>
                    <div class="bp-node rounded-none p-3 shadow-sheet">
                        <p class="font-mono text-[10px] uppercase tracking-wider text-ink-mute">Pass rate</p>
                        <p class="mt-0.5 font-mono text-2xl font-semibold leading-none text-ink">{{ ops.pass_rate }}%</p>
                        <p class="font-mono text-[10px] text-ink-mute">{{ ops.total }} runs</p>
                    </div>
                    <div class="bp-node rounded-none p-3 shadow-sheet">
                        <p class="font-mono text-[10px] uppercase tracking-wider text-ink-mute">Avg duration</p>
                        <p class="mt-0.5 font-mono text-2xl font-semibold leading-none text-ink">{{ ops.avg_dur != null ? ops.avg_dur + 's' : '—' }}</p>
                    </div>
                </div>
                <div class="overflow-x-auto rounded-none border border-border-line bg-bg shadow-sheet">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="border-b border-border-line font-mono text-[10px] uppercase tracking-wider text-ink-mute">
                                <th class="px-3 py-2">time (UTC)</th>
                                <th class="px-3 py-2">verdict</th>
                                <th class="px-3 py-2">pass/fail/warn</th>
                                <th class="px-3 py-2">mode</th>
                                <th class="px-3 py-2">duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(r, i) in runs" :key="i" class="border-b border-border-line last:border-0">
                                <td class="px-3 py-1.5 font-mono text-ink-dim">{{ r.ts }}</td>
                                <td class="px-3 py-1.5 font-mono font-semibold" :class="verdictClass(r.overall)">{{ r.overall }}</td>
                                <td class="px-3 py-1.5 font-mono text-ink-dim">{{ r.pass }}/{{ r.fail }}/{{ r.warn }}</td>
                                <td class="px-3 py-1.5 font-mono text-ink-mute">{{ r.fast ? 'fast' : 'full' }}</td>
                                <td class="px-3 py-1.5 font-mono text-ink-dim">{{ r.dur != null ? r.dur + 's' : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <template v-if="metrics">
                <!-- Regression banner -->
                <div
                    class="rounded-none border p-4"
                    :class="hasRegressions ? 'border-state-bad-line bg-state-bad-surface' : 'border-border-line bg-surface'"
                >
                    <div v-if="!hasRegressions" class="flex items-center gap-2 text-sm font-medium text-state-ok-ink">
                        <span class="inline-block h-2 w-2 rounded-full bg-state-ok-solid" /> No regressions — no KPI moved against its good direction in the latest snapshot.
                    </div>
                    <div v-else>
                        <p class="text-sm font-semibold text-state-bad-ink">⚠ Regressions detected</p>
                        <ul class="mt-1.5 space-y-1">
                            <li v-for="(r, i) in metrics.regressions" :key="i" class="font-mono text-xs text-state-bad-ink">— {{ r }}</li>
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
                                :class="c.good ? 'text-state-ok-ink' : 'text-state-bad-ink'"
                            >{{ c.good ? '▲ good' : '▼ watch' }} · {{ c.delta }}</span>
                        </div>
                        <p class="mt-0.5 font-mono text-[10px] text-ink-mute">
                            {{ c.dir === 'down' ? 'lower is better' : 'higher is better' }} · was {{ c.start }}{{ c.unit }}<span v-if="c.forward_only"> · forward-only</span>
                        </p>
                        <!-- node names for the untested KPI -->
                        <p v-if="c.names && c.names.length" class="mt-1 font-mono text-[10px] text-state-warn-ink">
                            {{ c.names.join(', ') }}
                        </p>
                        <!-- trend chart, or a current-snapshot note when there's no trend yet -->
                        <svg v-if="c.points" class="mt-3 w-full" :viewBox="`0 0 ${W} ${H}`" preserveAspectRatio="none" style="height: 56px">
                            <polyline
                                :points="c.points"
                                fill="none"
                                :stroke="c.good ? '#059669' : '#e11d48'"
                                stroke-width="1.5"
                                vector-effect="non-scaling-stroke"
                            />
                        </svg>
                        <p v-else class="mt-3 font-mono text-[10px] text-ink-mute" style="height: 56px">current snapshot — trend builds over time</p>
                    </div>
                </div>

                <!-- Defects (untested nodes is now a headline KPI card above) -->
                <div class="rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                    <p class="font-mono text-xs uppercase tracking-wider text-ink-mute">Defects</p>
                    <div class="mt-2 flex flex-wrap gap-6">
                        <div>
                            <span class="font-mono text-xl font-semibold text-ink">{{ metrics.escapes }}</span>
                            <span class="ml-1.5 text-xs text-ink-dim">escapes (reactive prod bugfixes)</span>
                        </div>
                        <div>
                            <span class="font-mono text-xl font-semibold text-state-ok-ink">{{ metrics.catches }}</span>
                            <span class="ml-1.5 text-xs text-ink-dim">catches (bugs the audit found pre-merge)</span>
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
