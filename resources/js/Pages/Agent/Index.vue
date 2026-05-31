<script setup>
import { nextTick, ref } from 'vue';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    configured: { type: Boolean, default: false },
});

const messages = ref([]);       // { role: 'user'|'agent', text }
const buttons = ref([]);        // quick-reply choices from the agent
const captured = ref({});       // lead fields captured so far
const leadId = ref(null);
const userId = ref(null);
const input = ref('');
const busy = ref(false);
const started = ref(false);
const ended = ref(false);
const scroller = ref(null);

async function scrollToEnd() {
    await nextTick();
    if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight;
}

function applyResponse(data) {
    userId.value = data.user_id;
    if (data.lead_id) leadId.value = data.lead_id;
    if (data.captured) captured.value = { ...captured.value, ...data.captured };
    data.messages.forEach((text) => messages.value.push({ role: 'agent', text }));
    buttons.value = data.buttons ?? [];
    ended.value = !!data.ended;
    scrollToEnd();
}

async function start() {
    busy.value = true;
    try {
        const { data } = await axios.post(route('agent.launch'), {});
        started.value = true;
        applyResponse(data);
    } catch (e) {
        messages.value.push({ role: 'agent', text: errorText(e) });
    } finally {
        busy.value = false;
    }
}

async function send(text) {
    const message = (text ?? input.value).trim();
    if (!message || busy.value || !userId.value) return;

    messages.value.push({ role: 'user', text: message });
    input.value = '';
    buttons.value = [];
    busy.value = true;
    scrollToEnd();

    try {
        const { data } = await axios.post(route('agent.interact'), {
            user_id: userId.value,
            message,
            lead_id: leadId.value,
        });
        applyResponse(data);
    } catch (e) {
        messages.value.push({ role: 'agent', text: errorText(e) });
    } finally {
        busy.value = false;
    }
}

function errorText(e) {
    if (e?.response?.status === 503) return 'The Voiceflow agent is not configured yet.';
    return 'Something went wrong reaching the agent. Please try again.';
}

const capturedEntries = () => Object.entries(captured.value);
</script>

<template>
    <AppLayout title="Lead Agent">
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Lead Agent</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-3 lg:px-8">
                <!-- Conversation -->
                <div class="lg:col-span-2">
                    <div class="flex h-[32rem] flex-col overflow-hidden rounded-xl bg-white shadow">
                        <div v-if="!configured" class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-700">
                            Voiceflow isn't configured. Set <code>VOICEFLOW_API_KEY</code> to enable the agent.
                        </div>

                        <div ref="scroller" class="flex-1 space-y-3 overflow-y-auto p-4">
                            <div v-if="!started" class="flex h-full flex-col items-center justify-center text-center text-gray-400">
                                <p class="mb-4">Start a conversation with the lead-qualification agent.</p>
                                <PrimaryButton :disabled="busy || !configured" @click="start">
                                    {{ busy ? 'Starting…' : 'Start conversation' }}
                                </PrimaryButton>
                            </div>

                            <div
                                v-for="(m, i) in messages"
                                :key="i"
                                class="flex"
                                :class="m.role === 'user' ? 'justify-end' : 'justify-start'"
                            >
                                <div
                                    class="max-w-[80%] rounded-2xl px-4 py-2 text-sm"
                                    :class="m.role === 'user'
                                        ? 'bg-indigo-600 text-white'
                                        : 'bg-gray-100 text-gray-800'"
                                >
                                    {{ m.text }}
                                </div>
                            </div>

                            <div v-if="busy && started" class="flex justify-start">
                                <div class="rounded-2xl bg-gray-100 px-4 py-2 text-sm text-gray-400">…</div>
                            </div>
                        </div>

                        <!-- Quick-reply buttons -->
                        <div v-if="buttons.length" class="flex flex-wrap gap-2 border-t border-gray-100 px-4 py-2">
                            <button
                                v-for="(b, i) in buttons"
                                :key="i"
                                type="button"
                                class="rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                                @click="send(b.name)"
                            >
                                {{ b.name }}
                            </button>
                        </div>

                        <!-- Composer -->
                        <form
                            v-if="started && !ended"
                            class="flex items-center gap-2 border-t border-gray-100 p-3"
                            @submit.prevent="send()"
                        >
                            <TextInput v-model="input" type="text" class="flex-1" placeholder="Type a message…" :disabled="busy" />
                            <PrimaryButton :disabled="busy || !input.trim()">Send</PrimaryButton>
                        </form>
                        <div v-else-if="ended" class="border-t border-gray-100 p-3 text-center text-sm text-gray-400">
                            Conversation ended.
                        </div>
                    </div>
                </div>

                <!-- Captured lead -->
                <div>
                    <div class="rounded-xl bg-white p-4 shadow">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700">Captured lead</h3>
                        <dl v-if="capturedEntries().length" class="space-y-2">
                            <div v-for="[k, v] in capturedEntries()" :key="k" class="flex justify-between gap-2 text-sm">
                                <dt class="capitalize text-gray-500">{{ k }}</dt>
                                <dd class="truncate font-medium text-gray-900">{{ v }}</dd>
                            </div>
                        </dl>
                        <p v-else class="text-sm text-gray-400">
                            Lead fields captured during the conversation will appear here and sync to the board live.
                        </p>

                        <a
                            v-if="leadId"
                            :href="route('leads.index')"
                            class="mt-4 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            View on the board →
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
