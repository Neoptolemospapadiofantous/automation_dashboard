<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    align: {
        type: String,
        default: 'right',
    },
    width: {
        type: String,
        default: '48',
    },
    /**
     * 'bottom' (default) — panel opens DOWN from the trigger (`mt-2`)
     * 'top' — panel opens UP from the trigger (`bottom-full mb-2`).
     *   Required for triggers pinned to the bottom of a fixed-height
     *   container (e.g. the sidebar user card), where the default 'bottom'
     *   placement would render the panel off-screen below the viewport.
     */
    placement: {
        type: String,
        default: 'bottom',
        validator: (v) => ['bottom', 'top'].includes(v),
    },
    contentClasses: {
        type: Array,
        default: () => ['py-1', 'bg-bg'],
    },
});

let open = ref(false);

const closeOnEscape = (e) => {
    if (open.value && e.key === 'Escape') {
        open.value = false;
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape));

// Width prop → Tailwind class. Must be statically referenced (not built
// via template literal) so Tailwind's JIT compiler picks them up. Add
// new sizes here as needed.
const widthClass = computed(() => {
    return {
        '48': 'w-48',
        '56': 'w-56',
        '60': 'w-60',
        '80': 'w-80',
    }[props.width.toString()] ?? 'w-48';
});

const placementClass = computed(() =>
    props.placement === 'top'
        ? 'bottom-full mb-2'
        : 'mt-2',
);

const alignmentClasses = computed(() => {
    // origin-* controls the scale-95 → scale-100 transition anchor so the
    // panel "grows from" the trigger corner instead of the center.
    const originPrefix = props.placement === 'top' ? 'origin-bottom' : 'origin-top';

    if (props.align === 'left') {
        return `ltr:${originPrefix}-left rtl:${originPrefix}-right start-0`;
    }

    if (props.align === 'right') {
        return `ltr:${originPrefix}-right rtl:${originPrefix}-left end-0`;
    }

    return originPrefix;
});
</script>

<template>
    <div class="relative">
        <div @click="open = ! open">
            <slot name="trigger" />
        </div>

        <!-- Full Screen Dropdown Overlay -->
        <div v-show="open" class="fixed inset-0 z-40" @click="open = false" />

        <transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="transform opacity-0 scale-95"
            enter-to-class="transform opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="transform opacity-100 scale-100"
            leave-to-class="transform opacity-0 scale-95"
        >
            <div
                v-show="open"
                class="absolute z-50 rounded-none shadow-sheet"
                :class="[widthClass, placementClass, alignmentClasses]"
                style="display: none;"
                @click="open = false"
            >
                <div class="rounded-none border border-border-line" :class="contentClasses">
                    <slot name="content" />
                </div>
            </div>
        </transition>
    </div>
</template>
