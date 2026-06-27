<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    agent: { type: Object, required: true },
    window: { type: Object, required: true }, // { days, start, end }
    series: { type: Object, required: true }, // { conversations, messages, leads, credits }
    totals: { type: Object, required: true },
    funnel: { type: Array, required: true },
    sources: { type: Array, required: true },
    hourly: { type: Array, required: true },
});

const fmt = (n) => (typeof n === 'number' ? n.toLocaleString() : '0');

// --- Window selector -------------------------------------------------------
function setWindow(days) {
    router.get(route('agents.analytics', props.agent.slug), { window: days }, {
        preserveScroll: true,
        preserveState: true,
    });
}

// --- Sparkline path builder ------------------------------------------------
// Returns a polyline `points` attribute for the given array of {date, count}.
function sparklinePath(series, width = 200, height = 40) {
    if (!series?.length) return '';
    const values = series.map((s) => s.count);
    const max = Math.max(1, ...values);
    const stepX = width / Math.max(1, series.length - 1);
    return values
        .map((v, i) => {
            const x = i * stepX;
            const y = height - (v / max) * height;
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
}

const convoSparkline = computed(() => sparklinePath(props.series.conversations));
const messagesSparkline = computed(() => sparklinePath(props.series.messages));
const leadsSparkline = computed(() => sparklinePath(props.series.leads));
const creditsSparkline = computed(() => sparklinePath(props.series.credits));

// --- Funnel widths ---------------------------------------------------------
// Each row is rendered as a relative-width bar against the largest count.
const funnelMax = computed(() => Math.max(1, ...props.funnel.map((f) => f.count)));

// --- Hourly heatmap intensity ----------------------------------------------
const hourlyMax = computed(() => Math.max(1, ...props.hourly));
function hourCellOpacity(count) {
    if (count === 0) return 0.06;
    return 0.15 + (count / hourlyMax.value) * 0.85;
}

// --- Source bar widths -----------------------------------------------------
const sourcesMax = computed(() => Math.max(1, ...props.sources.map((s) => s.count)));
</script>

<template>
    <AppLayout :title="`Analytics — ${agent.name}`">
        <PageHeader
            :breadcrumbs="[
                { label: 'Agents', href: route('agents.index') },
                { label: agent.name, href: route('agents.show', agent.slug) },
                { label: 'Analytics' },
            ]"
            :title="`${agent.name} · Analytics`"
            :description="`Activity, conversion, and credit usage over the last ${window.days} days.`"
        >
            <template #actions>
                <div class="flex items-center gap-1 rounded-none border border-border-line bg-bg p-0.5">
                    <button
                        v-for="d in [7, 30, 90]"
                        :key="d"
                        type="button"
                        class="rounded-none px-3 py-1 font-mono text-xs font-medium transition"
                        :class="window.days === d ? 'bg-ink text-bg' : 'text-ink-dim hover:bg-surface-hi'"
                        @click="setWindow(d)"
                    >
                        {{ d }}d
                    </button>
                </div>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- Headline counters with sparklines -->
                <div class="flex items-center justify-between">
                    <span class="bp-ref">AGENT/METRICS</span>
                    <span class="bp-annot">last {{ window.days }} days</span>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                        <div class="flex items-baseline justify-between">
                            <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Conversations</div>
                            <div class="font-mono text-xs text-ink-mute">{{ window.days }}d</div>
                        </div>
                        <div class="mt-1 font-mono text-2xl font-semibold text-ink">{{ fmt(totals.conversations) }}</div>
                        <svg viewBox="0 0 200 40" class="mt-2 h-10 w-full">
                            <polyline :points="convoSparkline" fill="none" stroke="#6366f1" stroke-width="1.5" />
                        </svg>
                    </div>
                    <div class="rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                        <div class="flex items-baseline justify-between">
                            <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Messages</div>
                            <div class="font-mono text-xs text-ink-mute">{{ window.days }}d</div>
                        </div>
                        <div class="mt-1 font-mono text-2xl font-semibold text-ink">{{ fmt(totals.messages) }}</div>
                        <svg viewBox="0 0 200 40" class="mt-2 h-10 w-full">
                            <polyline :points="messagesSparkline" fill="none" stroke="#0ea5e9" stroke-width="1.5" />
                        </svg>
                    </div>
                    <div class="rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                        <div class="flex items-baseline justify-between">
                            <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Leads</div>
                            <div class="font-mono text-xs text-ink-mute">{{ window.days }}d</div>
                        </div>
                        <div class="mt-1 font-mono text-2xl font-semibold text-ink">{{ fmt(totals.leads) }}</div>
                        <svg viewBox="0 0 200 40" class="mt-2 h-10 w-full">
                            <polyline :points="leadsSparkline" fill="none" stroke="#10b981" stroke-width="1.5" />
                        </svg>
                    </div>
                    <div class="rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                        <div class="flex items-baseline justify-between">
                            <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Credits spent</div>
                            <div class="font-mono text-xs text-ink-mute">{{ window.days }}d</div>
                        </div>
                        <div class="mt-1 font-mono text-2xl font-semibold text-ink">{{ fmt(totals.credits_spent) }}</div>
                        <svg viewBox="0 0 200 40" class="mt-2 h-10 w-full">
                            <polyline :points="creditsSparkline" fill="none" stroke="#f59e0b" stroke-width="1.5" />
                        </svg>
                    </div>
                </div>

                <!-- Conversion rates -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-none border border-violet bg-bg p-5 shadow-sheet">
                        <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Capture rate</p>
                        <p class="mt-2 font-mono text-3xl font-semibold text-violet">{{ totals.capture_rate }}%</p>
                        <p class="mt-1 text-xs text-ink-dim">
                            of {{ fmt(totals.conversations) }} conversations produced a lead
                        </p>
                    </div>
                    <div class="rounded-none border border-border-line bg-bg p-5">
                        <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Qualify rate</p>
                        <p class="mt-2 font-mono text-3xl font-semibold text-ink">{{ totals.qualify_rate }}%</p>
                        <p class="mt-1 text-xs text-ink-dim">
                            of {{ fmt(totals.leads) }} leads that reached Qualified
                        </p>
                    </div>
                    <div class="rounded-none border border-border-line bg-bg p-5">
                        <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Win rate</p>
                        <p class="mt-2 font-mono text-3xl font-semibold text-ink">{{ totals.win_rate }}%</p>
                        <p class="mt-1 text-xs text-ink-dim">
                            of {{ fmt(totals.qualified) }} qualified leads closed
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Funnel -->
                    <div class="rounded-none border border-border-line bg-bg p-5">
                        <h3 class="text-sm font-semibold text-ink">Lead funnel</h3>
                        <p class="mt-1 text-xs text-ink-dim">Status distribution for leads created in this window.</p>
                        <div class="mt-4 space-y-2">
                            <div
                                v-for="step in funnel"
                                :key="step.status"
                                class="flex items-center gap-3 text-xs"
                            >
                                <div class="w-20 flex-shrink-0 text-ink-dim">{{ step.label }}</div>
                                <div class="flex-1 rounded-none bg-surface-hi">
                                    <div
                                        class="h-2 rounded-none bg-ink"
                                        :style="{ width: `${(step.count / funnelMax) * 100}%` }"
                                    />
                                </div>
                                <div class="w-10 text-right font-mono text-ink-dim tabular-nums">{{ fmt(step.count) }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Sources -->
                    <div class="rounded-none border border-border-line bg-bg p-5">
                        <h3 class="text-sm font-semibold text-ink">Top sources</h3>
                        <p class="mt-1 text-xs text-ink-dim">Where leads came from (top 8).</p>
                        <div v-if="sources.length" class="mt-4 space-y-2">
                            <div
                                v-for="src in sources"
                                :key="src.source"
                                class="flex items-center gap-3 text-xs"
                            >
                                <div class="w-24 flex-shrink-0 truncate text-ink-dim">{{ src.source }}</div>
                                <div class="flex-1 rounded-none bg-surface-hi">
                                    <div
                                        class="h-2 rounded-none bg-emerald-500"
                                        :style="{ width: `${(src.count / sourcesMax) * 100}%` }"
                                    />
                                </div>
                                <div class="w-10 text-right font-mono text-ink-dim tabular-nums">{{ fmt(src.count) }}</div>
                            </div>
                        </div>
                        <p v-else class="mt-4 text-xs italic text-ink-dim">No leads captured in this window.</p>
                    </div>
                </div>

                <!-- Hourly activity heatmap -->
                <div class="rounded-none border border-border-line bg-bg p-5">
                    <h3 class="text-sm font-semibold text-ink">Hour-of-day activity</h3>
                    <p class="mt-1 text-xs text-ink-dim">
                        When conversations are started, by hour (UTC). Use this to time your support hours.
                    </p>
                    <div class="mt-4 flex items-end gap-1 overflow-x-auto">
                        <div
                            v-for="(count, hour) in hourly"
                            :key="hour"
                            class="flex min-w-[14px] flex-1 flex-col items-center gap-1"
                            :title="`${hour}:00 — ${count} conversation${count === 1 ? '' : 's'}`"
                        >
                            <div
                                class="w-full rounded-none bg-ink transition"
                                :style="{ height: '32px', opacity: hourCellOpacity(count) }"
                            />
                            <div class="font-mono text-[9px] text-ink-mute">{{ hour }}</div>
                        </div>
                    </div>
                </div>

                <!-- Back link -->
                <div class="text-center">
                    <Link :href="route('agents.show', agent.slug)" class="text-xs text-ink underline hover:text-ink-dim">
                        ← Back to agent settings
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
