<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button :type="type" class="inline-flex items-center justify-center px-4 py-2 bg-state-bad-solid border border-state-bad-solid rounded-none font-mono font-semibold text-xs text-state-bad-on uppercase tracking-widest hover:bg-state-bad-solid active:bg-state-bad-solid focus:outline-none focus:ring-2 focus:ring-state-bad-solid focus:ring-offset-2 transition ease-in-out duration-150">
        <slot />
    </button>
</template>
