<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';

defineProps({
    policy: String,
});
</script>

<template>
    <Head title="Privacy Policy" />

    <div class="font-sans text-ink antialiased">
        <div class="pt-4 bg-bg-elev">
            <div class="min-h-screen flex flex-col items-center pt-6 sm:pt-0">
                <div>
                    <AuthenticationCardLogo />
                </div>

                <div class="w-full sm:max-w-2xl mt-6 p-6 bg-bg border border-border-line shadow-[8px_8px_0_rgba(0,0,0,0.06)] overflow-hidden rounded-none prose" v-html="policy" />
            </div>
        </div>
    </div>
</template>
