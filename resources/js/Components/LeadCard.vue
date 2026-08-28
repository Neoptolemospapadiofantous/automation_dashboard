<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    lead: { type: Object, required: true },
    members: { type: Array, default: () => [] },
});

const emit = defineEmits(['delete', 'assign', 'open']);

// Map the status color token to concrete classes (Tailwind needs them literal).
const scoreColor = (score) => {
    if (score >= 70) return 'bg-state-ok-surface text-state-ok-ink';
    if (score >= 40) return 'bg-state-warn-surface text-state-warn-ink';
    return 'bg-surface-hi text-ink-dim';
};

// Resting label for the current assignee (hover reveals the full picker).
const assigneeName = computed(() => {
    if (!props.lead.assigned_to) return null;
    return props.members.find((m) => m.id === props.lead.assigned_to)?.name ?? null;
});

function onAssignChange(e) {
    const value = e.target.value;
    if (value === '__auto__') {
        emit('assign', props.lead, { strategy: 'round_robin' });
    } else if (value === '') {
        emit('assign', props.lead, { strategy: 'unassigned' });
    } else {
        emit('assign', props.lead, { strategy: 'manual', assigned_to: Number(value) });
    }
}
</script>

<template>
    <div
        class="group cursor-grab rounded-none border border-border-line bg-bg px-2 py-1.5 shadow-sheet transition-colors hover:border-ink active:cursor-grabbing"
        draggable="true"
        @dragstart="$event.dataTransfer.setData('text/lead-id', String(lead.id))"
    >
        <div
            class="flex cursor-pointer items-center justify-between gap-2"
            role="button"
            tabindex="0"
            :aria-label="`Open lead ${lead.name}`"
            @click="$emit('open', lead)"
            @keydown.enter="$emit('open', lead)"
        >
            <div class="min-w-0">
                <p class="truncate text-[13px] font-medium leading-tight text-ink hover:underline">{{ lead.name }}</p>
                <p v-if="lead.company" class="truncate text-[11px] leading-tight text-ink-dim">{{ lead.company }}</p>
            </div>
            <span class="shrink-0 rounded-none px-1.5 py-0.5 font-mono text-[11px] font-semibold" :class="scoreColor(lead.score)">
                {{ lead.score }}
            </span>
        </div>

        <!-- Contact: a single truncated line keeps the card short. -->
        <div v-if="lead.email || lead.phone" class="mt-1 flex items-center gap-2 text-[11px] text-ink-dim">
            <a
                v-if="lead.email"
                :href="`mailto:${lead.email}`"
                class="min-w-0 flex-1 truncate py-1 hover:text-ink hover:underline"
                :title="`Email ${lead.email}`"
                @click.stop
                @mousedown.stop
                @dragstart.prevent
            >
                ✉︎ {{ lead.email }}
            </a>
            <a
                v-if="lead.phone"
                :href="`tel:${lead.phone}`"
                class="-my-1.5 inline-flex min-h-8 min-w-8 shrink-0 items-center justify-center hover:text-ink hover:underline"
                :title="`Call ${lead.phone}`"
                @click.stop
                @mousedown.stop
                @dragstart.prevent
            >
                ☎︎
            </a>
        </div>

        <div class="mt-1 flex items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-1.5">
                <span class="rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wider text-ink-dim">
                    {{ lead.source }}
                </span>
                <Link
                    v-if="lead.conversations_count"
                    :href="route('conversations.index', { lead_id: lead.id })"
                    class="rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-[10px] font-medium text-ink hover:bg-ink hover:text-bg"
                    :title="`View ${lead.conversations_count} conversation${lead.conversations_count === 1 ? '' : 's'}`"
                    @click.stop
                    @mousedown.stop
                    @dragstart.prevent
                >
                    💬 {{ lead.conversations_count }}
                </Link>
                <!-- Resting assignee hint (the picker reveals on hover). -->
                <span
                    class="truncate text-[10px] text-ink-mute"
                    :title="assigneeName ? `Assigned to ${assigneeName}` : 'Unassigned'"
                >
                    {{ assigneeName ? `· ${assigneeName}` : '· Unassigned' }}
                </span>
            </div>
            <button
                type="button"
                class="-mr-2 -mt-1.5 inline-flex size-8 shrink-0 items-center justify-center text-xs text-ink-mute opacity-0 transition hover:text-state-bad-ink group-hover:opacity-100 max-sm:opacity-100"
                title="Delete lead"
                aria-label="Delete lead"
                @click="$emit('delete', lead)"
            >
                ✕
            </button>
        </div>

        <!-- Delegation: collapsed at rest, revealed on hover/focus so the
             resting card stays compact and columns scan faster. -->
        <div
            class="max-h-0 overflow-hidden opacity-0 transition-all duration-150 group-hover:mt-1.5 group-hover:max-h-12 group-hover:opacity-100 group-focus-within:mt-1.5 group-focus-within:max-h-12 group-focus-within:opacity-100 max-sm:mt-1.5 max-sm:max-h-12 max-sm:opacity-100"
            @mousedown.stop
            @dragstart.stop
        >
            <select
                class="w-full rounded-none border-border-hi bg-bg py-0.5 text-[11px] text-ink-dim focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                :value="lead.assigned_to ?? ''"
                aria-label="Assign lead"
                @change="onAssignChange"
                @click.stop
            >
                <option value="">Unassigned</option>
                <option value="__auto__">⟳ Auto-assign</option>
                <option v-for="m in members" :key="m.id" :value="m.id">{{ m.name }}</option>
            </select>
        </div>
    </div>
</template>
