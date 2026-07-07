<script setup>
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { useConfirmState } from '@/Composables/useConfirm';

// Single, app-wide confirm dialog. Mounted once inside AppLayout. Pages
// trigger it by calling confirm() from useConfirm; the result is a
// promise resolving to true/false. Replaces the browser's native
// confirm() everywhere so the modal feels consistent with the rest of
// the app (matches DeleteTeam, ApiTokenManager, etc.).
const { visible, options, accept, reject } = useConfirmState();
</script>

<template>
    <ConfirmationModal :show="visible" @close="reject">
        <template #title>{{ options.title }}</template>
        <template #content>{{ options.message }}</template>
        <template #footer>
            <SecondaryButton @click="reject">
                {{ options.cancelText }}
            </SecondaryButton>
            <DangerButton v-if="options.dangerous" class="sm:ms-3" @click="accept">
                {{ options.buttonText }}
            </DangerButton>
            <PrimaryButton v-else class="sm:ms-3" @click="accept">
                {{ options.buttonText }}
            </PrimaryButton>
        </template>
    </ConfirmationModal>
</template>
