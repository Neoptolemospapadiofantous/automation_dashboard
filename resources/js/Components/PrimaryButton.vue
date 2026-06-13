<script setup>
defineProps({
    type: {
        type: String,
        default: 'submit',
    },
});
</script>

<template>
    <!-- Brand .btn-grad: solid ink block, mono label, hover inverts. -->
    <button :type="type" class="inline-flex items-center px-4 py-2 bg-ink border border-ink font-mono font-semibold text-xs text-bg uppercase tracking-widest hover:bg-bg hover:text-ink active:bg-bg active:text-ink focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150">
        <slot />
    </button>
</template>
