<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    modules: { type: Array, required: true },
    plan: { type: Object, required: true },
    studio_url: { type: String, required: true },
    audit_url: { type: String, required: true },
});

// Two lines, kept apart on purpose: the app is what this dashboard runs;
// the Studio is the done-for-you service line, invoiced separately. The
// page never blends them — a Studio item has no switch here, only a door.
const appLive = computed(() => props.modules.filter((m) => m.line === 'app' && m.status === 'live'));
const appComing = computed(() => props.modules.filter((m) => m.line === 'app' && m.status === 'coming'));
const studio = computed(() => props.modules.filter((m) => m.line === 'studio'));

const requesting = new Set();
function requestModule(m) {
    if (m.requested || requesting.has(m.key)) return;
    requesting.add(m.key);
    router.post(route('suite.request'), { module: m.key }, {
        preserveScroll: true,
        onFinish: () => requesting.delete(m.key),
    });
}
</script>

<template>
    <AppLayout title="Suite">
        <PageHeader
            title="Suite"
            description="Everything Flowstack does, in two lines: what the app runs for you, and what the Studio does for you."
            width="max-w-6xl"
        />

        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
            <!-- ================= THE APP ================= -->
            <section>
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 border-b border-ink pb-3">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">In the app</h2>
                    <p class="text-sm text-ink-dim">
                        Self-serve, billed by plan. You are on
                        <span class="font-medium text-ink">{{ plan.label }}</span>
                        —
                        <Link :href="route('billing.index')" class="inline-block py-1.5 underline underline-offset-4 hover:text-ink">change plan</Link>
                    </p>
                </div>

                <h3 class="mt-6 text-xs font-medium uppercase tracking-wider text-ink-mute">Live now</h3>
                <ul class="mt-3 grid grid-cols-1 gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="m in appLive" :key="m.key" class="flex flex-col gap-3 bg-bg p-5">
                        <div class="flex items-start justify-between gap-3">
                            <h4 class="text-base font-semibold text-ink">{{ m.name }}</h4>
                            <span
                                v-if="m.on_plan"
                                class="shrink-0 text-xs font-medium text-state-ok-ink"
                            >Live</span>
                            <span
                                v-else
                                class="shrink-0 text-xs font-medium text-ink-mute"
                            >From {{ m.min_plan_label }}</span>
                        </div>
                        <p class="text-sm leading-relaxed text-ink-dim">{{ m.blurb }}</p>
                        <div class="mt-auto pt-2">
                            <Link
                                v-if="m.on_plan && m.href"
                                :href="m.href"
                                class="inline-flex min-h-[32px] items-center text-sm font-medium text-ink underline underline-offset-4"
                            >Open →</Link>
                            <Link
                                v-else
                                :href="route('billing.index')"
                                class="inline-flex min-h-[32px] items-center text-sm font-medium text-ink underline underline-offset-4"
                            >Upgrade to {{ m.min_plan_label }} →</Link>
                        </div>
                    </li>
                </ul>

                <!-- COMING modules are not available and the copy says so in
                     those words. The only action is to ask for it; the count
                     of teams asking is what decides what gets built next. -->
                <h3 class="mt-8 text-xs font-medium uppercase tracking-wider text-ink-mute">Not yet available — request it</h3>
                <p class="mt-2 max-w-[60ch] text-sm text-ink-dim">
                    None of these work today. Tell us which ones you need and we'll build in that order — and email you when yours is ready.
                    Need one now? The Studio does all of them for you, below.
                </p>
                <ul class="mt-3 grid grid-cols-1 gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="m in appComing" :key="m.key" class="flex flex-col gap-3 bg-bg-elev p-5">
                        <div class="flex items-start justify-between gap-3">
                            <h4 class="text-base font-semibold text-ink">{{ m.name }}</h4>
                            <span class="shrink-0 text-xs font-medium text-state-warn-ink">Coming</span>
                        </div>
                        <p class="text-sm leading-relaxed text-ink-dim">{{ m.blurb }}</p>
                        <p v-if="m.min_plan_label" class="text-xs text-ink-mute">Will need {{ m.min_plan_label }} or above</p>
                        <div class="mt-auto pt-2">
                            <span v-if="m.requested" class="inline-flex min-h-[32px] items-center text-sm font-medium text-state-ok-ink">Requested ✓</span>
                            <button
                                v-else
                                type="button"
                                class="inline-flex min-h-[32px] items-center rounded-none border border-ink bg-ink px-3 text-sm font-medium text-bg hover:bg-bg hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-ink"
                                @click="requestModule(m)"
                            >Request it</button>
                        </div>
                    </li>
                </ul>
            </section>

            <!-- ================= THE STUDIO ================= -->
            <section class="mt-12">
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 border-b border-ink pb-3">
                    <h2 class="text-lg font-semibold tracking-tight text-ink">From the Studio</h2>
                    <p class="text-sm text-ink-dim">Done for you, in Cyprus. Quoted and invoiced separately from this subscription.</p>
                </div>
                <p class="mt-4 max-w-[62ch] text-sm leading-relaxed text-ink-dim">
                    Nothing here is a switch in the app. It starts with the free Leak Report — one page on where you are losing customers — and everything after it is built, installed and watched by us. The chat you run here is what the Studio installs; your subscription stays your own.
                </p>
                <ul class="mt-4 grid grid-cols-1 gap-px border border-line bg-line sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="m in studio" :key="m.key" class="flex flex-col gap-3 bg-bg p-5">
                        <h4 class="text-base font-semibold text-ink">{{ m.name }}</h4>
                        <p class="text-sm leading-relaxed text-ink-dim">{{ m.blurb }}</p>
                    </li>
                </ul>
                <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-2">
                    <a
                        :href="audit_url"
                        class="inline-flex min-h-[36px] items-center rounded-none border border-ink bg-ink px-4 text-sm font-medium text-bg hover:bg-bg hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-ink"
                    >Start with the free Leak Report →</a>
                    <a :href="studio_url" class="inline-flex min-h-[32px] items-center text-sm font-medium text-ink underline underline-offset-4">What the Studio does</a>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
