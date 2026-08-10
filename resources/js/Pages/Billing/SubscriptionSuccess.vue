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
                <div class="mb-5 flex items-center justify-center">
                    <span class="ins-stamp text-state-ok-ink">Paid</span>
                </div>
                <p class="bp-ref">BILLING/SUBSCRIBED</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight text-ink">You're subscribed</h1>
                <p class="mt-3 text-sm text-ink-dim">
                    Thanks. Your subscription is processing — credits and plan tier usually
                    activate within a few seconds. This page refreshes itself once that lands.
                </p>

                <div class="mt-8 flex items-center justify-center gap-3">
                    <Link
                        href="/billing"
                        class="rounded-none border border-violet bg-violet px-4 py-2 text-sm font-medium text-bg transition-colors hover:bg-bg hover:text-violet"
                    >
                        See your plan
                    </Link>
                    <Link
                        href="/chat"
                        class="rounded-none border border-border-hi bg-bg px-4 py-2 text-sm font-medium text-ink-dim hover:bg-surface-hi"
                    >
                        Open chat
                    </Link>
                </div>

                <p class="mt-10 font-mono text-[10px] text-ink-mute">FLOWSTACK</p>
            </div>
        </div>
    </AppLayout>
</template>
