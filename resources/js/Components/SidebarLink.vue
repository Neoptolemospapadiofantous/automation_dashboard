<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Navigation link styled for the persistent sidebar. Visually distinct from
 * the older top-nav NavLink (which has an underline-on-active treatment).
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
        class="group flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm font-medium transition-colors"
        :class="isActive
            ? 'bg-indigo-50 text-indigo-700'
            : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'"
    >
        <span v-if="$slots.icon" class="flex h-4 w-4 items-center justify-center text-gray-400 group-hover:text-gray-500" :class="{ 'text-indigo-500 group-hover:text-indigo-600': isActive }">
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
