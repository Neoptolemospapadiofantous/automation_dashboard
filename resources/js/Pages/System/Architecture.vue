<script setup>
import { ref, onMounted, nextTick } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    flows: { type: Array, required: true },
});

const mermaidReady = ref(false);

onMounted(async () => {
    // Lazy-load Mermaid only when this page mounts so the main bundle
    // stays small. ~600 KB gzipped — never paid by users who don't visit
    // this page.
    const { default: mermaid } = await import('mermaid');
    mermaid.initialize({
        startOnLoad: false,
        theme: 'base',
        themeVariables: {
            primaryColor: '#eef2ff',
            primaryTextColor: '#312e81',
            primaryBorderColor: '#6366f1',
            lineColor: '#94a3b8',
            secondaryColor: '#fef3c7',
            tertiaryColor: '#dcfce7',
            fontFamily: 'ui-sans-serif, system-ui, sans-serif',
            fontSize: '14px',
        },
        flowchart: { useMaxWidth: true, htmlLabels: true, curve: 'basis' },
        sequence: { useMaxWidth: true, mirrorActors: false },
    });

    await nextTick();
    await mermaid.run({ querySelector: '.mermaid' });
    mermaidReady.value = true;
});
</script>

<template>
    <AppLayout title="API & Data Flow">
        <PageHeader
            title="API & Data Flow"
            description="How requests move between the browser, our Laravel API, and Voiceflow's three hosts. Every flow here is a real codepath — link beside the title points to the implementation."
        />

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-10 px-4 sm:px-6 lg:px-8">
                <!-- Architecture summary card -->
                <section class="rounded-xl bg-white p-6 shadow">
                    <h2 class="text-lg font-semibold text-gray-900">The wrapper at a glance</h2>
                    <div class="mt-4 grid gap-4 text-sm md:grid-cols-2">
                        <div>
                            <h3 class="font-semibold text-gray-700">Voiceflow hosts</h3>
                            <ul class="mt-1 space-y-1 text-gray-600">
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">general-runtime</code> — session, interact, state, KB query, streaming</li>
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">analytics-api</code> — transcripts, evaluations, usage</li>
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">realtime-api</code> — KB CRUD, environments</li>
                            </ul>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-700">Auth keys</h3>
                            <ul class="mt-1 space-y-1 text-gray-600">
                                <li><strong>DM key</strong> (<code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">VF.DM.*</code>) — per project; used for session start, state CRUD, KB query</li>
                                <li><strong>Workspace key</strong> — workspace-wide; required for analytics, evaluations, KB CRUD, environments (paid tier)</li>
                                <li><strong>Session key</strong> — ephemeral, minted from DM key per (project, env), cached 1h</li>
                                <li><strong>Per-agent webhook secret</strong> — encrypted at rest, auth for inbound from Voiceflow</li>
                            </ul>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-700">Typed subclients</h3>
                            <ul class="mt-1 space-y-1 text-gray-600">
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">RuntimeClient</code> — 8 methods</li>
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">AnalyticsClient</code> — 14 methods</li>
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">RealtimeClient</code> — 19 methods</li>
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">StreamingClient</code> — SSE wrapper</li>
                            </ul>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-700">Inbound surface</h3>
                            <ul class="mt-1 space-y-1 text-gray-600">
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">/api/voiceflow/lead-captured/{slug}</code> — per-agent secret</li>
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">/api/voiceflow/webhooks/session/{slug}</code> — per-agent secret</li>
                                <li><code class="rounded bg-gray-100 px-1.5 py-0.5 text-[12px]">/api/voiceflow/webhooks/org</code> — Svix HMAC, falls back to platform shared secret</li>
                            </ul>
                        </div>
                    </div>
                </section>

                <!-- Loading hint -->
                <div v-if="!mermaidReady" class="rounded-xl bg-indigo-50 p-4 text-sm text-indigo-700">
                    Rendering diagrams…
                </div>

                <!-- Per-flow diagrams -->
                <section
                    v-for="flow in flows"
                    :key="flow.id"
                    :id="flow.id"
                    class="rounded-xl bg-white p-6 shadow"
                >
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-lg font-semibold text-gray-900">{{ flow.title }}</h2>
                        <a
                            v-if="flow.source"
                            :href="`#${flow.id}`"
                            class="text-xs text-indigo-600 hover:text-indigo-800"
                            :title="flow.source"
                        >
                            {{ flow.source }}
                        </a>
                    </div>
                    <p v-if="flow.summary" class="mt-2 text-sm text-gray-600">{{ flow.summary }}</p>

                    <div class="mt-4 overflow-x-auto rounded-lg border border-gray-100 bg-gray-50 p-4">
                        <pre class="mermaid">{{ flow.diagram }}</pre>
                    </div>

                    <div v-if="flow.notes && flow.notes.length" class="mt-4 space-y-1 text-sm text-gray-600">
                        <p
                            v-for="(note, i) in flow.notes"
                            :key="i"
                            class="border-l-2 border-indigo-200 pl-3"
                        >{{ note }}</p>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>

<style>
/* Mermaid sometimes inlines very wide SVGs; let them scroll horizontally
   inside our container without pushing the whole page wide. */
.mermaid svg { max-width: 100%; height: auto; }
</style>

