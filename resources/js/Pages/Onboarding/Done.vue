<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    agent: { type: Object, required: true },
});
</script>

<template>
    <Head title="You're set" />
    <div class="min-h-screen bg-gray-50">
        <div class="mx-auto max-w-2xl px-4 py-12">
            <div class="mb-8 flex items-center justify-between text-xs">
                <ol class="flex items-center gap-2 font-medium text-gray-500">
                    <li class="text-gray-400">✓ 1. Create agent</li>
                    <li>→</li>
                    <li class="text-gray-400">✓ 2. Connect Voiceflow</li>
                    <li>→</li>
                    <li class="text-indigo-600">3. Done</li>
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
                    <span class="font-medium">{{ agent.name }}</span> is connected and answering. Start a chat to see it in action, or skip straight to the dashboard.
                </p>

                <div class="mt-8 flex items-center justify-center gap-3">
                    <Link :href="route('agent.index')">
                        <PrimaryButton>Start chatting</PrimaryButton>
                    </Link>
                    <Link :href="route('dashboard')" class="text-sm text-gray-500 hover:text-gray-700">Go to dashboard →</Link>
                </div>
            </div>
        </div>
    </div>
</template>
