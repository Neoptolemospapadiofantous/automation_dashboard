<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useEcho } from '@/composables/useEcho';

const props = defineProps({
    stats: { type: Object, required: true },
    funnel: { type: Array, required: true },
    rep_load: { type: Array, required: true },
    setup: { type: Object, default: () => ({ complete: true, steps: [] }) },
    series: { type: Object, default: () => ({}) },
    queue: { type: Array, default: () => [] },
    activity: { type: Array, default: () => [] },
});

const SETUP_META = {
    knowledge: { label: 'Add knowledge', hint: 'Paste your FAQ, pricing, or a docs URL so answers are grounded.', route: 'knowledge.index' },
    behavior: { label: 'Publish behavior', hint: 'Tell the agent who you sell to and how to qualify — then Publish.', route: 'agents.versions.index' },
    chat: { label: 'Test it in Chat', hint: 'Talk to your agent the way a lead would.', route: 'chat.index' },
    install: { label: 'Install the widget', hint: 'Copy the snippet onto your website — done when the first visitor opens it.', route: 'install.index' },
    lead: { label: 'Capture your first lead', hint: 'Share contact details in a chat and watch the card appear on the board.', route: 'leads.index' },
};

const setupSteps = computed(() =>
    (props.setup.steps ?? []).map((s) => ({ ...s, ...(SETUP_META[s.key] ?? { label: s.key, hint: '', route: null }) })),
);
const setupDone = computed(() => setupSteps.value.filter((s) => s.done).length);
const nextSetupStep = computed(() => setupSteps.value.find((s) => !s.done) ?? null);
// The checklist is one line (count + next step) everywhere now; tap to expand.
const setupOpen = ref(false);

const page = usePage();
const teamId = computed(() => page.props.auth.user.current_team_id);

function refresh() {
    router.reload({ only: ['stats', 'funnel', 'rep_load', 'series', 'queue', 'activity'] });
}
const { connected } = useEcho(`team.${teamId.value}`, '.lead.saved', refresh, { presence: true });
useEcho(`team.${teamId.value}`, '.lead.deleted', refresh, { presence: true });

// --- KPI tiles: value + 7-day sparkline + delta -------------------------------
// A sparkline is a polyline over a 100×28 box; the area below it is a soft
// fill so a flat week still reads as "measured", not "empty".
function sparkPath(points) {
    if (!points || points.length < 2) return null;
    const max = Math.max(1, ...points);
    const step = 100 / (points.length - 1);
    const pts = points.map((v, i) => [Math.round(i * step), Math.round(26 - (v / max) * 22 + 2)]);
    const line = pts.map(([x, y], i) => `${i ? 'L' : 'M'}${x} ${y}`).join('');
    return { line, area: `${line}L100 28L0 28Z` };
}

const cards = computed(() => {
    const s = props.series ?? {};
    const deltaChip = (d, unit = '') => (d === null || d === undefined)
        ? null
        : d > 0 ? { text: `▲ +${d}${unit} · 7d`, tone: 'ok' } : d < 0 ? { text: `▼ ${d}${unit} · 7d`, tone: 'bad' } : { text: '— · 7d', tone: 'flat' };
    return [
        { ref: 'DASH/01', label: 'Total leads', value: props.stats.total_leads, spark: sparkPath(s.total_leads?.points), chip: deltaChip(s.total_leads?.delta), accent: false },
        { ref: 'DASH/02', label: 'Qualified', value: props.stats.qualified, spark: sparkPath(s.qualified?.points), chip: deltaChip(s.qualified?.delta), accent: true },
        { ref: 'DASH/03', label: 'Assigned', value: props.stats.assigned, spark: sparkPath(s.assigned?.points), chip: deltaChip(s.assigned?.delta), accent: false },
        { ref: 'DASH/04', label: 'Won', value: props.stats.won, spark: sparkPath(s.won?.points), chip: deltaChip(s.won?.delta), accent: false, tone: 'ok' },
        { ref: 'DASH/05', label: 'Conversion', value: props.stats.conversion_rate + '%', spark: sparkPath(s.won?.points), chip: { text: 'of decided', tone: 'flat' }, accent: false },
        { ref: 'DASH/06', label: 'Conversations', value: props.stats.conversations, spark: sparkPath(s.conversations?.points), chip: { text: `${props.stats.messages} messages`, tone: 'flat' }, accent: false },
    ];
});

const chipClass = (tone) => ({
    ok: 'bg-state-ok-surface text-state-ok-ink',
    bad: 'bg-state-bad-surface text-state-bad-ink',
    flat: 'bg-surface-hi text-ink-dim',
}[tone] || 'bg-surface-hi text-ink-dim');

// --- Funnel: one stepped bar ----------------------------------------------------
// Segment width is proportional to count (min 1 so an empty stage still shows
// as a sliver); the palette is the state set the app already uses.
const funnelSegments = computed(() => {
    const fills = { new: 'bg-ink text-bg', qualified: 'bg-signal text-[#111]', assigned: 'bg-ink-dim text-bg', won: 'bg-state-ok-solid text-state-ok-on', lost: 'bg-surface-hi text-ink-dim' };
    return props.funnel.map((f) => ({ ...f, grow: Math.max(1, f.count), cls: fills[f.value] || 'bg-ink-mute text-bg' }));
});

// --- Queue + activity ------------------------------------------------------------
const kindChip = (kind) => ({
    handoff: 'bg-signal text-[#111]',
    unassigned: 'bg-surface-hi text-ink-dim',
    stale: 'bg-surface-hi text-ink-dim',
}[kind] || 'bg-surface-hi text-ink-dim');
const kindLabel = (kind) => ({ handoff: 'Handoff', unassigned: 'Unassigned', stale: 'Stale' }[kind] || kind);

function ago(iso) {
    if (!iso) return '';
    const ms = Date.now() - new Date(iso).getTime();
    const m = Math.round(ms / 60000);
    if (m < 1) return 'now';
    if (m < 60) return `${m} min`;
    const h = Math.round(m / 60);
    if (h < 24) return `${h} h`;
    const d = Math.round(h / 24);
    return d === 1 ? 'yesterday' : `${d} d`;
}

const shortcuts = [
    { label: 'Test your agent', hint: 'Talk to it like a lead.', route: 'chat.index' },
    { label: 'Add knowledge', hint: 'Ground its answers.', route: 'knowledge.index' },
    { label: 'Install the widget', hint: 'One script tag.', route: 'install.index' },
    { label: 'Read conversations', hint: 'Newest first.', route: 'conversations.index' },
];
</script>

<template>
    <AppLayout title="Dashboard">
        <PageHeader title="Dashboard" description="Pipeline at a glance, live across all connected screens.">
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 rounded-none bg-surface-hi px-2.5 py-1 font-mono text-xs font-medium text-ink-dim"
                        :title="connected ? 'Live' : 'Offline — start the Reverb server for live updates'"
                    >
                        <span class="inline-block h-1.5 w-1.5 rounded-full" :class="connected ? 'bg-state-ok-solid pulse-glow text-state-ok-ink' : 'bg-ink-mute'" />
                        {{ connected ? 'Live' : 'Offline' }}
                    </span>
                    <Link :href="route('leads.index')" class="btn-signal inline-flex h-9 items-center gap-2 rounded-none border px-3.5 font-mono text-xs font-semibold uppercase tracking-wider">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                        New lead
                    </Link>
                </div>
            </template>
        </PageHeader>

        <div class="relative">
            <div class="bg-grid bg-grid-fade pointer-events-none absolute inset-0 opacity-50" aria-hidden="true" />
            <div class="relative mx-auto max-w-7xl space-y-4 px-4 py-4 sm:space-y-5 sm:px-6 sm:py-6 lg:px-8">

                <!-- Setup: one line, expandable — never a wall of cards again. -->
                <div v-if="!setup.complete" class="rounded-none border border-border-line bg-bg-elev shadow-sheet">
                    <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left" @click="setupOpen = !setupOpen">
                        <span class="bp-ref hidden sm:inline">Setup</span>
                        <span class="inline-flex gap-[3px]" aria-hidden="true">
                            <span v-for="s in setupSteps" :key="s.key" class="h-1 w-4" :class="s.done ? 'bg-ink' : 'bg-border-hi'" />
                        </span>
                        <span class="min-w-0 flex-1 truncate font-mono text-xs text-ink-dim">
                            {{ setupDone }}/{{ setupSteps.length }} done
                            <template v-if="nextSetupStep"> · next: <span class="text-ink underline">{{ nextSetupStep.label }}</span></template>
                        </span>
                        <svg class="size-3.5 flex-shrink-0 text-ink-mute transition" :class="setupOpen ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" /></svg>
                    </button>
                    <ul v-if="setupOpen" class="grid gap-2 border-t border-border-line px-4 py-3 sm:grid-cols-2 lg:grid-cols-3">
                        <li v-for="s in setupSteps" :key="s.key" class="flex items-start gap-3 rounded-none border border-border-line bg-bg p-3">
                            <span class="mt-0.5 inline-flex h-4 w-4 flex-shrink-0 items-center justify-center rounded-full" :class="s.done ? 'bg-state-ok-solid text-state-ok-on' : 'border border-border-hi'">
                                <svg v-if="s.done" class="size-2.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                            </span>
                            <div class="min-w-0">
                                <component :is="s.route && !s.done ? Link : 'span'" :href="s.route && !s.done ? route(s.route) : undefined" class="inline-block py-1 text-xs font-medium sm:py-0" :class="s.route && !s.done ? 'text-ink underline hover:no-underline' : 'text-ink-dim'">{{ s.label }}</component>
                                <p class="bp-annot mt-0.5 leading-snug">{{ s.hint }}</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- KPI tiles: value, sparkline, delta -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                    <div
                        v-for="c in cards"
                        :key="c.ref"
                        class="bp-node relative rounded-none p-4 shadow-sheet transition-colors hover:border-ink"
                        :class="c.accent ? 'border-violet' : ''"
                    >
                        <p class="font-mono text-xs uppercase tracking-wider text-ink-mute">{{ c.label }}</p>
                        <p class="mt-2 font-mono text-3xl font-semibold leading-none" :class="c.accent ? 'text-violet' : c.tone === 'ok' ? 'text-state-ok-ink' : 'text-ink'">{{ c.value }}</p>
                        <svg v-if="c.spark" class="mt-2 h-7 w-full" viewBox="0 0 100 28" preserveAspectRatio="none" aria-hidden="true">
                            <path :d="c.spark.area" class="fill-ink/5" />
                            <path :d="c.spark.line" fill="none" stroke="currentColor" stroke-width="1.5" class="text-ink" />
                        </svg>
                        <div v-else class="mt-2 h-7" />
                        <div class="mt-1.5 flex items-center justify-between gap-2">
                            <span v-if="c.chip" class="inline-flex items-center whitespace-nowrap rounded-none px-1.5 py-0.5 font-mono text-[10px] font-semibold" :class="chipClass(c.chip.tone)">{{ c.chip.text }}</span>
                            <span class="bp-ref hidden text-[10px] min-[400px]:inline">{{ c.ref }}</span>
                        </div>
                    </div>
                </div>

                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
                    <div class="min-w-0 space-y-5">
                        <!-- Funnel: one stepped bar -->
                        <div class="rounded-none border border-border-line bg-bg p-5 shadow-sheet">
                            <div class="flex items-baseline justify-between">
                                <h3 class="text-sm font-semibold text-ink">Pipeline funnel</h3>
                                <span class="bp-ref">DASH/FUNNEL</span>
                            </div>
                            <div class="mt-4 flex h-11 gap-[3px]">
                                <div
                                    v-for="f in funnelSegments"
                                    :key="f.value"
                                    class="flex min-w-0 items-end overflow-hidden px-2 py-1.5 font-mono text-xs"
                                    :class="f.cls"
                                    :style="{ flexGrow: f.grow }"
                                    :title="`${f.label}: ${f.count}`"
                                >
                                    <span class="truncate">{{ f.label }} · {{ f.count }}</span>
                                </div>
                            </div>
                            <p class="bp-annot mt-2.5">
                                {{ stats.total_leads }} leads · {{ stats.conversion_rate }}% of decided leads won · lost shown last
                            </p>
                        </div>

                        <!-- Needs you -->
                        <div class="rounded-none border border-border-line bg-bg p-5 shadow-sheet">
                            <div class="flex items-baseline justify-between">
                                <h3 class="text-sm font-semibold text-ink">Needs you</h3>
                                <span class="bp-ref">DASH/QUEUE</span>
                            </div>
                            <ul v-if="queue.length" class="mt-1 divide-y divide-border-line">
                                <li v-for="(q, i) in queue" :key="i" class="flex items-center gap-3 py-2.5">
                                    <span class="inline-flex flex-shrink-0 rounded-none px-1.5 py-0.5 font-mono text-[11px] font-semibold" :class="kindChip(q.kind)">{{ kindLabel(q.kind) }}</span>
                                    <span class="min-w-0 flex-1 truncate text-[13px] text-ink">
                                        {{ q.title }}
                                        <span v-if="q.detail" class="text-ink-dim"> · {{ q.detail }}</span>
                                        <span v-if="q.score !== null && q.score !== undefined" class="font-mono text-state-ok-ink"> · {{ q.score }}</span>
                                    </span>
                                    <span class="bp-annot flex-shrink-0">{{ ago(q.at) }}</span>
                                    <Link :href="q.href" class="inline-flex h-8 flex-shrink-0 items-center border border-ink px-2.5 font-mono text-[11px] font-semibold uppercase tracking-wider text-ink hover:bg-surface-hi">{{ q.action }}</Link>
                                </li>
                            </ul>
                            <p v-else class="mt-3 text-sm text-ink-dim">Nothing waiting on you — every visitor answered, every lead assigned.</p>
                            <p v-if="rep_load.length" class="bp-annot mt-3 border-t border-border-line pt-3">
                                Open leads per rep: <template v-for="(r, i) in rep_load" :key="i">{{ i ? ' · ' : '' }}{{ r.name }} {{ r.count }}</template>
                            </p>
                        </div>
                    </div>

                    <!-- Right rail -->
                    <div class="min-w-0 space-y-5">
                        <div class="rounded-none border border-border-line bg-bg p-5 shadow-sheet">
                            <span class="bp-ref">GO</span>
                            <div class="mt-3 grid grid-cols-2 gap-2.5">
                                <Link v-for="s in shortcuts" :key="s.route" :href="route(s.route)" class="flex flex-col gap-1 rounded-none border border-border-line p-3 transition-colors hover:border-ink hover:bg-surface-hi">
                                    <span class="text-[13px] font-semibold text-ink">{{ s.label }}</span>
                                    <span class="bp-annot text-[11px]">{{ s.hint }}</span>
                                </Link>
                            </div>
                        </div>
                        <div class="rounded-none border border-border-line bg-bg p-5 shadow-sheet">
                            <div class="flex items-baseline justify-between">
                                <h3 class="text-sm font-semibold text-ink">Activity</h3>
                                <span class="bp-ref">DASH/LOG</span>
                            </div>
                            <ul v-if="activity.length" class="mt-1 divide-y divide-border-line">
                                <li v-for="(a, i) in activity" :key="i" class="flex items-center gap-2.5 py-2.5">
                                    <span class="bp-dot flex-shrink-0" aria-hidden="true" />
                                    <component :is="a.href ? Link : 'span'" :href="a.href || undefined" class="min-w-0 flex-1 truncate text-[13px] text-ink" :class="a.href ? 'hover:underline' : ''">{{ a.text }}</component>
                                    <span class="bp-annot flex-shrink-0">{{ ago(a.at) }}</span>
                                </li>
                            </ul>
                            <p v-else class="mt-3 text-sm text-ink-dim">No activity yet — it starts with the first conversation.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
