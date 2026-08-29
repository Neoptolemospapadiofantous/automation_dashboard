<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { confirm } from '@/Composables/useConfirm';

const props = defineProps({
    configured: { type: Boolean, default: false },
    documents: { type: Array, default: () => [] },
    gaps: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    error: { type: String, default: null },
    filter: { type: Object, default: () => ({ type: null }) },
    accepted_types: { type: Array, default: () => [] },
    agent: { type: Object, default: null },
});

// --- URL document form ------------------------------------------------------
const urlForm = useForm({ url: '', name: '' });
function addUrl() {
    urlForm.post(route('knowledge.url'), {
        preserveScroll: true,
        onSuccess: () => urlForm.reset(),
    });
}

// --- File upload form -------------------------------------------------------
const fileInput = ref(null);
const fileForm = useForm({ file: null });

// File-picker accept list + help text derive from the engine's
// accepted_types prop (native: pdf/txt/md/csv; legacy adds docx/xlsx) so
// the picker never offers a format the backend will reject.
const EXT_BY_TYPE = { pdf: '.pdf', text: '.txt', md: '.md', csv: '.csv', docx: '.docx', xlsx: '.xlsx' };
const fileAccept = computed(() => {
    const exts = (props.accepted_types ?? []).map((t) => EXT_BY_TYPE[t]).filter(Boolean);
    return exts.length ? exts.join(',') : '.pdf,.txt,.md,.csv';
});
const fileHelp = computed(() => fileAccept.value.replaceAll('.', '').toUpperCase().split(',').join(', '));

function onFilePicked(event) {
    const picked = event.target.files?.[0] ?? null;
    fileForm.file = picked;
}

function uploadFile() {
    if (!fileForm.file) return;
    fileForm.post(route('knowledge.file'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            fileForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

// --- Text-paste form --------------------------------------------------------
const textForm = useForm({ name: '', text: '' });
function addText() {
    textForm.post(route('knowledge.text'), {
        preserveScroll: true,
        onSuccess: () => textForm.reset(),
    });
}

// --- Type filter ------------------------------------------------------------
function changeFilter(type) {
    router.get(route('knowledge.index'), { type: type || undefined }, {
        preserveScroll: true,
        preserveState: true,
    });
}

// --- Delete -----------------------------------------------------------------
async function destroy(documentID, name) {
    const ok = await confirm({
        title: 'Delete document',
        message: `Delete "${name ?? documentID}"? The agent will lose access to this content immediately.`,
        buttonText: 'Delete',
        dangerous: true,
    });
    if (!ok) return;
    router.delete(route('knowledge.destroy', documentID), { preserveScroll: true });
}

// --- Inspect (chunks) -------------------------------------------------------
const inspecting = ref(null); // { documentID, data, chunks, metadata } | null
const inspectLoading = ref(false);
const inspectError = ref(null);

async function inspect(documentID) {
    inspecting.value = { documentID, data: null, chunks: [], metadata: [] };
    inspectLoading.value = true;
    inspectError.value = null;
    try {
        const { data } = await axios.get(route('knowledge.show', documentID));
        inspecting.value = { documentID, ...data };
    } catch (e) {
        inspectError.value = 'Could not load that document.';
    } finally {
        inspectLoading.value = false;
    }
}

// --- KB query (Q&A panel) ---------------------------------------------------
const question = ref('');
const answer = ref(null);
const sourceChunks = ref([]);
const querying = ref(false);

async function ask() {
    if (!question.value.trim()) return;
    querying.value = true;
    answer.value = null;
    sourceChunks.value = [];
    try {
        const { data } = await axios.post(route('knowledge.query'), { question: question.value });
        answer.value = data.answer ?? 'No answer returned.';
        sourceChunks.value = data.chunks ?? [];
    } catch (e) {
        answer.value = 'Query failed. Check that the knowledge base has documents.';
    } finally {
        querying.value = false;
    }
}

// --- Knowledge gaps (unanswered questions work list) -------------------------
function resolveGap(id) {
    router.delete(route('knowledge.gaps.resolve', id), { preserveScroll: true });
}

// Inline "resolve with answer": the typed answer becomes a KB document on
// the same ingest path as pasted text, and the gap clears in one motion.
const answeringGap = ref(null); // gap id with the editor open
const answerForm = useForm({ answer: '' });

function openAnswer(gap) {
    answeringGap.value = gap.id;
    answerForm.reset();
    answerForm.clearErrors();
}

function submitAnswer(gap) {
    answerForm.post(route('knowledge.gaps.answer', gap.id), {
        preserveScroll: true,
        onSuccess: () => {
            answeringGap.value = null;
            answerForm.reset();
        },
    });
}

function timeAgo(iso) {
    const mins = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.round(mins / 60);
    if (hours < 48) return `${hours}h ago`;
    return `${Math.round(hours / 24)}d ago`;
}

// --- Helpers ----------------------------------------------------------------
const statusTone = (s) => ({
    SUCCESS: 'bg-state-ok-surface text-state-ok-ink',
    PENDING: 'bg-state-warn-surface text-state-warn-ink',
    INITIALIZED: 'bg-surface-hi text-ink-dim',
    ERROR: 'bg-state-bad-surface text-state-bad-ink',
}[s] || 'bg-surface-hi text-ink-dim');

const typeBadge = (t) => ({
    url: 'bg-surface-hi text-ink',
    pdf: 'bg-surface-hi text-ink',
    docx: 'bg-surface-hi text-ink',
    text: 'bg-surface-hi text-ink',
    md: 'bg-surface-hi text-ink',
    csv: 'bg-surface-hi text-ink',
    xlsx: 'bg-surface-hi text-ink',
    table: 'bg-surface-hi text-ink',
}[t] || 'bg-surface-hi text-ink-dim');

const description = computed(() => {
    if (!props.agent) return 'Ground your agent in your own content.';
    return `Documents belong to "${props.agent.name}". Switching agents shows a different knowledge base.`;
});
</script>

<template>
    <AppLayout title="Knowledge Base">
        <PageHeader title="Knowledge Base" :description="description">
            <template #actions>
                <span class="bp-ref">KB/SOURCES</span>
                <span v-if="agent" class="inline-flex items-center gap-1.5 rounded-none bg-surface-hi px-2.5 py-1 font-mono text-xs font-medium text-ink">
                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-ink" />
                    {{ agent.name }}
                </span>
                <span class="font-mono text-xs text-ink-dim">
                    {{ total ? `${total} document${total === 1 ? '' : 's'}` : 'No documents' }}
                </span>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="!configured" class="rounded-none border border-state-warn-line bg-state-warn-surface px-4 py-3 text-sm text-state-warn-ink">
                    Your agent isn't set up yet — finish onboarding to add documents to its knowledge base.
                </div>

                <div class="grid min-w-0 gap-6 lg:grid-cols-3">
                    <!-- Documents panel (left, wider) -->
                    <div class="shadow-sheet min-w-0 rounded-none border border-border-line bg-bg p-5 lg:col-span-2">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-line pb-3">
                            <h3 class="text-sm font-semibold text-ink">Documents</h3>
                            <!-- Type filter -->
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button
                                    type="button"
                                    class="rounded-none border px-3 py-2 font-mono text-xs font-medium transition sm:py-1.5"
                                    :class="!filter.type ? 'border-ink bg-ink text-bg' : 'border-border-line text-ink-dim hover:bg-surface-hi'"
                                    @click="changeFilter(null)"
                                >
                                    All
                                </button>
                                <button
                                    v-for="t in accepted_types"
                                    :key="t"
                                    type="button"
                                    class="rounded-none border px-3 py-2 font-mono text-xs font-medium uppercase tracking-wider transition sm:py-1.5"
                                    :class="filter.type === t ? 'border-ink bg-ink text-bg' : 'border-border-line text-ink-dim hover:bg-surface-hi'"
                                    @click="changeFilter(t)"
                                >
                                    {{ t }}
                                </button>
                            </div>
                        </div>

                        <!-- Add forms (three: URL / file / text-paste) -->
                        <p class="bp-annot mt-4">// add a source — url, file, or pasted text</p>
                        <div class="mt-2 grid gap-4 sm:grid-cols-3">
                            <form class="min-w-0 space-y-2 rounded-none border border-dashed border-border-hi bg-grid p-3" @submit.prevent="addUrl">
                                <InputLabel for="url" value="Add a URL" />
                                <TextInput
                                    id="url"
                                    v-model="urlForm.url"
                                    type="url"
                                    class="block w-full"
                                    placeholder="https://yoursite.com/pricing"
                                    :disabled="!configured || urlForm.processing"
                                />
                                <InputError :message="urlForm.errors.url" />
                                <TextInput
                                    id="url-name"
                                    v-model="urlForm.name"
                                    type="text"
                                    class="block w-full"
                                    placeholder="Name (optional)"
                                    aria-label="URL display name (optional)"
                                    :disabled="!configured || urlForm.processing"
                                />
                                <PrimaryButton :disabled="urlForm.processing || !configured || !urlForm.url">
                                    {{ urlForm.processing ? 'Scraping…' : 'Add URL' }}
                                </PrimaryButton>
                            </form>

                            <form class="min-w-0 space-y-2 rounded-none border border-dashed border-border-hi bg-grid p-3" @submit.prevent="uploadFile">
                                <InputLabel for="file" value="Upload a file" />
                                <label for="file" class="flex min-w-0 cursor-pointer items-center gap-3 text-xs">
                                    <span class="shrink-0 rounded-none bg-ink px-3 py-1.5 font-mono text-xs font-medium text-bg transition hover:bg-ink-dim">Choose file</span>
                                    <span class="min-w-0 truncate text-ink-dim">{{ fileForm.file?.name || 'No file chosen' }}</span>
                                </label>
                                <input
                                    id="file"
                                    ref="fileInput"
                                    type="file"
                                    :accept="fileAccept"
                                    class="sr-only"
                                    :disabled="!configured || fileForm.processing"
                                    @change="onFilePicked"
                                />
                                <InputError :message="fileForm.errors.file" />
                                <p class="text-[11px] text-ink-dim">{{ fileHelp }} · max 10 MB.</p>
                                <PrimaryButton :disabled="fileForm.processing || !configured || !fileForm.file">
                                    {{ fileForm.processing ? 'Uploading…' : 'Upload file' }}
                                </PrimaryButton>
                            </form>

                            <form class="min-w-0 space-y-2 rounded-none border border-dashed border-border-hi bg-grid p-3" @submit.prevent="addText">
                                <InputLabel for="text-name" value="Paste text" />
                                <TextInput
                                    id="text-name"
                                    v-model="textForm.name"
                                    type="text"
                                    class="block w-full"
                                    placeholder="Name (e.g. House rules)"
                                    :disabled="!configured || textForm.processing"
                                />
                                <InputError :message="textForm.errors.name" />
                                <textarea
                                    id="text-body"
                                    v-model="textForm.text"
                                    rows="3"
                                    class="block w-full rounded-none border-border-hi bg-bg text-xs text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                                    placeholder="Policy snippet, FAQ entry, short answer…"
                                    :disabled="!configured || textForm.processing"
                                />
                                <InputError :message="textForm.errors.text" />
                                <p class="text-[11px] text-ink-dim">Plain text · max 200k chars.</p>
                                <PrimaryButton :disabled="textForm.processing || !configured || !textForm.name || !textForm.text">
                                    {{ textForm.processing ? 'Saving…' : 'Add text' }}
                                </PrimaryButton>
                            </form>
                        </div>

                        <p v-if="error" class="mt-4 text-sm text-state-bad-ink">{{ error }}</p>

                        <!-- Document list -->
                        <ul class="mt-5 divide-y divide-border-line">
                            <li
                                v-for="d in documents"
                                :key="d.documentID"
                                class="group flex items-center gap-3 border-l-2 border-transparent py-2 pl-2 text-sm transition-colors hover:border-ink hover:bg-surface-hi"
                            >
                                <span class="rounded-none px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wider" :class="typeBadge(d.data?.type)">
                                    {{ d.data?.type || '?' }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate font-medium text-ink">
                                        {{ d.data?.name || d.data?.url || d.documentID }}
                                    </div>
                                    <div v-if="d.data?.url && d.data?.name !== d.data?.url" class="truncate text-xs text-ink-dim">
                                        {{ d.data.url }}
                                    </div>
                                </div>
                                <span class="shrink-0 rounded-none px-2 py-0.5 font-mono text-[10px] font-medium" :class="statusTone(d.status?.type)">
                                    {{ d.status?.type || '—' }}
                                </span>
                                <!-- Always visible below sm: touch devices have no hover
                                     to reveal these, so hover-only would make documents
                                     unmanageable on mobile. -->
                                <button
                                    type="button"
                                    class="py-2 text-xs text-ink-mute transition hover:text-ink sm:py-1.5 sm:opacity-0 sm:group-hover:opacity-100"
                                    @click="inspect(d.documentID)"
                                >
                                    Inspect
                                </button>
                                <button
                                    type="button"
                                    class="py-2 text-xs text-ink-mute transition hover:text-state-bad-ink sm:py-1.5 sm:opacity-0 sm:group-hover:opacity-100"
                                    @click="destroy(d.documentID, d.data?.name)"
                                >
                                    Delete
                                </button>
                            </li>
                            <li v-if="configured && !documents.length" class="py-8 text-center text-sm text-ink-mute">
                                <template v-if="filter.type">No {{ filter.type }} documents.</template>
                                <template v-else>No documents yet — add a URL or upload a file.</template>
                            </li>
                        </ul>
                    </div>

                    <!-- Right column: Ask + Inspect panels -->
                    <!-- min-w-0 on both grid children: grid items default to
                         min-width:auto, which on phones sized this column to the
                         forms' intrinsic width and pushed the page 178px wide. -->
                    <div class="min-w-0 space-y-6">
                        <!-- Ask the KB -->
                        <div class="shadow-sheet rounded-none border border-border-line bg-bg p-5">
                            <h3 class="mb-3 text-sm font-semibold text-ink">Ask the knowledge base</h3>
                            <form class="flex gap-2" @submit.prevent="ask">
                                <TextInput v-model="question" type="text" class="min-w-0 flex-1" placeholder="e.g. What is your pricing?" :disabled="!configured" />
                                <PrimaryButton :disabled="querying || !configured">{{ querying ? '…' : 'Ask' }}</PrimaryButton>
                            </form>
                            <div v-if="answer" class="mt-4">
                                <p class="rounded-none bg-bg-elev border border-border-line p-3 text-sm text-ink">{{ answer }}</p>
                                <div v-if="sourceChunks.length" class="mt-3 space-y-2">
                                    <p class="font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Sources</p>
                                    <div v-for="(c, i) in sourceChunks" :key="i" class="break-words rounded-none border border-border-line p-2 text-xs text-ink-dim">
                                        <span v-if="c.source" class="font-medium text-ink">{{ c.source }}: </span>{{ c.content }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Knowledge gaps — questions the KB couldn't answer -->
                        <div v-if="gaps.length" class="shadow-sheet rounded-none border border-border-line bg-bg p-5">
                            <div class="mb-1 flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-ink">Knowledge gaps</h3>
                                <span class="font-mono text-[10px] uppercase tracking-wider text-ink-mute">{{ gaps.length }} open</span>
                            </div>
                            <p class="mb-3 text-xs text-ink-dim">
                                Visitors asked these and the agent had no confident answer — each one escalated to a teammate.
                                Hit <span class="font-medium text-ink">Answer</span> to type the answer once: it becomes a knowledge
                                document instantly and the gap clears. Dismiss only removes the row.
                            </p>
                            <ul class="divide-y divide-border-line">
                                <li v-for="g in gaps" :key="g.id" class="group py-2.5">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 shrink-0 rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-[10px] font-semibold text-ink" :title="`asked ${g.asked_count} time${g.asked_count === 1 ? '' : 's'}`">
                                            ×{{ g.asked_count }}
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="break-words text-sm text-ink">{{ g.question }}</div>
                                            <div class="mt-0.5 font-mono text-[11px] text-ink-dim">
                                                best match {{ Math.round(g.top_score * 100) }}% · last asked {{ timeAgo(g.last_asked_at) }}
                                            </div>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-3 sm:opacity-0 sm:group-hover:opacity-100" :class="{ 'sm:opacity-100': answeringGap === g.id }">
                                            <button type="button" class="text-xs font-medium text-ink transition hover:underline" @click="answeringGap === g.id ? (answeringGap = null) : openAnswer(g)">
                                                {{ answeringGap === g.id ? 'Cancel' : 'Answer' }}
                                            </button>
                                            <button type="button" class="py-2 text-xs text-ink-mute transition sm:py-0 hover:text-ink" title="Clear without adding content" @click="resolveGap(g.id)">
                                                Dismiss
                                            </button>
                                        </div>
                                    </div>
                                    <!-- Inline answer editor: the answer becomes a knowledge
                                         document and the gap clears in one motion. -->
                                    <form v-if="answeringGap === g.id" class="mt-2 pl-8" @submit.prevent="submitAnswer(g)">
                                        <textarea
                                            v-model="answerForm.answer"
                                            rows="3"
                                            maxlength="20000"
                                            required
                                            class="block w-full rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                                            :placeholder="`Write the answer visitors should get for: ${g.question.slice(0, 80)}`"
                                        />
                                        <div v-if="answerForm.errors.answer" class="mt-1 text-xs text-state-bad-ink">{{ answerForm.errors.answer }}</div>
                                        <div class="mt-2 flex items-center justify-between">
                                            <span class="text-[11px] text-ink-dim">Saves as a knowledge document — the very next visitor gets this answer.</span>
                                            <PrimaryButton type="submit" class="!px-3 !py-1.5" :disabled="answerForm.processing" :class="{ 'opacity-50': answerForm.processing }">
                                                Save answer
                                            </PrimaryButton>
                                        </div>
                                    </form>
                                </li>
                            </ul>
                        </div>

                        <!-- Inspect panel — chunks of a single document -->
                        <div v-if="inspecting" class="rounded-none border border-border-line bg-bg p-5">
                            <div class="mb-3 flex items-center justify-between">
                                <h3 class="text-sm font-semibold text-ink">
                                    Document chunks
                                </h3>
                                <button type="button" class="text-xs text-ink-mute hover:text-ink" @click="inspecting = null">Close ×</button>
                            </div>
                            <div v-if="inspectLoading" class="text-sm text-ink-mute">Loading…</div>
                            <div v-else-if="inspectError" class="text-sm text-state-bad-ink">{{ inspectError }}</div>
                            <div v-else>
                                <div class="truncate text-xs font-medium text-ink">
                                    {{ inspecting.data?.data?.name || inspecting.documentID }}
                                </div>
                                <div class="mt-1 font-mono text-[11px] text-ink-dim">
                                    {{ (inspecting.chunks ?? []).length }} chunks ·
                                    last updated {{ inspecting.data?.updatedAt ? new Date(inspecting.data.updatedAt).toLocaleString() : '—' }}
                                </div>
                                <div class="mt-3 max-h-80 space-y-2 overflow-y-auto pr-1">
                                    <div
                                        v-for="(c, i) in (inspecting.chunks ?? [])"
                                        :key="c.chunkID || i"
                                        class="break-words rounded-none border border-border-line p-2 text-xs leading-relaxed text-ink-dim"
                                    >
                                        {{ c.content }}
                                    </div>
                                    <p v-if="!(inspecting.chunks ?? []).length" class="text-xs text-ink-dim">
                                        No chunks yet — document may still be processing.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
