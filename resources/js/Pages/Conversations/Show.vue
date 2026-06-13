<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { confirm } from '@/Composables/useConfirm';

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, required: true },
});

const fmt = (d) => (d ? new Date(d).toLocaleString() : '');

const hasTranscript = computed(() => !!props.conversation.voiceflow_transcript_id);
const isEnded = computed(() => !!props.conversation.ended_at);

const endUpstream = async () => {
    if (!hasTranscript.value || isEnded.value) return;
    const ok = await confirm({
        title: 'End upstream session',
        message: 'Force-end this session at the provider? The local conversation is preserved.',
        buttonText: 'End upstream',
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
                        v-if="hasTranscript && !isEnded"
                        type="button"
                        @click="endUpstream"
                        :title="'End the upstream session for transcript ' + conversation.voiceflow_transcript_id"
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
                <div class="space-y-3 rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                    <div
                        v-for="m in messages"
                        :key="m.id"
                        class="flex"
                        :class="m.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[80%] rounded-none px-4 py-2 text-sm"
                            :class="m.role === 'user' ? 'bg-ink text-bg' : 'bg-surface-hi text-ink'"
                        >
                            <p>{{ m.text }}</p>
                            <p class="mt-1 font-mono text-[10px] opacity-60">{{ fmt(m.sent_at) }}</p>
                        </div>
                    </div>
                    <div v-if="!messages.length" class="bg-grid bg-grid-fade rounded-none border border-dashed border-border-line py-12 text-center">
                        <span class="bp-annot">No messages recorded.</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
