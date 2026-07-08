<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    team: { type: Object, required: true },
    tiers: { type: Array, default: () => [] },
});

// Optional segmentation fields — all nullable server-side. Filling these
// in tailors the welcome experience and feeds marketing segmentation.
const form = useForm({
    name: 'Default agent',
    industry: null,
    use_case: null,
    team_size: null,
    website: '',
    model_tier: 'haiku',
});

const industries = [
    { value: 'saas', label: 'SaaS / Software' },
    { value: 'ecommerce', label: 'E-commerce' },
    { value: 'agency', label: 'Agency / Marketing' },
    { value: 'services', label: 'Professional services' },
    { value: 'real_estate', label: 'Real estate' },
    { value: 'healthcare', label: 'Healthcare' },
    { value: 'education', label: 'Education' },
    { value: 'other', label: 'Other' },
];

const useCases = [
    { value: 'lead_capture', label: 'Capture leads', desc: 'Inbound visitors → qualified contacts on the kanban.' },
    { value: 'customer_support', label: 'Customer support', desc: 'Deflect FAQs and triage tickets before they hit a human.' },
    { value: 'scheduling', label: 'Book appointments', desc: 'Take visitors from interest to calendar in the same chat.' },
    { value: 'qualification', label: 'Qualify + score', desc: 'BANT-style filtering before sales reps invest time.' },
    { value: 'faq', label: 'Answer questions', desc: 'Ground answers in your docs / pages / PDFs.' },
    { value: 'other', label: 'Something else', desc: 'We can scope a Custom flow for you.' },
];

const teamSizes = [
    { value: 'solo', label: 'Just me' },
    { value: '2-5', label: '2–5' },
    { value: '6-20', label: '6–20' },
    { value: '21-100', label: '21–100' },
    { value: '100+', label: '100+' },
];

// Live, reactive recap of the choices so far — composes the selected fields
// into a sentence that updates as the user fills the form, so the page reacts
// to input instead of sitting static.
const summary = computed(() => {
    const uc = useCases.find((u) => u.value === form.use_case);
    const ind = industries.find((i) => i.value === form.industry);
    const size = teamSizes.find((t) => t.value === form.team_size);

    if (!uc && !ind && !size) {
        return 'Pick what fits — the summary updates as you go.';
    }

    const verb = uc ? uc.label.toLowerCase() : 'help out';
    const who = ind ? ` for a ${ind.label.toLowerCase()} team` : '';
    const scale = size ? ` (${size.label})` : '';

    return `Your agent will ${verb}${who}${scale}.`;
});

function continueOn() {
    form.post(route('onboarding.start'));
}
</script>

<template>
    <Head title="Welcome — Set up your agent" />
    <div class="min-h-screen bg-bg-elev bg-grid">
        <div class="mx-auto max-w-2xl px-4 py-12">
            <div class="bp-rise mb-8 flex items-center justify-between text-xs">
                <ol class="flex items-center gap-3 font-mono font-medium text-ink-dim">
                    <li class="flex items-center gap-2 text-ink"><span class="bp-dot pulse-glow text-violet" />1. Set up agent</li>
                    <li class="bp-wire inline-block w-8" aria-hidden="true" />
                    <li class="flex items-center gap-2"><span class="bp-dot" />2. Done</li>
                </ol>
                <span class="bp-ref">SETUP/01</span>
            </div>

            <div class="bp-node rounded-none p-8">
                <h1 class="bp-rise break-words text-2xl font-semibold text-ink" style="--rise-delay: 60ms">Welcome to {{ team.name }}.</h1>
                <p class="bp-rise mt-2 text-ink-dim" style="--rise-delay: 100ms">
                    A couple of quick questions to tailor your setup. None are required —
                    skip and we'll still provision your chat agent right away.
                </p>

                <p
                    class="bp-rise mt-3 border-l-2 border-violet pl-3 font-mono text-xs text-ink-dim transition-colors"
                    style="--rise-delay: 140ms"
                    aria-live="polite"
                >
                    {{ summary }}
                </p>

                <form class="mt-8 space-y-6" @submit.prevent="continueOn">
                    <!-- Primary use case (radio cards) -->
                    <fieldset class="bp-rise" style="--rise-delay: 180ms">
                        <legend class="text-sm font-medium text-ink">What's the main thing you'll use this for?</legend>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="uc in useCases"
                                :key="uc.value"
                                class="flex cursor-pointer flex-col rounded-none border p-3 text-left transition duration-200"
                                :class="form.use_case === uc.value
                                    ? '-translate-y-0.5 border-violet bg-surface-hi shadow-sheet ring-1 ring-violet'
                                    : 'border-border-line bg-bg hover:-translate-y-0.5 hover:border-ink'"
                            >
                                <input v-model="form.use_case" :value="uc.value" type="radio" class="sr-only" />
                                <span class="text-sm font-semibold text-ink">{{ uc.label }}</span>
                                <span class="mt-0.5 text-[11px] text-ink-dim">{{ uc.desc }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- Industry + team size (selects, side-by-side on wider screens) -->
                    <div class="bp-rise grid gap-4 sm:grid-cols-2" style="--rise-delay: 240ms">
                        <div>
                            <label class="text-sm font-medium text-ink">Industry</label>
                            <select
                                v-model="form.industry"
                                class="mt-2 w-full rounded-none border-border-line bg-bg text-sm text-ink focus:border-ink focus:ring-ink"
                            >
                                <option :value="null">Skip</option>
                                <option v-for="ind in industries" :key="ind.value" :value="ind.value">{{ ind.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-ink">Team size</label>
                            <select
                                v-model="form.team_size"
                                class="mt-2 w-full rounded-none border-border-line bg-bg text-sm text-ink focus:border-ink focus:ring-ink"
                            >
                                <option :value="null">Skip</option>
                                <option v-for="ts in teamSizes" :key="ts.value" :value="ts.value">{{ ts.label }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Response quality tier — couples model to credit cost -->
                    <div class="bp-rise" style="--rise-delay: 300ms">
                        <label class="text-sm font-medium text-ink">Response quality</label>
                        <p class="mt-0.5 text-[11px] text-ink-dim">
                            How smart should your agent be? You can change this per agent anytime on the Versions page.
                        </p>
                        <div class="mt-2 grid gap-3 sm:grid-cols-3">
                            <label
                                v-for="t in tiers"
                                :key="t.key"
                                class="flex items-start gap-2.5 rounded-none border p-3 text-sm transition duration-200"
                                :class="[
                                    t.available === false ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
                                    form.model_tier === t.key ? '-translate-y-0.5 border-violet bg-surface-hi shadow-sheet ring-1 ring-violet' : 'border-border-line hover:border-border-hi',
                                ]"
                            >
                                <input v-model="form.model_tier" type="radio" :value="t.key" :disabled="t.available === false" class="mt-0.5 text-ink focus:ring-ink" />
                                <span>
                                    <span class="flex items-center gap-2 font-medium text-ink">
                                        {{ t.label }}
                                        <span class="rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-[10px] font-semibold text-ink-dim">
                                            {{ t.credits_per_message }} cr/msg
                                        </span>
                                    </span>
                                    <span class="mt-1 block text-[11px] leading-snug text-ink-dim">{{ t.available === false ? 'Not available yet on this workspace.' : t.description }}</span>
                                </span>
                            </label>
                        </div>
                        <div v-if="form.errors.model_tier" class="mt-1 text-xs text-rose-600">{{ form.errors.model_tier }}</div>
                    </div>

                    <!-- Website — where they'll embed -->
                    <div class="bp-rise" style="--rise-delay: 360ms">
                        <label class="text-sm font-medium text-ink">Your website (optional)</label>
                        <input
                            v-model="form.website"
                            type="url"
                            placeholder="https://example.com"
                            class="mt-2 w-full rounded-none border-border-line bg-bg text-sm text-ink placeholder:text-ink-mute focus:border-ink focus:ring-ink"
                        />
                        <p class="mt-1 text-[11px] text-ink-dim">
                            We'll show install instructions tailored to this domain.
                        </p>
                    </div>

                    <div v-if="form.errors.website" class="text-xs text-rose-600">{{ form.errors.website }}</div>

                    <div class="bp-rise flex flex-col gap-3 border-t border-border-line pt-5 sm:flex-row sm:items-center sm:justify-between" style="--rise-delay: 420ms">
                        <p class="text-xs text-ink-mute">
                            You can change all of this later in account settings.
                        </p>
                        <PrimaryButton :disabled="form.processing" :class="{ 'opacity-50': form.processing }" type="submit">
                            {{ form.processing ? 'Setting up your agent…' : 'Set up my agent →' }}
                        </PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
