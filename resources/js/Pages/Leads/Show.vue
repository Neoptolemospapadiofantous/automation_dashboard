<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { confirm } from '@/Composables/useConfirm';

const props = defineProps({
    lead: { type: Object, required: true },
    statuses: { type: Array, required: true },
    members: { type: Array, required: true },
    conversations: { type: Array, required: true },
});

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

// --- Status update --------------------------------------------------------
const statusForm = useForm({ status: props.lead.status });
function updateStatus(value) {
    if (value === props.lead.status) return;
    statusForm.status = value;
    statusForm.patch(route('leads.status', props.lead.id), {
        preserveScroll: true,
        preserveState: true,
    });
}

// --- Notes (autosave) -----------------------------------------------------
const notes = ref(props.lead.notes ?? '');
const notesForm = useForm({ notes });
let notesTimer = null;
function saveNotes() {
    if (notesTimer) clearTimeout(notesTimer);
    notesTimer = setTimeout(() => {
        notesForm.notes = notes.value;
        notesForm.patch(route('leads.notes', props.lead.id), {
            preserveScroll: true,
            preserveState: true,
        });
    }, 600);
}

// --- Mark contacted ---------------------------------------------------------
const lastContactedAt = ref(props.lead.last_contacted_at ?? null);
const contactSaving = ref(false);
async function markContacted() {
    contactSaving.value = true;
    try {
        const { data } = await axios.patch(route('leads.contacted', props.lead.id));
        lastContactedAt.value = data.last_contacted_at;
    } finally {
        contactSaving.value = false;
    }
}

// --- Assign ---------------------------------------------------------------
const assignForm = useForm({ strategy: 'manual', assigned_to: props.lead.assigned_to ?? null });
function assignTo(userId) {
    assignForm.assigned_to = userId;
    assignForm.strategy = userId ? 'manual' : 'unassigned';
    assignForm.post(route('leads.assign', props.lead.id), {
        preserveScroll: true,
        preserveState: true,
    });
}

// --- Delete ---------------------------------------------------------------
async function destroy() {
    const ok = await confirm({
        title: 'Delete this lead?',
        description: 'The lead and its assignment history will be removed. Conversations stay.',
        confirmText: 'Delete lead',
        danger: true,
    });
    if (!ok) return;
    router.delete(route('leads.destroy', props.lead.id), {
        onSuccess: () => router.visit(route('leads.index')),
    });
}

const currentStatusLabel = computed(() => {
    const s = props.statuses.find((x) => x.value === props.lead.status);
    return s?.label ?? props.lead.status;
});

// Semantic score tier — kept as a status hue (not the accent).
const scoreColor = computed(() => {
    const s = props.lead.score ?? 0;
    if (s >= 70) return 'text-green-600';
    if (s >= 40) return 'text-amber-600';
    return 'text-ink';
});

// Explainable score breakdown (fit/intent/urgency), each with its own
// max. Only present when the agent scored across all three dimensions.
const scoreBreakdown = computed(() => {
    const b = props.lead.score_breakdown;
    if (!b) return null;
    return [
        { key: 'fit', label: 'Fit', value: b.fit ?? 0, max: 40 },
        { key: 'intent', label: 'Intent', value: b.intent ?? 0, max: 35 },
        { key: 'urgency', label: 'Urgency', value: b.urgency ?? 0, max: 25 },
    ];
});
</script>

<template>
    <AppLayout :title="`Lead — ${lead.name ?? '(no name)'}`">
        <PageHeader
            :breadcrumbs="[{ label: 'Leads', href: route('leads.index') }, { label: lead.name ?? '(no name)' }]"
            :title="lead.name ?? '(no name)'"
            :description="lead.company ? `at ${lead.company}` : 'No company recorded'"
        >
            <template #actions>
                <span class="bp-ref hidden sm:inline">LEAD/{{ String(lead.id).padStart(2, '0') }}</span>
                <DangerButton type="button" @click="destroy">Delete lead</DangerButton>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- Status + assign + score row -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-none border border-border-line bg-bg p-4">
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Status</div>
                        <select
                            :value="lead.status"
                            class="mt-2 w-full rounded-none border-border-line text-sm focus:border-ink focus:ring-ink"
                            @change="updateStatus($event.target.value)"
                        >
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                        <div class="mt-1 text-[11px] text-ink-dim">Currently: {{ currentStatusLabel }}</div>
                    </div>
                    <div class="rounded-none border border-border-line bg-bg p-4">
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Assigned to</div>
                        <select
                            :value="lead.assigned_to ?? ''"
                            class="mt-2 w-full rounded-none border-border-line text-sm focus:border-ink focus:ring-ink"
                            @change="assignTo($event.target.value || null)"
                        >
                            <option value="">— Unassigned —</option>
                            <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </div>
                    <div class="rounded-none border border-border-line bg-bg p-4">
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Score</div>
                        <div class="mt-2 flex items-baseline gap-2">
                            <span class="font-mono text-2xl font-semibold" :class="scoreColor">{{ lead.score ?? '—' }}</span>
                            <span class="text-xs text-ink-mute">/ 100</span>
                        </div>
                        <div v-if="scoreBreakdown" class="mt-3 space-y-1.5">
                            <div
                                v-for="d in scoreBreakdown"
                                :key="d.key"
                                class="flex items-center gap-2 text-[11px]"
                                :title="`${d.label}: ${d.value} of ${d.max}`"
                            >
                                <span class="w-12 flex-shrink-0 text-ink-dim">{{ d.label }}</span>
                                <span class="h-1.5 flex-1 rounded-none bg-surface-hi">
                                    <span
                                        class="block h-1.5 rounded-none bg-ink"
                                        :style="{ width: `${(d.value / d.max) * 100}%` }"
                                    />
                                </span>
                                <span class="w-9 text-right font-mono tabular-nums text-ink-dim">{{ d.value }}/{{ d.max }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact -->
                <div class="bp-node shadow-sheet rounded-none p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-ink-dim">Contact</h3>
                        <div class="flex items-center gap-3">
                            <span class="text-[11px] text-ink-dim">
                                {{ lastContactedAt ? `Last contacted ${fmt(lastContactedAt)}` : 'Not contacted yet' }}
                            </span>
                            <button
                                type="button"
                                class="rounded-none border border-border-hi px-2.5 py-1 text-xs font-medium text-ink transition hover:bg-surface-hi disabled:opacity-50"
                                :disabled="contactSaving"
                                @click="markContacted"
                            >
                                {{ contactSaving ? 'Saving…' : 'Mark contacted' }}
                            </button>
                            <span class="bp-ref hidden sm:inline">LEAD/CONTACT</span>
                        </div>
                    </div>
                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-ink-dim">Email</dt>
                            <dd class="mt-0.5 break-all text-ink-dim">
                                <a v-if="lead.email" :href="`mailto:${lead.email}`" class="text-violet underline hover:text-ink-dim">{{ lead.email }}</a>
                                <span v-else class="text-ink-mute">—</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-dim">Phone</dt>
                            <dd class="mt-0.5 text-ink-dim">
                                <a v-if="lead.phone" :href="`tel:${lead.phone}`" class="text-violet underline hover:text-ink-dim">{{ lead.phone }}</a>
                                <span v-else class="text-ink-mute">—</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-dim">Company</dt>
                            <dd class="mt-0.5 text-ink-dim">{{ lead.company ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-dim">Source</dt>
                            <dd class="mt-0.5 text-ink-dim">{{ lead.source ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-dim">Captured</dt>
                            <dd class="mt-0.5 text-ink-dim">{{ fmt(lead.created_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-dim">Last updated</dt>
                            <dd class="mt-0.5 text-ink-dim">{{ fmt(lead.updated_at) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Notes -->
                <div class="rounded-none border border-border-line bg-bg p-5">
                    <div class="flex items-baseline justify-between">
                        <h3 class="text-sm font-semibold text-ink-dim">Notes</h3>
                        <span class="text-[11px] text-ink-dim">
                            {{ notesForm.processing ? 'Saving…' : (notesForm.recentlySuccessful ? 'Saved' : '') }}
                        </span>
                    </div>
                    <textarea
                        v-model="notes"
                        rows="6"
                        placeholder="Anything worth remembering — last contact, follow-up date, qualification notes…"
                        class="mt-2 w-full rounded-none border-border-line text-sm focus:border-ink focus:ring-ink"
                        @input="saveNotes"
                    ></textarea>
                </div>

                <!-- Conversations -->
                <div class="rounded-none border border-border-line bg-bg p-5">
                    <h3 class="text-sm font-semibold text-ink-dim">Conversations</h3>
                    <p class="mt-1 text-xs text-ink-dim">Last 20 conversations this visitor had with the agent.</p>
                    <div v-if="conversations.length" class="mt-3 divide-y divide-border-line">
                        <Link
                            v-for="c in conversations"
                            :key="c.id"
                            :href="route('conversations.show', c.id)"
                            class="flex items-center justify-between py-2.5 text-sm hover:bg-surface-hi"
                        >
                            <div class="flex flex-col">
                                <span class="font-medium text-ink">Conversation #{{ c.id }}</span>
                                <span class="text-[11px] text-ink-dim">{{ fmt(c.started_at) }}</span>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-ink-dim">
                                <span>{{ c.message_count }} msg</span>
                                <span class="text-ink underline">View →</span>
                            </div>
                        </Link>
                    </div>
                    <p v-else class="mt-3 text-xs italic text-ink-dim">No conversations on file.</p>
                </div>

                <Link :href="route('leads.index')" class="block text-center text-xs text-ink underline hover:text-ink-dim">
                    ← Back to all leads
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
