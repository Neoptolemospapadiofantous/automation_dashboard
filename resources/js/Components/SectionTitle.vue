<template>
    <div class="md:col-span-1 flex justify-between">
        <div class="px-4 sm:px-0">
            <h3 class="flex items-center gap-2 text-lg font-medium text-ink">
                <span class="bp-dot flex-shrink-0" aria-hidden="true" />
                <slot name="title" />
            </h3>
            <div class="bp-dim mt-2 max-w-[6rem]" aria-hidden="true" />

            <p class="mt-2 text-sm text-ink-dim">
                <slot name="description" />
            </p>
        </div>

        <div class="px-4 sm:px-0">
            <slot name="aside" />
        </div>
    </div>
</template>
