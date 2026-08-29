<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    draftActions: { type: Array, default: null }, // null = no draft yet
    publishedActions: { type: Array, default: () => [] },
    enabled: { type: Boolean, default: false },
    allowPrivateHosts: { type: Boolean, default: false },
    modes: { type: Array, default: () => ['sync', 'async'] },
    paramTypes: { type: Array, default: () => ['string', 'number', 'boolean'] },
});

// `id` is the action's stable identity (minted server-side) — round-tripped
// untouched so a rename keeps its activity history linked.
const clone = (rows) =>
    rows.map((a) => ({
        id: a.id ?? '',
        name: a.name ?? '',
        description: a.description ?? '',
        url: a.url ?? '',
        mode: a.mode ?? 'sync',
        credit_cost: a.credit_cost ?? 1,
        parameters: (a.parameters ?? []).map((p) => ({
            name: p.name ?? '',
            type: p.type ?? 'string',
            description: p.description ?? '',
            required: !!p.required,
        })),
    }));

// Edit the draft if one exists, else seed from what's live, else blank.
const seed = props.draftActions ?? props.publishedActions ?? [];

const form = useForm({ automations: clone(seed) });

const err = (i, field) => form.errors[`automations.${i}.${field}`];
const paramErr = (i, j, field) => form.errors[`automations.${i}.parameters.${j}.${field}`];

const addAction = () =>
    form.automations.push({ id: '', name: '', description: '', url: '', mode: 'sync', credit_cost: 1, parameters: [] });
const removeAction = (i) => form.automations.splice(i, 1);

const addParam = (i) =>
    form.automations[i].parameters.push({ name: '', type: 'string', description: '', required: false });
const removeParam = (i, j) => form.automations[i].parameters.splice(j, 1);

const save = () => form.post(route('agents.actions.save'), { preserveScroll: true });

const publishForm = useForm({});
const publish = () => {
    // Save the editor first so what's on screen is exactly what goes live,
    // then publish the shared draft (behavior + actions together).
    form.post(route('agents.actions.save'), {
        preserveScroll: true,
        onSuccess: () => publishForm.post(route('agents.versions.publish'), { preserveScroll: true }),
    });
};

const dirty = computed(() => form.isDirty);
const urlHint = computed(() =>
    props.allowPrivateHosts
        ? 'The n8n webhook URL (http or https in local dev).'
        : 'The n8n webhook URL — must be https.',
);
</script>

<template>
    <AppLayout title="Actions">
        <PageHeader width="max-w-5xl"
            title="Actions"
            description="Give the agent hands. Each action is an n8n workflow it can call mid-conversation — look up an order, open a ticket, sync a CRM. Stage changes as a draft, then publish."
        />

        <div class="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Coming soon: the automation subsystem is turned off
                 (RUNTIME_AUTOMATION_ENABLED). The editor unlocks automatically
                 once it's enabled, so this is the whole surface until then. -->
            <div
                v-if="!enabled"
                class="rounded-none border border-border-line bg-bg p-8 text-center shadow-sheet"
            >
                <span class="bp-ref">AGENT/ACTIONS</span>
                <h2 class="mt-3 text-lg font-medium text-ink">Actions — coming soon</h2>
                <p class="mx-auto mt-2 max-w-xl text-sm text-ink-dim">
                    Actions let the agent call your n8n workflows mid-conversation — look up an order, open a
                    ticket, sync a CRM — and use the result in its reply. We're wiring this up; the authoring
                    editor will appear here once it's live.
                </p>
                <p class="mt-3 font-mono text-xs text-ink-mute">
                    Meanwhile, see <Link :href="route('agents.activity.index')" class="underline hover:text-ink-dim">Activity</Link>
                    for the automation run log.
                </p>
            </div>

            <template v-if="enabled">
            <!-- Live summary -->
            <div class="rounded-none border border-border-line bg-bg p-5 shadow-sheet">
                <span class="bp-ref">AGENT/ACTIONS</span>
                <div class="mb-1 mt-1 flex items-center justify-between">
                    <h2 class="text-base font-medium text-ink">Automations</h2>
                    <span class="font-mono text-xs text-ink-mute">
                        {{ publishedActions.length }} live · {{ form.automations.length }} in draft
                    </span>
                </div>
                <p class="text-xs text-ink-dim">
                    The model is told which actions exist and when to use each from their name + description, then calls
                    the one it needs. Drafts are invisible to the agent until published.
                </p>
            </div>

            <!-- Editor -->
            <EmptyState
                v-if="form.automations.length === 0"
                title="No actions yet"
                description="Add an action to let the agent trigger an n8n workflow during a conversation."
            >
                <template #actions>
                    <PrimaryButton @click="addAction">Add action</PrimaryButton>
                </template>
            </EmptyState>

            <div
                v-for="(action, i) in form.automations"
                :key="i"
                class="rounded-none border border-border-line bg-bg p-5"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-mono text-sm font-semibold text-ink">Action {{ i + 1 }}</h3>
                    <button
                        type="button"
                        class="font-mono text-xs text-state-bad-ink hover:underline"
                        @click="removeAction(i)"
                    >
                        Remove
                    </button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel :for="`name-${i}`" value="Name" />
                        <p class="mt-0.5 text-xs text-ink-dim">Lowercase identifier the agent uses, e.g. lookup_order.</p>
                        <input
                            :id="`name-${i}`"
                            v-model="action.name"
                            type="text"
                            maxlength="64"
                            class="mt-2 block w-full rounded-none border-border-hi bg-bg font-mono text-sm text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                            placeholder="lookup_order"
                        />
                        <InputError :message="err(i, 'name')" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel :for="`mode-${i}`" value="Mode" />
                        <p class="mt-0.5 text-xs text-ink-dim">
                            Sync waits for a result to use in the reply; async fires and forgets.
                        </p>
                        <select
                            :id="`mode-${i}`"
                            v-model="action.mode"
                            class="mt-2 block w-full rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                        >
                            <option v-for="m in modes" :key="m" :value="m">{{ m }}</option>
                        </select>
                        <InputError :message="err(i, 'mode')" class="mt-1" />
                    </div>
                </div>

                <div class="mt-4">
                    <InputLabel :for="`desc-${i}`" value="Description" />
                    <p class="mt-0.5 text-xs text-ink-dim">Tells the agent WHEN to use this action. Be specific.</p>
                    <input
                        :id="`desc-${i}`"
                        v-model="action.description"
                        type="text"
                        maxlength="500"
                        class="mt-2 block w-full rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                        placeholder="Look up an order by its number for the visitor."
                    />
                    <InputError :message="err(i, 'description')" class="mt-1" />
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <InputLabel :for="`url-${i}`" value="Webhook URL" />
                        <p class="mt-0.5 text-xs text-ink-dim">{{ urlHint }}</p>
                        <input
                            :id="`url-${i}`"
                            v-model="action.url"
                            type="url"
                            maxlength="2000"
                            class="mt-2 block w-full rounded-none border-border-hi bg-bg font-mono text-xs text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                            placeholder="https://n8n.flowstack.run/webhook/orders"
                        />
                        <InputError :message="err(i, 'url')" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :for="`cost-${i}`" value="Credit cost" />
                        <p class="mt-0.5 text-xs text-ink-dim">Debited per call.</p>
                        <input
                            :id="`cost-${i}`"
                            v-model.number="action.credit_cost"
                            type="number"
                            min="1"
                            max="1000"
                            class="mt-2 block w-full rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                        />
                        <InputError :message="err(i, 'credit_cost')" class="mt-1" />
                    </div>
                </div>

                <!-- Parameters -->
                <div class="mt-5 border-t border-border-line pt-4">
                    <div class="flex items-center justify-between">
                        <InputLabel value="Parameters" />
                        <button type="button" class="font-mono text-xs text-ink-dim hover:text-ink" @click="addParam(i)">
                            + Add parameter
                        </button>
                    </div>
                    <p class="mt-0.5 text-xs text-ink-dim">
                        The arguments the agent collects and sends to n8n. Leave empty for a no-argument trigger.
                    </p>

                    <div v-if="action.parameters.length" class="mt-3 space-y-2">
                        <div
                            v-for="(param, j) in action.parameters"
                            :key="j"
                            class="grid items-start gap-2 sm:grid-cols-12"
                        >
                            <div class="sm:col-span-3">
                                <input
                                    v-model="param.name"
                                    type="text"
                                    maxlength="64"
                                    aria-label="Parameter name"
                                    class="block w-full rounded-none border-border-hi bg-bg font-mono text-xs text-ink focus:border-ink focus:ring-1 focus:ring-ink"
                                    placeholder="order_id"
                                />
                                <InputError :message="paramErr(i, j, 'name')" class="mt-1" />
                            </div>
                            <div class="sm:col-span-2">
                                <select
                                    v-model="param.type"
                                    aria-label="Parameter type"
                                    class="block w-full rounded-none border-border-hi bg-bg text-xs text-ink focus:border-ink focus:ring-1 focus:ring-ink"
                                >
                                    <option v-for="t in paramTypes" :key="t" :value="t">{{ t }}</option>
                                </select>
                            </div>
                            <div class="sm:col-span-5">
                                <input
                                    v-model="param.description"
                                    type="text"
                                    maxlength="200"
                                    aria-label="Parameter description"
                                    class="block w-full rounded-none border-border-hi bg-bg text-xs text-ink focus:border-ink focus:ring-1 focus:ring-ink"
                                    placeholder="The order number to look up"
                                />
                            </div>
                            <div class="flex items-center justify-between sm:col-span-2">
                                <label class="flex items-center gap-1.5 text-xs text-ink-dim">
                                    <input
                                        v-model="param.required"
                                        type="checkbox"
                                        class="rounded-none border-border-hi text-ink focus:ring-ink"
                                    />
                                    Required
                                </label>
                                <button
                                    type="button"
                                    aria-label="Remove parameter"
                                    class="font-mono text-xs text-state-bad-ink hover:underline"
                                    @click="removeParam(i, j)"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="form.automations.length" class="flex justify-start">
                <SecondaryButton @click="addAction">+ Add another action</SecondaryButton>
            </div>

            <!-- Save / publish -->
            <div class="flex flex-wrap items-center gap-3 border-t border-border-line pt-5">
                <SecondaryButton :disabled="form.processing || !dirty" @click="save">
                    {{ form.processing ? 'Saving…' : 'Save draft' }}
                </SecondaryButton>
                <PrimaryButton :disabled="form.processing || publishForm.processing" @click="publish">
                    {{ publishForm.processing ? 'Publishing…' : 'Publish' }}
                </PrimaryButton>
                <p class="text-xs text-ink-dim">
                    Publishing stages every draft change — behavior and actions — live on the next message.
                </p>
            </div>
            </template>
        </div>
    </AppLayout>
</template>
