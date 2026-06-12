<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
    lead: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const visible = computed(() => props.lead !== null);

// Notes — debounced auto-save on type. Local copy so reactive edits
// don't fight the parent's reactive lead object.
const notesDraft = ref('');
const notesSaving = ref(false);
const notesSavedAt = ref(null);
let saveTimer = null;

watch(() => props.lead, (lead) => {
    notesDraft.value = lead?.notes ?? '';
    notesSavedAt.value = null;
}, { immediate: true });

function scheduleSave() {
    if (!props.lead) return;
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNotes, 800);
}

async function saveNotes() {
    if (!props.lead) return;
    notesSaving.value = true;
    try {
        const { data } = await axios.patch(route('leads.notes', props.lead.id), {
            notes: notesDraft.value || null,
        });
        notesSavedAt.value = new Date();
        if (data?.notes !== undefined) {
            // sync parent's copy if it's still mounted on this lead
            props.lead.notes = data.notes;
        }
    } catch (e) {
        // Soft fail — keep the draft locally; user can retry.
    } finally {
        notesSaving.value = false;
    }
}

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

// Captured-vars renderer — shows entries as key/value rows, falls back
// to a single line for primitive values. the engine can shove anything
// into `captured`, so be defensive.
const capturedRows = computed(() => {
    const c = props.lead?.captured;
    if (!c || typeof c !== 'object') return [];
    return Object.entries(c).map(([k, v]) => ({
        key: k,
        value: typeof v === 'object' ? JSON.stringify(v) : String(v ?? ''),
    }));
});
</script>

<template>
    <Transition
        enter-active-class="transition-opacity duration-200"
        leave-active-class="transition-opacity duration-200"
        enter-from-class="opacity-0"
        leave-to-class="opacity-0"
    >
        <div v-if="visible" class="fixed inset-0 z-40 bg-black/30" @click="emit('close')" />
    </Transition>

    <Transition
        enter-active-class="transform transition duration-200 ease-out"
        leave-active-class="transform transition duration-150 ease-in"
        enter-from-class="translate-x-full"
        leave-to-class="translate-x-full"
    >
        <aside
            v-if="visible"
            class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col overflow-y-auto border-l border-border-line bg-bg"
            role="dialog"
            aria-label="Lead detail"
        >
            <!-- Header -->
            <div class="flex items-start justify-between border-b border-border-line px-5 py-4">
                <div class="min-w-0">
                    <p class="font-mono text-[10px] uppercase tracking-[0.2em] text-ink-mute">Lead</p>
                    <h2 class="mt-1 truncate text-lg font-semibold text-ink">{{ lead.name }}</h2>
                    <p v-if="lead.company" class="truncate text-sm text-ink-dim">{{ lead.company }}</p>
                </div>
                <button
                    type="button"
                    class="rounded-none p-1.5 text-ink-mute transition hover:bg-surface-hi hover:text-ink"
                    aria-label="Close"
                    @click="emit('close')"
                >
                    ✕
                </button>
            </div>

            <!-- Score + status -->
            <div class="flex items-center gap-3 border-b border-border-line px-5 py-3 text-xs">
                <span
                    class="inline-flex items-center rounded-none px-2 py-0.5 font-mono font-semibold"
                    :class="lead.score >= 70 ? 'bg-green-100 text-green-700' : lead.score >= 40 ? 'bg-amber-100 text-amber-700' : 'bg-surface-hi text-ink-dim'"
                >
                    Score {{ lead.score }}
                </span>
                <span class="rounded-none bg-ink px-2 py-0.5 font-mono font-medium text-bg">
                    {{ lead.status }}
                </span>
                <span class="ml-auto font-mono text-ink-mute">{{ lead.source }}</span>
            </div>

            <!-- Contact -->
            <section class="border-b border-border-line px-5 py-4">
                <h3 class="font-mono text-[10px] uppercase tracking-[0.2em] text-ink-mute">Contact</h3>
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div v-if="lead.email" class="flex items-center justify-between gap-2">
                        <dt class="text-xs text-ink-mute">Email</dt>
                        <dd>
                            <a :href="`mailto:${lead.email}`" class="text-ink underline hover:text-ink-dim">{{ lead.email }}</a>
                        </dd>
                    </div>
                    <div v-if="lead.phone" class="flex items-center justify-between gap-2">
                        <dt class="text-xs text-ink-mute">Phone</dt>
                        <dd>
                            <a :href="`tel:${lead.phone}`" class="text-ink underline hover:text-ink-dim">{{ lead.phone }}</a>
                        </dd>
                    </div>
                    <div v-if="lead.company" class="flex items-center justify-between gap-2">
                        <dt class="text-xs text-ink-mute">Company</dt>
                        <dd class="text-ink-dim">{{ lead.company }}</dd>
                    </div>
                    <p v-if="!lead.email && !lead.phone && !lead.company" class="text-xs italic text-ink-mute">
                        No contact info captured yet.
                    </p>
                </dl>
            </section>

            <!-- Captured variables -->
            <section v-if="capturedRows.length" class="border-b border-border-line px-5 py-4">
                <h3 class="font-mono text-[10px] uppercase tracking-[0.2em] text-ink-mute">Captured fields</h3>
                <dl class="mt-3 space-y-1.5 text-xs">
                    <div v-for="row in capturedRows" :key="row.key" class="flex items-start justify-between gap-3">
                        <dt class="shrink-0 font-mono text-ink-mute">{{ row.key }}</dt>
                        <dd class="truncate text-right text-ink-dim">{{ row.value }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Conversations cross-link + open in full page -->
            <section class="border-b border-border-line px-5 py-4">
                <h3 class="font-mono text-[10px] uppercase tracking-[0.2em] text-ink-mute">Open</h3>
                <div class="mt-2 flex flex-col gap-1.5 text-sm">
                    <Link
                        :href="route('leads.show', lead.id)"
                        class="inline-flex items-center gap-1 text-ink underline hover:text-ink-dim"
                    >
                        📄 Open full lead page →
                    </Link>
                    <Link
                        v-if="lead.conversations_count"
                        :href="route('conversations.index', { lead_id: lead.id })"
                        class="inline-flex items-center gap-1 text-ink underline hover:text-ink-dim"
                    >
                        💬 View {{ lead.conversations_count }} conversation<span v-if="lead.conversations_count > 1">s</span> →
                    </Link>
                </div>
            </section>

            <!-- Notes — debounced auto-save -->
            <section class="flex-1 px-5 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-mono text-[10px] uppercase tracking-[0.2em] text-ink-mute">Notes</h3>
                    <p class="font-mono text-[10px] text-ink-mute">
                        <span v-if="notesSaving">Saving…</span>
                        <span v-else-if="notesSavedAt">Saved {{ fmtDate(notesSavedAt) }}</span>
                        <span v-else>Edit to save</span>
                    </p>
                </div>
                <textarea
                    v-model="notesDraft"
                    rows="6"
                    class="mt-2 block w-full rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-0"
                    placeholder="Follow-up context, next action, what they care about…"
                    @input="scheduleSave"
                    @blur="saveNotes"
                />
            </section>

            <!-- Footer -->
            <div class="border-t border-border-line px-5 py-3 font-mono text-xs text-ink-mute">
                Captured {{ fmtDate(lead.created_at) }}
            </div>
        </aside>
    </Transition>
</template>
