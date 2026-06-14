<script setup>
defineProps({
    value: String,
});
</script>

<template>
    <!-- Mono annotation label (brand .bp-annot register). -->
    <label class="block font-mono text-xs uppercase tracking-wider text-ink-dim">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
