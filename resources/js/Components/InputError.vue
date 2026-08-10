<script setup>
defineProps({
    message: String,
});
</script>

<template>
    <div v-show="message">
        <p class="text-sm text-state-bad-ink">
            {{ message }}
        </p>
    </div>
</template>
