<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { confirm } from '@/Composables/useConfirm';

const props = defineProps({
    agent: { type: Object, required: true },
    /**
     * Always null since Phase 14 — kept as a prop for backwards
     * compatibility with the controller's `webhook_url` field, which
     * still ships in the payload.
     */
    webhook_url: { type: String, default: null },
    is_current: { type: Boolean, default: false },
    activity: { type: Object, default: () => ({ leads: 0, leads_qualified: 0, conversations: 0, messages: 0, last_message_at: null }) },
});

const lastActivityLabel = computed(() => {
    if (!props.activity?.last_message_at) return 'No activity yet';
    const diffMs = Date.now() - new Date(props.activity.last_message_at).getTime();
    const min = Math.floor(diffMs / 60_000);
    if (min < 1) return 'Active just now';
    if (min < 60) return `Last activity ${min} min ago`;
    const hr = Math.floor(min / 60);
    if (hr < 24) return `Last activity ${hr} h ago`;
    return `Last activity ${Math.floor(hr / 24)} d ago`;
});

// Phase 14: the settings page is managed-view only. BYOK was removed
// from the product surface; the user can rename the agent and that's it.
const form = useForm({ name: props.agent.name });

function save() {
    form.put(route('agents.update', props.agent.slug), { preserveScroll: true });
}

// --- Embed snippet --------------------------------------------------------
const widgetUrl = computed(() => `${window.location.origin}/widget/${props.agent.slug}.js`);
const embedPreviewUrl = computed(() => `${window.location.origin}/embed/${props.agent.slug}`);
const embedSnippet = computed(() => `<script src="${widgetUrl.value}" defer><\/script>`);

const copyState = ref('idle');
async function copyEmbedSnippet() {
    try {
        await navigator.clipboard.writeText(embedSnippet.value);
        copyState.value = 'copied';
        setTimeout(() => (copyState.value = 'idle'), 2000);
    } catch (e) {
        copyState.value = 'idle';
    }
}

async function destroy() {
    const ok = await confirm({
        title: 'Delete agent',
        message: `Delete agent "${props.agent.name}"? Conversations and leads stay, but lose their agent link.`,
        buttonText: 'Delete',
        dangerous: true,
    });
    if (!ok) return;
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
                <!-- Activity counters: pulse-check + cross-links to the
                     relevant lists. Counters are click-throughs so the
                     operator can pivot from "what's going on" to "show me." -->
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Link
                        :href="route('leads.index')"
                        class="rounded-xl bg-white p-4 shadow ring-1 ring-black/5 transition hover:ring-indigo-200"
                    >
                        <div class="text-xs uppercase tracking-wide text-gray-400">Leads</div>
                        <div class="mt-1 flex items-baseline gap-1.5">
                            <span class="text-2xl font-semibold text-gray-900">{{ activity.leads.toLocaleString() }}</span>
                            <span v-if="activity.leads_qualified" class="text-xs text-green-700">· {{ activity.leads_qualified }} qualified</span>
                        </div>
                    </Link>
                    <Link
                        :href="route('conversations.index')"
                        class="rounded-xl bg-white p-4 shadow ring-1 ring-black/5 transition hover:ring-indigo-200"
                    >
                        <div class="text-xs uppercase tracking-wide text-gray-400">Conversations</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900">{{ activity.conversations.toLocaleString() }}</div>
                    </Link>
                    <div class="rounded-xl bg-white p-4 shadow ring-1 ring-black/5">
                        <div class="text-xs uppercase tracking-wide text-gray-400">Messages</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900">{{ activity.messages.toLocaleString() }}</div>
                    </div>
                    <div class="rounded-xl bg-white p-4 shadow ring-1 ring-black/5">
                        <div class="text-xs uppercase tracking-wide text-gray-400">Pulse</div>
                        <div class="mt-1 text-sm font-medium" :class="activity.last_message_at ? 'text-gray-900' : 'text-gray-400'">
                            {{ lastActivityLabel }}
                        </div>
                    </div>
                </div>

                <!-- Analytics deep-link. Shows charts, funnel, sources, hourly. -->
                <Link
                    :href="route('agents.analytics', agent.slug)"
                    class="flex items-center justify-between rounded-xl border border-indigo-200 bg-indigo-50/40 p-4 transition hover:bg-indigo-50"
                >
                    <div>
                        <p class="text-sm font-semibold text-gray-900">📊 Analytics for this agent</p>
                        <p class="mt-0.5 text-xs text-gray-600">
                            7/30/90-day trends · funnel · top sources · hourly activity heatmap
                        </p>
                    </div>
                    <span class="text-sm text-indigo-700">View →</span>
                </Link>

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

                <!-- Embed snippet — the HTML the customer pastes into their own
                     website's <head> or before </body>. Renders the floating
                     chat widget that opens an iframe to /embed/{slug}. -->
                <div class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Embed on your website</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Paste this snippet into your website's HTML — anywhere before <code class="rounded bg-gray-100 px-1 py-0.5 text-[11px]">&lt;/body&gt;</code> works. A floating chat
                                button appears bottom-right; clicking it opens this agent's chat in an iframe.
                            </p>
                        </div>
                        <a
                            :href="embedPreviewUrl"
                            target="_blank"
                            rel="noopener"
                            class="rounded-md border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Preview ↗
                        </a>
                    </div>

                    <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-3 font-mono text-[12px] leading-relaxed text-gray-700">
                        <pre class="whitespace-pre-wrap break-all">{{ embedSnippet }}</pre>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <PrimaryButton type="button" @click="copyEmbedSnippet">
                            {{ copyState === 'copied' ? '✓ Copied' : 'Copy snippet' }}
                        </PrimaryButton>
                        <a
                            :href="widgetUrl"
                            target="_blank"
                            rel="noopener"
                            class="text-xs text-indigo-600 hover:text-indigo-800"
                        >
                            View raw widget.js
                        </a>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3 text-xs text-gray-500">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Mobile</p>
                            <p class="mt-1">Full-screen takeover under 480px wide.</p>
                        </div>
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Sessions</p>
                            <p class="mt-1">Visitor cookies are 30-day, scoped to the agent — return visitors continue their thread.</p>
                        </div>
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Billing</p>
                            <p class="mt-1">Embedded conversations debit credits from this team, same as dashboard chats.</p>
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
