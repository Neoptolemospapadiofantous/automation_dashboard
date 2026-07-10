<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    conversations: { type: Object, required: true },
    feedback: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    channel_options: { type: Array, default: () => [] },
    filter_lead: { type: Object, default: null },
});

// Inline list filters (the old Search page lives here now). The keyword box
// scans visitor id / lead name+email / message text server-side; the selects
// narrow by channel / status / rating.
const q = ref(props.filters.q ?? '');
const channel = ref(props.filters.channel ?? '');
const status = ref(props.filters.status ?? '');
const ratingFilter = ref(props.filters.rating ?? '');
const needsHuman = ref(!!props.filters.needs_human);

function applyFilters() {
    router.get(
        route('conversations.index'),
        {
            q: q.value || undefined,
            channel: channel.value || undefined,
            status: status.value || undefined,
            rating: ratingFilter.value || undefined,
            needs_human: needsHuman.value ? 1 : undefined,
            lead_id: props.filter_lead?.id || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// Selects apply immediately; the keyword box debounces so typing doesn't fire
// a request per keystroke.
let debounce;
watch(q, () => {
    clearTimeout(debounce);
    debounce = setTimeout(applyFilters, 350);
});
watch([channel, status, ratingFilter, needsHuman], applyFilters);

function clearFilters() {
    q.value = '';
    channel.value = '';
    status.value = '';
    ratingFilter.value = '';
    needsHuman.value = false;
}

const fmt = (d) => (d ? new Date(d).toLocaleString() : '—');

// Rating presentation: emoji + colour token, literal classes for Tailwind.
const ratings = {
    good: { emoji: '☺', label: 'Good', cls: 'bg-green-100 text-green-700' },
    ok: { emoji: '😐', label: 'OK', cls: 'bg-amber-100 text-amber-700' },
    bad: { emoji: '☹', label: 'Bad', cls: 'bg-rose-100 text-rose-700' },
};
const rating = (key) => ratings[key] ?? null;
</script>

<template>
    <AppLayout title="Conversations">
        <PageHeader title="Conversations" description="Every chat that's happened with your agents.">
            <template #actions>
                <span class="bp-ref">CONV/LOG</span>
            </template>
        </PageHeader>


        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <!-- Cross-link filter banner — landed here via a LeadCard's
                     conversation-count chip. Clear button strips ?lead_id
                     and returns to the full list. -->
                <div v-if="filter_lead" class="mb-4 flex items-center justify-between gap-3 rounded-none border border-border-hi bg-bg-elev px-4 py-3 text-sm text-ink">
                    <div>
                        Showing conversations for
                        <Link :href="route('leads.index')" class="font-semibold underline">{{ filter_lead.name }}</Link>
                        <span v-if="filter_lead.email" class="text-ink-dim"> · {{ filter_lead.email }}</span>
                    </div>
                    <Link :href="route('conversations.index')" class="text-xs font-medium text-ink underline hover:text-ink-dim">
                        Clear filter ✕
                    </Link>
                </div>

                <!-- Recent feedback: the last 5 rated conversations for this
                     agent, a quick read on how chats have been landing. -->
                <div v-if="feedback.length" class="mb-6 rounded-none border border-border-line bg-bg shadow-sheet">
                    <div class="flex items-center justify-between border-b border-border-line bg-bg-elev px-4 py-2">
                        <span class="font-mono text-xs uppercase tracking-wider text-ink-dim">Recent feedback · last 5</span>
                    </div>
                    <ul class="divide-y divide-border-line">
                        <li
                            v-for="f in feedback"
                            :key="f.id"
                            class="flex cursor-pointer items-start gap-3 px-4 py-2.5 transition-colors hover:bg-surface-hi"
                            @click="router.visit(route('conversations.show', f.id))"
                        >
                            <span
                                class="mt-0.5 shrink-0 rounded-none px-2 py-0.5 font-mono text-xs"
                                :class="rating(f.rating)?.cls"
                            >{{ rating(f.rating)?.emoji }} {{ rating(f.rating)?.label }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-ink">{{ f.name }}</p>
                                <p v-if="f.comment" class="mt-0.5 text-xs text-ink-dim">{{ f.comment }}</p>
                            </div>
                            <span class="shrink-0 font-mono text-xs text-ink-mute">{{ fmt(f.rated_at) }}</span>
                        </li>
                    </ul>
                </div>

                <!-- Inline filter bar: keyword + channel/status/rating selects.
                     Filters apply live (keyword debounced, selects on change). -->
                <div class="mb-6 flex flex-wrap items-center gap-2">
                    <TextInput
                        v-model="q"
                        type="search"
                        class="w-full flex-1 sm:w-auto sm:min-w-[16rem]"
                        placeholder="Search visitor, lead or message text…"
                    />
                    <select
                        v-model="channel"
                        class="rounded-none border-border-line bg-bg font-mono text-sm text-ink focus:border-ink focus:ring-0"
                    >
                        <option value="">All channels</option>
                        <option v-for="opt in channel_options" :key="opt" :value="opt">{{ opt }}</option>
                    </select>
                    <select
                        v-model="status"
                        class="rounded-none border-border-line bg-bg font-mono text-sm text-ink focus:border-ink focus:ring-0"
                    >
                        <option value="">All statuses</option>
                        <option value="active">active</option>
                        <option value="ended">ended</option>
                    </select>
                    <select
                        v-model="ratingFilter"
                        class="rounded-none border-border-line bg-bg font-mono text-sm text-ink focus:border-ink focus:ring-0"
                    >
                        <option value="">All ratings</option>
                        <option value="good">☺ Good</option>
                        <option value="ok">😐 OK</option>
                        <option value="bad">☹ Bad</option>
                    </select>
                    <!-- Never-miss-a-lead view: escalated + still open. -->
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-none border border-border-line bg-bg px-3 py-2 font-mono text-sm" :class="needsHuman ? 'border-violet text-ink' : 'text-ink-dim'">
                        <input v-model="needsHuman" type="checkbox" class="rounded-none border-border-hi text-ink focus:ring-ink" />
                        Needs human
                    </label>
                    <button
                        v-if="q || channel || status || ratingFilter || needsHuman"
                        type="button"
                        class="font-mono text-xs text-ink-dim underline hover:text-ink"
                        @click="clearFilters"
                    >
                        Clear ✕
                    </button>
                </div>

                <div class="overflow-x-auto rounded-none border border-border-line bg-bg shadow-sheet">
                    <table class="min-w-full divide-y divide-border-line text-sm">
                        <thead class="bg-bg-elev text-left font-mono text-xs uppercase tracking-wider text-ink-dim">
                            <tr>
                                <th class="px-4 py-3">Lead</th>
                                <th class="px-4 py-3">Channel</th>
                                <th class="px-4 py-3">Messages</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Rating</th>
                                <th class="px-4 py-3">Last activity</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-line">
                            <tr
                                v-for="c in conversations.data"
                                :key="c.id"
                                class="cursor-pointer transition-colors hover:bg-surface-hi"
                                @click="router.visit(route('conversations.show', c.id))"
                            >
                                <td class="px-4 py-3 font-medium text-ink">
                                    {{ c.lead?.name || c.visitor_id }}
                                </td>
                                <td class="px-4 py-3 text-ink-dim">{{ c.channel }}</td>
                                <td class="px-4 py-3 font-mono text-ink-dim">{{ c.message_count }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-none px-2 py-0.5 font-mono text-xs"
                                        :class="c.status === 'ended' ? 'bg-surface-hi text-ink-dim' : 'bg-green-100 text-green-700'"
                                    >{{ c.status }}</span>
                                    <span
                                        v-if="c.meta?.handoff_requested && c.status !== 'ended'"
                                        class="ml-1 rounded-none border border-violet px-2 py-0.5 font-mono text-xs text-violet"
                                    >{{ c.meta?.human_takeover ? 'live' : 'needs human' }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        v-if="rating(c.rating)"
                                        class="rounded-none px-2 py-0.5 font-mono text-xs"
                                        :class="rating(c.rating).cls"
                                    >{{ rating(c.rating).emoji }} {{ rating(c.rating).label }}</span>
                                    <span v-else class="font-mono text-xs text-ink-mute">—</span>
                                </td>
                                <td class="px-4 py-3 font-mono text-ink-dim">{{ fmt(c.last_message_at) }}</td>
                            </tr>
                            <tr v-if="!conversations.data.length">
                                <td colspan="6" class="px-4 py-14 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <svg class="h-10 w-10 text-ink-mute" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                                        </svg>
                                        <div>
                                            <p class="text-sm font-medium text-ink-dim">No conversations yet</p>
                                            <p class="mt-1 text-xs text-ink-mute">Conversations appear here as soon as someone chats with your agent.</p>
                                        </div>
                                        <Link :href="route('chat.index')" class="text-xs font-medium text-ink underline hover:text-ink-dim">
                                            Try the chat panel →
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="conversations.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
                    <Link
                        v-for="link in conversations.links"
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
        </div>
    </AppLayout>
</template>
