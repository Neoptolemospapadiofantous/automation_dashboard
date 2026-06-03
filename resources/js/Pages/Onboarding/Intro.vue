<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    team: { type: Object, required: true },
    template_url: { type: String, required: true },
    /**
     * Phase J: managed=true means the backend will provision a Voiceflow
     * environment on the user's behalf when they click Continue. No
     * credential paste step. The wizard collapses to 1 visible step.
     */
    managed: { type: Boolean, default: false },
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
            <!-- Step indicator (different shape per mode) -->
            <div class="mb-8 flex items-center justify-between text-xs">
                <ol v-if="managed" class="flex items-center gap-2 font-medium text-gray-500">
                    <li class="text-indigo-600">1. Set up agent</li>
                    <li>→</li>
                    <li>2. Done</li>
                </ol>
                <ol v-else class="flex items-center gap-2 font-medium text-gray-500">
                    <li class="text-indigo-600">1. Create agent</li>
                    <li>→</li>
                    <li>2. Connect Voiceflow</li>
                    <li>→</li>
                    <li>3. Done</li>
                </ol>
            </div>

            <!-- Managed mode: one-click setup -->
            <div v-if="managed" class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-black/5">
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

            <!-- BYOK mode: 3-step instructions (existing flow) -->
            <div v-else class="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-black/5">
                <h1 class="text-2xl font-semibold text-gray-900">Welcome to {{ team.name }}.</h1>
                <p class="mt-2 text-gray-600">
                    Your dashboard is ready. The last thing left is connecting a
                    Voiceflow agent — that's the AI that will qualify leads on
                    your behalf.
                </p>

                <ol class="mt-6 space-y-5 text-sm text-gray-700">
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">1</span>
                        <div>
                            <p class="font-medium text-gray-900">Sign up for Voiceflow (or sign in)</p>
                            <p class="mt-0.5 text-gray-500">It's free for a starter project — no credit card required.</p>
                            <a href="https://creator.voiceflow.com/signup" target="_blank" rel="noopener" class="mt-1 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500">
                                Open Voiceflow ↗
                            </a>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">2</span>
                        <div>
                            <p class="font-medium text-gray-900">Import our lead-qualification template</p>
                            <p class="mt-0.5 text-gray-500">Inside Voiceflow → New project → Import. The template already captures name, email, phone, and company and forwards qualified leads to your dashboard.</p>
                            <a :href="template_url" class="mt-1 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500" download>
                                Download template (.vf) ↓
                            </a>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-indigo-50 text-xs font-semibold text-indigo-600">3</span>
                        <div>
                            <p class="font-medium text-gray-900">Generate an API key</p>
                            <p class="mt-0.5 text-gray-500">In Voiceflow: <span class="font-mono text-xs">Settings → API keys</span>. Copy the key that starts with <code>VF.DM.</code> — you'll paste it on the next screen.</p>
                        </div>
                    </li>
                </ol>

                <div class="mt-8 flex justify-end">
                    <PrimaryButton :disabled="form.processing" :class="{ 'opacity-50': form.processing }" @click="continueOn">
                        I've created my Voiceflow project — continue
                    </PrimaryButton>
                </div>
            </div>
        </div>
    </div>
</template>
