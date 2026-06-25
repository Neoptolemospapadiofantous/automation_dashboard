<script setup>
import { Head } from '@inertiajs/vue3';
import AuthPortal from '@/Components/AuthPortal.vue';

defineProps({
    canResetPassword: Boolean,
    status: String,
});
</script>

<template>
    <Head title="Log in" />

    <AuthPortal initial-mode="login" :can-reset-password="canResetPassword" :status="status" />
</template>
