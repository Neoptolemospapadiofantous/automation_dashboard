<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    agent: { type: Object, required: true },
});

// "What's next" checklist — drives engagement after the one-click signup.
// Each step links to the actual surface that handles it.  No persistence
// yet (no "I completed step X" tracking); operators self-mark by clicking
// through. Add tracking later if drop-off becomes visible.
const nextSteps = [
    {
        title: 'Test your agent live',
        body: 'Talk to your agent the way a lead would. Captured fields appear on the right and sync to the board.',
        cta: 'Open the chat panel',
        href: 'chat.index',
    },
    {
        title: 'Train it on your content',
        body: 'Add the pages, PDFs, or docs you want the agent to ground its answers in.',
        cta: 'Add knowledge',
        href: 'knowledge.index',
    },
    {
        title: 'See where leads land',
        body: 'Watch every qualified lead show up on the live kanban. Drag cards between stages.',
        cta: 'Open the board',
        href: 'leads.index',
    },
];
</script>

<template>
    <Head title="You're set" />
    <div class="min-h-screen bg-gray-50">
        <div class="mx-auto max-w-2xl px-4 py-12">
            <div class="mb-8 flex items-center justify-between text-xs">
                <ol class="flex items-center gap-2 font-medium text-gray-500">
                    <li class="text-gray-400">✓ 1. Set up agent</li>
                    <li>→</li>
                    <li class="text-indigo-600">2. Done</li>
                </ol>
            </div>

            <div class="rounded-2xl bg-white p-8 text-center shadow-sm ring-1 ring-black/5">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100">
                    <svg class="h-7 w-7 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                </div>
                <h1 class="mt-4 text-2xl font-semibold text-gray-900">You're live.</h1>
                <p class="mt-2 text-gray-600">
                    <span class="font-medium">{{ agent.name }}</span> is connected and answering.
                </p>

                <div class="mt-8 flex items-center justify-center gap-3">
                    <Link :href="route('chat.index')">
                        <PrimaryButton>Start chatting</PrimaryButton>
                    </Link>
                    <Link :href="route('dashboard')" class="text-sm text-gray-500 hover:text-gray-700">Go to dashboard →</Link>
                </div>
            </div>

            <!-- Post-onboarding checklist. Drives the user to the three
                 surfaces that need at least one touch for the product to
                 feel like it's running, not sitting. -->
            <div class="mt-10">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Next 60 seconds</p>
                <ul class="mt-3 space-y-3">
                    <li
                        v-for="(step, i) in nextSteps"
                        :key="step.href"
                        class="flex gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-black/5"
                    >
                        <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">
                            {{ i + 1 }}
                        </span>
                        <div class="flex-1">
                            <p class="font-medium text-gray-900">{{ step.title }}</p>
                            <p class="mt-0.5 text-sm text-gray-500">{{ step.body }}</p>
                            <Link :href="route(step.href)" class="mt-2 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">
                                {{ step.cta }} →
                            </Link>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
