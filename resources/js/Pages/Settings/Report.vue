<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { confirm } from '@/Composables/useConfirm';

const props = defineProps({
    shareUrl: { type: String, default: null },
    preview: { type: Object, required: true },
});

const page = usePage();
const isOwner = computed(() => page.props.billing?.is_owner ?? false);

const copied = ref(false);
async function copy() {
    try {
        await navigator.clipboard.writeText(props.shareUrl);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

const enable = () => router.post(route('report.enable'), {}, { preserveScroll: true });
async function rotate() {
    const ok = await confirm({ title: 'Create a new link', message: 'The current link stops working for everyone who has it.', buttonText: 'New link' });
    if (ok) router.post(route('report.rotate'), {}, { preserveScroll: true });
}
async function disable() {
    const ok = await confirm({ title: 'Turn sharing off', message: 'The link stops working. You can turn it back on later with a new link.', buttonText: 'Turn off', dangerous: true });
    if (ok) router.post(route('report.disable'), {}, { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Weekly report">
        <PageHeader
            width="max-w-4xl"
            title="Weekly report"
            description="One page with last week's numbers that anyone with the link can read — no login. It refreshes itself every week."
        >
            <template #actions><span class="bp-ref">SET/REPORT</span></template>
        </PageHeader>

        <div class="py-6">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                    <template v-if="shareUrl">
                        <p class="text-sm font-medium text-ink">Sharing is on.</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <a :href="shareUrl" target="_blank" rel="noopener" class="break-all font-mono text-xs text-violet underline">{{ shareUrl }}</a>
                            <SecondaryButton type="button" @click="copy">{{ copied ? 'Copied' : 'Copy link' }}</SecondaryButton>
                        </div>
                        <p class="mt-2 text-xs text-ink-dim">The Monday email carries this link too. Anyone who has it can read the report until you rotate or turn it off.</p>
                        <div v-if="isOwner" class="mt-4 flex flex-wrap gap-2">
                            <SecondaryButton type="button" @click="rotate">New link</SecondaryButton>
                            <DangerButton type="button" @click="disable">Turn sharing off</DangerButton>
                        </div>
                    </template>
                    <template v-else>
                        <p class="text-sm font-medium text-ink">Sharing is off.</p>
                        <p class="mt-1 text-xs text-ink-dim">Turn it on to get a link you can forward to a partner, an accountant or a colleague without an account.</p>
                        <PrimaryButton v-if="isOwner" class="mt-4" type="button" @click="enable">Turn sharing on</PrimaryButton>
                        <p v-else class="mt-3 text-xs text-ink-dim">Only the team owner can turn sharing on.</p>
                    </template>
                </div>

                <div class="rounded-none border border-border-line bg-bg shadow-sheet">
                    <div class="border-b border-border-line bg-bg-elev px-4 py-2 font-mono text-xs uppercase tracking-wider text-ink-dim">
                        Preview · {{ preview.window.start }} → {{ preview.window.end }}
                    </div>
                    <div class="grid grid-cols-2 gap-px bg-border-line sm:grid-cols-4">
                        <div class="bg-bg p-4"><div class="font-mono text-[10px] uppercase tracking-wider text-ink-mute">Conversations</div><div class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ preview.conversations }}</div></div>
                        <div class="bg-bg p-4"><div class="font-mono text-[10px] uppercase tracking-wider text-ink-mute">Leads</div><div class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ preview.leads }}</div></div>
                        <div class="bg-bg p-4"><div class="font-mono text-[10px] uppercase tracking-wider text-ink-mute">Asked for a human</div><div class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ preview.escalated }}</div></div>
                        <div class="bg-bg p-4"><div class="font-mono text-[10px] uppercase tracking-wider text-ink-mute">Satisfaction</div><div class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ preview.csat === null ? '—' : `${preview.csat}%` }}</div></div>
                    </div>
                    <p class="px-4 py-3 text-xs text-ink-dim">The public page shows the same numbers, a day-by-day bar, the unanswered questions and the housekeeping list. Nothing else — no transcripts, no names.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
