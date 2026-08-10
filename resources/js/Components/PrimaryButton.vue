<script setup>
defineProps({
    type: {
        type: String,
        default: 'submit',
    },
});
</script>

<template>
    <!-- Primary CTA as the landing draws it (.btn-signal): signal-yellow
         block, ink border, hard ink shadow that presses in on hover. -->
    <button :type="type" class="btn-signal inline-flex items-center px-4 py-2 border font-mono font-semibold text-xs uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-ink focus:ring-offset-2 disabled:opacity-50 transition ease-in-out duration-150">
        <slot />
    </button>
</template>
