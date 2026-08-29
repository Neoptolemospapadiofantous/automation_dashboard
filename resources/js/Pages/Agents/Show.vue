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
    health: { type: Object, default: () => ({ ok: true, reason: null }) },
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
        <PageHeader width="max-w-4xl"
            :breadcrumbs="[{ label: 'Agents', href: route('agents.index') }, { label: agent.name }]"
            :title="agent.name"
            description="Provisioned automatically. The conversational engine is fully managed — you only need to manage the name."
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="inline-flex rounded-none px-2.5 py-1 font-mono text-xs font-medium" :class="{
                        'bg-state-ok-surface text-state-ok-ink': agent.status === 'active',
                        'bg-state-warn-surface text-state-warn-ink': agent.status === 'draft',
                        'bg-surface-hi text-ink-dim': agent.status === 'disabled',
                    }">
                        {{ agent.status }}
                    </span>
                    <span v-if="is_current" class="inline-flex rounded-none bg-ink px-2.5 py-1 font-mono text-xs font-medium text-bg">
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
                <div class="flex items-center justify-between">
                    <span class="bp-ref">AGENT/RUNTIME</span>
                    <span class="bp-annot">live activity · click any counter to drill in</span>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Link
                        :href="route('leads.index')"
                        class="rounded-none border border-border-line bg-bg p-4 shadow-sheet transition hover:border-ink"
                    >
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Leads</div>
                        <div class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-2xl font-semibold text-ink">{{ activity.leads.toLocaleString() }}</span>
                            <span v-if="activity.leads_qualified" class="text-xs text-state-ok-ink">· {{ activity.leads_qualified }} qualified</span>
                        </div>
                    </Link>
                    <Link
                        :href="route('conversations.index')"
                        class="rounded-none border border-border-line bg-bg p-4 transition hover:border-ink"
                    >
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Conversations</div>
                        <div class="mt-1 font-mono text-2xl font-semibold text-ink">{{ activity.conversations.toLocaleString() }}</div>
                    </Link>
                    <div class="rounded-none border border-border-line bg-bg p-4">
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Messages</div>
                        <div class="mt-1 font-mono text-2xl font-semibold text-ink">{{ activity.messages.toLocaleString() }}</div>
                    </div>
                    <div class="rounded-none border border-border-line bg-bg p-4">
                        <div class="font-mono text-xs uppercase tracking-wider text-ink-mute">Pulse</div>
                        <div class="mt-1 text-sm font-medium" :class="activity.last_message_at ? 'text-ink' : 'text-ink-mute'">
                            {{ lastActivityLabel }}
                        </div>
                    </div>
                </div>

                <!-- Analytics deep-link. Shows charts, funnel, sources, hourly. -->
                <Link
                    :href="route('agents.analytics', agent.slug)"
                    class="flex items-center justify-between rounded-none border border-border-hi bg-bg-elev p-4 transition hover:bg-surface-hi"
                >
                    <div>
                        <p class="text-sm font-semibold text-ink">📊 Analytics for this agent</p>
                        <p class="mt-0.5 text-xs text-ink-dim">
                            7/30/90-day trends · funnel · top sources · hourly activity heatmap
                        </p>
                    </div>
                    <span class="text-sm text-violet underline">View →</span>
                </Link>

                <form class="rounded-none border border-border-line bg-bg p-6 shadow-sheet" @submit.prevent="save">
                    <span class="bp-ref">AGENT/CONFIG</span>
                    <h3 class="mt-1 text-base font-semibold text-ink">Agent details</h3>
                    <p class="mt-1 text-sm text-ink-dim">
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
                        <span v-if="agent.last_health_check_at" class="text-xs text-ink-dim">
                            Checked {{ new Date(agent.last_health_check_at).toLocaleString() }} —
                            <span :class="health.ok ? 'text-state-ok-ink' : 'text-state-bad-ink'">
                                {{ health.ok ? '✓ healthy' : '✗ not answering' }}
                            </span>
                            <span v-if="!health.ok && health.reason" class="block text-state-bad-ink">{{ health.reason }}</span>
                        </span>
                    </div>
                </form>

                <div class="rounded-none border border-border-line bg-bg-elev p-4 text-sm text-ink-dim">
                    <div class="flex items-start gap-3">
                        <svg class="mt-0.5 size-4 flex-shrink-0 text-ink-mute" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />
                        </svg>
                        <div>
                            The conversational engine, API keys, and infrastructure are all provisioned and managed on your behalf. If something goes wrong, contact support — there's nothing here for you to tweak.
                        </div>
                    </div>
                </div>

                <!-- Section divider into the install / embed block -->
                <div class="flex items-center gap-3 py-1">
                    <span class="bp-ref shrink-0">AGENT/INSTALL</span>
                    <div class="bp-dim flex-1" />
                </div>

                <!-- Embed snippet — the HTML the customer pastes into their own
                     website's <head> or before </body>. Renders the floating
                     chat widget that opens an iframe to /embed/{slug}. -->
                <div class="bp-node relative rounded-none p-6 shadow-sheet">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-ink">Embed on your website</h3>
                            <p class="mt-1 text-sm text-ink-dim">
                                Paste this snippet into your website's HTML — anywhere before <code class="rounded-none bg-surface-hi px-1 py-0.5 text-[11px]">&lt;/body&gt;</code> works. A floating chat
                                button appears bottom-right; clicking it opens this agent's chat in an iframe.
                            </p>
                        </div>
                        <a
                            :href="embedPreviewUrl"
                            target="_blank"
                            rel="noopener"
                            class="rounded-none border border-ink bg-bg px-3 py-1.5 font-mono text-xs font-medium text-ink hover:bg-ink hover:text-bg"
                        >
                            Preview ↗
                        </a>
                    </div>

                    <div class="mt-4 rounded-none border border-border-line bg-bg-elev p-3 font-mono text-[12px] leading-relaxed text-ink-dim">
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
                            class="inline-block py-2 text-xs text-ink underline hover:text-ink-dim"
                        >
                            View raw widget.js
                        </a>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-3 text-xs text-ink-dim">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Mobile</p>
                            <p class="mt-1">Full-screen takeover under 480px wide.</p>
                        </div>
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Sessions</p>
                            <p class="mt-1">Visitor cookies are 30-day, scoped to the agent — return visitors continue their thread.</p>
                        </div>
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Billing</p>
                            <p class="mt-1">Embedded conversations debit credits from this team, same as dashboard chats.</p>
                        </div>
                    </div>
                </div>

                <!-- Danger zone -->
                <div class="rounded-none border border-state-bad-line bg-state-bad-surface p-6">
                    <h3 class="text-base font-semibold text-state-bad-ink">Danger zone</h3>
                    <p class="mt-1 text-sm text-state-bad-ink">
                        Deleting the agent unlinks (but does not delete) its leads and conversations.
                        Its underlying engine resources are retired and never reassigned to another customer.
                    </p>
                    <div class="mt-4">
                        <DangerButton @click="destroy">Delete agent</DangerButton>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
