<script setup>
import { Link } from '@inertiajs/vue3';
import ApplicationMark from '@/Components/ApplicationMark.vue';
</script>

<!--
    Shared shell for every auth page (login/register portal, 2FA, reset,
    verify, confirm-password). Split-panel blueprint layout: a brand panel
    with the canonical tagline (§3.4) and an agent schematic on the left
    (desktop only), the form sheet over a fading grid on the right. All
    colors come from the sheet tokens, so it re-themes in dark mode for free.
-->
<template>
    <div class="relative flex min-h-screen bg-bg-elev">
        <!-- ── Brand panel (desktop) ─────────────────────────────────── -->
        <aside class="relative hidden w-[46%] flex-col justify-between overflow-hidden border-r border-border-line bg-bg lg:flex" aria-hidden="true">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-60" />

            <!-- Title block: mark + wordmark as one lockup -->
            <div class="relative flex items-center gap-3 px-10 pt-8">
                <Link :href="'/'" class="text-ink">
                    <ApplicationMark class="h-9 w-auto" />
                </Link>
                <span class="bp-ref flex items-center gap-1.5">
                    <span class="bp-dot pulse-glow text-violet" />
                    Flowstack
                </span>
            </div>

            <!-- Tagline + schematic -->
            <div class="relative px-10 py-12">
                <h1 class="bp-rise max-w-md text-3xl font-semibold leading-tight text-ink" style="--rise-delay: 40ms">
                    Automate the busywork.
                    <span class="text-ink-dim">Aggregate the data.</span>
                    Answer every inbound.
                </h1>
                <p class="bp-rise mt-4 max-w-md text-sm leading-relaxed text-ink-dim" style="--rise-delay: 110ms">
                    Automations run the repetitive work, your data lands in one
                    live view, and a chat agent on your site answers every inbound.
                </p>

                <!-- Flow schematic: visitor → agent → lead -->
                <div class="bp-rise mt-12 flex max-w-md items-center" style="--rise-delay: 180ms">
                    <div class="bp-node whitespace-nowrap px-3.5 py-2.5">
                        <span class="bp-annot">VISITOR</span>
                    </div>
                    <div class="bp-wire w-12 flex-none" />
                    <div class="bp-node flex items-center gap-2 whitespace-nowrap px-3.5 py-2.5 shadow-sheet">
                        <span class="bp-dot pulse-glow text-violet" />
                        <span class="bp-annot text-ink">AGENT</span>
                    </div>
                    <div class="bp-wire w-12 flex-none" />
                    <div class="bp-node whitespace-nowrap px-3.5 py-2.5">
                        <span class="bp-annot">LEAD</span>
                    </div>
                </div>
                <div class="bp-rise mt-5 flex max-w-md items-center gap-3" style="--rise-delay: 250ms">
                    <span class="bp-dim w-16 flex-none" />
                    <span class="bp-annot">answers · qualifies · books · hands off</span>
                </div>
            </div>

            <!-- Register strip -->
            <div class="relative border-t border-border-line px-10 py-4">
                <div class="flex items-center justify-between">
                    <span class="bp-annot">FLOWSTACK STUDIO</span>
                    <span class="bp-annot">SHEET · ACCESS/01</span>
                    <span class="bp-annot">NICOSIA · CY</span>
                </div>
            </div>
        </aside>

        <!-- ── Form column ───────────────────────────────────────────── -->
        <div class="relative flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6">
            <div class="bg-grid bg-grid-fade pointer-events-none absolute inset-0 opacity-50" aria-hidden="true" />

            <!-- Mark above the sheet on mobile; the brand panel owns it on desktop. -->
            <div class="relative lg:hidden">
                <slot name="logo" />
            </div>

            <!-- Blueprint panel: white sheet, hairline border, hard offset shadow. -->
            <div class="relative mt-6 w-full max-w-md overflow-hidden border border-border-line bg-bg p-6 shadow-sheet sm:p-8 lg:mt-0">
                <slot />
            </div>

            <!-- Legal copy canonically lives on the marketing site (same
                 reasoning as SiteFooter — single source, works cross-subdomain). -->
            <p class="relative mt-6 text-center text-xs text-ink-mute">
                © Flowstack ·
                <a href="https://flowstack.run/terms" target="_blank" rel="noopener" class="underline hover:text-ink-dim">Terms</a> ·
                <a href="https://flowstack.run/privacy" target="_blank" rel="noopener" class="underline hover:text-ink-dim">Privacy</a>
            </p>
        </div>
    </div>
</template>
