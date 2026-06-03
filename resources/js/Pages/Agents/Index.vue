<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

defineProps({
    agents: { type: Array, required: true },
});

const showCreate = ref(false);
const form = useForm({ name: '' });

function submit() {
    form.post(route('agents.store'), {
        onSuccess: () => {
            showCreate.value = false;
            form.reset();
        },
    });
}

function makeCurrent(agent) {
    router.put(route('current-agent.update'), { agent_id: agent.id }, {
        preserveScroll: true,
    });
}

function statusClass(status) {
    return {
        active: 'bg-green-50 text-green-700',
        draft: 'bg-amber-50 text-amber-700',
        disabled: 'bg-gray-100 text-gray-500',
    }[status] ?? 'bg-gray-100 text-gray-500';
}
</script>

<template>
    <AppLayout title="Agents">
        <PageHeader
            title="Agents"
            description="Each agent is one Voiceflow project. Switch between them in the top-left."
        >
            <template #actions>
                <PrimaryButton @click="showCreate = true">New agent</PrimaryButton>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-xl bg-white shadow ring-1 ring-black/5">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-semibold text-gray-600">Name</th>
                                <th class="px-4 py-2.5 text-left font-semibold text-gray-600">Status</th>
                                <th class="px-4 py-2.5 text-left font-semibold text-gray-600">Last health check</th>
                                <th class="px-4 py-2.5 text-right" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            <tr v-for="agent in agents" :key="agent.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <Link :href="route('agents.show', agent.slug)" class="font-medium text-gray-900 hover:text-indigo-600">
                                        {{ agent.name }}
                                    </Link>
                                    <span v-if="agent.is_current" class="ml-2 inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                        Current
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(agent.status)">
                                        {{ agent.status }}
                                    </span>
                                    <span v-if="!agent.is_configured" class="ml-2 text-xs text-amber-600">Needs credentials</span>
                                    <span v-else-if="!agent.last_health_ok" class="ml-2 text-xs text-rose-600">Health check failing</span>
                                </td>
                                <td class="px-4 py-3 text-gray-600">
                                    {{ agent.last_health_check_at ? new Date(agent.last_health_check_at).toLocaleString() : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="!agent.is_current"
                                        type="button"
                                        class="mr-3 text-sm text-gray-500 hover:text-gray-700"
                                        @click="makeCurrent(agent)"
                                    >
                                        Make current
                                    </button>
                                    <Link :href="route('agents.show', agent.slug)" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                                        Settings →
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!agents.length">
                                <td colspan="4">
                                    <EmptyState
                                        title="No agents yet"
                                        description="Create your first agent to start qualifying leads. Each agent is a separate Voiceflow project with its own keys."
                                    >
                                        <template #actions>
                                            <PrimaryButton @click="showCreate = true">Create your first agent</PrimaryButton>
                                        </template>
                                    </EmptyState>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <DialogModal :show="showCreate" @close="showCreate = false">
            <template #title>New agent</template>
            <template #content>
                <p class="text-sm text-gray-600">Give it a name. You'll paste the Voiceflow credentials on the next screen.</p>
                <div class="mt-4">
                    <InputLabel for="name" value="Agent name" />
                    <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" placeholder="e.g. Sales bot" />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
            </template>
            <template #footer>
                <SecondaryButton @click="showCreate = false">Cancel</SecondaryButton>
                <PrimaryButton class="ms-3" :class="{ 'opacity-50': form.processing }" :disabled="form.processing" @click="submit">
                    Create
                </PrimaryButton>
            </template>
        </DialogModal>
    </AppLayout>
</template>
