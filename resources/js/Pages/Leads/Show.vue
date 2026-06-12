<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
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
</script>

<template>
    <AppLayout :title="`Lead — ${lead.name ?? '(no name)'}`">
        <PageHeader
            :breadcrumbs="[{ label: 'Leads', href: route('leads.index') }, { label: lead.name ?? '(no name)' }]"
            :title="lead.name ?? '(no name)'"
            :description="lead.company ? `at ${lead.company}` : 'No company recorded'"
        >
            <template #actions>
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
                        <div class="mt-1 text-[11px] text-ink-mute">Currently: {{ currentStatusLabel }}</div>
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
                            <span class="font-mono text-2xl font-semibold text-ink">{{ lead.score ?? '—' }}</span>
                            <span class="text-xs text-ink-mute">/ 100</span>
                        </div>
                    </div>
                </div>

                <!-- Contact -->
                <div class="rounded-none border border-border-line bg-bg p-5">
                    <h3 class="text-sm font-semibold text-ink-dim">Contact</h3>
                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-ink-mute">Email</dt>
                            <dd class="mt-0.5 text-ink-dim">
                                <a v-if="lead.email" :href="`mailto:${lead.email}`" class="text-ink underline hover:text-ink-dim">{{ lead.email }}</a>
                                <span v-else class="text-ink-mute">—</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-mute">Phone</dt>
                            <dd class="mt-0.5 text-ink-dim">
                                <a v-if="lead.phone" :href="`tel:${lead.phone}`" class="text-ink underline hover:text-ink-dim">{{ lead.phone }}</a>
                                <span v-else class="text-ink-mute">—</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-mute">Company</dt>
                            <dd class="mt-0.5 text-ink-dim">{{ lead.company ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-mute">Source</dt>
                            <dd class="mt-0.5 text-ink-dim">{{ lead.source ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-mute">Captured</dt>
                            <dd class="mt-0.5 text-ink-dim">{{ fmt(lead.created_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-ink-mute">Last updated</dt>
                            <dd class="mt-0.5 text-ink-dim">{{ fmt(lead.updated_at) }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Notes -->
                <div class="rounded-none border border-border-line bg-bg p-5">
                    <div class="flex items-baseline justify-between">
                        <h3 class="text-sm font-semibold text-ink-dim">Notes</h3>
                        <span class="text-[11px] text-ink-mute">
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
                                <span class="text-[11px] text-ink-mute">{{ fmt(c.started_at) }}</span>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-ink-dim">
                                <span>{{ c.message_count }} msg</span>
                                <span class="text-ink underline">View →</span>
                            </div>
                        </Link>
                    </div>
                    <p v-else class="mt-3 text-xs italic text-ink-mute">No conversations on file.</p>
                </div>

                <Link :href="route('leads.index')" class="block text-center text-xs text-ink underline hover:text-ink-dim">
                    ← Back to all leads
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
