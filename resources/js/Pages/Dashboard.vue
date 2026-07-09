<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useEcho } from '@/composables/useEcho';

const props = defineProps({
    stats: { type: Object, required: true },
    funnel: { type: Array, required: true },
    rep_load: { type: Array, required: true },
    setup: { type: Object, default: () => ({ complete: true, steps: [] }) },
});

// Setup checklist copy + destinations, keyed to the backend's step keys.
// The card self-completes (each step is derived from live data) and
// disappears entirely once everything is done.
const SETUP_META = {
    knowledge: {
        label: 'Add knowledge',
        hint: 'Paste your FAQ, pricing, or a docs URL so answers are grounded.',
        route: 'knowledge.index',
    },
    behavior: {
        label: 'Publish behavior',
        hint: 'Tell the agent who you sell to and how to qualify — then Publish.',
        route: 'agents.versions.index',
    },
    chat: {
        label: 'Test it in Chat',
        hint: 'Talk to your agent the way a lead would.',
        route: 'chat.index',
    },
    install: {
        label: 'Install the widget',
        hint: 'Copy the snippet onto your website — done when the first visitor opens it.',
        route: 'install.index',
    },
    lead: {
        label: 'Capture your first lead',
        hint: 'Share contact details in a chat and watch the card appear on the board.',
        route: 'leads.index',
    },
};

const setupSteps = computed(() =>
    (props.setup.steps ?? []).map((s) => ({ ...s, ...(SETUP_META[s.key] ?? { label: s.key, hint: '', route: null }) })),
);
const setupDone = computed(() => setupSteps.value.filter((s) => s.done).length);

const page = usePage();
const teamId = computed(() => page.props.auth.user.current_team_id);

// Live: when any lead changes, reload the dashboard props (only) so the
// counters/funnel tick without a full navigation.
function refresh() {
    router.reload({ only: ['stats', 'funnel', 'rep_load'] });
}
const { connected } = useEcho(`team.${teamId.value}`, '.lead.saved', refresh, { presence: true });
useEcho(`team.${teamId.value}`, '.lead.deleted', refresh, { presence: true });

const cards = computed(() => [
    { label: 'Total leads', value: props.stats.total_leads, tone: 'gray' },
    { label: 'Qualified', value: props.stats.qualified, tone: 'violet' },
    { label: 'Assigned', value: props.stats.assigned, tone: 'blue' },
    { label: 'Won', value: props.stats.won, tone: 'green' },
    { label: 'Conversion', value: props.stats.conversion_rate + '%', tone: 'emerald' },
    { label: 'Conversations', value: props.stats.conversations, tone: 'amber' },
]);

const toneClass = (tone) => ({
    gray: 'text-ink',
    violet: 'text-ink',
    blue: 'text-blue-600',
    green: 'text-green-600',
    emerald: 'text-emerald-600',
    amber: 'text-amber-600',
}[tone] || 'text-ink');

const colorBar = (color) => ({
    sky: 'bg-sky-400', amber: 'bg-amber-400', violet: 'bg-violet',
    blue: 'bg-blue-400', green: 'bg-green-400', rose: 'bg-rose-400',
}[color] || 'bg-ink-mute');

const funnelMax = computed(() => Math.max(1, ...props.funnel.map((f) => f.count)));

// Where you actually go from here — grounds the page below the numbers.
const shortcuts = [
    { ref: 'GO/CHAT', label: 'Test your agent', hint: 'Talk to it the way a lead would.', route: 'chat.index' },
    { ref: 'GO/KB', label: 'Add knowledge', hint: 'Ground its answers in your own docs.', route: 'knowledge.index' },
    { ref: 'GO/INSTALL', label: 'Install the widget', hint: 'Put the agent on your website.', route: 'install.index' },
    { ref: 'GO/INBOX', label: 'Read conversations', hint: 'Every transcript, newest first.', route: 'conversations.index' },
];
</script>

<template>
    <AppLayout title="Dashboard">
        <PageHeader title="Dashboard" description="Pipeline at a glance, live across all connected screens.">
            <template #actions>
                <span
                    class="inline-flex items-center gap-1.5 rounded-none bg-surface-hi px-2.5 py-1 font-mono text-xs font-medium text-ink-dim"
                    :title="connected ? 'Live' : 'Offline — start the Reverb server for live updates'"
                >
                    <span class="inline-block h-1.5 w-1.5 rounded-full" :class="connected ? 'bg-green-500 pulse-glow text-green-500' : 'bg-ink-mute'" />
                    {{ connected ? 'Live' : 'Offline' }}
                </span>
            </template>
        </PageHeader>

        <div class="relative py-8">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-grid bg-grid-fade" aria-hidden="true" />
            <div class="relative mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Setup checklist — self-completing, hidden once done -->
                <div v-if="!setup.complete" class="rounded-none border border-border-line bg-bg-elev p-5 shadow-sheet">
                    <div class="mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2.5">
                            <span class="bp-ref">DASH/SETUP</span>
                            <h2 class="text-sm font-semibold text-ink">Finish setting up your agent</h2>
                        </div>
                        <span class="font-mono text-xs text-ink-dim">{{ setupDone }}/{{ setupSteps.length }} done</span>
                    </div>
                    <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <li
                            v-for="s in setupSteps"
                            :key="s.key"
                            class="flex items-start gap-2.5 rounded-none border border-border-line bg-bg px-3 py-2.5"
                            :class="s.done ? 'opacity-60' : ''"
                        >
                            <span
                                class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold"
                                :class="s.done ? 'bg-emerald-500 text-white' : 'border-2 border-border-hi text-transparent'"
                            >✓</span>
                            <div class="min-w-0">
                                <component
                                    :is="s.route && !s.done ? 'a' : 'span'"
                                    :href="s.route && !s.done ? route(s.route) : undefined"
                                    class="text-xs font-medium"
                                    :class="s.route && !s.done ? 'text-ink underline hover:no-underline' : 'text-ink-dim'"
                                >
                                    {{ s.label }}
                                </component>
                                <p class="bp-annot mt-0.5 leading-snug">{{ s.hint }}</p>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Stat cards -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <div
                        v-for="(c, i) in cards"
                        :key="c.label"
                        class="bp-node relative rounded-none p-4 shadow-sheet transition-colors hover:border-ink"
                        :class="c.tone === 'violet' ? 'border-violet' : ''"
                    >
                        <span class="bp-ref absolute bottom-2 right-2">DASH/{{ String(i + 1).padStart(2, '0') }}</span>
                        <p class="font-mono text-xs uppercase tracking-wider text-ink-mute">{{ c.label }}</p>
                        <p class="mt-2 pr-10 font-mono text-3xl font-semibold leading-none" :class="c.tone === 'violet' ? 'text-violet' : toneClass(c.tone)">{{ c.value }}</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Funnel -->
                    <div class="rounded-none border border-border-line bg-bg p-5 shadow-sheet">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-ink-dim">Pipeline funnel</h3>
                            <span class="bp-ref">DASH/FUNNEL</span>
                        </div>
                        <div class="space-y-2">
                            <div v-for="f in funnel" :key="f.value" class="flex items-center gap-3">
                                <span class="w-20 text-xs text-ink-dim">{{ f.label }}</span>
                                <div class="h-5 flex-1 overflow-hidden rounded-none bg-surface-hi">
                                    <div
                                        class="h-full rounded-none"
                                        :class="colorBar(f.color)"
                                        :style="{ width: Math.round((f.count / funnelMax) * 100) + '%' }"
                                    />
                                </div>
                                <span class="w-8 text-right font-mono text-sm font-medium text-ink-dim">{{ f.count }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Rep load -->
                    <div class="rounded-none border border-border-line bg-bg p-5 shadow-sheet">
                        <div class="mb-4 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-ink-dim">Open leads per rep</h3>
                            <span class="bp-ref">DASH/LOAD</span>
                        </div>
                        <ul v-if="rep_load.length" class="space-y-2">
                            <li v-for="(r, i) in rep_load" :key="i" class="flex items-center justify-between text-sm">
                                <span class="text-ink-dim">{{ r.name }}</span>
                                <span class="rounded-none bg-surface-hi px-2 py-0.5 font-mono text-xs font-medium text-ink">
                                    {{ r.count }}
                                </span>
                            </li>
                        </ul>
                        <div v-else class="flex items-center gap-3 rounded-none border border-border-line bg-bg-elev px-3 py-2.5">
                            <span class="bp-dot" aria-hidden="true" />
                            <p class="bp-annot">No assigned leads yet — assign from the Leads board.</p>
                        </div>

                        <!-- Conversation volume — real figures, not a footnote. -->
                        <div class="mt-4 grid grid-cols-2 gap-3 border-t border-border-line pt-4">
                            <div>
                                <p class="font-mono text-xs uppercase tracking-wider text-ink-mute">Active convos</p>
                                <p class="mt-1 font-mono text-xl font-semibold leading-none text-ink">{{ stats.active_conversations }}</p>
                            </div>
                            <div>
                                <p class="font-mono text-xs uppercase tracking-wider text-ink-mute">Messages stored</p>
                                <p class="mt-1 font-mono text-xl font-semibold leading-none text-ink">{{ stats.messages }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shortcuts — where you go from here -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <Link
                        v-for="s in shortcuts"
                        :key="s.ref"
                        :href="route(s.route)"
                        class="bp-node group relative rounded-none p-4 shadow-sheet transition-colors hover:border-ink"
                    >
                        <span class="bp-ref">{{ s.ref }}</span>
                        <p class="mt-2 text-sm font-medium text-ink group-hover:underline">{{ s.label }}</p>
                        <p class="bp-annot mt-1 leading-snug">{{ s.hint }}</p>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
