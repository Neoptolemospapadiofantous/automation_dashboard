<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
</script>

<template>
    <AppLayout title="Subscription canceled">
        <Head title="Subscription canceled" />
        <div class="flex min-h-[60vh] items-center justify-center px-4">
            <div class="max-w-md text-center">
                <p class="font-mono text-[10px] uppercase tracking-[0.3em] text-gray-400">CANCELED</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight text-gray-900">No payment taken</h1>
                <p class="mt-3 text-sm text-gray-600">
                    You backed out of checkout — no charge was made. You can try
                    again any time from the billing page.
                </p>

                <div class="mt-8 flex items-center justify-center gap-3">
                    <Link
                        href="/billing"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Back to billing
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
