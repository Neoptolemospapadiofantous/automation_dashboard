import { ref } from 'vue';

/**
 * Promise-based confirm() replacement that uses the app's ConfirmationModal
 * instead of the browser's native confirm() dialog.
 *
 * Pages call:
 *   const ok = await confirm({
 *     title: 'Delete lead',
 *     message: `Delete "${lead.name}"?`,
 *     buttonText: 'Delete',
 *     dangerous: true,
 *   });
 *   if (!ok) return;
 *
 * The actual <ConfirmDialog> is rendered once in AppLayout and reads from
 * this module's shared state — that way every page gets the same modal
 * styling without each having to import + mount its own.
 */

const visible = ref(false);
const options = ref({
    title: 'Confirm',
    message: '',
    buttonText: 'Confirm',
    cancelText: 'Cancel',
    dangerous: false,
});
let resolver = null;

/** Page-side: ask the user. Returns Promise<boolean>. */
export function confirm({
    title = 'Confirm',
    message = '',
    buttonText = 'Confirm',
    cancelText = 'Cancel',
    dangerous = false,
} = {}) {
    options.value = { title, message, buttonText, cancelText, dangerous };
    visible.value = true;

    return new Promise((resolve) => {
        resolver = resolve;
    });
}

/** Modal-side: read the current request + bind cancel/confirm. */
export function useConfirmState() {
    return {
        visible,
        options,
        accept() {
            visible.value = false;
            if (resolver) {
                resolver(true);
                resolver = null;
            }
        },
        reject() {
            visible.value = false;
            if (resolver) {
                resolver(false);
                resolver = null;
            }
        },
    };
}
