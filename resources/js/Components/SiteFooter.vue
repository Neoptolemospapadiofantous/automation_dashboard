<script setup>
/**
 * Footer with legal + support links pointing at flowstack.com.
 * Lives at the bottom of the AppLayout. Customers expect Privacy /
 * Terms / Security links visible without having to leave the
 * dashboard.
 *
 * URLs deliberately absolute → flowstack.com so the dashboard works
 * standalone (different subdomain) and a single canonical source
 * of legal copy stays on the marketing site.
 */
const LANDING = 'https://flowstack.com';
const year = new Date().getFullYear();
</script>

<template>
    <footer class="border-t border-border-line bg-bg">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-col items-start gap-3 font-mono text-xs tracking-wider text-ink-mute sm:flex-row sm:items-center sm:justify-between">
                <p>
                    © {{ year }} Flowstack ·
                    <a :href="LANDING" class="hover:text-ink hover:underline" target="_blank" rel="noopener">flowstack.com</a>
                </p>
                <nav class="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <a :href="`${LANDING}/pricing`" target="_blank" rel="noopener" class="hover:text-ink hover:underline">Pricing</a>
                    <a :href="`${LANDING}/privacy`" target="_blank" rel="noopener" class="hover:text-ink hover:underline">Privacy</a>
                    <a :href="`${LANDING}/terms`" target="_blank" rel="noopener" class="hover:text-ink hover:underline">Terms</a>
                    <a :href="`${LANDING}/security`" target="_blank" rel="noopener" class="hover:text-ink hover:underline">Security</a>
                    <a :href="`${LANDING}/dpa`" target="_blank" rel="noopener" class="hover:text-ink hover:underline">DPA</a>
                </nav>
            </div>
        </div>
    </footer>
</template>
