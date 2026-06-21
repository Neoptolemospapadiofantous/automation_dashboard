<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    // { nodes:[{id,label,group}], links:[{source,target}] } for the 3D graph.
    graph: { type: Object, default: () => ({ nodes: [], links: [] }) },
    // [{ caption, code }] — the flat Mermaid blocks, for the optional 2D view.
    diagrams: { type: Array, default: () => [] },
});

const mode = ref('3d'); // '3d' | '2d'
const error = ref(null);
const selected = ref(null);
const graphEl = ref(null);
const categoryLabels = ref([]); // [{ group, x, y }] screen coords for overlay
let graphInstance = null;
let anchors = {};
let rafId = null;

// One colour per architecture layer.
const GROUP_COLORS = {
    client: '#38bdf8', // sky
    http: '#a78bfa', // violet — middleware
    controller: '#60a5fa', // blue
    model: '#2dd4bf', // teal — Eloquent models
    domain: '#c084fc', // purple — lifecycle / state machines
    runtime: '#34d399', // emerald — runtime engine
    llm: '#4ade80', // green — LLM clients/router
    tools: '#a3e635', // lime — runtime tools
    knowledge: '#22d3ee', // cyan — RAG / KB
    service: '#fb7185', // rose — app services
    billing: '#fbbf24', // amber
    events: '#f472b6', // pink — events / listeners
    enum: '#cbd5e1', // light slate
    policy: '#fca5a5', // light red
    notification: '#fdba74', // orange
    action: '#818cf8', // indigo
    console: '#f87171', // red — artisan/scheduler commands
    provider: '#e879f9', // fuchsia — service providers
    support: '#5eead4', // light teal — support helpers
    data: '#94a3b8', // slate — runtime models / persistence
};

const FALLBACK_COLOR = '#cbd5e1';
const colorFor = (g) => GROUP_COLORS[g] ?? FALLBACK_COLOR;

// Legend is derived from the groups actually present in the data, so any
// auto-discovered layer (even one with no preset colour) still shows up.
const LAYERS = computed(() => {
    const counts = {};
    for (const n of props.graph.nodes ?? []) counts[n.group] = (counts[n.group] ?? 0) + 1;
    return Object.keys(counts).sort((a, b) => counts[b] - counts[a]);
});

// Load a UMD script from a CDN once and resolve when its global is ready.
function loadScript(src) {
    return new Promise((resolve, reject) => {
        const el = document.createElement('script');
        el.src = src;
        el.onload = resolve;
        el.onerror = () => reject(new Error(`failed to load ${src}`));
        document.head.appendChild(el);
    });
}

async function render3d() {
    if (graphInstance || !graphEl.value) return;
    try {
        // 3d-force-graph (three.js + d3-force-3d) isn't a bundled dep — this is a
        // local-only operator page, so pull the UMD build from a CDN at runtime.
        if (!window.ForceGraph3D) {
            await loadScript('https://cdn.jsdelivr.net/npm/3d-force-graph@1');
        }

        // Each layer gets an anchor spread evenly over a sphere (fibonacci
        // distribution); its nodes are pulled toward it so the layer forms one
        // coloured lobe. Anchors double as the positions for the HTML category
        // labels overlaid on top (see updateLabels).
        const groups = [...new Set(props.graph.nodes.map((n) => n.group))];
        const R = Math.max(220, Math.cbrt(props.graph.nodes.length || 1) * 115);
        anchors = {};
        const golden = Math.PI * (1 + Math.sqrt(5));
        groups.forEach((g, i) => {
            const phi = Math.acos(1 - (2 * (i + 0.5)) / groups.length);
            const theta = golden * i;
            anchors[g] = {
                x: R * Math.sin(phi) * Math.cos(theta),
                y: R * Math.sin(phi) * Math.sin(theta),
                z: R * Math.cos(phi),
            };
        });

        graphInstance = window.ForceGraph3D()(graphEl.value)
            .graphData({
                nodes: props.graph.nodes.map((n) => ({ ...n })),
                links: props.graph.links.map((l) => ({ ...l })),
            })
            .backgroundColor('#0b1120')
            .showNavInfo(false)
            .nodeLabel((n) => n.label)
            .nodeColor((n) => colorFor(n.group))
            .nodeRelSize(5)
            .nodeVal(3)
            .nodeOpacity(0.95)
            .nodeResolution(16)
            .linkColor(() => 'rgba(148,163,184,0.3)')
            .linkWidth(0.5)
            .linkDirectionalParticles(2)
            .linkDirectionalParticleSpeed(0.006)
            .linkDirectionalParticleWidth(1.6)
            .onNodeClick((n) => {
                selected.value = n;
                // Fly the camera to the clicked node.
                const dist = 120;
                const ratio = 1 + dist / Math.hypot(n.x || 1, n.y || 1, n.z || 1);
                graphInstance.cameraPosition(
                    { x: (n.x || 0) * ratio, y: (n.y || 0) * ratio, z: (n.z || 0) * ratio },
                    n,
                    1000,
                );
            });

        // Weak links + light repulsion so the cluster force dominates and each
        // layer separates into its own lobe instead of collapsing inward.
        graphInstance.d3Force('charge').strength(-28);
        graphInstance.d3Force('link').distance(26).strength(0.12);

        const cluster = (() => {
            let nodes = [];
            const force = (alpha) => {
                const k = alpha * 0.3;
                for (const n of nodes) {
                    const a = anchors[n.group];
                    if (!a) continue;
                    n.vx += (a.x - (n.x || 0)) * k;
                    n.vy += (a.y - (n.y || 0)) * k;
                    n.vz += (a.z - (n.z || 0)) * k;
                }
            };
            force.initialize = (n) => {
                nodes = n;
            };
            return force;
        })();
        graphInstance.d3Force('cluster', cluster);

        // The library defaults the canvas to window size, which overflows our
        // panel. Pin it to the wrapper's real box on every resize.
        const resize = () => {
            if (graphEl.value && graphInstance) {
                graphInstance.width(graphEl.value.clientWidth).height(graphEl.value.clientHeight);
            }
        };
        resize();
        window.addEventListener('resize', resize);
        graphInstance.__resize = resize;

        // Frame the whole graph once the force layout settles so it's centered
        // and fully visible regardless of node count.
        setTimeout(() => graphInstance?.zoomToFit(800, 60), 3500);

        // Project each layer anchor to screen space every frame so the HTML
        // category labels (rendered by Vue) track the 3D scene as you rotate.
        const tick = () => {
            if (!graphInstance) return;
            categoryLabels.value = groups.map((g) => {
                const a = anchors[g];
                const s = graphInstance.graph2ScreenCoords(a.x, a.y, a.z);
                return { group: g, x: s.x, y: s.y };
            });
            rafId = requestAnimationFrame(tick);
        };
        rafId = requestAnimationFrame(tick);
    } catch (e) {
        error.value = e?.message ?? String(e);
    }
}

async function render2d() {
    try {
        const mermaid = (
            await import(
                /* @vite-ignore */ 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs'
            )
        ).default;
        mermaid.initialize({ startOnLoad: false, theme: 'neutral', securityLevel: 'loose' });
        const host = document.getElementById('mermaid-host');
        if (!host) return;
        host.innerHTML = '';
        for (let i = 0; i < props.diagrams.length; i++) {
            const d = props.diagrams[i];
            const { svg } = await mermaid.render(`arch-2d-${i}`, d.code);
            const section = document.createElement('section');
            section.className =
                'overflow-x-auto rounded-xl border border-gray-200 bg-white p-6 shadow-sm';
            section.innerHTML = `<h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-gray-500">${d.caption}</h2><div class="flex justify-center">${svg}</div>`;
            host.appendChild(section);
        }
    } catch (e) {
        error.value = e?.message ?? String(e);
    }
}

function setMode(m) {
    mode.value = m;
    if (m === '2d') setTimeout(render2d, 0);
    else setTimeout(render3d, 0);
}

onMounted(render3d);

onBeforeUnmount(() => {
    if (rafId) cancelAnimationFrame(rafId);
    if (graphInstance?.__resize) window.removeEventListener('resize', graphInstance.__resize);
    if (graphInstance?._destructor) graphInstance._destructor();
});
</script>

<template>
    <Head title="Architecture graph" />

    <AppLayout title="Architecture graph">
        <template #header>
            <PageHeader
                title="Architecture graph"
                subtitle="Interactive 3D map of the whole application — drag to rotate, scroll to zoom, click a node to focus (local only)"
            />
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div class="inline-flex rounded-lg border border-gray-200 bg-white p-0.5 shadow-sm">
                        <button
                            class="rounded-md px-3 py-1.5 text-sm font-medium"
                            :class="mode === '3d' ? 'bg-indigo-600 text-white' : 'text-gray-600'"
                            @click="setMode('3d')"
                        >
                            3D graph
                        </button>
                        <button
                            class="rounded-md px-3 py-1.5 text-sm font-medium"
                            :class="mode === '2d' ? 'bg-indigo-600 text-white' : 'text-gray-600'"
                            @click="setMode('2d')"
                        >
                            2D diagrams
                        </button>
                    </div>

                    <div v-if="mode === '3d'" class="flex flex-wrap gap-3 text-xs text-gray-500">
                        <span v-for="g in LAYERS" :key="g" class="inline-flex items-center gap-1.5">
                            <span
                                class="inline-block h-2.5 w-2.5 rounded-full"
                                :style="{ backgroundColor: colorFor(g) }"
                            />
                            {{ g }}
                        </span>
                    </div>
                </div>

                <div
                    v-if="error"
                    class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"
                >
                    Failed to render: {{ error }}
                </div>

                <!-- 3D graph -->
                <div v-show="mode === '3d'" class="relative">
                    <div
                        ref="graphEl"
                        class="w-full overflow-hidden rounded-xl border border-gray-800 bg-[#0b1120]"
                        style="height: 680px"
                    />
                    <!-- Layer name floating over each cluster, tracking the 3D scene. -->
                    <div class="pointer-events-none absolute inset-0 overflow-hidden">
                        <span
                            v-for="l in categoryLabels"
                            :key="l.group"
                            class="absolute -translate-x-1/2 -translate-y-1/2 whitespace-nowrap text-xs font-semibold uppercase tracking-wider drop-shadow"
                            :style="{ left: l.x + 'px', top: l.y + 'px', color: colorFor(l.group) }"
                        >
                            {{ l.group }}
                        </span>
                    </div>
                    <div
                        v-if="selected"
                        class="absolute left-4 top-4 max-w-xs rounded-lg bg-black/70 px-4 py-3 text-sm text-white shadow-lg backdrop-blur"
                    >
                        <div class="flex items-center gap-2 font-semibold">
                            <span
                                class="inline-block h-2.5 w-2.5 rounded-full"
                                :style="{ backgroundColor: colorFor(selected.group) }"
                            />
                            {{ selected.label }}
                        </div>
                        <div class="mt-1 text-xs text-gray-300">layer: {{ selected.group }}</div>
                    </div>
                </div>

                <!-- 2D Mermaid -->
                <div v-show="mode === '2d'" id="mermaid-host" class="space-y-6" />
            </div>
        </div>
    </AppLayout>
</template>
