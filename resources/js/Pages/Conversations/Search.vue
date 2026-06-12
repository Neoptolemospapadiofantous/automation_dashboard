<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TextInput from '@/Components/TextInput.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    q: { type: String, default: '' },
    results: { type: Array, default: () => [] },
});

const q = ref(props.q);
function search() {
    router.get(route('conversations.search'), { q: q.value }, { preserveState: true });
}
const fmt = (d) => (d ? new Date(d).toLocaleString() : '');
</script>

<template>
    <AppLayout title="Search conversations">
        <PageHeader
            :breadcrumbs="[{ label: 'Conversations', href: route('conversations.index') }, { label: 'Search' }]"
            title="Search conversations"
            description="Find messages by keyword or meaning (semantic search powered by Typesense when configured)."
        />

        <div class="py-8">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <form class="mb-6 flex gap-2" @submit.prevent="search">
                    <TextInput v-model="q" type="search" class="flex-1" placeholder="Search by keyword or meaning…" autofocus />
                    <PrimaryButton>Search</PrimaryButton>
                </form>

                <p v-if="q && !results.length" class="text-ink-mute">No matches for “{{ q }}”.</p>

                <ul class="space-y-2">
                    <li
                        v-for="r in results"
                        :key="r.id"
                        class="cursor-pointer rounded-none border border-border-line bg-bg p-3 hover:bg-surface-hi"
                        @click="router.visit(route('conversations.show', r.conversation_id))"
                    >
                        <div class="mb-1 flex items-center gap-2 font-mono text-xs text-ink-mute">
                            <span class="uppercase tracking-wider">{{ r.role }}</span>
                            <span>·</span>
                            <span>{{ fmt(r.sent_at) }}</span>
                        </div>
                        <p class="text-sm text-ink">{{ r.text }}</p>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
