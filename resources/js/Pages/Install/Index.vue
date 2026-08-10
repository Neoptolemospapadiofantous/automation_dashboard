<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    agent: { type: Object, default: null },
});

const page = usePage();

const widgetUrl = computed(() =>
    props.agent ? `${window.location.origin}/widget/${props.agent.slug}.js` : null,
);
const previewUrl = computed(() =>
    props.agent ? `${window.location.origin}/embed/${props.agent.slug}` : null,
);
const snippet = computed(() =>
    widgetUrl.value ? `<script src="${widgetUrl.value}" defer><\/script>` : '',
);

// Three installation styles users can pick from. The default snippet
// drops in a floating button. The other two demonstrate the same widget
// but invoked differently — pure positioning/copy variations for now,
// no schema change required.
const installVariants = [
    {
        id: 'floating',
        title: 'Floating button (recommended)',
        description: 'Bottom-right corner of every page. Mobile takes over the full screen below 480px.',
        snippet: () => `<script src="${widgetUrl.value}" defer><\/script>`,
    },
    {
        id: 'wordpress',
        title: 'WordPress / Squarespace',
        description: 'Add to the Custom HTML block, the theme footer, or the site footer via the editor.',
        snippet: () => `<!-- Flowstack chat widget -->
<script src="${widgetUrl.value}" defer><\/script>`,
    },
    {
        id: 'react',
        title: 'React / Next.js',
        description: 'Mount once in your app root (e.g. _app.tsx or layout.tsx).',
        snippet: () => `useEffect(() => {
    const s = document.createElement('script');
    s.src = '${widgetUrl.value}';
    s.defer = true;
    document.body.appendChild(s);
    return () => { s.remove(); };
}, []);`,
    },
];

const activeVariant = ref('floating');
const currentSnippet = computed(() =>
    installVariants.find((v) => v.id === activeVariant.value)?.snippet() ?? '',
);

const copyState = ref('idle');
async function copy() {
    if (!currentSnippet.value) return;
    try {
        await navigator.clipboard.writeText(currentSnippet.value);
        copyState.value = 'copied';
        setTimeout(() => (copyState.value = 'idle'), 2000);
    } catch (e) {
        copyState.value = 'idle';
    }
}

// --- Widget customization form -------------------------------------------
// Initialized from the agent's persisted widget_config + allowed_domains.
// The PUT to install.update accepts the flat widget_config fields plus the
// allowed_domains array.
const cfg = props.agent?.widget_config ?? {};
const form = useForm({
    accent_color: cfg.accent_color ?? '#4f46e5',
    text_color: cfg.text_color ?? '#ffffff',
    position: cfg.position ?? 'right',
    launcher_text: cfg.launcher_text ?? '',
    title: cfg.title ?? '',
    subtitle: cfg.subtitle ?? '',
    avatar_url: cfg.avatar_url ?? '',
    proactive_message: cfg.proactive_message ?? '',
    proactive_delay: cfg.proactive_delay ?? 0,
    auto_open: cfg.auto_open ?? false,
    show_branding: cfg.show_branding ?? true,
    allowed_domains: [...(props.agent?.allowed_domains ?? [])],
});

function save() {
    router.put(route('install.update'), { ...form.data() }, {
        preserveScroll: true,
        onStart: () => { form.processing = true; form.clearErrors(); },
        onSuccess: () => { form.recentlySuccessful = true; setTimeout(() => (form.recentlySuccessful = false), 2500); },
        onError: (errors) => { form.errors = errors; },
        onFinish: () => { form.processing = false; },
    });
}

const savedFlash = computed(
    () => page.props.flash?.status === 'widget-updated' || form.recentlySuccessful,
);

// --- Allowed-domains list editor -----------------------------------------
const newDomain = ref('');
function addDomain() {
    const d = newDomain.value.trim().toLowerCase();
    if (!d) return;
    if (!form.allowed_domains.includes(d) && form.allowed_domains.length < 50) {
        form.allowed_domains.push(d);
    }
    newDomain.value = '';
}
function removeDomain(i) {
    form.allowed_domains.splice(i, 1);
}

// --- Live preview helpers -------------------------------------------------
const previewTitle = computed(() => form.title || props.agent?.name || 'Your agent');
const previewLauncher = computed(() => form.launcher_text || 'Chat with us');
</script>

<template>
    <AppLayout title="Install">
        <PageHeader
            title="Install on your website"
            description="One snippet drops your agent onto every page of your site as a floating chat button. Pick the install style, copy, paste, done."
        />

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- No-agent state -->
                <div v-if="!agent" class="rounded-none border border-dashed border-border-hi bg-bg p-8 text-center">
                    <h3 class="text-sm font-medium text-ink">No agent yet</h3>
                    <p class="mt-1 text-xs text-ink-dim">
                        Create an agent first — then this page will show the snippet to install.
                    </p>
                    <Link :href="route('agents.index')" class="mt-4 inline-block">
                        <PrimaryButton>Create an agent</PrimaryButton>
                    </Link>
                </div>

                <!-- Inactive agent state -->
                <div v-else-if="agent.status !== 'active'" class="rounded-none border border-state-warn-line bg-state-warn-surface p-4 text-sm text-state-warn-ink">
                    Your agent <span class="font-medium">{{ agent.name }}</span> is currently <span class="font-medium">{{ agent.status }}</span>.
                    The widget will not load on customer websites until it's active.
                </div>

                <template v-else>
                    <!-- Variant tabs -->
                    <div class="rounded-none border border-border-line bg-bg p-1">
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="v in installVariants"
                                :key="v.id"
                                type="button"
                                class="flex-1 rounded-none px-4 py-2 text-sm font-medium transition"
                                :class="activeVariant === v.id
                                    ? 'bg-ink text-bg'
                                    : 'text-ink-dim hover:bg-surface-hi'"
                                @click="activeVariant = v.id"
                            >
                                {{ v.title }}
                            </button>
                        </div>
                    </div>

                    <!-- Snippet block -->
                    <div class="bp-node shadow-sheet rounded-none p-6">
                        <div class="mb-4 flex items-center justify-between">
                            <span class="bp-ref">INSTALL/EMBED</span>
                            <span class="bp-annot">{{ agent.slug }}</span>
                        </div>
                        <p class="text-sm text-ink-dim">
                            {{ installVariants.find((v) => v.id === activeVariant)?.description }}
                        </p>

                        <div class="mt-4 rounded-none border border-border-line bg-bg-elev p-4 font-mono text-[12px] leading-relaxed text-ink-dim">
                            <pre class="whitespace-pre-wrap break-all">{{ currentSnippet }}</pre>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <PrimaryButton type="button" @click="copy">
                                {{ copyState === 'copied' ? '✓ Copied' : 'Copy snippet' }}
                            </PrimaryButton>
                            <a
                                :href="previewUrl"
                                target="_blank"
                                rel="noopener"
                                class="text-xs font-medium text-violet underline hover:no-underline"
                            >
                                Live preview ↗
                            </a>
                            <a
                                :href="widgetUrl"
                                target="_blank"
                                rel="noopener"
                                class="text-xs text-ink-dim underline hover:text-ink"
                            >
                                View raw widget.js
                            </a>
                        </div>
                    </div>

                    <!-- Section divider into the customize block -->
                    <div class="flex items-center gap-3 py-1">
                        <span class="bp-ref shrink-0">WIDGET/CUSTOMIZE</span>
                        <div class="bp-dim flex-1" />
                    </div>

                    <!-- Customize widget -->
                    <form class="bp-node shadow-sheet rounded-none p-6" @submit.prevent="save">
                        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-ink">Customize widget</h3>
                                <p class="mt-1 text-sm text-ink-dim">
                                    Style the floating launcher and chat panel. Changes apply everywhere
                                    <span class="font-medium">{{ agent.name }}</span> is embedded.
                                </p>
                            </div>
                            <span class="bp-annot">{{ agent.slug }}</span>
                        </div>

                        <div class="grid gap-6 lg:grid-cols-[1fr_18rem]">
                            <!-- Controls -->
                            <div class="space-y-6">
                                <!-- Colors -->
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <InputLabel for="accent_color" value="Accent color" />
                                        <div class="mt-1 flex items-center gap-2">
                                            <input
                                                id="accent_color"
                                                v-model="form.accent_color"
                                                type="color"
                                                class="h-9 w-12 flex-shrink-0 cursor-pointer rounded-none border border-border-hi bg-bg p-0.5"
                                            >
                                            <TextInput
                                                v-model="form.accent_color"
                                                type="text"
                                                class="block w-full font-mono text-sm"
                                                placeholder="#4f46e5"
                                                maxlength="7"
                                            />
                                        </div>
                                        <InputError :message="form.errors.accent_color" class="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel for="text_color" value="Text color" />
                                        <div class="mt-1 flex items-center gap-2">
                                            <input
                                                id="text_color"
                                                v-model="form.text_color"
                                                type="color"
                                                class="h-9 w-12 flex-shrink-0 cursor-pointer rounded-none border border-border-hi bg-bg p-0.5"
                                            >
                                            <TextInput
                                                v-model="form.text_color"
                                                type="text"
                                                class="block w-full font-mono text-sm"
                                                placeholder="#ffffff"
                                                maxlength="7"
                                            />
                                        </div>
                                        <InputError :message="form.errors.text_color" class="mt-1" />
                                    </div>
                                </div>

                                <!-- Position toggle -->
                                <div>
                                    <InputLabel value="Position" />
                                    <div class="mt-1 inline-flex rounded-none border border-border-line bg-bg p-1">
                                        <button
                                            v-for="pos in ['left', 'right']"
                                            :key="pos"
                                            type="button"
                                            class="rounded-none px-4 py-1.5 text-sm font-medium capitalize transition"
                                            :class="form.position === pos ? 'bg-ink text-bg' : 'text-ink-dim hover:bg-surface-hi'"
                                            @click="form.position = pos"
                                        >
                                            {{ pos }}
                                        </button>
                                    </div>
                                    <InputError :message="form.errors.position" class="mt-1" />
                                </div>

                                <!-- Text fields -->
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <InputLabel for="launcher_text" value="Launcher text" />
                                        <TextInput id="launcher_text" v-model="form.launcher_text" type="text" class="mt-1 block w-full" placeholder="Chat with us" maxlength="255" />
                                        <InputError :message="form.errors.launcher_text" class="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel for="title" value="Title" />
                                        <TextInput id="title" v-model="form.title" type="text" class="mt-1 block w-full" :placeholder="agent.name" maxlength="255" />
                                        <InputError :message="form.errors.title" class="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel for="subtitle" value="Subtitle" />
                                        <TextInput id="subtitle" v-model="form.subtitle" type="text" class="mt-1 block w-full" placeholder="We typically reply in a few minutes" maxlength="255" />
                                        <InputError :message="form.errors.subtitle" class="mt-1" />
                                    </div>
                                    <div>
                                        <InputLabel for="avatar_url" value="Avatar URL" />
                                        <TextInput id="avatar_url" v-model="form.avatar_url" type="url" class="mt-1 block w-full" placeholder="https://…/avatar.png" />
                                        <InputError :message="form.errors.avatar_url" class="mt-1" />
                                    </div>
                                </div>

                                <!-- Proactive message -->
                                <div>
                                    <InputLabel for="proactive_message" value="Proactive message" />
                                    <textarea
                                        id="proactive_message"
                                        v-model="form.proactive_message"
                                        rows="2"
                                        class="mt-1 block w-full rounded-none border-border-hi bg-bg text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                                        placeholder="Hi there 👋 Need a hand with anything?"
                                    />
                                    <p class="mt-1 text-xs text-ink-dim">Shown as a teaser bubble before the visitor opens the chat.</p>
                                    <InputError :message="form.errors.proactive_message" class="mt-1" />
                                </div>

                                <div>
                                    <InputLabel for="proactive_delay" value="Proactive delay (seconds)" />
                                    <TextInput id="proactive_delay" v-model="form.proactive_delay" type="number" min="0" max="120" class="mt-1 block w-32" />
                                    <p class="mt-1 text-xs text-ink-dim">0–120 seconds after the page loads.</p>
                                    <InputError :message="form.errors.proactive_delay" class="mt-1" />
                                </div>

                                <!-- Toggles -->
                                <div class="space-y-3">
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input v-model="form.auto_open" type="checkbox" class="mt-0.5 rounded-none border-border-hi text-ink focus:ring-2 focus:ring-ink focus:ring-offset-1">
                                        <span>
                                            <span class="block text-sm font-medium text-ink">Auto-open chat</span>
                                            <span class="block text-xs text-ink-dim">Open the chat panel automatically on first visit.</span>
                                        </span>
                                    </label>
                                    <InputError :message="form.errors.auto_open" />
                                    <label class="flex cursor-pointer items-start gap-3">
                                        <input v-model="form.show_branding" type="checkbox" class="mt-0.5 rounded-none border-border-hi text-ink focus:ring-2 focus:ring-ink focus:ring-offset-1">
                                        <span>
                                            <span class="block text-sm font-medium text-ink">Show "Powered by" branding</span>
                                            <span class="block text-xs text-ink-dim">Display a small Flowstack credit in the chat footer.</span>
                                        </span>
                                    </label>
                                    <InputError :message="form.errors.show_branding" />
                                </div>
                            </div>

                            <!-- Live preview -->
                            <div class="rounded-none border border-border-line bg-bg-elev p-4">
                                <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Live preview</p>
                                <div class="relative mt-3 h-64 overflow-hidden rounded-none border border-border-line bg-surface-hi">
                                    <!-- Proactive teaser -->
                                    <div
                                        v-if="form.proactive_message"
                                        class="absolute bottom-16 max-w-[12rem] rounded-none border border-border-line bg-bg px-3 py-2 text-xs text-ink shadow-sheet"
                                        :class="form.position === 'left' ? 'left-3' : 'right-3'"
                                    >
                                        {{ form.proactive_message }}
                                    </div>
                                    <!-- Launcher -->
                                    <div
                                        class="absolute bottom-3 flex items-center gap-2 rounded-none px-4 py-2.5 text-sm font-medium shadow-sheet"
                                        :class="form.position === 'left' ? 'left-3' : 'right-3'"
                                        :style="{ backgroundColor: form.accent_color, color: form.text_color }"
                                    >
                                        <img v-if="form.avatar_url" :src="form.avatar_url" alt="" class="h-5 w-5 rounded-none object-cover">
                                        <span>{{ previewLauncher }}</span>
                                    </div>
                                </div>
                                <p class="mt-3 text-sm font-medium text-ink">{{ previewTitle }}</p>
                                <p v-if="form.subtitle" class="text-xs text-ink-dim">{{ form.subtitle }}</p>
                            </div>
                        </div>

                        <div class="mt-6 flex items-center gap-3 border-t border-border-line pt-4">
                            <PrimaryButton type="submit" :disabled="form.processing" :class="{ 'opacity-50': form.processing }">
                                Save
                            </PrimaryButton>
                            <span v-if="savedFlash" class="text-xs font-medium text-state-ok-ink">✓ Saved</span>
                        </div>
                    </form>

                    <!-- Allowed domains -->
                    <div class="rounded-none border border-border-line bg-bg p-6">
                        <h3 class="text-base font-semibold text-ink">Allowed domains</h3>
                        <p class="mt-1 text-sm text-ink-dim">
                            Empty = embeddable anywhere. Add hosts like
                            <code class="rounded-none bg-surface-hi px-1 py-0.5 text-[11px]">acme.com</code> or
                            <code class="rounded-none bg-surface-hi px-1 py-0.5 text-[11px]">*.acme.com</code>
                            to restrict where this widget can load.
                        </p>

                        <div class="mt-4 flex flex-wrap items-end gap-2">
                            <div class="w-full flex-1 sm:min-w-[16rem]">
                                <InputLabel for="new_domain" value="Add a host" />
                                <TextInput
                                    id="new_domain"
                                    v-model="newDomain"
                                    type="text"
                                    class="mt-1 block w-full font-mono text-sm"
                                    placeholder="acme.com"
                                    @keydown.enter.prevent="addDomain"
                                />
                            </div>
                            <PrimaryButton type="button" :disabled="form.allowed_domains.length >= 50" @click="addDomain">
                                Add
                            </PrimaryButton>
                        </div>
                        <InputError :message="form.errors.allowed_domains" class="mt-1" />

                        <ul v-if="form.allowed_domains.length" class="mt-4 flex flex-wrap gap-2">
                            <li
                                v-for="(domain, i) in form.allowed_domains"
                                :key="domain"
                                class="inline-flex items-center gap-2 rounded-none border border-border-line bg-surface-hi px-2.5 py-1 font-mono text-xs text-ink"
                            >
                                {{ domain }}
                                <button
                                    type="button"
                                    class="text-ink-mute hover:text-state-bad-ink"
                                    :aria-label="`Remove ${domain}`"
                                    @click="removeDomain(i)"
                                >
                                    ×
                                </button>
                            </li>
                        </ul>
                        <p v-else class="mt-4 text-xs text-ink-mute">No restrictions — embeddable on any domain.</p>

                        <div class="mt-4 flex items-center gap-3 border-t border-border-line pt-4">
                            <PrimaryButton type="button" :disabled="form.processing" :class="{ 'opacity-50': form.processing }" @click="save">
                                Save domains
                            </PrimaryButton>
                            <span v-if="savedFlash" class="text-xs font-medium text-state-ok-ink">✓ Saved</span>
                        </div>
                    </div>

                    <!-- Step-by-step install -->
                    <div class="rounded-none border border-border-line bg-bg p-6">
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <h3 class="text-base font-semibold text-ink">Install in 3 steps</h3>
                            <span class="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">
                                <span class="bp-dot" />paste<span class="bp-wire inline-block w-6" aria-hidden="true" /><span class="bp-dot" />live
                            </span>
                        </div>
                        <ol class="mt-4 space-y-4">
                            <li class="flex gap-4">
                                <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-none border border-ink bg-bg font-mono text-xs font-semibold text-ink">1</span>
                                <div>
                                    <p class="text-sm font-medium text-ink">Copy the snippet above.</p>
                                    <p class="mt-0.5 text-xs text-ink-dim">
                                        Click the copy button. The snippet is unique to <span class="font-medium">{{ agent.name }}</span>.
                                    </p>
                                </div>
                            </li>
                            <li class="flex gap-4">
                                <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-none border border-ink bg-bg font-mono text-xs font-semibold text-ink">2</span>
                                <div>
                                    <p class="text-sm font-medium text-ink">Paste it into your website's HTML.</p>
                                    <p class="mt-0.5 text-xs text-ink-dim">
                                        Anywhere before <code class="rounded-none bg-surface-hi px-1 py-0.5 text-[11px]">&lt;/body&gt;</code> works. For
                                        WordPress, add it to the theme footer or use a "custom HTML" block.
                                    </p>
                                </div>
                            </li>
                            <li class="flex gap-4">
                                <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-none border border-ink bg-bg font-mono text-xs font-semibold text-ink">3</span>
                                <div>
                                    <p class="text-sm font-medium text-ink">Reload your site.</p>
                                    <p class="mt-0.5 text-xs text-ink-dim">
                                        A floating chat button appears bottom-right. Click it → your agent's chat opens in a popover.
                                        Conversations and leads land in this dashboard.
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <!-- Facts -->
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-none border border-border-line bg-bg p-4">
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Mobile</p>
                            <p class="mt-2 text-sm text-ink-dim">Full-screen takeover under 480px wide.</p>
                        </div>
                        <div class="rounded-none border border-border-line bg-bg p-4">
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Sessions</p>
                            <p class="mt-2 text-sm text-ink-dim">
                                Visitors get a 30-day cookie scoped to your agent. Returning visitors continue their thread.
                            </p>
                        </div>
                        <div class="rounded-none border border-border-line bg-bg p-4">
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-mute">Billing</p>
                            <p class="mt-2 text-sm text-ink-dim">
                                Embedded conversations debit credits from your team, same as dashboard chats.
                            </p>
                        </div>
                    </div>

                    <!-- Troubleshooting -->
                    <details class="rounded-none border border-border-line bg-bg p-6">
                        <summary class="cursor-pointer text-base font-semibold text-ink">Troubleshooting</summary>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div>
                                <dt class="font-medium text-ink">The button doesn't appear.</dt>
                                <dd class="mt-1 text-xs text-ink-dim">
                                    Make sure the snippet sits inside <code>&lt;body&gt;</code> (not <code>&lt;head&gt;</code>),
                                    and check the browser console for errors. Browser extensions blocking
                                    third-party scripts can also hide it.
                                </dd>
                            </div>
                            <div>
                                <dt class="font-medium text-ink">It says "agent not found".</dt>
                                <dd class="mt-1 text-xs text-ink-dim">
                                    Your agent is paused or disabled. Activate it on the Agents page.
                                </dd>
                            </div>
                            <div>
                                <dt class="font-medium text-ink">Customers report "temporarily unavailable".</dt>
                                <dd class="mt-1 text-xs text-ink-dim">
                                    Your team is out of credits. Check the Billing page and top up to resume.
                                </dd>
                            </div>
                        </dl>
                    </details>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
