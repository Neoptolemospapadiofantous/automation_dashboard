<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <Link :href="'/'" class="text-ink">
        <!-- Flowstack mark (automation-landing/branding/source/mark-square-*.svg).
             Strokes use currentColor so the mark renders in ink on either sheet. -->
        <svg
            class="size-14"
            viewBox="0 0 512 512"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            role="img"
            aria-label="Flowstack"
        >
            <path d="M30 256 H110" stroke="currentColor" stroke-width="18" />
            <rect x="120" y="180" width="272" height="152" stroke="currentColor" stroke-width="18" fill="none" />
            <rect x="167" y="232" width="48" height="48" fill="currentColor" />
            <rect x="297" y="232" width="48" height="48" stroke="currentColor" stroke-width="14" fill="none" />
            <path d="M402 256 H468" stroke="currentColor" stroke-width="18" />
            <path d="M448 218 L482 256 L448 294" stroke="currentColor" stroke-width="18" stroke-linecap="square" stroke-linejoin="miter" fill="none" />
        </svg>
    </Link>
</template>
