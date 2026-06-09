<script setup>
import { ref, computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import EmptyState from '@/Components/EmptyState.vue';
import { confirm } from '@/Composables/useConfirm';

const props = defineProps({
    configured: { type: Boolean, default: false },
    projectId: { type: String, default: null },
    environments: { type: Array, default: () => [] },
    trafficSplit: { type: Object, default: null },
});

const showClone = ref(false);
const cloneForm = useForm({
    source_environment_id: '',
    alias: '',
});

const submitClone = () => {
    cloneForm.post(route('agents.environments.clone'), {
        preserveScroll: true,
        onSuccess: () => {
            showClone.value = false;
            cloneForm.reset();
        },
    });
};

const publish = async (idOrAlias) => {
    const ok = await confirm({
        title: 'Publish release',
        message: `Publish "${idOrAlias}" as a release? Live traffic will pick up the new version.`,
        buttonText: 'Publish',
    });
    if (!ok) return;
    useForm({}).post(route('agents.environments.publish', idOrAlias), { preserveScroll: true });
};

const destroy = async (envId) => {
    const ok = await confirm({
        title: 'Delete environment',
        message: `Delete environment "${envId}"? This is irreversible.`,
        buttonText: 'Delete',
        dangerous: true,
    });
    if (!ok) return;
    useForm({}).delete(route('agents.environments.destroy', envId), { preserveScroll: true });
};

const exportUrl = (alias, version = 'published') =>
    route('agents.environments.export', alias) + '?version=' + encodeURIComponent(version);

const splitRows = computed(() => {
    if (!props.trafficSplit || typeof props.trafficSplit !== 'object') return [];
    return Object.entries(props.trafficSplit).map(([k, v]) => ({ alias: k, percent: v }));
});
</script>

<template>
    <AppLayout title="Environments">
        <PageHeader title="Environments" description="Voiceflow environment management — list, clone, publish, export.">
            <template #breadcrumbs>
                <Link :href="route('agents.index')" class="text-sm text-gray-500 hover:text-gray-700">← Agents</Link>
            </template>
            <template #actions>
                <PrimaryButton :disabled="!configured" @click="showClone = !showClone">
                    {{ showClone ? 'Cancel' : 'Clone environment' }}
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div v-if="!configured" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-700">
                    Voiceflow isn't configured for the current agent — environments cannot be managed.
                </div>

                <div v-if="showClone && configured" class="rounded-lg bg-white p-4 shadow">
                    <h3 class="mb-3 text-base font-medium text-gray-900">Clone environment</h3>
                    <p class="mb-3 text-xs text-gray-500">
                        Voiceflow has no create-from-scratch; new environments must be cloned from an existing one.
                        Pick a source environment ID below and a fresh alias for the new one.
                    </p>
                    <form @submit.prevent="submitClone" class="space-y-3">
                        <div>
                            <InputLabel for="source" value="Source environment ID" />
                            <TextInput id="source" v-model="cloneForm.source_environment_id" type="text" class="mt-1 block w-full" required />
                            <InputError :message="cloneForm.errors.source_environment_id" />
                        </div>
                        <div>
                            <InputLabel for="alias" value="New alias" />
                            <TextInput id="alias" v-model="cloneForm.alias" type="text" class="mt-1 block w-full" placeholder="e.g. staging" required />
                            <InputError :message="cloneForm.errors.alias" />
                        </div>
                        <div class="flex justify-end gap-2">
                            <SecondaryButton type="button" @click="showClone = false">Cancel</SecondaryButton>
                            <PrimaryButton type="submit" :disabled="cloneForm.processing">Clone</PrimaryButton>
                        </div>
                    </form>
                </div>

                <div v-if="environments.length === 0 && configured" class="rounded-lg bg-white p-8 shadow">
                    <EmptyState title="No environments returned" message="Voiceflow returned an empty list. This is unusual — every project has at least 'main'." />
                </div>

                <ul v-if="environments.length > 0" class="divide-y divide-gray-200 overflow-hidden rounded-lg bg-white shadow">
                    <li v-for="env in environments" :key="env.id" class="flex flex-wrap items-center justify-between gap-2 p-4">
                        <div>
                            <div class="text-sm font-medium text-gray-900">
                                {{ env.alias ?? env.id }}
                                <span v-if="env.alias === 'main'" class="ml-2 rounded-full bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">main</span>
                            </div>
                            <div class="font-mono text-xs text-gray-500">
                                {{ env.id }}
                                <span v-if="env.isLive" class="ml-2 text-emerald-600">· live</span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <a :href="exportUrl(env.alias ?? env.id, 'published')" class="inline-flex items-center rounded-md bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100">
                                Export published JSON
                            </a>
                            <a :href="exportUrl(env.alias ?? env.id, 'draft')" class="inline-flex items-center rounded-md bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-100">
                                Export draft JSON
                            </a>
                            <SecondaryButton @click="publish(env.alias ?? env.id)">Publish</SecondaryButton>
                            <DangerButton v-if="env.alias !== 'main'" @click="destroy(env.id)">Delete</DangerButton>
                        </div>
                    </li>
                </ul>

                <div v-if="splitRows.length > 0" class="rounded-lg bg-white p-4 shadow">
                    <h3 class="mb-3 text-base font-medium text-gray-900">Traffic split</h3>
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs uppercase text-gray-500">
                                <th class="pb-2 font-medium">Alias / environment</th>
                                <th class="pb-2 font-medium">Percent</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in splitRows" :key="row.alias">
                                <td class="py-2 font-mono text-xs">{{ row.alias }}</td>
                                <td class="py-2">{{ row.percent }}%</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-xs text-gray-500">Read-only. Edits are operator-only via the API.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
