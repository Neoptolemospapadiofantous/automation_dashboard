<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    runs: { type: Object, required: true },
    filters: { type: Object, default: () => ({ status: '', action: '' }) },
    statuses: { type: Array, default: () => [] },
    actionOptions: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({ total: 0, success: 0, success_rate: null, credits_charged: 0 }) },
});

const isAdmin = computed(() => usePage().props.isAdmin === true);

const status = ref(props.filters.status ?? '');
const action = ref(props.filters.action ?? '');

const applyFilters = () => {
    router.get(
        route('agents.activity.index'),
        { status: status.value || undefined, action: action.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clear = () => {
    status.value = '';
    action.value = '';
    applyFilters();
};

const expanded = ref(null);
const toggle = (id) => (expanded.value = expanded.value === id ? null : id);

const statusTone = (s) =>
    ({
        success: 'bg-emerald-50 text-emerald-700',
        pending: 'bg-amber-50 text-amber-700',
        failed: 'bg-rose-50 text-rose-700',
        blocked: 'bg-rose-50 text-rose-700',
        timeout: 'bg-amber-50 text-amber-700',
        out_of_credits: 'bg-rose-50 text-rose-700',
    })[s] ?? 'bg-surface-hi text-ink-dim';

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—');
const dur = (ms) => (ms == null ? '—' : ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`);
const pretty = (v) => (v == null ? '' : JSON.stringify(v, null, 2));
</script>

<template>
    <AppLayout title="Activity">
        <PageHeader
            title="Activity"
            description="Every automation the agent has run — status, latency, and credits charged. Read-only audit log, newest first."
        />

        <div class="mx-auto max-w-6xl space-y-6 px-4 pb-12 pt-8 sm:px-6">
            <!-- Summary -->
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-none border border-border-line bg-bg p-4">
                    <p class="font-mono text-xs uppercase tracking-wider text-ink-dim">Total runs</p>
                    <p class="mt-1 text-2xl font-semibold text-ink">{{ summary.total }}</p>
                </div>
                <div class="rounded-none border border-border-line bg-bg p-4">
                    <p class="font-mono text-xs uppercase tracking-wider text-ink-dim">Success rate</p>
                    <p class="mt-1 text-2xl font-semibold text-ink">
                        {{ summary.success_rate == null ? '—' : summary.success_rate + '%' }}
                    </p>
                </div>
                <div class="rounded-none border border-border-line bg-bg p-4">
                    <p class="font-mono text-xs uppercase tracking-wider text-ink-dim">Credits charged</p>
                    <p class="mt-1 text-2xl font-semibold text-ink">{{ summary.credits_charged }}</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-2">
                <select
                    v-model="status"
                    aria-label="Filter by status"
                    class="rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-1 focus:ring-ink"
                    @change="applyFilters"
                >
                    <option value="">All statuses</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
                </select>
                <select
                    v-model="action"
                    aria-label="Filter by action"
                    class="rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-1 focus:ring-ink"
                    @change="applyFilters"
                >
                    <option value="">All actions</option>
                    <!-- value = the action's stable identity (id, or name for
                         pre-id runs), so the filter survives renames -->
                    <option v-for="a in actionOptions" :key="a.value" :value="a.value">{{ a.label }}</option>
                </select>
                <button
                    v-if="status || action"
                    type="button"
                    class="font-mono text-xs text-ink-dim hover:text-ink"
                    @click="clear"
                >
                    Clear ✕
                </button>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto rounded-none border border-border-line bg-bg shadow-sheet">
                <table class="min-w-full divide-y divide-border-line text-sm">
                    <thead class="bg-bg-elev text-left font-mono text-xs uppercase tracking-wider text-ink-dim">
                        <tr>
                            <th class="px-4 py-3">Action</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Mode</th>
                            <th class="px-4 py-3">HTTP</th>
                            <th class="px-4 py-3">Duration</th>
                            <th class="px-4 py-3">Credits</th>
                            <th class="px-4 py-3">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-line">
                        <template v-for="r in runs.data" :key="r.id">
                            <tr class="cursor-pointer transition-colors hover:bg-surface-hi" @click="toggle(r.id)">
                                <td class="px-4 py-3 font-mono font-medium text-ink">{{ r.action }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded-none px-2 py-0.5 font-mono text-xs" :class="statusTone(r.status)">
                                        {{ r.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-ink-dim">{{ r.mode }}</td>
                                <td class="px-4 py-3 font-mono text-ink-dim">{{ r.http_status ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-ink-dim">{{ dur(r.duration_ms) }}</td>
                                <td class="px-4 py-3 font-mono text-ink-dim">{{ r.credits_charged }}</td>
                                <td class="px-4 py-3 font-mono text-ink-dim">{{ fmt(r.created_at) }}</td>
                            </tr>
                            <tr v-if="expanded === r.id" class="bg-bg-elev">
                                <td colspan="7" class="px-4 py-3">
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div v-if="r.error" class="sm:col-span-2">
                                            <p class="mb-1 font-mono text-xs uppercase tracking-wider text-rose-700">Error</p>
                                            <p class="text-xs text-rose-700">{{ r.error }}</p>
                                        </div>
                                        <div>
                                            <p class="mb-1 font-mono text-xs uppercase tracking-wider text-ink-dim">Request</p>
                                            <pre class="overflow-x-auto rounded-none bg-bg p-2 font-mono text-[11px] text-ink-dim">{{ pretty(r.request) || '—' }}</pre>
                                        </div>
                                        <div>
                                            <p class="mb-1 font-mono text-xs uppercase tracking-wider text-ink-dim">Response</p>
                                            <pre class="overflow-x-auto rounded-none bg-bg p-2 font-mono text-[11px] text-ink-dim">{{ pretty(r.response) || '—' }}</pre>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!runs.data.length">
                            <td colspan="7" class="px-4 py-14 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <svg class="h-10 w-10 text-ink-mute" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                                    </svg>
                                    <div>
                                        <p class="text-sm font-medium text-ink-dim">No automation runs yet</p>
                                        <p class="mt-1 text-xs text-ink-mute">
                                            Runs appear here once the agent calls one of its actions in a conversation.
                                        </p>
                                    </div>
                                    <Link v-if="isAdmin" :href="route('agents.actions.index')" class="text-xs font-medium text-ink underline hover:text-ink-dim">
                                        Configure actions →
                                    </Link>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="runs.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
                <Link
                    v-for="link in runs.links"
                    :key="link.label"
                    :href="link.url || ''"
                    class="rounded-none px-3 py-1 font-mono text-sm"
                    :class="[
                        link.active ? 'bg-ink text-bg' : 'bg-bg text-ink-dim hover:bg-surface-hi',
                        !link.url && 'pointer-events-none opacity-40',
                    ]"
                    v-html="link.label"
                />
                <!-- @hermes-keep: Laravel paginator labels are server-controlled HTML entities (&laquo; / &raquo;), not user input. See .hermes/suppressions.yaml -->
            </div>
        </div>
    </AppLayout>
</template>
