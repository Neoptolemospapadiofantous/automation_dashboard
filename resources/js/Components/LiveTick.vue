<script setup>
import { ref } from 'vue';
import axios from 'axios';
import { useEcho } from '@/composables/useEcho';

// Live state, updated purely by broadcasts — no page reload, no polling.
const lastTick = ref(null);
const ticks = ref([]);
const firing = ref(false);

// Subscribe to the public "dashboard" channel and react to every ".tick".
const { connected } = useEcho('dashboard', '.tick', (payload) => {
    lastTick.value = payload;
    ticks.value.unshift(payload);
    ticks.value = ticks.value.slice(0, 8);
});

// Trigger a tick on the server; the *broadcast* is what updates every client.
async function fireTick() {
    firing.value = true;
    try {
        await axios.post(route('dashboard.tick'));
    } finally {
        firing.value = false;
    }
}
</script>

<template>
    <div class="p-6 lg:p-8">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span
                    class="inline-block h-2.5 w-2.5 rounded-full"
                    :class="connected ? 'bg-green-500 animate-pulse' : 'bg-gray-300'"
                />
                <h3 class="text-lg font-medium text-gray-900">
                    Real-time pipeline
                </h3>
            </div>
            <span class="text-xs font-medium" :class="connected ? 'text-green-600' : 'text-gray-400'">
                {{ connected ? 'LIVE' : 'OFFLINE (set PUSHER_* to go live)' }}
            </span>
        </div>

        <p class="mt-2 text-sm text-gray-500">
            Click the button in any open tab — every connected browser updates
            instantly via WebSocket, with no refresh and no polling.
        </p>

        <div class="mt-4 flex items-center gap-4">
            <button
                type="button"
                class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 disabled:opacity-50"
                :disabled="firing"
                @click="fireTick"
            >
                {{ firing ? 'Firing…' : 'Fire a live tick' }}
            </button>

            <div v-if="lastTick" class="text-sm text-gray-700">
                Tick <span class="font-mono font-semibold">#{{ lastTick.count }}</span>
                · {{ new Date(lastTick.at).toLocaleTimeString() }}
            </div>
        </div>

        <ul v-if="ticks.length" class="mt-4 space-y-1">
            <li
                v-for="(t, i) in ticks"
                :key="`${t.count}-${i}`"
                class="rounded bg-gray-50 px-3 py-1.5 font-mono text-xs text-gray-600"
            >
                #{{ t.count }} — {{ t.message }} @ {{ t.at }}
            </li>
        </ul>
    </div>
</template>
