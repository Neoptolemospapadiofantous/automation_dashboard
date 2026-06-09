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
// to a single line for primitive values. Voiceflow can shove anything
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
            class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col overflow-y-auto bg-white shadow-2xl"
            role="dialog"
            aria-label="Lead detail"
        >
            <!-- Header -->
            <div class="flex items-start justify-between border-b border-gray-100 px-5 py-4">
                <div class="min-w-0">
                    <p class="font-mono text-[10px] uppercase tracking-[0.2em] text-gray-400">Lead</p>
                    <h2 class="mt-1 truncate text-lg font-semibold text-gray-900">{{ lead.name }}</h2>
                    <p v-if="lead.company" class="truncate text-sm text-gray-500">{{ lead.company }}</p>
                </div>
                <button
                    type="button"
                    class="rounded-md p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700"
                    aria-label="Close"
                    @click="emit('close')"
                >
                    ✕
                </button>
            </div>

            <!-- Score + status -->
            <div class="flex items-center gap-3 border-b border-gray-100 px-5 py-3 text-xs">
                <span
                    class="inline-flex items-center rounded-full px-2 py-0.5 font-semibold"
                    :class="lead.score >= 70 ? 'bg-green-100 text-green-700' : lead.score >= 40 ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600'"
                >
                    Score {{ lead.score }}
                </span>
                <span class="rounded-full bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700">
                    {{ lead.status }}
                </span>
                <span class="ml-auto text-gray-400">{{ lead.source }}</span>
            </div>

            <!-- Contact -->
            <section class="border-b border-gray-100 px-5 py-4">
                <h3 class="font-mono text-[10px] uppercase tracking-[0.2em] text-gray-400">Contact</h3>
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div v-if="lead.email" class="flex items-center justify-between gap-2">
                        <dt class="text-xs text-gray-400">Email</dt>
                        <dd>
                            <a :href="`mailto:${lead.email}`" class="text-indigo-600 hover:underline">{{ lead.email }}</a>
                        </dd>
                    </div>
                    <div v-if="lead.phone" class="flex items-center justify-between gap-2">
                        <dt class="text-xs text-gray-400">Phone</dt>
                        <dd>
                            <a :href="`tel:${lead.phone}`" class="text-indigo-600 hover:underline">{{ lead.phone }}</a>
                        </dd>
                    </div>
                    <div v-if="lead.company" class="flex items-center justify-between gap-2">
                        <dt class="text-xs text-gray-400">Company</dt>
                        <dd class="text-gray-700">{{ lead.company }}</dd>
                    </div>
                    <p v-if="!lead.email && !lead.phone && !lead.company" class="text-xs italic text-gray-400">
                        No contact info captured yet.
                    </p>
                </dl>
            </section>

            <!-- Captured variables -->
            <section v-if="capturedRows.length" class="border-b border-gray-100 px-5 py-4">
                <h3 class="font-mono text-[10px] uppercase tracking-[0.2em] text-gray-400">Captured fields</h3>
                <dl class="mt-3 space-y-1.5 text-xs">
                    <div v-for="row in capturedRows" :key="row.key" class="flex items-start justify-between gap-3">
                        <dt class="shrink-0 text-gray-400">{{ row.key }}</dt>
                        <dd class="truncate text-right text-gray-700">{{ row.value }}</dd>
                    </div>
                </dl>
            </section>

            <!-- Conversations cross-link -->
            <section v-if="lead.conversations_count" class="border-b border-gray-100 px-5 py-4">
                <h3 class="font-mono text-[10px] uppercase tracking-[0.2em] text-gray-400">Conversations</h3>
                <Link
                    :href="route('conversations.index', { lead_id: lead.id })"
                    class="mt-2 inline-flex items-center gap-1 text-sm text-indigo-600 hover:underline"
                >
                    💬 View {{ lead.conversations_count }} conversation<span v-if="lead.conversations_count > 1">s</span> →
                </Link>
            </section>

            <!-- Notes — debounced auto-save -->
            <section class="flex-1 px-5 py-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-mono text-[10px] uppercase tracking-[0.2em] text-gray-400">Notes</h3>
                    <p class="text-[10px] text-gray-400">
                        <span v-if="notesSaving">Saving…</span>
                        <span v-else-if="notesSavedAt">Saved {{ fmtDate(notesSavedAt) }}</span>
                        <span v-else>Edit to save</span>
                    </p>
                </div>
                <textarea
                    v-model="notesDraft"
                    rows="6"
                    class="mt-2 block w-full rounded-md border-gray-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400"
                    placeholder="Follow-up context, next action, what they care about…"
                    @input="scheduleSave"
                    @blur="saveNotes"
                />
            </section>

            <!-- Footer -->
            <div class="border-t border-gray-100 px-5 py-3 text-xs text-gray-400">
                Captured {{ fmtDate(lead.created_at) }}
            </div>
        </aside>
    </Transition>
</template>
