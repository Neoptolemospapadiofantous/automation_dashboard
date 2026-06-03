<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useEcho } from '@/composables/useEcho';

const props = defineProps({
    stats: { type: Object, required: true },
    funnel: { type: Array, required: true },
    rep_load: { type: Array, required: true },
});

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
    gray: 'text-gray-900',
    violet: 'text-violet-600',
    blue: 'text-blue-600',
    green: 'text-green-600',
    emerald: 'text-emerald-600',
    amber: 'text-amber-600',
}[tone] || 'text-gray-900');

const colorBar = (color) => ({
    sky: 'bg-sky-400', amber: 'bg-amber-400', violet: 'bg-violet-400',
    blue: 'bg-blue-400', green: 'bg-green-400', rose: 'bg-rose-400',
}[color] || 'bg-gray-300');

const funnelMax = computed(() => Math.max(1, ...props.funnel.map((f) => f.count)));
</script>

<template>
    <AppLayout title="Dashboard">
        <PageHeader title="Dashboard" description="Pipeline at a glance, live across all connected screens.">
            <template #actions>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-600"
                    :title="connected ? 'Live' : 'Offline — set PUSHER_* to enable live updates'"
                >
                    <span class="inline-block h-1.5 w-1.5 rounded-full" :class="connected ? 'bg-green-500 animate-pulse' : 'bg-gray-300'" />
                    {{ connected ? 'Live' : 'Offline' }}
                </span>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Stat cards -->
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <div v-for="c in cards" :key="c.label" class="rounded-xl bg-white p-4 shadow">
                        <p class="text-xs uppercase tracking-wide text-gray-400">{{ c.label }}</p>
                        <p class="mt-1 text-2xl font-semibold" :class="toneClass(c.tone)">{{ c.value }}</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Funnel -->
                    <div class="rounded-xl bg-white p-5 shadow">
                        <h3 class="mb-4 text-sm font-semibold text-gray-700">Pipeline funnel</h3>
                        <div class="space-y-2">
                            <div v-for="f in funnel" :key="f.value" class="flex items-center gap-3">
                                <span class="w-20 text-xs text-gray-500">{{ f.label }}</span>
                                <div class="h-5 flex-1 overflow-hidden rounded bg-gray-100">
                                    <div
                                        class="h-full rounded"
                                        :class="colorBar(f.color)"
                                        :style="{ width: Math.round((f.count / funnelMax) * 100) + '%' }"
                                    />
                                </div>
                                <span class="w-8 text-right text-sm font-medium text-gray-700">{{ f.count }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Rep load -->
                    <div class="rounded-xl bg-white p-5 shadow">
                        <h3 class="mb-4 text-sm font-semibold text-gray-700">Open leads per rep</h3>
                        <ul v-if="rep_load.length" class="space-y-2">
                            <li v-for="(r, i) in rep_load" :key="i" class="flex items-center justify-between text-sm">
                                <span class="text-gray-700">{{ r.name }}</span>
                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">
                                    {{ r.count }}
                                </span>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-gray-400">No assigned leads yet.</p>

                        <div class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-500">
                            <span class="font-medium text-gray-700">{{ stats.active_conversations }}</span> active ·
                            <span class="font-medium text-gray-700">{{ stats.messages }}</span> messages stored
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
