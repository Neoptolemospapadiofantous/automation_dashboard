<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, required: true },
});

const fmt = (d) => (d ? new Date(d).toLocaleString() : '');

const hasTranscript = computed(() => !!props.conversation.voiceflow_transcript_id);
const isEnded = computed(() => !!props.conversation.ended_at);

const endUpstream = () => {
    if (!hasTranscript.value || isEnded.value) return;
    if (!confirm('Force-end this session upstream at Voiceflow? The local conversation is preserved.')) return;
    useForm({}).post(route('conversations.end-upstream', props.conversation.id), {
        preserveScroll: true,
    });
};

const deleteUpstream = () => {
    if (!confirm(`Delete this conversation and ${hasTranscript.value ? 'its Voiceflow transcript' : 'its local record'}? This is irreversible.`)) return;
    useForm({}).delete(route('conversations.delete-upstream', props.conversation.id));
};
</script>

<template>
    <AppLayout title="Conversation">
        <PageHeader
            :breadcrumbs="[
                { label: 'Conversations', href: route('conversations.index') },
                { label: conversation.lead?.name || conversation.voiceflow_user_id }
            ]"
            :title="conversation.lead?.name || conversation.voiceflow_user_id"
            :description="`Started ${fmt(conversation.started_at)} · ${conversation.message_count} messages`"
        >
            <template #actions>
                <div class="flex flex-wrap items-center gap-2">
                    <!-- Cross-link to the lead's other conversations + back to
                         the kanban. Lets the operator pivot from a single
                         transcript to the lead's full footprint in one click. -->
                    <template v-if="conversation.lead">
                        <Link
                            :href="route('conversations.index', { lead_id: conversation.lead.id })"
                            class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                        >
                            💬 All conversations with {{ conversation.lead.name }}
                        </Link>
                        <Link
                            :href="route('leads.index')"
                            class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200"
                        >
                            → View on board
                        </Link>
                    </template>
                    <SecondaryButton
                        v-if="hasTranscript && !isEnded"
                        type="button"
                        @click="endUpstream"
                        :title="'End the Voiceflow session for transcript ' + conversation.voiceflow_transcript_id"
                    >
                        End upstream
                    </SecondaryButton>
                    <DangerButton type="button" @click="deleteUpstream">
                        Delete
                    </DangerButton>
                </div>
            </template>
        </PageHeader>

        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <div class="space-y-3 rounded-xl bg-white p-4 shadow">
                    <div
                        v-for="m in messages"
                        :key="m.id"
                        class="flex"
                        :class="m.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[80%] rounded-2xl px-4 py-2 text-sm"
                            :class="m.role === 'user' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-800'"
                        >
                            <p>{{ m.text }}</p>
                            <p class="mt-1 text-[10px] opacity-60">{{ fmt(m.sent_at) }}</p>
                        </div>
                    </div>
                    <p v-if="!messages.length" class="py-8 text-center text-gray-400">No messages recorded.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
