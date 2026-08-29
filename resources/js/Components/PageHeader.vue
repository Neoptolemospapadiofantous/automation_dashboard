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
    // Optional mono sheet-reference label shown to the left of the title
    // (e.g. "DASH/01"). Falls back to a generic decorative ref.
    refLabel: { type: String, default: 'FS' },
    // Column the header shares with the page body — pass the body's own
    // max-w-* so title and content keep one left edge at every width.
    width: { type: String, default: 'max-w-7xl' },
});
</script>

<template>
    <header class="relative overflow-hidden border-b border-border-line bg-bg">
        <div class="bg-grid bg-grid-fade pointer-events-none absolute inset-0 opacity-50" aria-hidden="true" />
        <div class="relative mx-auto px-4 py-4 sm:px-6 sm:py-5 lg:px-8" :class="width">
            <nav v-if="breadcrumbs.length" class="mb-2 flex" aria-label="Breadcrumb">
                <ol class="flex items-center gap-1.5 font-mono text-xs tracking-wider text-ink-dim">
                    <li v-for="(crumb, i) in breadcrumbs" :key="i" class="flex items-center gap-1.5">
                        <component
                            :is="crumb.href ? Link : 'span'"
                            v-if="crumb.label"
                            :href="crumb.href"
                            :class="[
                                crumb.href ? 'py-1 text-ink-dim hover:text-ink hover:underline' : 'text-ink font-medium',
                            ]"
                        >
                            {{ crumb.label }}
                        </component>
                        <svg v-if="i < breadcrumbs.length - 1" class="size-3 text-ink-mute" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </li>
                </ol>
            </nav>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <slot name="title">
                        <div v-if="title" class="flex items-center gap-2.5">
                            <span v-if="refLabel" class="bp-ref flex-shrink-0">{{ refLabel }}</span>
                            <h1 class="truncate text-xl font-semibold leading-7 text-ink">
                                {{ title }}
                            </h1>
                        </div>
                    </slot>
                    <div v-if="title" class="bp-dim mt-2 max-w-[7rem]" aria-hidden="true" />
                    <p v-if="description" class="mt-2 text-sm text-ink-dim">
                        {{ description }}
                    </p>
                </div>
                <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 sm:ms-4">
                    <slot name="actions" />
                </div>
            </div>

            <div v-if="$slots.default" class="mt-4">
                <slot />
            </div>
        </div>
    </header>
</template>
