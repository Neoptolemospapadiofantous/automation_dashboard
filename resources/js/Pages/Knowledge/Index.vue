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

// --- Helpers ----------------------------------------------------------------
const statusTone = (s) => ({
    SUCCESS: 'bg-green-100 text-green-700',
    PENDING: 'bg-amber-100 text-amber-700',
    INITIALIZED: 'bg-surface-hi text-ink-dim',
    ERROR: 'bg-rose-100 text-rose-700',
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
                <div v-if="!configured" class="rounded-none border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    Your agent isn't set up yet — finish onboarding to add documents to its knowledge base.
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Documents panel (left, wider) -->
                    <div class="lg:col-span-2 rounded-none border border-border-line bg-bg p-5">
                        <div class="flex items-center justify-between gap-3 border-b border-border-line pb-3">
                            <h3 class="text-sm font-semibold text-ink">Documents</h3>
                            <!-- Type filter -->
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button
                                    type="button"
                                    class="rounded-none border px-2.5 py-0.5 font-mono text-xs font-medium transition"
                                    :class="!filter.type ? 'border-ink bg-ink text-bg' : 'border-border-line text-ink-dim hover:bg-surface-hi'"
                                    @click="changeFilter(null)"
                                >
                                    All
                                </button>
                                <button
                                    v-for="t in accepted_types"
                                    :key="t"
                                    type="button"
                                    class="rounded-none border px-2.5 py-0.5 font-mono text-xs font-medium uppercase tracking-wider transition"
                                    :class="filter.type === t ? 'border-ink bg-ink text-bg' : 'border-border-line text-ink-dim hover:bg-surface-hi'"
                                    @click="changeFilter(t)"
                                >
                                    {{ t }}
                                </button>
                            </div>
                        </div>

                        <!-- Add forms (three: URL / file / text-paste) -->
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <form class="space-y-2 rounded-none border border-dashed border-border-hi p-3" @submit.prevent="addUrl">
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

                            <form class="space-y-2 rounded-none border border-dashed border-border-hi p-3" @submit.prevent="uploadFile">
                                <InputLabel for="file" value="Upload a file" />
                                <input
                                    id="file"
                                    ref="fileInput"
                                    type="file"
                                    :accept="fileAccept"
                                    class="block w-full text-xs file:mr-3 file:rounded-none file:border-0 file:bg-ink file:px-3 file:py-1.5 file:font-mono file:text-xs file:font-medium file:text-bg hover:file:bg-ink-dim"
                                    :disabled="!configured || fileForm.processing"
                                    @change="onFilePicked"
                                />
                                <InputError :message="fileForm.errors.file" />
                                <p class="text-[11px] text-ink-dim">{{ fileHelp }} · max 10 MB.</p>
                                <PrimaryButton :disabled="fileForm.processing || !configured || !fileForm.file">
                                    {{ fileForm.processing ? 'Uploading…' : 'Upload file' }}
                                </PrimaryButton>
                            </form>

                            <form class="space-y-2 rounded-none border border-dashed border-border-hi p-3" @submit.prevent="addText">
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

                        <p v-if="error" class="mt-4 text-sm text-rose-600">{{ error }}</p>

                        <!-- Document list -->
                        <ul class="mt-5 divide-y divide-border-line">
                            <li
                                v-for="d in documents"
                                :key="d.documentID"
                                class="group flex items-start gap-3 py-2.5 text-sm hover:bg-surface-hi"
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
                                <button
                                    type="button"
                                    class="text-xs text-ink-mute opacity-0 transition group-hover:opacity-100 hover:text-ink"
                                    @click="inspect(d.documentID)"
                                >
                                    Inspect
                                </button>
                                <button
                                    type="button"
                                    class="text-xs text-ink-mute opacity-0 transition group-hover:opacity-100 hover:text-rose-600"
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
                    <div class="space-y-6">
                        <!-- Ask the KB -->
                        <div class="rounded-none border border-border-line bg-bg p-5">
                            <h3 class="mb-3 text-sm font-semibold text-ink">Ask the knowledge base</h3>
                            <form class="flex gap-2" @submit.prevent="ask">
                                <TextInput v-model="question" type="text" class="flex-1" placeholder="e.g. What is your pricing?" :disabled="!configured" />
                                <PrimaryButton :disabled="querying || !configured">{{ querying ? '…' : 'Ask' }}</PrimaryButton>
                            </form>
                            <div v-if="answer" class="mt-4">
                                <p class="rounded-none bg-bg-elev border border-border-line p-3 text-sm text-ink">{{ answer }}</p>
                                <div v-if="sourceChunks.length" class="mt-3 space-y-2">
                                    <p class="font-mono text-xs font-semibold uppercase tracking-wider text-ink-mute">Sources</p>
                                    <div v-for="(c, i) in sourceChunks" :key="i" class="rounded-none border border-border-line p-2 text-xs text-ink-dim">
                                        <span v-if="c.source" class="font-medium text-ink">{{ c.source }}: </span>{{ c.content }}
                                    </div>
                                </div>
                            </div>
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
                            <div v-else-if="inspectError" class="text-sm text-rose-600">{{ inspectError }}</div>
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
                                        class="rounded-none border border-border-line p-2 text-xs leading-relaxed text-ink-dim"
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
