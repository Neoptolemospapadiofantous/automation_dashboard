<script setup>
/**
 * Empty-state slot used inside tables and list pages.
 *
 * <EmptyState
 *   title="No agents yet"
 *   description="Create your first agent to start qualifying leads."
 *   :icon="..."
 * >
 *   <template #actions>
 *     <PrimaryButton>Create agent</PrimaryButton>
 *   </template>
 * </EmptyState>
 */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center">
        <div v-if="$slots.icon" class="flex h-12 w-12 items-center justify-center rounded-none bg-surface-hi text-ink-mute">
            <slot name="icon" />
        </div>
        <div v-else class="flex h-12 w-12 items-center justify-center rounded-none bg-surface-hi text-ink-mute">
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 01-1.125-1.125v-3.75zM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-8.25zM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 01-1.125-1.125v-2.25z" />
            </svg>
        </div>
        <div class="space-y-1">
            <h3 class="text-sm font-semibold text-ink">{{ title }}</h3>
            <p v-if="description" class="text-sm text-ink-dim">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="mt-2 flex items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
