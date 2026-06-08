<script setup>
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    configured: { type: Boolean, default: false },
    evaluations: { type: Array, default: () => [] },
});

const showCreate = ref(false);
const runTarget = ref(null);   // { id, name } currently being run
const runTranscriptId = ref('');
const runResult = ref(null);
const runError = ref('');

const form = useForm({ name: '', type: 'boolean', description: '' });

const submit = () => {
    form.post(route('agents.evaluations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            form.reset();
        },
    });
};

const destroy = (id) => {
    if (!confirm('Delete this evaluation? Its results are also removed.')) return;
    useForm({}).delete(route('agents.evaluations.destroy', id), { preserveScroll: true });
};

const openRun = (e) => {
    runTarget.value = { id: e.id, name: e.name };
    runTranscriptId.value = '';
    runResult.value = null;
    runError.value = '';
};

const closeRun = () => {
    runTarget.value = null;
};

const runNow = async () => {
    if (!runTranscriptId.value.trim() || !runTarget.value) return;
    runError.value = '';
    runResult.value = null;
    try {
        const { data } = await axios.post(
            route('agents.evaluations.run', runTarget.value.id),
            { transcript_id: runTranscriptId.value.trim() },
        );
        runResult.value = data;
    } catch (e) {
        runError.value = e?.response?.data?.error ?? 'Run failed';
    }
};
</script>

<template>
    <AppLayout title="Evaluations">
        <PageHeader title="Evaluations" description="Regression panel — define checks, run them against transcripts, see what's drifting.">
            <template #breadcrumbs>
                <Link :href="route('agents.index')" class="text-sm text-gray-500 hover:text-gray-700">← Agents</Link>
            </template>
            <template #actions>
                <PrimaryButton :disabled="!configured" @click="showCreate = !showCreate">
                    {{ showCreate ? 'Cancel' : 'New evaluation' }}
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div v-if="!configured" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
                    Voiceflow isn't configured for the current agent. Finish onboarding to define evaluations.
                </div>

                <div v-if="showCreate && configured" class="mb-6 rounded-lg bg-white p-4 shadow">
                    <h3 class="mb-3 text-base font-medium text-gray-900">New evaluation</h3>
                    <form @submit.prevent="submit" class="space-y-3">
                        <div>
                            <InputLabel for="name" value="Name" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div>
                            <InputLabel for="type" value="Type" />
                            <select id="type" v-model="form.type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="boolean">boolean (pass/fail)</option>
                                <option value="number">number (score)</option>
                                <option value="string">string (free-form)</option>
                                <option value="option">option (enum)</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel for="description" value="Description (optional)" />
                            <TextInput id="description" v-model="form.description" type="text" class="mt-1 block w-full" />
                        </div>
                        <div class="flex justify-end gap-2">
                            <SecondaryButton type="button" @click="showCreate = false">Cancel</SecondaryButton>
                            <PrimaryButton type="submit" :disabled="form.processing">Create</PrimaryButton>
                        </div>
                    </form>
                </div>

                <div v-if="evaluations.length === 0 && configured" class="rounded-lg bg-white p-8 shadow">
                    <EmptyState title="No evaluations yet" message="Create one to start measuring agent behaviour against the same transcript repeatedly." />
                </div>

                <ul v-if="evaluations.length > 0" class="divide-y divide-gray-200 overflow-hidden rounded-lg bg-white shadow">
                    <li v-for="evaluation in evaluations" :key="evaluation.id" class="flex items-center justify-between p-4">
                        <div>
                            <div class="text-sm font-medium text-gray-900">{{ evaluation.name }}</div>
                            <div class="text-xs text-gray-500">
                                Type: <span class="font-mono">{{ evaluation.type ?? '—' }}</span>
                                <span v-if="evaluation.description" class="ml-2">{{ evaluation.description }}</span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <SecondaryButton @click="openRun(evaluation)">Run</SecondaryButton>
                            <DangerButton @click="destroy(evaluation.id)">Delete</DangerButton>
                        </div>
                    </li>
                </ul>

                <!-- Run modal -->
                <div v-if="runTarget" class="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" @click.self="closeRun">
                    <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
                        <h3 class="mb-3 text-base font-medium text-gray-900">Run "{{ runTarget.name }}"</h3>
                        <InputLabel for="transcript_id" value="Transcript ID" />
                        <TextInput id="transcript_id" v-model="runTranscriptId" type="text" class="mt-1 block w-full" placeholder="t-…" />
                        <InputError :message="runError" />

                        <div v-if="runResult" class="mt-4 rounded-md bg-gray-50 p-3 text-xs">
                            <pre class="whitespace-pre-wrap">{{ JSON.stringify(runResult, null, 2) }}</pre>
                        </div>

                        <div class="mt-4 flex justify-end gap-2">
                            <SecondaryButton @click="closeRun">Close</SecondaryButton>
                            <PrimaryButton @click="runNow">Run</PrimaryButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
