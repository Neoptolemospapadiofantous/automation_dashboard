<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';

defineProps({
    configured: { type: Boolean, default: false },
    documents: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const urlForm = useForm({ url: '', name: '' });
function addUrl() {
    urlForm.post(route('knowledge.url'), { preserveScroll: true, onSuccess: () => urlForm.reset() });
}

// KB query (semantic Q&A against the documents).
const question = ref('');
const answer = ref(null);
const chunks = ref([]);
const querying = ref(false);
async function ask() {
    if (!question.value.trim()) return;
    querying.value = true;
    answer.value = null;
    chunks.value = [];
    try {
        const { data } = await axios.post(route('knowledge.query'), { question: question.value });
        answer.value = data.answer ?? 'No answer returned.';
        chunks.value = data.chunks ?? [];
    } catch (e) {
        answer.value = 'Query failed. Check that the knowledge base has documents.';
    } finally {
        querying.value = false;
    }
}

const statusTone = (s) => ({
    SUCCESS: 'bg-green-100 text-green-700',
    PENDING: 'bg-amber-100 text-amber-700',
    INITIALIZED: 'bg-gray-100 text-gray-600',
    ERROR: 'bg-rose-100 text-rose-700',
}[s] || 'bg-gray-100 text-gray-600');
</script>

<template>
    <AppLayout title="Knowledge Base">
        <PageHeader title="Knowledge Base" description="Ground your agent in your own content. Add URLs, ask the KB for answers." />

        <div class="py-8">
            <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
                <div v-if="!configured" class="lg:col-span-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                    Voiceflow isn't configured. Set <code>VOICEFLOW_API_KEY</code> and <code>VOICEFLOW_PROJECT_ID</code>.
                </div>

                <!-- Documents -->
                <div class="rounded-xl bg-white p-5 shadow">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Documents</h3>

                    <form class="mb-4 space-y-2" @submit.prevent="addUrl">
                        <div>
                            <InputLabel for="url" value="Add a URL to scrape" />
                            <TextInput id="url" v-model="urlForm.url" type="url" class="mt-1 block w-full" placeholder="https://yoursite.com/pricing" :disabled="!configured" />
                            <InputError :message="urlForm.errors.url" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="name" value="Name (optional)" />
                            <TextInput id="name" v-model="urlForm.name" type="text" class="mt-1 block w-full" :disabled="!configured" />
                        </div>
                        <PrimaryButton :disabled="urlForm.processing || !configured">Add document</PrimaryButton>
                    </form>

                    <p v-if="error" class="text-sm text-rose-600">{{ error }}</p>

                    <ul class="divide-y divide-gray-100">
                        <li v-for="d in documents" :key="d.documentID" class="flex items-center justify-between py-2 text-sm">
                            <span class="truncate text-gray-700">{{ d.data?.name || d.data?.url || d.documentID }}</span>
                            <span class="ml-2 shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium" :class="statusTone(d.status?.type)">
                                {{ d.status?.type || '—' }}
                            </span>
                        </li>
                        <li v-if="configured && !documents.length" class="py-6 text-center text-sm text-gray-400">
                            No documents yet. Add a URL above.
                        </li>
                    </ul>
                </div>

                <!-- Query -->
                <div class="rounded-xl bg-white p-5 shadow">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Ask the knowledge base</h3>
                    <form class="flex gap-2" @submit.prevent="ask">
                        <TextInput v-model="question" type="text" class="flex-1" placeholder="e.g. What is your pricing?" :disabled="!configured" />
                        <PrimaryButton :disabled="querying || !configured">{{ querying ? '…' : 'Ask' }}</PrimaryButton>
                    </form>

                    <div v-if="answer" class="mt-4">
                        <p class="rounded-lg bg-indigo-50 p-3 text-sm text-gray-800">{{ answer }}</p>
                        <div v-if="chunks.length" class="mt-3 space-y-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Sources</p>
                            <div v-for="(c, i) in chunks" :key="i" class="rounded border border-gray-100 p-2 text-xs text-gray-600">
                                <span v-if="c.source" class="font-medium text-gray-700">{{ c.source }}: </span>{{ c.content }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
