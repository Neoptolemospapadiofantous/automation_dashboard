<script setup>
/**
 * Skeleton placeholder for table rows. Use while async data loads
 * (transcripts page, KB documents page, etc.) instead of spinners.
 */
defineProps({
    rows: { type: Number, default: 5 },
    cols: { type: Number, default: 4 },
});
</script>

<template>
    <div class="divide-y divide-gray-100">
        <div v-for="r in rows" :key="r" class="flex items-center gap-4 px-4 py-3">
            <div
                v-for="c in cols"
                :key="c"
                class="h-3 animate-pulse rounded bg-gray-200"
                :class="c === 1 ? 'flex-[2]' : 'flex-1'"
            />
        </div>
    </div>
</template>
