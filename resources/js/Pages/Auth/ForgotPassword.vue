<script setup>
import { Head } from '@inertiajs/vue3';
import AuthPortal from '@/Components/AuthPortal.vue';

defineProps({
    status: String,
});
</script>

<template>
    <Head title="Forgot Password" />

    <AuthPortal initial-mode="forgot" :status="status" />
</template>
