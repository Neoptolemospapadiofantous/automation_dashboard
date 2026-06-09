<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    conversations: { type: Object, required: true },
    filter_lead: { type: Object, default: null },
});

const q = ref('');
function search() {
    router.get(route('conversations.search'), { q: q.value }, { preserveState: true });
}

const fmt = (d) => (d ? new Date(d).toLocaleString() : '—');
</script>

<template>
    <AppLayout title="Conversations">
        <PageHeader title="Conversations" description="Every chat that's happened with your agents." />


        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <!-- Cross-link filter banner — landed here via a LeadCard's
                     conversation-count chip. Clear button strips ?lead_id
                     and returns to the full list. -->
                <div v-if="filter_lead" class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
                    <div>
                        Showing conversations for
                        <Link :href="route('leads.index')" class="font-semibold underline">{{ filter_lead.name }}</Link>
                        <span v-if="filter_lead.email" class="text-indigo-700"> · {{ filter_lead.email }}</span>
                    </div>
                    <Link :href="route('conversations.index')" class="text-xs font-medium text-indigo-700 underline hover:text-indigo-900">
                        Clear filter ✕
                    </Link>
                </div>

                <form class="mb-6 flex gap-2" @submit.prevent="search">
                    <TextInput v-model="q" type="search" class="flex-1" placeholder="Search all conversations…" />
                    <PrimaryButton>Search</PrimaryButton>
                </form>

                <div class="overflow-hidden rounded-xl bg-white shadow">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Lead</th>
                                <th class="px-4 py-3">Channel</th>
                                <th class="px-4 py-3">Messages</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Last activity</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="c in conversations.data"
                                :key="c.id"
                                class="cursor-pointer hover:bg-gray-50"
                                @click="router.visit(route('conversations.show', c.id))"
                            >
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    {{ c.lead?.name || c.voiceflow_user_id }}
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ c.channel }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ c.message_count }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :class="c.status === 'ended' ? 'bg-gray-100 text-gray-600' : 'bg-green-100 text-green-700'"
                                    >{{ c.status }}</span>
                                </td>
                                <td class="px-4 py-3 text-gray-500">{{ fmt(c.last_message_at) }}</td>
                            </tr>
                            <tr v-if="!conversations.data.length">
                                <td colspan="5" class="px-4 py-14 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <svg class="h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                                        </svg>
                                        <div>
                                            <p class="text-sm font-medium text-gray-600">No conversations yet</p>
                                            <p class="mt-1 text-xs text-gray-400">Conversations appear here as soon as someone chats with your agent.</p>
                                        </div>
                                        <Link :href="route('chat.index')" class="text-xs font-medium text-indigo-600 hover:text-indigo-800">
                                            Try the chat panel →
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <!-- @hermes-keep: Laravel paginator labels are server-controlled HTML entities (&laquo; / &raquo;), not user input. See .hermes/suppressions.yaml -->
                <div v-if="conversations.links?.length > 3" class="mt-4 flex flex-wrap gap-1">
                    <Link
                        v-for="link in conversations.links"
                        :key="link.label"
                        :href="link.url || ''"
                        class="rounded px-3 py-1 text-sm"
                        :class="[
                            link.active ? 'bg-gray-800 text-white' : 'bg-white text-gray-600 hover:bg-gray-100',
                            !link.url && 'pointer-events-none opacity-40',
                        ]"
                        v-html="link.label"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
