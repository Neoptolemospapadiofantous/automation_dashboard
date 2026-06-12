<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <!-- Brand .btn-draw: outlined "drawn" ghost button, hover fills with ink. -->
    <button :type="type" class="inline-flex items-center px-4 py-2 bg-transparent border border-ink font-mono font-semibold text-xs text-ink uppercase tracking-widest hover:bg-ink hover:text-bg focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150">
        <slot />
    </button>
</template>
