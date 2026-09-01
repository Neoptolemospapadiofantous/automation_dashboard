<script setup>
import { computed, nextTick, ref } from 'vue';
import axios from 'axios';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { confirm } from '@/Composables/useConfirm';
import { useEcho } from '@/composables/useEcho';

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, required: true },
    messagesHasMore: { type: Boolean, default: false },
});

// Only the most recent window arrives in props; older turns lazy-load on demand
// and are prepended here, keeping the long-transcript page light on first paint.
const transcript = ref([...props.messages]);
const hasMore = ref(props.messagesHasMore);
const loadingMore = ref(false);

// --- Human takeover -------------------------------------------------------
// Escalated conversations can be answered live from here: the first reply
// pauses the AI (the widget's messages stop hitting the runtime) and reaches
// the visitor via the widget's poll loop. Release hands the chat back.
const page = usePage();
const teamId = computed(() => page.props.auth.user.current_team_id);
const handoff = ref(!!props.conversation.meta?.handoff_requested);
const takeover = ref(!!props.conversation.meta?.human_takeover);
const handoffReason = props.conversation.meta?.handoff_reason || '';
const isActive = computed(() => props.conversation.status !== 'ended');

const replyText = ref('');
const sending = ref(false);
const thread = ref(null);

const scrollToEnd = () => nextTick(() => {
    const el = thread.value;
    if (el) el.scrollTop = el.scrollHeight;
});

async function sendReply() {
    const text = replyText.value.trim();
    if (!text || sending.value) return;
    sending.value = true;
    try {
        const { data } = await axios.post(route('conversations.reply', props.conversation.id), {
            message: text,
        });
        transcript.value = [...transcript.value, {
            id: `local-${Date.now()}`,
            role: 'human',
            text,
            sent_at: data.message.sent_at,
            sequence: data.message.sequence,
        }];
        replyText.value = '';
        takeover.value = true;
        handoff.value = true;
        scrollToEnd();
    } finally {
        sending.value = false;
    }
}

async function releaseToAgent() {
    await axios.post(route('conversations.release', props.conversation.id));
    takeover.value = false;
}

// Live: visitor (and other-teammate) messages for THIS conversation appear
// without a refresh — same team channel the Leads board listens on.
useEcho(`team.${teamId.value}`, '.lead.message', (payload) => {
    if (payload?.conversation_id !== props.conversation.id) return;
    if (payload.role === 'human') return; // own replies are appended locally
    transcript.value = [...transcript.value, {
        id: `live-${Date.now()}-${Math.random()}`,
        role: payload.role,
        text: payload.text,
        sent_at: payload.at,
        sequence: null,
    }];
    scrollToEnd();
}, { presence: true });

async function loadEarlier() {
    if (loadingMore.value || !hasMore.value) return;
    loadingMore.value = true;
    try {
        const before = transcript.value[0]?.sequence;
        const { data } = await axios.get(route('conversations.messages', props.conversation.id), {
            params: { before },
        });
        transcript.value = [...data.messages, ...transcript.value];
        hasMore.value = data.has_more;
    } finally {
        loadingMore.value = false;
    }
}

const fmt = (d) => (d ? new Date(d).toLocaleString() : '');

// KB citation chips: dedupe by document_title, keep order of first
// appearance, and cap the visible list (the rest collapse into "+N more").
// Backend leaves citations null/empty for user turns, greetings, and
// low-confidence / no-KB answers, so callers must guard on length.
const citationChips = (citations) => {
    if (!Array.isArray(citations)) return [];
    const seen = new Set();
    const titles = [];
    for (const c of citations) {
        const title = c?.document_title;
        if (!title || seen.has(title)) continue;
        seen.add(title);
        titles.push(title);
    }
    return titles;
};

const hasTranscript = computed(() => !!props.conversation.transcript_id);
const isEnded = computed(() => !!props.conversation.ended_at);

// Closing a chat is a teammate's call as much as the visitor's or the idle
// timer's. It marks the conversation ended and stops the assistant answering;
// the transcript, the lead and any rating are kept. The old gate on
// transcript_id was left over from the days this force-ended an upstream
// provider session — it no longer calls anything remote, and gating on it
// meant a conversation without one could never be closed at all.
const closeChat = async () => {
    if (isEnded.value) return;
    const ok = await confirm({
        title: 'Close this chat?',
        message: 'The visitor can start a new chat any time. The transcript, lead and rating are kept.',
        buttonText: 'Close chat',
    });
    if (!ok) return;
    useForm({}).post(route('conversations.end-upstream', props.conversation.id), {
        preserveScroll: true,
    });
};

const deleteUpstream = async () => {
    const ok = await confirm({
        title: 'Delete conversation',
        message: `Delete this conversation and ${hasTranscript.value ? 'its provider transcript' : 'its local record'}? This is irreversible.`,
        buttonText: 'Delete',
        dangerous: true,
    });
    if (!ok) return;
    useForm({}).delete(route('conversations.delete-upstream', props.conversation.id));
};
</script>

<template>
    <AppLayout title="Conversation">
        <PageHeader width="max-w-3xl"
            :breadcrumbs="[
                { label: 'Conversations', href: route('conversations.index') },
                { label: conversation.lead?.name || 'Anonymous visitor' }
            ]"
            :title="conversation.lead?.name || 'Anonymous visitor'"
            :description="`Started ${fmt(conversation.started_at)} · ${conversation.message_count} messages · visitor ${String(conversation.visitor_id).slice(0, 12)}…`"
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="bp-ref mr-1">CONV/{{ conversation.id }}</span>
                    <!-- Cross-link to the lead's other conversations + back to
                         the kanban. Lets the operator pivot from a single
                         transcript to the lead's full footprint in one click. -->
                    <template v-if="conversation.lead">
                        <Link
                            :href="route('conversations.index', { lead_id: conversation.lead.id })"
                            class="inline-flex items-center gap-1.5 rounded-none bg-surface-hi px-2.5 py-1 text-xs font-medium text-ink hover:bg-ink hover:text-bg"
                        >
                            💬 All conversations with {{ conversation.lead.name }}
                        </Link>
                        <Link
                            :href="route('leads.index')"
                            class="inline-flex items-center gap-1.5 rounded-none bg-surface-hi px-2.5 py-1 text-xs font-medium text-ink-dim hover:bg-ink hover:text-bg"
                        >
                            → View on board
                        </Link>
                    </template>
                    <SecondaryButton
                        v-if="!isEnded"
                        type="button"
                        @click="closeChat"
                        title="Close this chat — the assistant stops answering and the transcript is kept"
                    >
                        Close chat
                    </SecondaryButton>
                    <DangerButton type="button" @click="deleteUpstream">
                        Delete
                    </DangerButton>
                </div>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <!-- Escalation banner: the visitor asked for a human. -->
                <div
                    v-if="handoff && isActive"
                    class="mb-4 flex flex-wrap items-center gap-3 rounded-none border border-violet bg-bg p-4 shadow-sheet"
                >
                    <span class="bp-dot pulse-glow text-violet" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-ink">
                            {{ takeover ? "You're live with this visitor — the assistant is paused." : 'Visitor asked for a human.' }}
                        </p>
                        <p v-if="handoffReason && !takeover" class="mt-0.5 text-xs text-ink-dim">{{ handoffReason }}</p>
                        <p v-else-if="!takeover" class="mt-0.5 text-xs text-ink-dim">Reply below to take over — your messages reach them live in the widget.</p>
                    </div>
                    <SecondaryButton v-if="takeover" type="button" @click="releaseToAgent">
                        Hand back to assistant
                    </SecondaryButton>
                </div>

                <div ref="thread" class="space-y-3 rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                    <div v-if="hasMore" class="flex justify-center">
                        <button
                            type="button"
                            class="rounded-none border border-border-line bg-surface-hi px-3 py-1.5 text-xs font-medium text-ink-dim hover:bg-ink hover:text-bg disabled:opacity-50"
                            :disabled="loadingMore"
                            @click="loadEarlier"
                        >
                            {{ loadingMore ? 'Loading…' : 'Load earlier messages' }}
                        </button>
                    </div>
                    <div
                        v-for="m in transcript"
                        :key="m.id"
                        class="flex"
                        :class="m.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[80%] whitespace-pre-wrap break-words rounded-none px-4 py-2 text-sm"
                            :class="m.role === 'user' ? 'bg-ink text-bg' : m.role === 'human' ? 'border border-violet bg-bg text-ink' : 'bg-surface-hi text-ink'"
                        >
                            <p v-if="m.role === 'human'" class="bp-ref mb-1">Team</p>
                            <p>{{ m.text }}</p>
                            <!-- KB source chips: secondary metadata under agent
                                 turns only, deduped by document title. -->
                            <div
                                v-if="m.role === 'agent' && citationChips(m.citations).length"
                                class="mt-1.5 flex flex-wrap gap-1"
                            >
                                <span
                                    v-for="title in citationChips(m.citations).slice(0, 3)"
                                    :key="title"
                                    class="inline-flex items-center rounded-none border border-border-line bg-bg px-1.5 py-0.5 font-mono text-[10px] text-ink-dim"
                                >
                                    Source: {{ title }}
                                </span>
                                <span
                                    v-if="citationChips(m.citations).length > 3"
                                    class="inline-flex items-center font-mono text-[10px] text-ink-dim opacity-60"
                                >
                                    +{{ citationChips(m.citations).length - 3 }} more
                                </span>
                            </div>
                            <p class="mt-1 font-mono text-[10px] opacity-60">{{ fmt(m.sent_at) }}</p>
                        </div>
                    </div>
                    <div v-if="!transcript.length" class="bg-grid bg-grid-fade rounded-none border border-dashed border-border-line py-12 text-center">
                        <span class="bp-annot">No messages recorded.</span>
                    </div>
                </div>

                <!-- Reply composer: live human takeover. -->
                <form
                    v-if="isActive"
                    class="mt-4 flex flex-wrap items-end gap-2 rounded-none border border-border-line bg-bg p-3 shadow-sheet"
                    @submit.prevent="sendReply"
                >
                    <textarea
                        v-model="replyText"
                        rows="2"
                        :placeholder="takeover ? 'Reply to the visitor…' : 'Type to take over — the assistant pauses on your first message.'"
                        class="block w-full basis-full resize-none rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-1 focus:ring-ink sm:basis-0 sm:flex-1"
                        @keydown.enter.exact.prevent="sendReply"
                    />
                    <button
                        type="submit"
                        :disabled="sending || !replyText.trim()"
                        class="ml-auto rounded-none bg-ink px-4 py-2.5 font-mono text-xs font-semibold uppercase tracking-wider text-bg disabled:opacity-40 sm:py-2"
                    >
                        {{ sending ? '…' : 'Send' }}
                    </button>
                </form>
                <p v-else class="mt-4 text-center text-xs text-ink-mute">This conversation has ended.</p>
            </div>
        </div>
    </AppLayout>
</template>
