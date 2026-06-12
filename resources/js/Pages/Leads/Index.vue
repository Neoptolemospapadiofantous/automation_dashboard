<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import LeadCard from '@/Components/LeadCard.vue';
import LeadDetailDrawer from '@/Components/LeadDetailDrawer.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { confirm } from '@/Composables/useConfirm';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useEcho } from '@/composables/useEcho';

const props = defineProps({
    leads: { type: Array, required: true },
    statuses: { type: Array, required: true },
    members: { type: Array, required: true },
    sources: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ mine: false, q: '', status: null, source: null, assignee: null, min_score: null, since_key: null }) },
});

const page = usePage();
const teamId = computed(() => page.props.auth.user.current_team_id);
const currentUserId = computed(() => page.props.auth.user.id);

// "My leads" filter toggle (server-side scope).
function toggleMine() {
    applyFilters({ mine: props.filters.mine ? undefined : 1 });
}

// Generic filter setter — merges the patch onto current URL-bound
// filters and navigates. URL params are explicitly whitelisted so
// server-derived fields (since_key, etc.) don't round-trip.
const URL_KEYS = ['mine', 'q', 'status', 'source', 'assignee', 'min_score', 'since'];
function applyFilters(patch) {
    const current = {
        mine: props.filters.mine,
        q: props.filters.q,
        status: props.filters.status,
        source: props.filters.source,
        assignee: props.filters.assignee,
        min_score: props.filters.min_score,
        since: props.filters.since_key, // server parses 'since' → exposes 'since_key'
    };
    const next = { ...current, ...patch };
    // Drop empties so the URL stays clean.
    URL_KEYS.forEach((k) => {
        if (next[k] === '' || next[k] === null || next[k] === undefined || next[k] === false) {
            delete next[k];
        }
    });
    router.get(route('leads.index'), next, { preserveScroll: true, preserveState: true });
}

const search = ref(props.filters.q ?? '');
let searchTimer = null;
function onSearchInput() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => applyFilters({ q: search.value }), 350);
}

function clearFilters() {
    router.get(route('leads.index'), {}, { preserveScroll: true, preserveState: true });
    search.value = '';
}

const activeFilterCount = computed(() => {
    let n = 0;
    if (props.filters.q) n++;
    if (props.filters.status) n++;
    if (props.filters.source) n++;
    if (props.filters.assignee) n++;
    if (props.filters.min_score) n++;
    if (props.filters.since_key) n++;
    return n;
});

// Assign a single lead (manual to a member, or a strategy like round_robin).
function assign(lead, { strategy = 'manual', assigned_to = null } = {}) {
    router.post(route('leads.assign', lead.id), { strategy, assigned_to }, {
        preserveScroll: true,
        preserveState: true,
        only: ['leads'],
    });
}

// Local reactive store of leads, keyed by id, kept in sync by broadcasts.
const leads = reactive(new Map(props.leads.map((l) => [l.id, l])));

// When the server re-sends the leads prop (e.g. after creating one in this
// same browser, which broadcasts toOthers and so won't echo back here), merge
// it in so this client stays consistent.
watch(() => props.leads, (fresh) => {
    fresh.forEach((l) => leads.set(l.id, l));
});

const columns = computed(() =>
    props.statuses.map((status) => ({
        ...status,
        leads: [...leads.values()].filter((l) => l.status === status.value),
    })),
);

// --- Live updates: patch the board in place, no reload ----------------------
const { connected } = useEcho(`team.${teamId.value}`, '.lead.saved', ({ lead }) => {
    leads.set(lead.id, lead);
}, { presence: true });

useEcho(`team.${teamId.value}`, '.lead.deleted', ({ id }) => {
    leads.delete(id);
}, { presence: true });

// --- Drag and drop status changes -------------------------------------------
function onDrop(event, status) {
    const id = Number(event.dataTransfer.getData('text/lead-id'));
    const lead = leads.get(id);
    if (!lead || lead.status === status) return;

    // Optimistic move; the server broadcast confirms to everyone else.
    lead.status = status;
    router.patch(route('leads.status', id), { status }, {
        preserveScroll: true,
        preserveState: true,
        only: [],
    });
}

async function destroy(lead) {
    const ok = await confirm({
        title: 'Delete lead',
        message: `Delete lead "${lead.name}"? This is irreversible.`,
        buttonText: 'Delete',
        dangerous: true,
    });
    if (!ok) return;
    leads.delete(lead.id);
    router.delete(route('leads.destroy', lead.id), { preserveScroll: true, preserveState: true, only: [] });
}

// --- Create form ------------------------------------------------------------
// Lead detail drawer — opens when a card is clicked (body-area only).
// The lead reference is the same reactive object from `leads` so notes
// edits propagate back without a re-fetch.
const drawerLead = ref(null);
function openDetail(lead) {
    drawerLead.value = lead;
}
function closeDetail() {
    drawerLead.value = null;
}

const showCreate = ref(false);
const form = useForm({
    name: '',
    email: '',
    phone: '',
    company: '',
    source: 'manual',
    score: 0,
    assigned_to: null,
    status: 'new',
});

function submit() {
    // Reload the leads prop on success; the watcher above merges it into the
    // board. Other connected clients get the new card via the broadcast.
    form.post(route('leads.store'), {
        preserveScroll: true,
        only: ['leads'],
        onSuccess: () => {
            form.reset();
            showCreate.value = false;
        },
    });
}
</script>

<template>
    <AppLayout title="Leads">
        <PageHeader title="Leads" description="Kanban board of every lead in this agent. Drag cards to move statuses.">
            <template #actions>
                <span
                    class="inline-flex items-center gap-1.5 rounded-none bg-surface-hi px-2.5 py-1 text-xs font-medium text-ink-dim"
                    :title="connected ? 'Live' : 'Offline — set PUSHER_* to enable live updates'"
                >
                    <span class="inline-block h-1.5 w-1.5 rounded-full" :class="connected ? 'bg-green-500 animate-pulse' : 'bg-ink-mute'" />
                    {{ connected ? 'Live' : 'Offline' }}
                </span>
                <button
                    type="button"
                    class="rounded-none border px-3 py-1.5 text-sm font-medium transition"
                    :class="filters.mine ? 'border-ink bg-ink text-bg' : 'border-border-hi bg-bg text-ink-dim hover:bg-surface-hi'"
                    @click="toggleMine"
                >
                    {{ filters.mine ? 'My leads' : 'All leads' }}
                </button>
                <PrimaryButton @click="showCreate = true">New lead</PrimaryButton>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">

                <!-- Filter bar. All filters are server-side (URL-bound) so
                     state survives reload + can be shared. The text search
                     debounces by 350ms. -->
                <div class="mb-4 flex flex-wrap items-center gap-2 rounded-none border border-border-line bg-bg p-3">
                    <div class="relative flex-1 min-w-[180px]">
                        <input
                            v-model="search"
                            type="search"
                            placeholder="Search name, email, company, phone…"
                            class="w-full rounded-none border-border-line pl-8 text-sm focus:border-ink focus:ring-ink"
                            @input="onSearchInput"
                        />
                        <svg class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 h-4 w-4 text-ink-mute" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>

                    <select
                        :value="filters.status ?? ''"
                        class="rounded-none border-border-line py-1.5 text-xs text-ink-dim"
                        @change="applyFilters({ status: $event.target.value || null })"
                    >
                        <option value="">All statuses</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>

                    <select
                        v-if="sources.length"
                        :value="filters.source ?? ''"
                        class="rounded-none border-border-line py-1.5 text-xs text-ink-dim"
                        @change="applyFilters({ source: $event.target.value || null })"
                    >
                        <option value="">All sources</option>
                        <option v-for="s in sources" :key="s" :value="s">{{ s }}</option>
                    </select>

                    <select
                        :value="filters.assignee ?? ''"
                        class="rounded-none border-border-line py-1.5 text-xs text-ink-dim"
                        @change="applyFilters({ assignee: $event.target.value ? Number($event.target.value) : null })"
                    >
                        <option value="">Anyone assigned</option>
                        <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>

                    <select
                        :value="filters.min_score ?? ''"
                        class="rounded-none border-border-line py-1.5 text-xs text-ink-dim"
                        @change="applyFilters({ min_score: $event.target.value ? Number($event.target.value) : null })"
                    >
                        <option value="">Any score</option>
                        <option :value="50">50+</option>
                        <option :value="70">70+</option>
                        <option :value="90">90+</option>
                    </select>

                    <div class="flex items-center gap-1">
                        <button
                            v-for="k in ['7d', '30d', '90d']"
                            :key="k"
                            type="button"
                            class="rounded-none border px-2.5 py-0.5 text-xs font-medium transition"
                            :class="filters.since_key === k ? 'border-ink bg-ink text-bg' : 'border-border-line text-ink-dim hover:bg-surface-hi'"
                            @click="applyFilters({ since: filters.since_key === k ? null : k })"
                        >
                            {{ k }}
                        </button>
                    </div>

                    <button
                        v-if="activeFilterCount > 0"
                        type="button"
                        class="text-xs text-ink-dim hover:text-ink underline"
                        @click="clearFilters"
                    >
                        Clear ({{ activeFilterCount }})
                    </button>

                    <div class="ml-auto text-xs text-ink-mute">
                        {{ leads.size ?? leads.length ?? 0 }} {{ (leads.size ?? leads.length ?? 0) === 1 ? 'lead' : 'leads' }}
                    </div>
                </div>

                <!-- Team-level empty state: never had a lead. Surfaced
                     above the kanban (which would otherwise just show 6
                     empty columns "Drop leads here" with no CTA). -->
                <div
                    v-if="leads.size === 0"
                    class="mb-6 rounded-none border border-dashed border-border-line bg-bg p-8 text-center"
                >
                    <svg class="mx-auto h-10 w-10 text-ink-mute" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                    <h3 class="mt-3 text-sm font-medium text-ink-dim">No leads yet</h3>
                    <p class="mt-1 text-xs text-ink-dim">
                        Leads appear here automatically when your agent captures
                        contact info. You can also create one manually.
                    </p>
                    <div class="mt-4 flex justify-center gap-2">
                        <Link
                            :href="route('chat.index')"
                            class="rounded-none border border-ink bg-ink px-3 py-1.5 text-xs font-medium text-bg hover:bg-bg hover:text-ink"
                        >
                            Try the chat panel
                        </Link>
                        <button
                            type="button"
                            class="rounded-none border border-border-line bg-bg px-3 py-1.5 text-xs font-medium text-ink-dim hover:bg-surface-hi"
                            @click="showCreate = true"
                        >
                            Create one manually
                        </button>
                    </div>
                </div>
                <div class="flex gap-4 overflow-x-auto pb-4">
                    <div
                        v-for="col in columns"
                        :key="col.value"
                        class="flex w-72 flex-shrink-0 flex-col rounded-none border border-border-line bg-surface p-3"
                        @dragover.prevent
                        @drop="onDrop($event, col.value)"
                    >
                        <div class="mb-3 flex items-center justify-between px-1">
                            <h3 class="text-sm font-semibold text-ink-dim">{{ col.label }}</h3>
                            <span class="rounded-none bg-bg px-2 py-0.5 font-mono text-xs text-ink-dim">
                                {{ col.leads.length }}
                            </span>
                        </div>

                        <div class="flex flex-1 flex-col gap-2">
                            <LeadCard
                                v-for="lead in col.leads"
                                :key="lead.id"
                                :lead="lead"
                                :members="members"
                                @delete="destroy"
                                @assign="assign"
                                @open="openDetail"
                            />
                            <p
                                v-if="!col.leads.length"
                                class="rounded-none border-2 border-dashed border-border-line p-4 text-center text-xs text-ink-mute"
                            >
                                Drop leads here
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create lead modal -->
        <DialogModal :show="showCreate" @close="showCreate = false">
            <template #title>New lead</template>
            <template #content>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <InputLabel for="name" value="Name" />
                        <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="phone" value="Phone" />
                        <TextInput id="phone" v-model="form.phone" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="company" value="Company" />
                        <TextInput id="company" v-model="form.company" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="score" value="Score (0–100)" />
                        <TextInput id="score" v-model="form.score" type="number" min="0" max="100" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="status" value="Status" />
                        <select id="status" v-model="form.status" class="mt-1 block w-full rounded-none border-border-hi focus:border-ink focus:ring-ink">
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="assigned_to" value="Assign to" />
                        <select id="assigned_to" v-model="form.assigned_to" class="mt-1 block w-full rounded-none border-border-hi focus:border-ink focus:ring-ink">
                            <option :value="null">Unassigned</option>
                            <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </div>
                </div>
            </template>
            <template #footer>
                <SecondaryButton @click="showCreate = false">Cancel</SecondaryButton>
                <PrimaryButton class="ms-3" :class="{ 'opacity-50': form.processing }" :disabled="form.processing" @click="submit">
                    Create
                </PrimaryButton>
            </template>
        </DialogModal>

        <!-- Right-side detail drawer. Opens on card click. Notes auto-save
             with 800ms debounce; conversation cross-link and captured
             fields are inline. -->
        <LeadDetailDrawer :lead="drawerLead" @close="closeDetail" />
    </AppLayout>
</template>
