<script setup>
import { computed, ref } from 'vue';
import axios from 'axios';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { useVoiceflowValidation } from '@/composables/useVoiceflowValidation.js';

const props = defineProps({
    agent: { type: Object, required: true },
    /**
     * Null in managed mode — we configure the webhook on the master
     * template, so the per-tenant URL isn't user-actionable.
     */
    webhook_url: { type: String, default: null },
    is_current: { type: Boolean, default: false },
});

const isManaged = computed(() => props.agent.mode === 'managed');

const page = usePage();
const flashSecret = computed(() => page.props.flash?.webhook_secret_rotated ?? null);

// Two separate forms — BYOK has every credential field, managed is name-only.
// Splitting prevents the managed form from accidentally sending phantom
// credential fields the controller would silently drop anyway.
const byokForm = useForm({
    name: props.agent.name,
    voiceflow_api_key: '',
    voiceflow_project_id: props.agent.voiceflow_project_id ?? '',
    voiceflow_environment: props.agent.voiceflow_environment ?? 'main',
    voiceflow_workspace_api_key: '',
});

const managedForm = useForm({
    name: props.agent.name,
});

const { isValid, errorFor } = useVoiceflowValidation(byokForm, { requireKey: false, requireProject: false });

function saveByok() {
    if (!isValid.value) return;
    byokForm.put(route('agents.update', props.agent.slug), { preserveScroll: true });
}

function saveManagedName() {
    managedForm.put(route('agents.update', props.agent.slug), { preserveScroll: true });
}

const healthBusy = ref(false);
const healthResult = ref(null);

async function runHealth() {
    healthBusy.value = true;
    healthResult.value = null;
    try {
        const { data } = await axios.post(route('agents.health', props.agent.slug));
        healthResult.value = data;
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
    router.post(route('agents.rotate-secret', props.agent.slug), {}, { preserveScroll: true });
}

function destroy() {
    if (!confirm(`Delete agent "${props.agent.name}"? Conversations and leads stay, but lose their agent link.`)) return;
    router.delete(route('agents.destroy', props.agent.slug));
}
</script>

<template>
    <AppLayout :title="`Agent — ${agent.name}`">
        <PageHeader
            :breadcrumbs="[{ label: 'Agents', href: route('agents.index') }, { label: agent.name }]"
            :title="agent.name"
            :description="isManaged
                ? 'Provisioned automatically. Voiceflow runs in the background — you only need to manage the name.'
                : 'Voiceflow credentials, webhook URL, and health status for this agent.'"
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium" :class="{
                        'bg-green-50 text-green-700': agent.status === 'active',
                        'bg-amber-50 text-amber-700': agent.status === 'draft',
                        'bg-gray-100 text-gray-500': agent.status === 'disabled',
                    }">
                        {{ agent.status }}
                    </span>
                    <span v-if="isManaged" class="inline-flex rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700">
                        Managed
                    </span>
                    <span v-if="is_current" class="inline-flex rounded-full bg-violet-50 px-2.5 py-1 text-xs font-medium text-violet-700">
                        Current
                    </span>
                </div>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">

                <!-- ============================================================
                     MANAGED MODE — no credentials surfaced. The user didn't set
                     these and can't change them; surfacing them would only
                     confuse them and risk leaking implementation details.
                     ============================================================ -->
                <template v-if="isManaged">
                    <form class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5" @submit.prevent="saveManagedName">
                        <h3 class="text-base font-semibold text-gray-800">Agent details</h3>
                        <p class="mt-1 text-sm text-gray-500">
                            Voiceflow is set up and running. You can rename the agent here; everything else is managed for you.
                        </p>

                        <div class="mt-6">
                            <InputLabel for="name" value="Display name" />
                            <TextInput id="name" v-model="managedForm.name" type="text" class="mt-1 block w-full" required maxlength="255" />
                            <InputError :message="managedForm.errors.name" class="mt-1" />
                        </div>

                        <div class="mt-6 flex items-center gap-3">
                            <PrimaryButton :disabled="managedForm.processing" :class="{ 'opacity-50': managedForm.processing }">
                                Save
                            </PrimaryButton>
                            <span v-if="agent.last_health_check_at" class="text-xs text-gray-500">
                                Provisioned {{ new Date(agent.last_health_check_at).toLocaleString() }} —
                                <span :class="agent.last_health_ok ? 'text-green-700' : 'text-rose-700'">
                                    {{ agent.last_health_ok ? '✓ healthy' : '✗ failed' }}
                                </span>
                            </span>
                        </div>
                    </form>

                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
                        <div class="flex items-start gap-3">
                            <svg class="mt-0.5 size-4 flex-shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                            </svg>
                            <div>
                                The Voiceflow project, API keys, environment, and webhook are all provisioned and managed on your behalf. If something goes wrong, contact support — there's nothing here for you to tweak.
                            </div>
                        </div>
                    </div>
                </template>

                <!-- ============================================================
                     BYOK MODE — full credential form + webhook URL/secret.
                     ============================================================ -->
                <template v-else>
                    <form class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5" @submit.prevent="saveByok">
                        <h3 class="text-base font-semibold text-gray-800">Voiceflow credentials</h3>
                        <p class="mt-1 text-sm text-gray-500">Paste the Dialog Manager key + project ID from your Voiceflow project's Settings → API keys. Saving runs a health check; the agent activates if it passes.</p>

                        <div class="mt-6 grid grid-cols-2 gap-4">
                            <div class="col-span-2">
                                <InputLabel for="name" value="Name" />
                                <TextInput id="name" v-model="byokForm.name" type="text" class="mt-1 block w-full" required maxlength="255" />
                                <InputError :message="byokForm.errors.name" class="mt-1" />
                            </div>
                            <div class="col-span-2">
                                <InputLabel for="api_key" value="VF.DM.* API key" />
                                <TextInput
                                    id="api_key"
                                    v-model="byokForm.voiceflow_api_key"
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
                                    v-model="byokForm.voiceflow_project_id"
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
                                    v-model="byokForm.voiceflow_environment"
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
                                    v-model="byokForm.voiceflow_workspace_api_key"
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
                            <PrimaryButton :disabled="byokForm.processing || !isValid" :class="{ 'opacity-50': byokForm.processing || !isValid }">Save + test connection</PrimaryButton>
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

                    <div v-if="webhook_url" class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5">
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
                </template>

                <!-- Danger zone — shown for both modes -->
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-6">
                    <h3 class="text-base font-semibold text-rose-800">Danger zone</h3>
                    <p class="mt-1 text-sm text-rose-700">
                        Deleting the agent unlinks (but does not delete) its leads and conversations.
                        <span v-if="isManaged">The Voiceflow environment will remain in our workspace for archival; contact support if you need it actually purged.</span>
                    </p>
                    <div class="mt-4">
                        <DangerButton @click="destroy">Delete agent</DangerButton>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
