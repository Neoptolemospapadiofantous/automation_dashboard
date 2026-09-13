<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    preferences: { type: Object, required: true },
    events: { type: Array, required: true },
    timezones: { type: Array, required: true },
    callConfigured: { type: Boolean, default: false },
});

const form = useForm({
    events: JSON.parse(JSON.stringify(props.preferences.events)),
    quiet_hours: { ...props.preferences.quiet_hours },
});

const channelLabel = { bell: 'Bell', mail: 'Email', call: 'Phone call' };

const submit = () => form.put(route('notifications.preferences.update'), { preserveScroll: true });
</script>

<template>
    <AppLayout title="Notifications">
        <PageHeader
            width="max-w-4xl"
            title="Notifications"
            description="Which alerts reach you, and where. These are yours alone — teammates set their own."
        >
            <template #actions><span class="bp-ref">SET/NOTIFY</span></template>
        </PageHeader>

        <div class="py-6">
            <form class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8" @submit.prevent="submit">
                <div class="overflow-x-auto rounded-none border border-border-line bg-bg shadow-sheet">
                    <table class="min-w-full divide-y divide-border-line text-sm">
                        <thead class="bg-bg-elev text-left font-mono text-xs uppercase tracking-wider text-ink-dim">
                            <tr>
                                <th class="px-4 py-3">Event</th>
                                <th v-for="c in ['bell', 'mail', 'call']" :key="c" class="px-4 py-3 text-center">{{ channelLabel[c] }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-line">
                            <tr v-for="ev in events" :key="ev.key">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-ink">{{ ev.label }}</div>
                                    <div class="text-xs text-ink-dim">{{ ev.hint }}</div>
                                </td>
                                <td v-for="c in ['bell', 'mail', 'call']" :key="c" class="px-4 py-3 text-center">
                                    <label v-if="ev.channels.includes(c)" class="inline-flex size-8 cursor-pointer items-center justify-center">
                                        <input
                                            v-model="form.events[ev.key][c]"
                                            type="checkbox"
                                            class="size-4 rounded-none border-border-hi text-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                                            :aria-label="`${ev.label} — ${channelLabel[c]}`"
                                        />
                                    </label>
                                    <span v-else class="text-ink-mute">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="!callConfigured" class="text-xs text-ink-dim">
                    The phone call is not configured on this installation yet, so the call column has no effect until it is.
                </p>

                <div class="rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="font-medium text-ink">Quiet hours</h2>
                            <p class="text-xs text-ink-dim">No email and no phone call inside this window. The bell still records everything, so nothing is lost.</p>
                        </div>
                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-ink">
                            <input v-model="form.quiet_hours.enabled" type="checkbox" class="size-4 rounded-none border-border-hi text-ink focus:ring-2 focus:ring-ink focus:ring-offset-1" />
                            On
                        </label>
                    </div>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3" :class="{ 'opacity-50': !form.quiet_hours.enabled }">
                        <label class="text-xs text-ink-dim">
                            From
                            <input v-model="form.quiet_hours.start" type="time" class="mt-1 block w-full rounded-none border-border-hi bg-bg text-[16px] text-ink focus:border-ink focus:ring-ink sm:text-sm" :disabled="!form.quiet_hours.enabled" />
                        </label>
                        <label class="text-xs text-ink-dim">
                            Until
                            <input v-model="form.quiet_hours.end" type="time" class="mt-1 block w-full rounded-none border-border-hi bg-bg text-[16px] text-ink focus:border-ink focus:ring-ink sm:text-sm" :disabled="!form.quiet_hours.enabled" />
                        </label>
                        <label class="text-xs text-ink-dim">
                            Time zone
                            <select v-model="form.quiet_hours.timezone" class="mt-1 block w-full rounded-none border-border-hi bg-bg text-[16px] text-ink focus:border-ink focus:ring-ink sm:text-sm" :disabled="!form.quiet_hours.enabled">
                                <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                            </select>
                        </label>
                    </div>
                    <InputError class="mt-2" :message="form.errors['quiet_hours.start'] || form.errors['quiet_hours.end'] || form.errors['quiet_hours.timezone']" />
                </div>

                <div class="flex items-center gap-3">
                    <PrimaryButton :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save preferences' }}</PrimaryButton>
                    <span v-if="form.recentlySuccessful" class="text-xs text-state-ok-ink">Saved.</span>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
