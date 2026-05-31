<script setup>
const props = defineProps({
    lead: { type: Object, required: true },
});

defineEmits(['delete']);

// Map the status color token to concrete classes (Tailwind needs them literal).
const scoreColor = (score) => {
    if (score >= 70) return 'bg-green-100 text-green-700';
    if (score >= 40) return 'bg-amber-100 text-amber-700';
    return 'bg-gray-100 text-gray-600';
};
</script>

<template>
    <div
        class="group cursor-grab rounded-lg border border-gray-200 bg-white p-3 shadow-sm active:cursor-grabbing"
        draggable="true"
        @dragstart="$event.dataTransfer.setData('text/lead-id', String(lead.id))"
    >
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="truncate font-medium text-gray-900">{{ lead.name }}</p>
                <p v-if="lead.company" class="truncate text-xs text-gray-500">{{ lead.company }}</p>
            </div>
            <span class="rounded px-1.5 py-0.5 text-xs font-semibold" :class="scoreColor(lead.score)">
                {{ lead.score }}
            </span>
        </div>

        <p v-if="lead.email" class="mt-1 truncate text-xs text-gray-500">{{ lead.email }}</p>

        <div class="mt-2 flex items-center justify-between">
            <span class="rounded bg-gray-50 px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-gray-400">
                {{ lead.source }}
            </span>
            <div class="flex items-center gap-2">
                <span v-if="lead.assignee" class="text-xs text-blue-600">
                    {{ lead.assignee.name }}
                </span>
                <button
                    type="button"
                    class="text-xs text-gray-300 opacity-0 transition hover:text-rose-500 group-hover:opacity-100"
                    title="Delete lead"
                    @click="$emit('delete', lead)"
                >
                    ✕
                </button>
            </div>
        </div>
    </div>
</template>
