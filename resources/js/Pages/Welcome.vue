<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: { type: Boolean, default: false },
    canRegister: { type: Boolean, default: false },
});

const features = [
    {
        title: 'Answers grounded in your content',
        body: 'Upload your docs, FAQ, or pricing. The assistant answers from your knowledge base — and is told never to invent facts.',
    },
    {
        title: 'Captures leads automatically',
        body: 'It collects name, email, phone and intent in conversation, then drops a qualified lead straight onto your board.',
    },
    {
        title: 'Live across your team',
        body: 'New leads and replies appear in real time on every open screen — assign, qualify, and follow up without a refresh.',
    },
];
</script>

<template>
    <Head title="Flowstack — AI assistants that answer visitors and capture leads" />

    <div class="min-h-screen bg-bg text-ink selection:bg-violet selection:text-bg">
        <div class="mx-auto flex min-h-screen max-w-6xl flex-col px-6 lg:px-8">
            <!-- Nav -->
            <header class="flex items-center justify-between py-6">
                <span class="font-mono text-sm font-semibold uppercase tracking-[0.28em] text-violet">Flowstack</span>

                <nav class="flex items-center gap-2 text-sm">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="rounded-none px-3 py-2 font-medium text-ink hover:text-ink-dim"
                    >
                        Go to dashboard →
                    </Link>
                    <template v-else-if="canLogin">
                        <Link :href="route('login')" class="rounded-none px-3 py-2 font-medium text-ink-dim hover:text-ink">
                            Log in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="rounded-none bg-violet px-3.5 py-2 font-medium text-bg transition hover:opacity-90"
                        >
                            Get started
                        </Link>
                    </template>
                </nav>
            </header>

            <!-- Hero -->
            <main class="flex flex-1 flex-col justify-center py-12">
                <div class="max-w-3xl">
                    <span class="bp-ref">FLOWSTACK / AI SALES ASSISTANT</span>
                    <h1 class="mt-5 text-4xl font-semibold leading-[1.1] tracking-tight text-ink sm:text-5xl lg:text-6xl">
                        Turn website visitors into
                        <span class="text-violet">qualified leads</span> — automatically.
                    </h1>
                    <p class="mt-6 max-w-2xl text-lg leading-relaxed text-ink-dim">
                        Flowstack puts a branded AI assistant on your site that answers questions from
                        <em>your</em> knowledge base and captures leads in conversation — then syncs them
                        live to your team's board.
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <Link
                            v-if="$page.props.auth.user"
                            :href="route('dashboard')"
                            class="rounded-none bg-violet px-5 py-3 text-sm font-semibold text-bg transition hover:opacity-90"
                        >
                            Go to dashboard
                        </Link>
                        <template v-else-if="canLogin">
                            <Link
                                v-if="canRegister"
                                :href="route('register')"
                                class="rounded-none bg-violet px-5 py-3 text-sm font-semibold text-bg transition hover:opacity-90"
                            >
                                Get started
                            </Link>
                            <Link
                                :href="route('login')"
                                class="rounded-none border border-border-line px-5 py-3 text-sm font-semibold text-ink transition hover:bg-surface-hi"
                            >
                                Log in
                            </Link>
                        </template>
                        <span class="text-sm text-ink-mute">From €99/mo · cancel anytime.</span>
                    </div>
                </div>

                <!-- Features -->
                <div class="mt-16 grid gap-px overflow-hidden rounded-none border border-border-line bg-border-line sm:grid-cols-3">
                    <div v-for="f in features" :key="f.title" class="bg-bg p-6">
                        <h2 class="text-sm font-semibold text-ink">{{ f.title }}</h2>
                        <p class="mt-2 text-sm leading-relaxed text-ink-dim">{{ f.body }}</p>
                    </div>
                </div>
            </main>

            <!-- Footer -->
            <footer class="flex flex-col items-center justify-between gap-2 border-t border-border-line py-6 text-xs text-ink-mute sm:flex-row">
                <span>© Flowstack</span>
                <a href="mailto:hello@flowstack.run" class="hover:text-ink-dim">hello@flowstack.run</a>
            </footer>
        </div>
    </div>
</template>
