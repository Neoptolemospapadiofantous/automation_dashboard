<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    team: { type: Object, required: true },
});

const form = useForm({ name: 'Default agent' });

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
                    Click below and we'll provision your AI lead-qualification agent
                    instantly. No accounts to create, nothing to configure.
                </p>

                <div class="mt-6 rounded-lg bg-indigo-50 p-4 text-sm text-indigo-900">
                    <p class="font-medium">What you're getting:</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-indigo-800">
                        <li>An AI agent that qualifies inbound leads conversationally</li>
                        <li>Live chat panel + kanban board to track everything</li>
                        <li>Your own isolated workspace — your data never mixes with anyone else's</li>
                    </ul>
                </div>

                <div class="mt-8 flex justify-end">
                    <PrimaryButton :disabled="form.processing" :class="{ 'opacity-50': form.processing }" @click="continueOn">
                        {{ form.processing ? 'Setting up your agent…' : 'Set up my agent' }}
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </div>
</template>
