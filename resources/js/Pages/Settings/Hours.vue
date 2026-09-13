<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps({
    hours: { type: Object, required: true },
    openNow: { type: Boolean, required: true },
    nextOpening: { type: String, default: null },
    defaultAway: { type: String, required: true },
    days: { type: Array, required: true },
    timezones: { type: Array, required: true },
});

const page = usePage();
const isOwner = computed(() => page.props.billing?.is_owner ?? false);

const dayLabel = { mon: 'Monday', tue: 'Tuesday', wed: 'Wednesday', thu: 'Thursday', fri: 'Friday', sat: 'Saturday', sun: 'Sunday' };

// Each day: { open: bool, from, until } — flattened for the form, folded
// back into the [from, until] | null shape the server keeps.
const form = useForm({
    enabled: props.hours.enabled,
    timezone: props.hours.timezone,
    away_message: props.hours.away_message === props.defaultAway ? '' : props.hours.away_message,
    rows: Object.fromEntries(props.days.map((d) => [d, {
        open: props.hours.days[d] !== null,
        from: props.hours.days[d]?.[0] ?? '09:00',
        until: props.hours.days[d]?.[1] ?? '18:00',
    }])),
});

function submit() {
    form.transform((data) => ({
        enabled: data.enabled,
        timezone: data.timezone,
        away_message: data.away_message || null,
        days: Object.fromEntries(props.days.map((d) => [d, data.rows[d].open ? [data.rows[d].from, data.rows[d].until] : null])),
    })).put(route('hours.update'), { preserveScroll: true });
}

function copyMondayToWeekdays() {
    ['tue', 'wed', 'thu', 'fri'].forEach((d) => {
        form.rows[d] = { ...form.rows.mon };
    });
}

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : null);
</script>

<template>
    <AppLayout title="Business hours">
        <PageHeader
            width="max-w-4xl"
            title="Business hours"
            description="When a visitor asks for a human outside these hours, the request still lands in your queue and inbox, the phone stays quiet, and the visitor is told when to expect a reply."
        >
            <template #actions>
                <span
                    class="inline-flex items-center gap-1.5 rounded-none px-2.5 py-1 font-mono text-xs"
                    :class="openNow ? 'bg-state-ok-surface text-state-ok-ink' : 'bg-state-warn-surface text-state-warn-ink'"
                >
                    <span class="inline-block size-1.5 rounded-full bg-current" />
                    {{ hours.enabled ? (openNow ? 'Open now' : 'Closed now') : 'Always open' }}
                </span>
                <span class="bp-ref">SET/HOURS</span>
            </template>
        </PageHeader>

        <div class="py-6">
            <form class="mx-auto max-w-4xl space-y-6 px-4 sm:px-6 lg:px-8" @submit.prevent="submit">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-none border border-border-line bg-bg p-4 shadow-sheet">
                    <div>
                        <h2 class="font-medium text-ink">Use business hours</h2>
                        <p class="text-xs text-ink-dim">Off means every handoff is treated as urgent, whatever the time — today's behaviour.</p>
                    </div>
                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-ink">
                        <input v-model="form.enabled" type="checkbox" class="size-4 rounded-none border-border-hi text-ink focus:ring-2 focus:ring-ink focus:ring-offset-1" :disabled="!isOwner" />
                        On
                    </label>
                </div>

                <div class="rounded-none border border-border-line bg-bg shadow-sheet" :class="{ 'opacity-60': !form.enabled }">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-line bg-bg-elev px-4 py-2">
                        <span class="font-mono text-xs uppercase tracking-wider text-ink-dim">Opening hours</span>
                        <label class="flex items-center gap-2 text-xs text-ink-dim">
                            Time zone
                            <select v-model="form.timezone" class="rounded-none border-border-hi bg-bg py-1 text-xs text-ink focus:border-ink focus:ring-ink" :disabled="!isOwner">
                                <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                            </select>
                        </label>
                    </div>
                    <div class="divide-y divide-border-line">
                        <div v-for="d in days" :key="d" class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                            <label class="inline-flex w-32 cursor-pointer items-center gap-2 text-sm text-ink">
                                <input v-model="form.rows[d].open" type="checkbox" class="size-4 rounded-none border-border-hi text-ink focus:ring-2 focus:ring-ink focus:ring-offset-1" :disabled="!isOwner" />
                                {{ dayLabel[d] }}
                            </label>
                            <template v-if="form.rows[d].open">
                                <input v-model="form.rows[d].from" type="time" class="rounded-none border-border-hi bg-bg py-1 text-[16px] text-ink focus:border-ink focus:ring-ink sm:text-sm" :disabled="!isOwner" />
                                <span class="text-xs text-ink-mute">to</span>
                                <input v-model="form.rows[d].until" type="time" class="rounded-none border-border-hi bg-bg py-1 text-[16px] text-ink focus:border-ink focus:ring-ink sm:text-sm" :disabled="!isOwner" />
                                <button v-if="d === 'mon' && isOwner" type="button" class="ml-auto py-1 font-mono text-[11px] text-ink-dim underline hover:text-ink" @click="copyMondayToWeekdays">
                                    copy to Tue–Fri
                                </button>
                            </template>
                            <span v-else class="font-mono text-xs text-ink-mute">closed</span>
                        </div>
                    </div>
                    <InputError class="px-4 pb-3" :message="form.errors.days" />
                </div>

                <div class="rounded-none border border-border-line bg-bg p-4 shadow-sheet" :class="{ 'opacity-60': !form.enabled }">
                    <h2 class="font-medium text-ink">What the visitor is told outside hours</h2>
                    <p class="text-xs text-ink-dim">Added to the chat's reply when someone asks for a human while you are closed. The next opening time is appended automatically.</p>
                    <textarea
                        v-model="form.away_message"
                        rows="2"
                        maxlength="300"
                        class="mt-3 block w-full rounded-none border-border-hi bg-bg text-[16px] text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1 sm:text-sm"
                        :placeholder="defaultAway"
                        :disabled="!isOwner"
                    />
                    <InputError class="mt-2" :message="form.errors.away_message" />
                    <p v-if="hours.enabled && !openNow && nextOpening" class="mt-2 font-mono text-xs text-ink-dim">Next opening: {{ fmt(nextOpening) }}</p>
                </div>

                <div v-if="isOwner" class="flex items-center gap-3">
                    <PrimaryButton :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save hours' }}</PrimaryButton>
                    <span v-if="form.recentlySuccessful" class="text-xs text-state-ok-ink">Saved.</span>
                </div>
                <p v-else class="text-xs text-ink-dim">Only the team owner can change business hours.</p>
            </form>
        </div>
    </AppLayout>
</template>
