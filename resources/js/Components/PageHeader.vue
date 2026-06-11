<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Standard page header used by every Phase 13+ page.
 *
 * <PageHeader
 *   :breadcrumbs="[{ label: 'Agents', href: route('agents.index') }, { label: agent.name }]"
 *   title="Sales Bot"
 *   description="Engine configuration"
 * >
 *   <template #actions>
 *     <PrimaryButton>Save</PrimaryButton>
 *   </template>
 * </PageHeader>
 *
 * Without props this is just a slot for fully custom headers.
 */
defineProps({
    title: { type: String, default: null },
    description: { type: String, default: null },
    breadcrumbs: { type: Array, default: () => [] },
});
</script>

<template>
    <header class="border-b border-gray-200 bg-white">
        <div class="mx-auto max-w-7xl px-4 py-5 sm:px-6 lg:px-8">
            <nav v-if="breadcrumbs.length" class="mb-2 flex" aria-label="Breadcrumb">
                <ol class="flex items-center gap-1.5 text-xs text-gray-500">
                    <li v-for="(crumb, i) in breadcrumbs" :key="i" class="flex items-center gap-1.5">
                        <component
                            :is="crumb.href ? Link : 'span'"
                            v-if="crumb.label"
                            :href="crumb.href"
                            :class="[
                                crumb.href ? 'text-gray-500 hover:text-gray-700' : 'text-gray-700 font-medium',
                            ]"
                        >
                            {{ crumb.label }}
                        </component>
                        <svg v-if="i < breadcrumbs.length - 1" class="size-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </li>
                </ol>
            </nav>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <slot name="title">
                        <h1 v-if="title" class="truncate text-xl font-semibold leading-7 text-gray-900">
                            {{ title }}
                        </h1>
                    </slot>
                    <p v-if="description" class="mt-0.5 text-sm text-gray-500">
                        {{ description }}
                    </p>
                </div>
                <div v-if="$slots.actions" class="flex items-center gap-2 sm:ms-4">
                    <slot name="actions" />
                </div>
            </div>

            <div v-if="$slots.default" class="mt-4">
                <slot />
            </div>
        </div>
    </header>
</template>
