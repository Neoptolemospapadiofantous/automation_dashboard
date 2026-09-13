<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import { confirm } from '@/Composables/useConfirm';

const props = defineProps({
    allowed: { type: Boolean, required: true },
    planLabel: { type: String, required: true },
    events: { type: Object, required: true },
    webhooks: { type: Array, default: () => [] },
    newSecret: { type: String, default: null },
});

const page = usePage();
const isOwner = computed(() => page.props.billing?.is_owner ?? false);

const form = useForm({ url: '', events: Object.keys(props.events) });
const submit = () => form.post(route('webhooks.store'), { preserveScroll: true, onSuccess: () => form.reset('url') });

const copied = ref(false);
async function copySecret() {
    try {
        await navigator.clipboard.writeText(props.newSecret);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}

const test = (id) => router.post(route('webhooks.test', id), {}, { preserveScroll: true });
const toggle = (id) => router.post(route('webhooks.toggle', id), {}, { preserveScroll: true });
async function remove(hook) {
    const ok = await confirm({ title: 'Remove webhook', message: `Stop sending events to ${hook.url}?`, buttonText: 'Remove', dangerous: true });
    if (!ok) return;
    router.delete(route('webhooks.destroy', hook.id), { preserveScroll: true });
}

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : 'never');

const sample = `{
  "id": "01J8…",
  "event": "lead.captured",
  "created_at": "2026-09-13T10:00:00+00:00",
  "team_id": 1,
  "data": { "id": 42, "name": "Maria K.", "email": "maria@example.com", "phone": null,
            "company": null, "source": "chat", "status": "new", "score": 72,
            "tags": ["hot"], "url": "https://app.flowstack.run/leads/42" }
}`;
</script>

<template>
    <AppLayout title="Webhooks">
        <PageHeader
            width="max-w-4xl"
            title="Webhooks"
            description="Send an event to your own URL the moment it happens — Zapier, Make, Google Sheets, a CRM. Signed with a secret you keep."
        >
            <template #actions><span class="bp-ref">SET/HOOKS</span></template>
        </PageHeader>

        <div class="py-6">
            <div class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Shown once, right after creation. -->
                <div v-if="newSecret" class="rounded-none border border-violet bg-bg p-4 shadow-sheet">
                    <p class="text-sm font-medium text-ink">Your signing secret — copy it now, it is not shown again.</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <code class="break-all rounded-none bg-surface-hi px-2 py-1 font-mono text-xs text-ink">{{ newSecret }}</code>
                        <SecondaryButton type="button" @click="copySecret">{{ copied ? 'Copied' : 'Copy' }}</SecondaryButton>
                    </div>
                    <p class="mt-2 text-xs text-ink-dim">
                        Each request carries <code class="font-mono">X-Flowstack-Signature: t=&lt;unix&gt;,v1=&lt;hex&gt;</code>, where v1 is HMAC-SHA256 of <code class="font-mono">&lt;t&gt;.&lt;raw body&gt;</code> with this secret.
                    </p>
                </div>

                <div v-if="!allowed" class="rounded-none border border-border-line bg-surface p-4 text-sm text-ink-dim">
                    Webhooks are part of the paid plans. You are on <strong class="text-ink">{{ planLabel }}</strong> —
                    <a :href="route('billing.index')" class="text-ink underline">upgrade on the Billing page</a> to connect your tools.
                </div>

                <form v-else-if="isOwner" class="rounded-none border border-border-line bg-bg p-4 shadow-sheet" @submit.prevent="submit">
                    <h2 class="font-medium text-ink">Add an endpoint</h2>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto]">
                        <div>
                            <InputLabel for="hook-url" value="URL (https)" />
                            <TextInput id="hook-url" v-model="form.url" type="url" class="mt-1 block w-full" placeholder="https://hooks.zapier.com/hooks/catch/…" required />
                            <InputError class="mt-1" :message="form.errors.url" />
                        </div>
                        <div class="sm:pt-6">
                            <PrimaryButton :disabled="form.processing || !form.url || !form.events.length">{{ form.processing ? 'Adding…' : 'Add webhook' }}</PrimaryButton>
                        </div>
                    </div>
                    <fieldset class="mt-3">
                        <legend class="font-mono text-[10px] uppercase tracking-[0.2em] text-ink-mute">Events</legend>
                        <div class="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                            <label v-for="(label, key) in events" :key="key" class="inline-flex cursor-pointer items-start gap-2 text-sm text-ink">
                                <input v-model="form.events" type="checkbox" :value="key" class="mt-0.5 size-4 rounded-none border-border-hi text-ink focus:ring-2 focus:ring-ink focus:ring-offset-1" />
                                <span><span class="font-mono text-xs">{{ key }}</span><br /><span class="text-xs text-ink-dim">{{ label }}</span></span>
                            </label>
                        </div>
                        <InputError class="mt-1" :message="form.errors.events" />
                    </fieldset>
                </form>
                <p v-else class="text-xs text-ink-dim">Only the team owner can add or change webhooks.</p>

                <div class="overflow-x-auto rounded-none border border-border-line bg-bg shadow-sheet">
                    <table class="min-w-full divide-y divide-border-line text-sm">
                        <thead class="bg-bg-elev text-left font-mono text-xs uppercase tracking-wider text-ink-dim">
                            <tr>
                                <th class="px-4 py-3">Endpoint</th>
                                <th class="px-4 py-3">Events</th>
                                <th class="px-4 py-3">Last delivery</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-line">
                            <tr v-for="hook in webhooks" :key="hook.id">
                                <td class="max-w-xs px-4 py-3">
                                    <div class="truncate font-mono text-xs text-ink" :title="hook.url">{{ hook.url }}</div>
                                    <span class="mt-1 inline-block rounded-none px-1.5 py-0.5 font-mono text-[10px]" :class="hook.active ? 'bg-state-ok-surface text-state-ok-ink' : 'bg-surface-hi text-ink-dim'">
                                        {{ hook.active ? 'active' : (hook.failure_count >= 25 ? 'switched off after repeated failures' : 'paused') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span v-for="e in hook.events" :key="e" class="mr-1 inline-block rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-[10px] text-ink-dim">{{ e }}</span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs">
                                    <div :class="hook.last_error ? 'text-state-bad-ink' : 'text-ink-dim'">
                                        {{ hook.last_status ? `HTTP ${hook.last_status}` : (hook.last_error ? 'failed' : '—') }}
                                        <span v-if="hook.last_error" :title="hook.last_error"> · {{ hook.last_error.slice(0, 60) }}</span>
                                    </div>
                                    <div class="text-ink-mute">{{ fmt(hook.last_delivered_at) }}</div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div v-if="isOwner" class="flex flex-wrap justify-end gap-1">
                                        <button type="button" class="px-2 py-1.5 text-xs text-ink-dim hover:text-ink" @click="test(hook.id)">Send test</button>
                                        <button type="button" class="px-2 py-1.5 text-xs text-ink-dim hover:text-ink" @click="toggle(hook.id)">{{ hook.active ? 'Pause' : 'Enable' }}</button>
                                        <button type="button" class="px-2 py-1.5 text-xs text-ink-dim hover:text-state-bad-ink" @click="remove(hook)">Remove</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!webhooks.length">
                                <td colspan="4" class="px-4 py-10 text-center text-sm text-ink-mute">No webhooks yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <details class="rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                    <summary class="cursor-pointer text-sm font-medium text-ink">What a delivery looks like</summary>
                    <p class="mt-2 text-xs text-ink-dim">POST, JSON body, headers <code class="font-mono">X-Flowstack-Event</code>, <code class="font-mono">X-Flowstack-Delivery</code> and <code class="font-mono">X-Flowstack-Signature</code>. Reply with any 2xx within 10 seconds; anything else is retried twice (after 30 s and 5 min). After 25 failures in a row the endpoint is switched off.</p>
                    <pre class="mt-3 overflow-x-auto rounded-none bg-surface-hi p-3 font-mono text-[11px] leading-relaxed text-ink">{{ sample }}</pre>
                </details>
            </div>
        </div>
    </AppLayout>
</template>
