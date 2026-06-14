<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Navigation link styled for the persistent sidebar.
 *
 * Active state via Ziggy `route().current(pattern)` — accept the pattern as a
 * prop so the layout can express "highlight under any conversations.* route".
 */
const props = defineProps({
    href: { type: String, required: true },
    activePattern: { type: [String, Array], default: null },
});

const isActive = computed(() => {
    if (!props.activePattern) return false;
    const patterns = Array.isArray(props.activePattern) ? props.activePattern : [props.activePattern];
    return patterns.some((p) => route().current(p));
});
</script>

<template>
    <Link
        :href="href"
        class="group flex items-center gap-2.5 rounded-none border-l-2 px-2 py-1.5 text-sm font-medium transition-colors"
        :class="isActive
            ? 'border-violet bg-surface-hi text-ink'
            : 'border-transparent text-ink-dim hover:bg-surface-hi hover:text-ink'"
    >
        <span v-if="$slots.icon" class="flex h-4 w-4 items-center justify-center text-ink-mute group-hover:text-ink-dim" :class="{ 'text-ink group-hover:text-ink': isActive }">
            <slot name="icon" />
        </span>
        <span class="flex-1 truncate">
            <slot />
        </span>
        <span v-if="$slots.badge" class="ml-auto">
            <slot name="badge" />
        </span>
    </Link>
</template>
