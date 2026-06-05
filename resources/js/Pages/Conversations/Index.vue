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
                                <td colspan="5" class="px-4 py-10 text-center text-gray-400">
                                    No conversations yet. Start one from the Agent page.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
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
