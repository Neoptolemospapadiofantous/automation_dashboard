<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    session_id: { type: String, default: null },
});

// Auto-refresh after a few seconds — the Stripe webhook normally lands
// within 1-3s of redirect, so a poll-then-show is friendlier than a
// hard "refresh to see your credits".
const refreshing = ref(false);
function refresh() {
    refreshing.value = true;
    router.reload({
        only: ['auth', 'billing'],
        onFinish: () => (refreshing.value = false),
    });
}
setTimeout(refresh, 2500);
</script>

<template>
    <AppLayout title="Subscription activated">
        <Head title="Subscription activated" />
        <div class="flex min-h-[60vh] items-center justify-center px-4">
            <div class="max-w-md text-center">
                <p class="font-mono text-[10px] uppercase tracking-[0.3em] text-emerald-600">PAYMENT CONFIRMED</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight text-gray-900">You're subscribed</h1>
                <p class="mt-3 text-sm text-gray-600">
                    Thanks. Your subscription is processing — credits and plan tier usually
                    activate within a few seconds. This page refreshes itself once that lands.
                </p>

                <div class="mt-8 flex items-center justify-center gap-3">
                    <Link
                        href="/billing"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        See your plan
                    </Link>
                    <Link
                        href="/chat"
                        class="rounded-md border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Open chat
                    </Link>
                </div>

                <p class="mt-10 font-mono text-[10px] text-gray-300">FLOWSTACK</p>
            </div>
        </div>
    </AppLayout>
</template>
