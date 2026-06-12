<template>
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-bg-elev">
        <div>
            <slot name="logo" />
        </div>

        <!-- Blueprint panel: white sheet, hairline border, hard offset shadow. -->
        <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-bg border border-border-line shadow-[8px_8px_0_rgba(0,0,0,0.06)] overflow-hidden">
            <slot />
        </div>
    </div>
</template>
