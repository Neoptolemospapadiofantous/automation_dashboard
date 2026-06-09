<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    agent: { type: Object, default: null },
});

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
                <div v-if="!agent" class="rounded-xl border border-dashed border-gray-200 bg-white p-8 text-center">
                    <h3 class="text-sm font-medium text-gray-700">No agent yet</h3>
                    <p class="mt-1 text-xs text-gray-500">
                        Create an agent first — then this page will show the snippet to install.
                    </p>
                    <Link :href="route('agents.index')" class="mt-4 inline-block">
                        <PrimaryButton>Create an agent</PrimaryButton>
                    </Link>
                </div>

                <!-- Inactive agent state -->
                <div v-else-if="agent.status !== 'active'" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    Your agent <span class="font-medium">{{ agent.name }}</span> is currently <span class="font-medium">{{ agent.status }}</span>.
                    The widget will not load on customer websites until it's active.
                </div>

                <template v-else>
                    <!-- Variant tabs -->
                    <div class="rounded-xl bg-white p-1 shadow ring-1 ring-black/5">
                        <div class="flex flex-wrap gap-1">
                            <button
                                v-for="v in installVariants"
                                :key="v.id"
                                type="button"
                                class="flex-1 rounded-lg px-4 py-2 text-sm font-medium transition"
                                :class="activeVariant === v.id
                                    ? 'bg-indigo-600 text-white shadow-sm'
                                    : 'text-gray-600 hover:bg-gray-50'"
                                @click="activeVariant = v.id"
                            >
                                {{ v.title }}
                            </button>
                        </div>
                    </div>

                    <!-- Snippet block -->
                    <div class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5">
                        <p class="text-sm text-gray-600">
                            {{ installVariants.find((v) => v.id === activeVariant)?.description }}
                        </p>

                        <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 font-mono text-[12px] leading-relaxed text-gray-700">
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
                                class="text-xs text-indigo-600 hover:text-indigo-800"
                            >
                                Live preview ↗
                            </a>
                            <a
                                :href="widgetUrl"
                                target="_blank"
                                rel="noopener"
                                class="text-xs text-gray-500 hover:text-gray-700"
                            >
                                View raw widget.js
                            </a>
                        </div>
                    </div>

                    <!-- Step-by-step install -->
                    <div class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5">
                        <h3 class="text-base font-semibold text-gray-900">Install in 3 steps</h3>
                        <ol class="mt-4 space-y-4">
                            <li class="flex gap-4">
                                <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">1</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-800">Copy the snippet above.</p>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        Click the copy button. The snippet is unique to <span class="font-medium">{{ agent.name }}</span>.
                                    </p>
                                </div>
                            </li>
                            <li class="flex gap-4">
                                <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">2</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-800">Paste it into your website's HTML.</p>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        Anywhere before <code class="rounded bg-gray-100 px-1 py-0.5 text-[11px]">&lt;/body&gt;</code> works. For
                                        WordPress, add it to the theme footer or use a "custom HTML" block.
                                    </p>
                                </div>
                            </li>
                            <li class="flex gap-4">
                                <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">3</span>
                                <div>
                                    <p class="text-sm font-medium text-gray-800">Reload your site.</p>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        A floating chat button appears bottom-right. Click it → your agent's chat opens in a popover.
                                        Conversations and leads land in this dashboard.
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <!-- Facts -->
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl bg-white p-4 shadow ring-1 ring-black/5">
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Mobile</p>
                            <p class="mt-2 text-sm text-gray-700">Full-screen takeover under 480px wide.</p>
                        </div>
                        <div class="rounded-xl bg-white p-4 shadow ring-1 ring-black/5">
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Sessions</p>
                            <p class="mt-2 text-sm text-gray-700">
                                Visitors get a 30-day cookie scoped to your agent. Returning visitors continue their thread.
                            </p>
                        </div>
                        <div class="rounded-xl bg-white p-4 shadow ring-1 ring-black/5">
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-gray-400">Billing</p>
                            <p class="mt-2 text-sm text-gray-700">
                                Embedded conversations debit credits from your team, same as dashboard chats.
                            </p>
                        </div>
                    </div>

                    <!-- Troubleshooting -->
                    <details class="rounded-xl bg-white p-6 shadow ring-1 ring-black/5">
                        <summary class="cursor-pointer text-base font-semibold text-gray-900">Troubleshooting</summary>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div>
                                <dt class="font-medium text-gray-800">The button doesn't appear.</dt>
                                <dd class="mt-1 text-xs text-gray-500">
                                    Make sure the snippet sits inside <code>&lt;body&gt;</code> (not <code>&lt;head&gt;</code>),
                                    and check the browser console for errors. Browser extensions blocking
                                    third-party scripts can also hide it.
                                </dd>
                            </div>
                            <div>
                                <dt class="font-medium text-gray-800">It says "agent not found".</dt>
                                <dd class="mt-1 text-xs text-gray-500">
                                    Your agent is paused or disabled. Activate it on the Agents page.
                                </dd>
                            </div>
                            <div>
                                <dt class="font-medium text-gray-800">Customers report "temporarily unavailable".</dt>
                                <dd class="mt-1 text-xs text-gray-500">
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
