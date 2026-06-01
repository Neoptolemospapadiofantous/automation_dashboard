<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

defineProps({
    conversations: { type: Object, required: true },
});

const q = ref('');
function search() {
    router.get(route('conversations.search'), { q: q.value }, { preserveState: true });
}

const fmt = (d) => (d ? new Date(d).toLocaleString() : '—');
</script>

<template>
    <AppLayout title="Conversations">
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Conversations</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
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
