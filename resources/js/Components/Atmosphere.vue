<script setup>
/**
 * Fixed, page-wide ambient backdrop behind all content — the landing's
 * grid layer, ported across (automation-landing/src/components/atmosphere.tsx).
 *
 * A faint blueprint grid that slowly drifts, giving the flat paper sheet
 * a sense of depth. Replaces the per-page `.bg-grid` backdrops that used
 * to sit on the dashboard hero and page headers: one ambient grid for the
 * whole shell, exactly as the landing does since the 2026-08 "ink on
 * paper" redesign.
 *
 * Decorative only — aria-hidden and pointer-events-none, and pinned at
 * -z-10 so it never intercepts interaction or covers content. Motion is
 * silenced under prefers-reduced-motion by the .atmosphere-grid rules in
 * resources/css/motifs.css.
 *
 * -z-10 works because the sheet colour lives on <body> (app.blade.php),
 * which propagates to the canvas — a negative-z layer paints above the
 * canvas but below every in-flow element. Put an opaque background on a
 * wrapper between the two and the grid disappears.
 */
</script>

<template>
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <span class="atmosphere-grid" />
    </div>
</template>
