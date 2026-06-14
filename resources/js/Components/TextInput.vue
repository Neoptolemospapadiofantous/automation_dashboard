<script setup>
import { onMounted, ref } from 'vue';

defineProps({
    modelValue: String,
});

defineEmits(['update:modelValue']);

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        ref="input"
        class="bg-bg text-ink border-border-hi focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1 rounded-none"
        :value="modelValue"
        @input="$emit('update:modelValue', $event.target.value)"
    >
</template>
