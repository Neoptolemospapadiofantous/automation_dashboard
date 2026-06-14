<script setup>
import { computed, useSlots } from 'vue';
import SectionTitle from './SectionTitle.vue';

defineEmits(['submitted']);

const hasActions = computed(() => !! useSlots().actions);
</script>

<template>
    <div class="md:grid md:grid-cols-3 md:gap-6">
        <SectionTitle>
            <template #title>
                <slot name="title" />
            </template>
            <template #description>
                <slot name="description" />
            </template>
        </SectionTitle>

        <div class="mt-5 md:mt-0 md:col-span-2">
            <div v-if="!! $slots.ref" class="mb-1.5 flex items-center gap-2">
                <span class="bp-ref"><slot name="ref" /></span>
                <span class="bp-dim flex-1" aria-hidden="true" />
            </div>
            <form class="shadow-sheet" @submit.prevent="$emit('submitted')">
                <div
                    class="px-4 py-5 bg-bg sm:p-6 border border-border-line"
                    :class="hasActions ? 'border-b-0' : ''"
                >
                    <div class="grid grid-cols-6 gap-6">
                        <slot name="form" />
                    </div>
                </div>

                <div v-if="hasActions" class="flex items-center justify-end px-4 py-3 bg-surface text-end sm:px-6 border border-border-line">
                    <slot name="actions" />
                </div>
            </form>
        </div>
    </div>
</template>
