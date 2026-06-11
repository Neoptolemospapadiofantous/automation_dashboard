<script setup>
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
    model_tier: 'standard',
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

function continueOn() {
    form.post(route('onboarding.start'));
}
</script>

<template>
    <Head title="Welcome — Set up your agent" />
    <div class="min-h-screen bg-gray-50">
        <div class="mx-auto max-w-2xl px-4 py-12">
            <div class="mb-8 flex items-center justify-between text-xs">
                <ol class="flex items-center gap-2 font-medium text-gray-500">
                    <li class="text-indigo-600">1. Set up agent</li>
                    <li>→</li>
                    <li>2. Done</li>
                </ol>
            </div>

            <div class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-black/5">
                <h1 class="text-2xl font-semibold text-gray-900">Welcome to {{ team.name }}.</h1>
                <p class="mt-2 text-gray-600">
                    A couple of quick questions to tailor your setup. None are required —
                    skip and we'll still provision your AI agent right away.
                </p>

                <form class="mt-8 space-y-6" @submit.prevent="continueOn">
                    <!-- Primary use case (radio cards) -->
                    <fieldset>
                        <legend class="text-sm font-medium text-gray-800">What's the main thing you'll use this for?</legend>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="uc in useCases"
                                :key="uc.value"
                                class="flex cursor-pointer flex-col rounded-lg border p-3 text-left transition"
                                :class="form.use_case === uc.value
                                    ? 'border-indigo-400 bg-indigo-50 ring-1 ring-indigo-200'
                                    : 'border-gray-200 bg-white hover:border-indigo-200'"
                            >
                                <input v-model="form.use_case" :value="uc.value" type="radio" class="sr-only" />
                                <span class="text-sm font-semibold text-gray-900">{{ uc.label }}</span>
                                <span class="mt-0.5 text-[11px] text-gray-500">{{ uc.desc }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- Industry + team size (selects, side-by-side on wider screens) -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="text-sm font-medium text-gray-800">Industry</label>
                            <select
                                v-model="form.industry"
                                class="mt-2 w-full rounded-md border-gray-200 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                            >
                                <option :value="null">Skip</option>
                                <option v-for="ind in industries" :key="ind.value" :value="ind.value">{{ ind.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-800">Team size</label>
                            <select
                                v-model="form.team_size"
                                class="mt-2 w-full rounded-md border-gray-200 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                            >
                                <option :value="null">Skip</option>
                                <option v-for="ts in teamSizes" :key="ts.value" :value="ts.value">{{ ts.label }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Response quality tier — couples model to credit cost -->
                    <div>
                        <label class="text-sm font-medium text-gray-800">Response quality</label>
                        <p class="mt-0.5 text-[11px] text-gray-500">
                            How smart should your agent be? You can change this per agent anytime on the Versions page.
                        </p>
                        <div class="mt-2 flex gap-3">
                            <label
                                v-for="t in tiers"
                                :key="t.key"
                                class="flex flex-1 cursor-pointer items-start gap-2.5 rounded-lg border p-3 text-sm transition"
                                :class="form.model_tier === t.key ? 'border-indigo-400 bg-indigo-50/50 ring-1 ring-indigo-300' : 'border-gray-200 hover:border-gray-300'"
                            >
                                <input v-model="form.model_tier" type="radio" :value="t.key" class="mt-0.5 text-indigo-600 focus:ring-indigo-400" />
                                <span>
                                    <span class="font-medium text-gray-900">{{ t.label }}</span>
                                    <span class="block text-[11px] text-gray-500">
                                        {{ t.credits_per_message }} credit{{ t.credits_per_message > 1 ? 's' : '' }} / message
                                        {{ t.key === 'enhanced' ? '· deeper reasoning for complex products' : '· fast, great for lead capture' }}
                                    </span>
                                </span>
                            </label>
                        </div>
                        <div v-if="form.errors.model_tier" class="mt-1 text-xs text-rose-600">{{ form.errors.model_tier }}</div>
                    </div>

                    <!-- Website — where they'll embed -->
                    <div>
                        <label class="text-sm font-medium text-gray-800">Your website (optional)</label>
                        <input
                            v-model="form.website"
                            type="url"
                            placeholder="https://example.com"
                            class="mt-2 w-full rounded-md border-gray-200 text-sm focus:border-indigo-400 focus:ring-indigo-400"
                        />
                        <p class="mt-1 text-[11px] text-gray-500">
                            We'll show install instructions tailored to this domain.
                        </p>
                    </div>

                    <div v-if="form.errors.website" class="text-xs text-rose-600">{{ form.errors.website }}</div>

                    <div class="flex items-center justify-between border-t border-gray-100 pt-5">
                        <p class="text-xs text-gray-400">
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
