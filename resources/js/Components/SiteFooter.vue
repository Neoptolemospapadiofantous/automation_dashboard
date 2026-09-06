<script setup>
/**
 * Footer with legal + support links pointing at flowstack.run.
 * Lives at the bottom of the AppLayout. Customers expect Privacy /
 * Terms / Security links visible without having to leave the
 * dashboard.
 *
 * URLs deliberately absolute → flowstack.run so the dashboard works
 * standalone (different subdomain) and a single canonical source
 * of legal copy stays on the marketing site.
 */
const LANDING = 'https://flowstack.run';
const year = new Date().getFullYear();
</script>

<template>
    <!-- bg-footer-grid: the denser "title block" register under a printed
         sheet — the one place the landing still carries a grid of its own,
         and the only place the dashboard does now. relative keeps the footer
         painting above the -z-10 atmosphere layer. -->
    <footer class="bg-footer-grid relative border-t border-border-line bg-bg">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <div class="flex flex-col items-start gap-3 font-mono text-xs tracking-wider text-ink-mute sm:flex-row sm:items-center sm:justify-between">
                <p>
                    © {{ year }} Flowstack ·
                    <a :href="LANDING" class="hover:text-ink hover:underline" target="_blank" rel="noopener">flowstack.run</a>
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
