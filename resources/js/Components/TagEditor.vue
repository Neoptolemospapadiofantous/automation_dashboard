<script setup>
import { computed, ref } from 'vue';

/**
 * Chip editor for the free-form tag/label lists. Emits the whole list on
 * every change — the server replaces the array, so add and remove are one
 * request shape. Suggestions are the tags already in use on the page.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    suggestions: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Add a tag…' },
    disabled: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const draft = ref('');
const input = ref(null);

const normalise = (s) => String(s ?? '').trim().toLowerCase().replace(/\s+/g, ' ').slice(0, 40);

const available = computed(() =>
    props.suggestions
        .filter((s) => !props.modelValue.includes(s))
        .filter((s) => !draft.value || s.includes(normalise(draft.value)))
        .slice(0, 6),
);

function add(raw) {
    const tag = normalise(raw);
    if (!tag || props.modelValue.includes(tag) || props.modelValue.length >= 20) {
        draft.value = '';
        return;
    }
    emit('update:modelValue', [...props.modelValue, tag]);
    draft.value = '';
}

function remove(tag) {
    emit('update:modelValue', props.modelValue.filter((t) => t !== tag));
}

function onKey(e) {
    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault();
        add(draft.value);
    } else if (e.key === 'Backspace' && !draft.value && props.modelValue.length) {
        remove(props.modelValue[props.modelValue.length - 1]);
    }
}
</script>

<template>
    <div>
        <div
            class="flex flex-wrap items-center gap-1 rounded-none border border-border-hi bg-bg px-1.5 py-1 focus-within:border-ink"
            :class="compact ? 'text-[11px]' : 'text-xs'"
            @click="input?.focus()"
        >
            <span
                v-for="tag in modelValue"
                :key="tag"
                class="inline-flex items-center gap-1 rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-ink"
            >
                {{ tag }}
                <button
                    v-if="!disabled"
                    type="button"
                    class="-mr-0.5 inline-flex size-5 items-center justify-center text-ink-mute hover:text-state-bad-ink"
                    :aria-label="`Remove ${tag}`"
                    @click.stop="remove(tag)"
                >✕</button>
            </span>
            <input
                v-if="!disabled"
                ref="input"
                v-model="draft"
                type="text"
                class="min-w-[6rem] flex-1 border-0 bg-transparent p-0.5 text-[16px] text-ink placeholder:text-ink-mute focus:ring-0 sm:text-xs"
                :placeholder="modelValue.length ? '' : placeholder"
                maxlength="40"
                @keydown="onKey"
                @blur="add(draft)"
            />
            <span v-else-if="!modelValue.length" class="px-1 text-ink-mute">—</span>
        </div>
        <div v-if="!disabled && available.length" class="mt-1 flex flex-wrap gap-1">
            <button
                v-for="s in available"
                :key="s"
                type="button"
                class="rounded-none border border-border-line px-1.5 py-0.5 font-mono text-[10px] text-ink-dim hover:border-ink hover:text-ink"
                @click="add(s)"
            >
                + {{ s }}
            </button>
        </div>
    </div>
</template>
