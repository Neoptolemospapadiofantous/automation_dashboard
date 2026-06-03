<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { useVoiceflowValidation } from '@/composables/useVoiceflowValidation.js';

const props = defineProps({
    agent: { type: Object, required: true },
    webhook_url: { type: String, required: true },
    is_current: { type: Boolean, default: false },
});

const page = usePage();
const flashSecret = computed(() => page.props.flash?.webhook_secret_rotated ?? null);

const form = useForm({
    name: props.agent.name,
    voiceflow_api_key: '',
    voiceflow_project_id: props.agent.voiceflow_project_id ?? '',
    voiceflow_environment: props.agent.voiceflow_environment ?? 'main',
    voiceflow_workspace_api_key: '',
});

// On the settings page everything is optional ("leave blank to keep
// current"). Validation only rejects malformed values; empty fields pass.
const { isValid, errorFor } = useVoiceflowValidation(form, { requireKey: false, requireProject: false });

function save() {
    if (!isValid.value) return;
    form.put(route('agents.update', props.agent.id), { preserveScroll: true });
}

const healthBusy = ref(false);
const healthResult = ref(null);

async function runHealth() {
    healthBusy.value = true;
    healthResult.value = null;
    try {
        const { data } = await axios.post(route('agents.health', props.agent.id));
        healthResult.value = data;
        // Refresh page to pick up any status change (e.g. draft → active).
        router.reload({ only: ['agent'] });
    } finally {
        healthBusy.value = false;
    }
}

function copy(text) {
    navigator.clipboard?.writeText(text);
}

function rotateSecret() {
    if (!confirm('Rotate the webhook secret? The Voiceflow Custom Action will start failing until you update its X-Webhook-Secret header to match the new value.')) return;
    router.post(route('agents.rotate-secret', props.agent.id), {}, { preserveScroll: true });
}

function destroy() {
    if (!confirm(`Delete agent "${props.agent.name}"? Conversations and leads stay, but lose their agent link.`)) return;
    router.delete(route('agents.destroy', props.agent.id));
}
</script>

<template>
    <AppLayout :title="`Agent — ${agent.name}`">
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <Link :href="route('agents.index')" class="text-sm text-indigo-600 hover:text-indigo-500">← All agents</Link>
                    <h2 class="mt-1 text-xl font-semibold leading-tight text-gray-800">{{ agent.name }}</h2>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="{
                        'bg-green-50 text-green-700': agent.status === 'active',
                        'bg-amber-50 text-amber-700': agent.status === 'draft',
                        'bg-gray-100 text-gray-500': agent.status === 'disabled',
                    }">
                        {{ agent.status }}
                    </span>
                    <span v-if="is_current" class="inline-flex rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700">
                        Current agent
                    </span>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Credentials -->
                <form class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5" @submit.prevent="save">
                    <h3 class="text-base font-semibold text-gray-800">Voiceflow credentials</h3>
                    <p class="mt-1 text-sm text-gray-500">Paste the Dialog Manager key + project ID from your Voiceflow project's Settings → API keys. Saving runs a health check; the agent activates if it passes.</p>

                    <div class="mt-6 grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <InputLabel for="name" value="Name" />
                            <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required maxlength="255" />
                            <InputError :message="form.errors.name" class="mt-1" />
                        </div>
                        <div class="col-span-2">
                            <InputLabel for="api_key" value="VF.DM.* API key" />
                            <TextInput
                                id="api_key"
                                v-model="form.voiceflow_api_key"
                                type="password"
                                class="mt-1 block w-full font-mono"
                                :class="{ 'border-rose-300 focus:border-rose-500 focus:ring-rose-500': errorFor('voiceflow_api_key') }"
                                :placeholder="agent.has_api_key ? '••••••• (leave blank to keep current)' : 'VF.DM.xxxxxxxxxxxxxxxxxxxxxxxx.yyyyyyyyyyyyyyyy'"
                                autocomplete="off"
                                spellcheck="false"
                            />
                            <InputError :message="errorFor('voiceflow_api_key')" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="project" value="Project ID" />
                            <TextInput
                                id="project"
                                v-model="form.voiceflow_project_id"
                                type="text"
                                class="mt-1 block w-full font-mono"
                                :class="{ 'border-rose-300 focus:border-rose-500 focus:ring-rose-500': errorFor('voiceflow_project_id') }"
                                placeholder="e.g. 64f8a1b2c3d4e5f6a7b8c9d0"
                                pattern="[a-fA-F0-9]{24}"
                                minlength="24"
                                maxlength="24"
                                autocomplete="off"
                                spellcheck="false"
                            />
                            <p class="mt-1 text-xs text-gray-500">24-character hex ID from the Voiceflow URL.</p>
                            <InputError :message="errorFor('voiceflow_project_id')" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel for="env" value="Environment" />
                            <TextInput
                                id="env"
                                v-model="form.voiceflow_environment"
                                type="text"
                                class="mt-1 block w-full font-mono"
                                :class="{ 'border-rose-300 focus:border-rose-500 focus:ring-rose-500': errorFor('voiceflow_environment') }"
                                placeholder="main"
                                pattern="[A-Za-z0-9_-]{2,32}"
                                maxlength="32"
                            />
                            <InputError :message="errorFor('voiceflow_environment')" class="mt-1" />
                        </div>
                        <div class="col-span-2">
                            <InputLabel for="ws" value="Workspace API key (optional — for transcripts + analytics)" />
                            <TextInput
                                id="ws"
                                v-model="form.voiceflow_workspace_api_key"
                                type="password"
                                class="mt-1 block w-full font-mono"
                                :class="{ 'border-rose-300 focus:border-rose-500 focus:ring-rose-500': errorFor('voiceflow_workspace_api_key') }"
                                :placeholder="agent.has_workspace_api_key ? '••••••• (leave blank to keep current)' : 'VF.…'"
                                autocomplete="off"
                                spellcheck="false"
                            />
                            <InputError :message="errorFor('voiceflow_workspace_api_key')" class="mt-1" />
                        </div>
                    </div>

                    <div class="mt-6 flex items-center gap-3">
                        <PrimaryButton :disabled="form.processing || !isValid" :class="{ 'opacity-50': form.processing || !isValid }">Save + test connection</PrimaryButton>
                        <SecondaryButton type="button" :disabled="healthBusy" @click="runHealth">
                            {{ healthBusy ? 'Testing…' : 'Test connection now' }}
                        </SecondaryButton>
                        <span v-if="agent.last_health_check_at" class="text-xs text-gray-500">
                            Last check: {{ new Date(agent.last_health_check_at).toLocaleString() }} — {{ agent.last_health_ok ? '✓ ok' : '✗ failed' }}
                        </span>
                    </div>

                    <div v-if="healthResult" class="mt-4 rounded-md p-3 text-sm" :class="healthResult.ok ? 'bg-green-50 text-green-800' : 'bg-rose-50 text-rose-800'">
                        {{ healthResult.reason ?? (healthResult.ok ? 'OK' : 'Failed') }}
                    </div>
                </form>

                <!-- Webhook -->
                <div class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5">
                    <h3 class="text-base font-semibold text-gray-800">Inbound webhook</h3>
                    <p class="mt-1 text-sm text-gray-500">Configure your Voiceflow agent's Custom Action to POST here with the secret in the <code>X-Webhook-Secret</code> header. Lead captures arrive in real-time.</p>

                    <div class="mt-4 space-y-3">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">URL</div>
                            <div class="mt-1 flex items-center gap-2">
                                <code class="flex-1 rounded-md bg-gray-50 px-3 py-2 text-xs">{{ webhook_url }}</code>
                                <SecondaryButton type="button" @click="copy(webhook_url)">Copy</SecondaryButton>
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Secret</div>
                            <div class="mt-1 flex items-center gap-2">
                                <code class="flex-1 rounded-md bg-gray-50 px-3 py-2 text-xs">{{ flashSecret ?? agent.webhook_secret }}</code>
                                <SecondaryButton type="button" @click="copy(flashSecret ?? agent.webhook_secret)">Copy</SecondaryButton>
                                <SecondaryButton type="button" @click="rotateSecret">Rotate</SecondaryButton>
                            </div>
                            <p v-if="flashSecret" class="mt-1 text-xs text-amber-700">Secret was just rotated — copy it now; we won't show this banner again on refresh.</p>
                        </div>
                    </div>
                </div>

                <!-- Danger zone -->
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-6">
                    <h3 class="text-base font-semibold text-rose-800">Danger zone</h3>
                    <p class="mt-1 text-sm text-rose-700">Deleting the agent unlinks (but does not delete) its leads and conversations.</p>
                    <div class="mt-4">
                        <DangerButton @click="destroy">Delete agent</DangerButton>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
