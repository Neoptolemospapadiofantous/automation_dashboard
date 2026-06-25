<script setup>
import { Head } from '@inertiajs/vue3';
import AuthPortal from '@/Components/AuthPortal.vue';
</script>

<template>
    <Head title="Register" />

    <AuthPortal initial-mode="register" />
</template>
