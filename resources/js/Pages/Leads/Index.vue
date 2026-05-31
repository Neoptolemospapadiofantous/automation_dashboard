<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import LeadCard from '@/Components/LeadCard.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useEcho } from '@/composables/useEcho';

const props = defineProps({
    leads: { type: Array, required: true },
    statuses: { type: Array, required: true },
    members: { type: Array, required: true },
});

const page = usePage();
const teamId = computed(() => page.props.auth.user.current_team_id);

// Local reactive store of leads, keyed by id, kept in sync by broadcasts.
const leads = reactive(new Map(props.leads.map((l) => [l.id, l])));

// When the server re-sends the leads prop (e.g. after creating one in this
// same browser, which broadcasts toOthers and so won't echo back here), merge
// it in so this client stays consistent.
watch(() => props.leads, (fresh) => {
    fresh.forEach((l) => leads.set(l.id, l));
});

const columns = computed(() =>
    props.statuses.map((status) => ({
        ...status,
        leads: [...leads.values()].filter((l) => l.status === status.value),
    })),
);

// --- Live updates: patch the board in place, no reload ----------------------
const { connected } = useEcho(`team.${teamId.value}`, '.lead.saved', ({ lead }) => {
    leads.set(lead.id, lead);
}, { presence: true });

useEcho(`team.${teamId.value}`, '.lead.deleted', ({ id }) => {
    leads.delete(id);
}, { presence: true });

// --- Drag and drop status changes -------------------------------------------
function onDrop(event, status) {
    const id = Number(event.dataTransfer.getData('text/lead-id'));
    const lead = leads.get(id);
    if (!lead || lead.status === status) return;

    // Optimistic move; the server broadcast confirms to everyone else.
    lead.status = status;
    router.patch(route('leads.status', id), { status }, {
        preserveScroll: true,
        preserveState: true,
        only: [],
    });
}

function destroy(lead) {
    if (!confirm(`Delete lead "${lead.name}"?`)) return;
    leads.delete(lead.id);
    router.delete(route('leads.destroy', lead.id), { preserveScroll: true, preserveState: true, only: [] });
}

// --- Create form ------------------------------------------------------------
const showCreate = ref(false);
const form = useForm({
    name: '',
    email: '',
    phone: '',
    company: '',
    source: 'manual',
    score: 0,
    assigned_to: null,
    status: 'new',
});

function submit() {
    // Reload the leads prop on success; the watcher above merges it into the
    // board. Other connected clients get the new card via the broadcast.
    form.post(route('leads.store'), {
        preserveScroll: true,
        only: ['leads'],
        onSuccess: () => {
            form.reset();
            showCreate.value = false;
        },
    });
}
</script>

<template>
    <AppLayout title="Leads">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="flex items-center gap-2 text-xl font-semibold leading-tight text-gray-800">
                    Leads
                    <span
                        class="inline-block h-2 w-2 rounded-full"
                        :class="connected ? 'bg-green-500 animate-pulse' : 'bg-gray-300'"
                        :title="connected ? 'Live' : 'Offline — set PUSHER_* to enable live updates'"
                    />
                </h2>
                <PrimaryButton @click="showCreate = true">New lead</PrimaryButton>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex gap-4 overflow-x-auto pb-4">
                    <div
                        v-for="col in columns"
                        :key="col.value"
                        class="flex w-72 flex-shrink-0 flex-col rounded-xl bg-gray-100 p-3"
                        @dragover.prevent
                        @drop="onDrop($event, col.value)"
                    >
                        <div class="mb-3 flex items-center justify-between px-1">
                            <h3 class="text-sm font-semibold text-gray-700">{{ col.label }}</h3>
                            <span class="rounded-full bg-white px-2 py-0.5 text-xs text-gray-500">
                                {{ col.leads.length }}
                            </span>
                        </div>

                        <div class="flex flex-1 flex-col gap-2">
                            <LeadCard
                                v-for="lead in col.leads"
                                :key="lead.id"
                                :lead="lead"
                                @delete="destroy"
                            />
                            <p
                                v-if="!col.leads.length"
                                class="rounded-lg border-2 border-dashed border-gray-200 p-4 text-center text-xs text-gray-400"
                            >
                                Drop leads here
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create lead modal -->
        <DialogModal :show="showCreate" @close="showCreate = false">
            <template #title>New lead</template>
            <template #content>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <InputLabel for="name" value="Name" />
                        <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel for="phone" value="Phone" />
                        <TextInput id="phone" v-model="form.phone" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="company" value="Company" />
                        <TextInput id="company" v-model="form.company" type="text" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="score" value="Score (0–100)" />
                        <TextInput id="score" v-model="form.score" type="number" min="0" max="100" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="status" value="Status" />
                        <select id="status" v-model="form.status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="assigned_to" value="Assign to" />
                        <select id="assigned_to" v-model="form.assigned_to" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option :value="null">Unassigned</option>
                            <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
                        </select>
                    </div>
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
