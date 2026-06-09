<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    events: { type: Array, required: true },
    event_types: { type: Array, required: true },
    filter: { type: Object, required: true },
    counts: { type: Object, required: true },
});

const expanded = ref(new Set());
function toggle(id) {
    if (expanded.value.has(id)) {
        expanded.value.delete(id);
    } else {
        expanded.value.add(id);
    }
    expanded.value = new Set(expanded.value);
}

function applyFilter(patch) {
    router.get(route('system.webhooks.index'), { ...props.filter, ...patch }, {
        preserveScroll: true,
        preserveState: true,
    });
}

const stateBadge = (state) => ({
    processed: 'bg-green-100 text-green-800',
    pending: 'bg-amber-100 text-amber-800',
    failed: 'bg-rose-100 text-rose-800',
}[state] ?? 'bg-gray-100 text-gray-600');

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

const visibleCount = computed(() => props.events.length);
</script>

<template>
    <AppLayout title="Webhook events">
        <PageHeader
            title="Webhook events"
            description="Inbound webhook events from Voiceflow. Shows the last 100 events for your team — use the filters to narrow down."
        >
            <template #actions>
                <span class="text-xs text-gray-500">{{ visibleCount }} shown</span>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">

                <!-- Filter bar -->
                <div class="flex flex-wrap items-center gap-2 rounded-xl bg-white p-3 shadow ring-1 ring-black/5">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-400">Filter</div>

                    <!-- State chips -->
                    <div class="flex items-center gap-1">
                        <button
                            v-for="s in ['all', 'processed', 'pending', 'failed']"
                            :key="s"
                            type="button"
                            class="rounded-full border px-2.5 py-0.5 text-xs font-medium transition"
                            :class="filter.state === s ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-500 hover:bg-gray-50'"
                            @click="applyFilter({ state: s })"
                        >
                            {{ s }}
                        </button>
                    </div>

                    <!-- Event type select -->
                    <select
                        :value="filter.type"
                        class="rounded border-gray-200 py-1 text-xs text-gray-600 focus:border-indigo-400 focus:ring-indigo-400"
                        @change="applyFilter({ type: $event.target.value })"
                    >
                        <option value="">All event types</option>
                        <option v-for="t in event_types" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>

                <!-- Events list -->
                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5">
                    <table v-if="events.length" class="min-w-full divide-y divide-gray-100 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-2 text-left font-semibold">Received</th>
                                <th class="px-4 py-2 text-left font-semibold">Event type</th>
                                <th class="px-4 py-2 text-left font-semibold">State</th>
                                <th class="px-4 py-2 text-left font-semibold">Signed</th>
                                <th class="px-4 py-2 text-left font-semibold">Voiceflow user</th>
                                <th class="px-4 py-2 text-right font-semibold"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <template v-for="e in events" :key="e.id">
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-2.5 font-mono text-[11px] text-gray-600">{{ fmt(e.received_at) }}</td>
                                    <td class="px-4 py-2.5 font-mono text-[11px] text-gray-800">{{ e.event_type }}</td>
                                    <td class="px-4 py-2.5">
                                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide" :class="stateBadge(e.state)">
                                            {{ e.state }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-xs">
                                        <span v-if="e.signature_valid" class="text-emerald-700">✓ valid</span>
                                        <span v-else class="text-rose-600">✗ invalid</span>
                                    </td>
                                    <td class="px-4 py-2.5 truncate font-mono text-[11px] text-gray-500" style="max-width: 220px;">
                                        {{ e.voiceflow_user_id ?? '—' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <button
                                            type="button"
                                            class="text-xs text-indigo-600 hover:text-indigo-800"
                                            @click="toggle(e.id)"
                                        >
                                            {{ expanded.has(e.id) ? 'Hide' : 'Inspect' }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="expanded.has(e.id)" class="bg-gray-50">
                                    <td colspan="6" class="px-4 py-3">
                                        <dl class="grid gap-3 text-xs sm:grid-cols-2">
                                            <div>
                                                <dt class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Event id</dt>
                                                <dd class="mt-0.5 font-mono text-gray-700">{{ e.event_id }}</dd>
                                            </div>
                                            <div>
                                                <dt class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Agent id</dt>
                                                <dd class="mt-0.5 font-mono text-gray-700">{{ e.agent_id ?? '(org-scoped)' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Processed</dt>
                                                <dd class="mt-0.5 text-gray-700">{{ fmt(e.processed_at) }}</dd>
                                            </div>
                                            <div>
                                                <dt class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Transcript</dt>
                                                <dd class="mt-0.5 font-mono text-gray-700">{{ e.voiceflow_transcript_id ?? '—' }}</dd>
                                            </div>
                                        </dl>
                                        <div v-if="e.processing_error" class="mt-3 rounded border border-rose-200 bg-rose-50 p-2 text-xs text-rose-800">
                                            <p class="font-mono text-[10px] uppercase tracking-[0.18em]">Processing error</p>
                                            <p class="mt-1 break-all">{{ e.processing_error }}</p>
                                        </div>
                                        <div class="mt-3">
                                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Payload (truncated)</p>
                                            <pre class="mt-1 overflow-x-auto rounded border border-gray-100 bg-white p-2 text-[11px] text-gray-700">{{ e.payload_preview }}</pre>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>

                    <div v-else class="px-4 py-14 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <div>
                                <p class="text-sm font-medium text-gray-600">No webhook events yet</p>
                                <p class="mt-1 text-xs text-gray-400">
                                    Events appear here as Voiceflow fires them at
                                    <code class="rounded bg-gray-100 px-1 py-0.5 text-[11px]">/api/voiceflow/webhooks/*</code>.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
