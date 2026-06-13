<script setup>
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    lead: { type: Object, required: true },
    members: { type: Array, default: () => [] },
});

const emit = defineEmits(['delete', 'assign', 'open']);

// Map the status color token to concrete classes (Tailwind needs them literal).
const scoreColor = (score) => {
    if (score >= 70) return 'bg-green-100 text-green-700';
    if (score >= 40) return 'bg-amber-100 text-amber-700';
    return 'bg-surface-hi text-ink-dim';
};

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
        class="group cursor-grab rounded-none border border-border-line bg-bg p-3 active:cursor-grabbing"
        draggable="true"
        @dragstart="$event.dataTransfer.setData('text/lead-id', String(lead.id))"
    >
        <div
            class="flex cursor-pointer items-start justify-between gap-2"
            role="button"
            tabindex="0"
            :aria-label="`Open lead ${lead.name}`"
            @click="$emit('open', lead)"
            @keydown.enter="$emit('open', lead)"
        >
            <div class="min-w-0">
                <p class="truncate font-medium text-ink hover:underline">{{ lead.name }}</p>
                <p v-if="lead.company" class="truncate text-xs text-ink-dim">{{ lead.company }}</p>
            </div>
            <span class="rounded-none px-1.5 py-0.5 font-mono text-xs font-semibold" :class="scoreColor(lead.score)">
                {{ lead.score }}
            </span>
        </div>

        <div v-if="lead.email || lead.phone" class="mt-1 flex flex-col gap-0.5 text-xs">
            <a
                v-if="lead.email"
                :href="`mailto:${lead.email}`"
                class="truncate text-ink-dim hover:text-ink hover:underline"
                :title="`Email ${lead.email}`"
                @click.stop
                @mousedown.stop
                @dragstart.prevent
            >
                ✉ {{ lead.email }}
            </a>
            <a
                v-if="lead.phone"
                :href="`tel:${lead.phone}`"
                class="truncate text-ink-dim hover:text-ink hover:underline"
                :title="`Call ${lead.phone}`"
                @click.stop
                @mousedown.stop
                @dragstart.prevent
            >
                ☎ {{ lead.phone }}
            </a>
        </div>

        <div class="mt-2 flex items-center justify-between">
            <div class="flex items-center gap-1.5">
                <span class="rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-[10px] uppercase tracking-wider text-ink-mute">
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
            </div>
            <button
                type="button"
                class="text-xs text-ink-mute opacity-0 transition hover:text-rose-500 group-hover:opacity-100"
                title="Delete lead"
                aria-label="Delete lead"
                @click="$emit('delete', lead)"
            >
                ✕
            </button>
        </div>

        <!-- Delegation: assign to a rep or auto round-robin -->
        <div class="mt-2 border-t border-border-line pt-2" @mousedown.stop @dragstart.stop>
            <select
                class="w-full rounded-none border-border-hi bg-bg py-1 text-xs text-ink-dim focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
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
