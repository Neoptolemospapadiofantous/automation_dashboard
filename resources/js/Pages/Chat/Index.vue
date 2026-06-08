<script setup>
import { nextTick, ref } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
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
        const { data } = await axios.post(route('chat.launch'), {});
        started.value = true;
        applyResponse(data);
    } catch (e) {
        messages.value.push({ role: 'agent', text: errorText(e) });
    } finally {
        busy.value = false;
    }
}

const streamSupported = typeof window !== 'undefined'
    && typeof window.ReadableStream === 'function'
    && typeof window.fetch === 'function'
    && typeof window.TextDecoder === 'function';

async function send(text) {
    const message = (text ?? input.value).trim();
    if (!message || busy.value || !userId.value) return;

    messages.value.push({ role: 'user', text: message });
    input.value = '';
    buttons.value = [];
    busy.value = true;
    scrollToEnd();

    try {
        if (streamSupported) {
            await sendStreaming(message);
        } else {
            await sendBlocking(message);
        }
    } catch (e) {
        messages.value.push({ role: 'agent', text: errorText(e) });
    } finally {
        busy.value = false;
    }
}

async function sendBlocking(message) {
    const { data } = await axios.post(route('chat.interact'), {
        user_id: userId.value,
        message,
        lead_id: leadId.value,
    });
    applyResponse(data);
}

/**
 * Streaming path: open POST to /chat/interact/stream, read SSE events as they
 * arrive, append text in-place on a single assistant bubble. The final
 * `event: summary` frame carries buttons/ended/lead_id.
 */
async function sendStreaming(message) {
    // @hermes-keep: standard Laravel CSRF read for raw fetch() — axios reads it
    // automatically but ReadableStream needs fetch(), not axios. Not a reactivity bypass.
    const csrf = document.head.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const response = await fetch(route('chat.interact-stream'), {
        method: 'POST',
        credentials: 'include',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'Accept': 'text/event-stream',
        },
        body: JSON.stringify({
            user_id: userId.value,
            message,
            lead_id: leadId.value,
        }),
    });

    if (!response.ok) {
        // Fall back to blocking on any non-2xx.
        return sendBlocking(message);
    }

    // One assistant bubble per stream — its `.text` mutates as tokens arrive.
    const assistantBubble = { role: 'agent', text: '' };
    messages.value.push(assistantBubble);
    scrollToEnd();

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buffer += decoder.decode(value, { stream: true });

        // SSE frames are separated by \n\n.
        let idx;
        while ((idx = buffer.indexOf('\n\n')) !== -1) {
            const rawEvent = buffer.slice(0, idx);
            buffer = buffer.slice(idx + 2);
            handleSseFrame(rawEvent, assistantBubble);
        }
    }
    // Tail
    if (buffer.trim()) handleSseFrame(buffer, assistantBubble);

    // Strip empty bubble if Voiceflow returned no text traces.
    if (assistantBubble.text === '') {
        messages.value = messages.value.filter((m) => m !== assistantBubble);
    }
}

function handleSseFrame(rawFrame, assistantBubble) {
    let eventType = 'message';
    const dataLines = [];
    rawFrame.split('\n').forEach((line) => {
        if (line.startsWith('event:')) eventType = line.slice(6).trim();
        else if (line.startsWith('data:')) dataLines.push(line.slice(5).trim());
    });
    if (dataLines.length === 0) return;

    let data;
    try {
        data = JSON.parse(dataLines.join('\n'));
    } catch (e) {
        return;
    }

    if (eventType === 'trace') {
        // Append text-style payloads to the live bubble.
        if (data?.type === 'text' || data?.type === 'speak') {
            const text = data.payload?.message ?? '';
            if (text) {
                assistantBubble.text += (assistantBubble.text ? '\n' : '') + text;
                scrollToEnd();
            }
        }
    } else if (eventType === 'summary') {
        // Final frame: buttons + ended + lead_id.
        if (data.lead_id) leadId.value = data.lead_id;
        buttons.value = Array.isArray(data.buttons) ? data.buttons : [];
        ended.value = !!data.ended;
        scrollToEnd();
    } else if (eventType === 'error') {
        messages.value.push({ role: 'agent', text: data?.error ?? 'Stream error' });
    }
}

function errorText(e) {
    if (e?.response?.status === 503) return 'Your agent isn’t set up yet. Finish onboarding to start chatting.';
    // Surface the server's specific reason (key invalid, version not published, etc.).
    const msg = e?.response?.data?.error;
    if (msg) return msg;
    return 'Something went wrong reaching the agent. Please try again.';
}

const capturedEntries = () => Object.entries(captured.value);
</script>

<template>
    <AppLayout title="Chat">
        <PageHeader title="Chat" description="Talk to your current agent the way a lead would. Captured fields appear on the right and sync to the board live." />

        <div class="py-8">
            <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-3 lg:px-8">
                <!-- Conversation -->
                <div class="lg:col-span-2">
                    <div class="flex h-[32rem] flex-col overflow-hidden rounded-xl bg-white shadow">
                        <div v-if="!configured" class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-700">
                            Your agent isn't set up yet.
                            <Link :href="route('agents.index')" class="font-medium underline">Finish onboarding</Link>
                            to start chatting.
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
