<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    agent: { type: Object, required: true },
});

const form = useForm({
    voiceflow_api_key: '',
    voiceflow_project_id: props.agent.voiceflow_project_id ?? '',
    voiceflow_environment: props.agent.voiceflow_environment ?? 'main',
    voiceflow_workspace_api_key: '',
});

function save() {
    form.post(route('onboarding.save'), { preserveScroll: true });
}

function copy(text) {
    navigator.clipboard?.writeText(text);
}
</script>

<template>
    <Head title="Connect Voiceflow" />
    <div class="min-h-screen bg-gray-50">
        <div class="mx-auto max-w-2xl px-4 py-12">
            <div class="mb-8 flex items-center justify-between text-xs">
                <ol class="flex items-center gap-2 font-medium text-gray-500">
                    <li class="text-gray-400">✓ 1. Create agent</li>
                    <li>→</li>
                    <li class="text-indigo-600">2. Connect Voiceflow</li>
                    <li>→</li>
                    <li>3. Done</li>
                </ol>
            </div>

            <form class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-black/5" @submit.prevent="save">
                <h1 class="text-2xl font-semibold text-gray-900">Connect Voiceflow.</h1>
                <p class="mt-2 text-gray-600">
                    Paste your Dialog Manager key + project ID. We'll run a
                    health check against your agent before saving — if it
                    doesn't respond, nothing is committed and you can try again.
                </p>

                <div class="mt-6 space-y-4">
                    <div>
                        <InputLabel for="api_key" value="VF.DM.* API key" />
                        <TextInput id="api_key" v-model="form.voiceflow_api_key" type="password" class="mt-1 block w-full font-mono" placeholder="VF.DM.…" autofocus />
                        <InputError :message="form.errors.voiceflow_api_key" class="mt-1" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel for="project" value="Project ID" />
                            <TextInput id="project" v-model="form.voiceflow_project_id" type="text" class="mt-1 block w-full font-mono" />
                            <InputError :message="form.errors.voiceflow_project_id" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="env" value="Environment" />
                            <TextInput id="env" v-model="form.voiceflow_environment" type="text" class="mt-1 block w-full font-mono" placeholder="main" />
                        </div>
                    </div>
                    <div>
                        <InputLabel for="ws" value="Workspace API key (optional)" />
                        <TextInput id="ws" v-model="form.voiceflow_workspace_api_key" type="password" class="mt-1 block w-full font-mono" placeholder="(skip for now — needed only for transcripts + analytics)" />
                        <p class="mt-1 text-xs text-gray-500">Optional. You can add it later from the agent's settings page.</p>
                    </div>
                </div>

                <details class="mt-6 rounded-md bg-gray-50 p-3 text-sm text-gray-700">
                    <summary class="cursor-pointer font-medium text-gray-900">Where do I find these in Voiceflow?</summary>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-gray-600">
                        <li><b>API key:</b> Voiceflow → Project settings → API keys → "Create new key" (Dialog Manager). It starts with <code>VF.DM.</code></li>
                        <li><b>Project ID:</b> the long ID in the URL of your Voiceflow project, between <code>/project/</code> and the next slash.</li>
                        <li><b>Environment:</b> usually <code>main</code> for the published version. Use <code>development</code> if you're testing a draft.</li>
                    </ul>
                </details>

                <div class="mt-6 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                    Once activated, configure your Voiceflow agent's Custom Action to POST captured leads to:
                    <div class="mt-2 flex items-center gap-2">
                        <code class="flex-1 truncate rounded bg-white px-2 py-1 text-xs">{{ agent.webhook_url }}</code>
                        <SecondaryButton type="button" @click="copy(agent.webhook_url)">Copy</SecondaryButton>
                    </div>
                    <div class="mt-2">…with the header <code>X-Webhook-Secret: {{ agent.webhook_secret }}</code></div>
                </div>

                <div class="mt-8 flex items-center justify-between">
                    <a :href="route('onboarding.intro')" class="text-sm text-gray-500 hover:text-gray-700">← Back</a>
                    <PrimaryButton :disabled="form.processing" :class="{ 'opacity-50': form.processing }">
                        {{ form.processing ? 'Testing connection…' : 'Save and activate' }}
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </div>
</template>
