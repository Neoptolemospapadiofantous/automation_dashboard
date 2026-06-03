<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, required: true },
});

const fmt = (d) => (d ? new Date(d).toLocaleString() : '');
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
        />

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
