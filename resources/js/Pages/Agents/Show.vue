<script setup>
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    agent: { type: Object, required: true },
    /**
     * Always null since Phase 14 — kept as a prop for backwards
     * compatibility with the controller's `webhook_url` field, which
     * still ships in the payload.
     */
    webhook_url: { type: String, default: null },
    is_current: { type: Boolean, default: false },
});

// Phase 14: the settings page is managed-view only. BYOK was removed
// from the product surface; the user can rename the agent and that's it.
const form = useForm({ name: props.agent.name });

function save() {
    form.put(route('agents.update', props.agent.slug), { preserveScroll: true });
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
            description="Provisioned automatically. Voiceflow runs in the background — you only need to manage the name."
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
                    <span v-if="is_current" class="inline-flex rounded-full bg-violet-50 px-2.5 py-1 text-xs font-medium text-violet-700">
                        Current
                    </span>
                </div>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                <form class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5" @submit.prevent="save">
                    <h3 class="text-base font-semibold text-gray-800">Agent details</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        Your agent is set up and running. You can rename it here; everything else is managed for you.
                    </p>

                    <div class="mt-6">
                        <InputLabel for="name" value="Display name" />
                        <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" required maxlength="255" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>

                    <div class="mt-6 flex items-center gap-3">
                        <PrimaryButton :disabled="form.processing" :class="{ 'opacity-50': form.processing }">
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

                <!-- Danger zone -->
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-6">
                    <h3 class="text-base font-semibold text-rose-800">Danger zone</h3>
                    <p class="mt-1 text-sm text-rose-700">
                        Deleting the agent unlinks (but does not delete) its leads and conversations.
                        The Voiceflow project itself is retired in our pool and won't be reassigned.
                    </p>
                    <div class="mt-4">
                        <DangerButton @click="destroy">Delete agent</DangerButton>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
