<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    status: { type: Number, default: 500 },
});

const meta = computed(() => {
    switch (props.status) {
        case 404:
            return {
                eyebrow: 'ERR-404',
                title: 'Page not found',
                body: "The page you were looking for doesn't exist or was moved. Most things live in the sidebar.",
            };
        case 403:
            return {
                eyebrow: 'ERR-403',
                title: 'Not allowed',
                body: "You don't have access to that page. If you think this is a mistake, check that you're signed in to the right team.",
            };
        case 419:
            return {
                eyebrow: 'ERR-419',
                title: 'Session expired',
                body: 'Your session timed out. Sign in again to continue.',
            };
        case 429:
            return {
                eyebrow: 'ERR-429',
                title: 'Slow down',
                body: 'You sent too many requests in a short window. Wait a moment and try again.',
            };
        case 503:
            return {
                eyebrow: 'ERR-503',
                title: 'Temporarily unavailable',
                body: "We're at capacity right now. Try again in a few minutes — we've been notified.",
            };
        default:
            return {
                eyebrow: `ERR-${props.status}`,
                title: 'Something went wrong',
                body: "Something on our end broke. We've logged it; if it keeps happening, drop us a line.",
            };
    }
});
</script>

<template>
    <Head :title="meta.title" />
    <div class="flex min-h-screen items-center justify-center bg-bg-elev px-4">
        <div class="mx-auto max-w-md text-center">
            <p class="font-mono text-[10px] uppercase tracking-[0.3em] text-ink-mute">{{ meta.eyebrow }}</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-ink">{{ meta.title }}</h1>
            <p class="mt-3 text-sm text-ink-dim">{{ meta.body }}</p>

            <div class="mt-8 flex items-center justify-center gap-3">
                <Link
                    href="/dashboard"
                    class="btn-signal rounded-none border px-4 py-2 text-sm font-medium"
                >
                    Back to dashboard
                </Link>
                <a
                    href="https://flowstack.run"
                    class="rounded-none border border-border-hi bg-bg px-4 py-2 text-sm font-medium text-ink-dim hover:bg-surface-hi active:bg-surface-hi"
                >
                    Visit flowstack.run
                </a>
            </div>

            <p class="mt-10 font-mono text-[10px] text-ink-mute">FLOWSTACK</p>
        </div>
    </div>
</template>
