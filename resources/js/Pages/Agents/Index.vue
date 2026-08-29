<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
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

const page = usePage();
const billing = computed(() => page.props.billing ?? null);
const planLimitFlash = computed(() => page.props.flash?.plan_limit ?? null);

// Plan gate. `max_agents >= 9999` is the sentinel for "unlimited" tiers; below
// that, the New-agent button locks once `agents_count` hits the cap. The
// server enforces the same limit and redirects-back with a `plan_limit` flash —
// this is the client-side preview so users see WHY before they click.
const atPlanLimit = computed(() => {
    if (!billing.value) return false;
    if (billing.value.max_agents >= 9999) return false;
    return billing.value.agents_count >= billing.value.max_agents;
});

const showCreate = ref(false);
const form = useForm({ name: '' });

function openCreate() {
    if (atPlanLimit.value) return;
    showCreate.value = true;
}

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

// "5 min ago" style for the last-check column — way easier to scan than full
// localised timestamps when most rows checked in the last hour.
function relativeTime(iso) {
    if (!iso) return '—';
    const diff = (Date.now() - new Date(iso).getTime()) / 1000;
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)} min ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)} h ago`;
    if (diff < 7 * 86400) return `${Math.floor(diff / 86400)} d ago`;
    return new Date(iso).toLocaleDateString();
}
</script>

<template>
    <AppLayout title="Agents">
        <PageHeader width="max-w-6xl"
            title="Agents"
            description="Each agent qualifies leads in one channel or persona. Switch between them from the top-left."
        >
            <template #actions>
                <span v-if="billing && billing.max_agents < 9999" class="font-mono text-xs text-ink-dim">
                    {{ billing.agents_count }} / {{ billing.max_agents }} on {{ billing.plan_label }}
                </span>
                <PrimaryButton
                    :disabled="atPlanLimit"
                    :class="{ 'opacity-50 cursor-not-allowed': atPlanLimit }"
                    :title="atPlanLimit ? `Your ${billing.plan_label} plan allows ${billing.max_agents} agents. Upgrade for more.` : null"
                    @click="openCreate"
                >
                    New agent
                </PrimaryButton>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-6xl space-y-4 px-4 sm:px-6 lg:px-8">
                <!-- Server-side plan-limit flash. Lands after the controller
                     blocks an over-cap agents.store. Self-dismisses on the
                     next non-redirect navigation. -->
                <div v-if="planLimitFlash" class="flex items-start gap-3 rounded-none border border-state-warn-line bg-state-warn-surface px-4 py-3 text-sm text-state-warn-ink">
                    <svg class="mt-0.5 size-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.732 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <div>{{ planLimitFlash.message }}</div>
                </div>

                <!-- Inline upgrade CTA when at plan limit. Shows even before
                     the user tries to click "New agent" so the next move is
                     obvious. Routes to the Billing page where the upgrade
                     happens; matches the same Stripe Checkout flow as a
                     direct subscribe click. -->
                <div
                    v-if="atPlanLimit && billing?.plan_label !== 'Custom'"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-none border border-border-hi bg-bg-elev p-4 text-sm"
                >
                    <div>
                        <p class="font-semibold text-ink">
                            You've hit your plan's agent limit
                        </p>
                        <p class="mt-0.5 text-xs text-ink-dim">
                            The <span class="font-medium">{{ billing.plan_label }}</span> plan allows {{ billing.max_agents }} agent{{ billing.max_agents === 1 ? '' : 's' }}.
                            Upgrade to {{ billing.plan_label === 'Starter' ? 'Operator (5 agents)' : 'Custom (unlimited)' }} to add more.
                        </p>
                    </div>
                    <Link :href="route('billing.index')" class="btn-signal rounded-none border px-3 py-1.5 font-mono text-xs font-medium">
                        Upgrade plan →
                    </Link>
                </div>

                <div class="overflow-x-auto rounded-none border border-border-line bg-bg shadow-sheet">
                    <table class="min-w-full divide-y divide-border-line text-sm">
                        <thead class="bg-bg-elev">
                            <tr>
                                <th class="px-4 py-2.5 text-left font-mono text-xs font-semibold uppercase tracking-wider text-ink-dim">Name</th>
                                <th class="px-4 py-2.5 text-left font-mono text-xs font-semibold uppercase tracking-wider text-ink-dim">Status</th>
                                <th class="px-4 py-2.5 text-left font-mono text-xs font-semibold uppercase tracking-wider text-ink-dim">Last health check</th>
                                <th class="px-4 py-2.5 text-right" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-line bg-bg">
                            <tr
                                v-for="(agent, i) in agents"
                                :key="agent.id"
                                class="hover:bg-surface-hi"
                                :class="agent.is_current ? 'border-l-2 border-violet' : 'border-l-2 border-transparent'"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex flex-col gap-0.5">
                                        <span class="bp-ref">AGENT/{{ String(i + 1).padStart(2, '0') }}</span>
                                        <div>
                                            <Link :href="route('agents.show', agent.slug)" class="font-medium text-ink hover:underline">
                                                {{ agent.name }}
                                            </Link>
                                            <span v-if="agent.is_current" class="ml-2 inline-flex rounded-none bg-ink px-2 py-0.5 font-mono text-xs font-medium text-bg">
                                                Current
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        class="ins-stamp"
                                        :class="agent.status === 'active' ? 'text-state-ok-ink' : 'text-ink-dim'"
                                    >
                                        {{ agent.status === 'active' ? 'Live' : agent.status }}
                                    </span>
                                    <span v-if="!agent.is_configured" class="ml-2 text-xs text-state-warn-ink">Needs credentials</span>
                                    <span v-else-if="!agent.last_health_ok" class="ml-2 text-xs text-state-bad-ink">Health check failing</span>
                                </td>
                                <td class="px-4 py-3 text-ink-dim" :title="agent.last_health_check_at ? new Date(agent.last_health_check_at).toLocaleString() : ''">
                                    {{ relativeTime(agent.last_health_check_at) }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="!agent.is_current"
                                        type="button"
                                        class="mr-3 text-sm text-ink-dim hover:text-ink"
                                        @click="makeCurrent(agent)"
                                    >
                                        Make current
                                    </button>
                                    <Link :href="route('agents.show', agent.slug)" class="text-sm font-medium text-ink underline hover:text-ink-dim">
                                        Settings →
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="!agents.length">
                                <td colspan="4">
                                    <EmptyState
                                        title="No agents yet"
                                        description="Create your first agent to start qualifying inbound leads automatically."
                                    >
                                        <template #actions>
                                            <PrimaryButton :disabled="atPlanLimit" :class="{ 'opacity-50': atPlanLimit }" @click="openCreate">
                                                Create your first agent
                                            </PrimaryButton>
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
                <p class="text-sm text-ink-dim">
                    Give it a name. We'll provision the rest automatically — usually under 10 seconds.
                </p>
                <div class="mt-4">
                    <InputLabel for="name" value="Agent name" />
                    <TextInput id="name" v-model="form.name" type="text" class="mt-1 block w-full" placeholder="e.g. Sales bot" />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
            </template>
            <template #footer>
                <SecondaryButton @click="showCreate = false">Cancel</SecondaryButton>
                <PrimaryButton class="sm:ms-3" :class="{ 'opacity-50': form.processing }" :disabled="form.processing" @click="submit">
                    Create
                </PrimaryButton>
            </template>
        </DialogModal>
    </AppLayout>
</template>
