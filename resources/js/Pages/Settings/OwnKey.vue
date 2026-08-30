<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    allowed: { type: Boolean, required: true },
    planLabel: { type: String, required: true },
    messageCap: { type: Number, default: 0 },
    messagesUsed: { type: Number, default: 0 },
    providers: { type: Array, default: () => [] },
    keys: { type: Array, default: () => [] },
});

const form = useForm({ provider: props.providers[0] ?? 'anthropic', api_key: '' });
const removing = ref(null);

const label = (p) => ({ anthropic: 'Anthropic (Claude)', openai: 'OpenAI (GPT)' }[p] ?? p);

// Open by default for someone with no key yet — the walkthrough is the whole
// point for them — and collapsed once they've connected one.
const showHelp = ref(props.keys.length === 0);

// The console is a DIFFERENT product from the consumer subscription, and that is
// the single most common confusion: a Claude Pro or ChatGPT Plus plan does not
// include API usage. Saying so here is cheaper than answering it in support.
const guides = {
    anthropic: {
        // console.anthropic.com 301s here — link the destination so the label
        // matches where the user actually lands, and so a retired redirect
        // can't break the walkthrough.
        console: { href: 'https://platform.claude.com/settings/keys', label: 'platform.claude.com' },
        notInPlan: 'Claude Pro',
        consumerSite: 'claude.ai',
        keyPath: 'Settings → API keys → Create Key',
        prefix: 'sk-ant-',
    },
    openai: {
        console: { href: 'https://platform.openai.com/api-keys', label: 'platform.openai.com' },
        notInPlan: 'ChatGPT Plus',
        consumerSite: 'chatgpt.com',
        keyPath: 'API keys → Create new secret key',
        prefix: 'sk-',
    },
};
const guide = computed(() => guides[form.provider] ?? guides.anthropic);

// Cap is PHP_INT_MAX on Custom — show it as unlimited rather than a silly number.
const capLabel = computed(() =>
    props.messageCap > 1_000_000 ? 'Unlimited' : props.messageCap.toLocaleString(),
);
const usedPct = computed(() =>
    props.messageCap > 0 && props.messageCap <= 1_000_000
        ? Math.min(100, Math.round((props.messagesUsed / props.messageCap) * 100))
        : 0,
);

const submit = () => form.post(route('own-key.store'), {
    preserveScroll: true,
    onSuccess: () => form.reset('api_key'),
});

const verify = (id) => router.post(route('own-key.verify', id), {}, { preserveScroll: true });

const remove = (id) => {
    removing.value = id;
    router.delete(route('own-key.destroy', id), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
};
</script>

<template>
    <AppLayout title="Your own API key">
        <PageHeader
            width="max-w-5xl"
            title="Your own API key"
            description="Run chat on your own provider account instead of spending credits."
        />

        <div class="py-6">
            <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
                <!-- Not on Operator: explain the trade rather than just refusing. -->
                <div v-if="!allowed" class="rounded-none border border-border-line bg-bg-elev p-6">
                    <h2 class="text-base font-semibold text-ink">Available on Operator</h2>
                    <p class="mt-2 text-sm text-ink-dim">
                        You're on {{ planLabel }}. On Operator you can connect your own Anthropic or
                        OpenAI key — chat then bills to your provider account instead of your
                        credits, with a monthly message allowance in place of the credit balance.
                    </p>
                    <a :href="route('billing.index')" class="mt-4 inline-block">
                        <PrimaryButton class="min-h-[2.25rem]">See plans</PrimaryButton>
                    </a>
                </div>

                <template v-else>
                    <!-- Usage against the cap that replaces credits -->
                    <div class="rounded-none border border-border-line bg-bg-elev p-6">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="text-base font-semibold text-ink">This month</h2>
                            <p class="text-sm text-ink-dim">
                                <span class="font-semibold text-ink">{{ messagesUsed.toLocaleString() }}</span>
                                of {{ capLabel }} messages
                            </p>
                        </div>
                        <div v-if="usedPct > 0 || messageCap <= 1_000_000" class="mt-3 h-2 w-full rounded-none bg-surface-hi">
                            <div class="h-2 rounded-none bg-signal" :style="{ width: usedPct + '%' }" />
                        </div>
                        <p class="mt-3 text-sm text-ink-dim">
                            Messages on your own key don't spend credits. Past the allowance, chat
                            falls back to your credit balance rather than stopping.
                        </p>
                    </div>

                    <!-- Stored keys -->
                    <div v-if="keys.length" class="rounded-none border border-border-line bg-bg-elev">
                        <ul class="divide-y divide-border-line">
                            <li v-for="k in keys" :key="k.id" class="flex flex-wrap items-center gap-3 p-4 sm:p-6">
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-ink">
                                        {{ label(k.provider) }}
                                        <span class="ml-2 font-mono text-ink-dim">{{ k.hint }}</span>
                                    </p>
                                    <p v-if="k.usable" class="mt-1 text-xs text-state-ok-ink">
                                        Verified{{ k.last_verified_at ? ' · ' + new Date(k.last_verified_at).toLocaleDateString() : '' }}
                                    </p>
                                    <p v-else class="mt-1 text-xs text-state-bad-ink">
                                        {{ k.last_error || 'Not verified yet — chat still uses credits.' }}
                                    </p>
                                </div>
                                <div class="flex gap-2">
                                    <SecondaryButton class="min-h-[1.75rem]" @click="verify(k.id)">Re-verify</SecondaryButton>
                                    <SecondaryButton
                                        class="min-h-[1.75rem]"
                                        :disabled="removing === k.id"
                                        @click="remove(k.id)"
                                    >
                                        {{ removing === k.id ? 'Removing…' : 'Remove' }}
                                    </SecondaryButton>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- Add / replace -->
                    <form class="rounded-none border border-border-line bg-bg-elev p-6" @submit.prevent="submit">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 class="text-base font-semibold text-ink">Connect a key</h2>
                            <button
                                type="button"
                                class="text-ink-dim hover:text-ink min-h-[1.75rem] text-sm underline"
                                @click="showHelp = !showHelp"
                            >
                                {{ showHelp ? 'Hide' : 'Where do I get a key?' }}
                            </button>
                        </div>
                        <p class="mt-2 text-sm text-ink-dim">
                            We check the key against the provider before saving it, so a bad key is
                            caught here rather than by a visitor. It's stored encrypted and shown
                            only as the last four characters.
                        </p>

                        <div v-if="showHelp" class="border-border-line bg-bg mt-4 border p-4 sm:p-5">
                            <p class="text-ink text-sm font-semibold">
                                Getting a key from {{ label(form.provider) }}
                            </p>
                            <ol class="text-ink-dim mt-3 space-y-2 text-sm">
                                <li>
                                    <span class="text-ink font-semibold">1.</span>
                                    Open
                                    <a
                                        :href="guide.console.href"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-ink underline"
                                    >{{ guide.console.label }}</a>
                                    and sign in. This is the developer console — a different site from
                                    {{ guide.consumerSite }}.
                                </li>
                                <li>
                                    <span class="text-ink font-semibold">2.</span>
                                    Add a payment method under Billing.
                                    <span class="text-ink">A {{ guide.notInPlan }} subscription does not
                                    include API usage</span> — it's billed separately, per message.
                                </li>
                                <li>
                                    <span class="text-ink font-semibold">3.</span>
                                    Go to {{ guide.keyPath }}.
                                </li>
                                <li>
                                    <span class="text-ink font-semibold">4.</span>
                                    Copy the key — it starts <code class="text-ink">{{ guide.prefix }}</code>
                                    and is shown only once — then paste it below.
                                </li>
                            </ol>
                            <p class="text-ink-mute mt-3 text-xs">
                                You pay {{ label(form.provider) }} directly for what your chat uses. We
                                charge no credits for those messages.
                            </p>
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-[12rem,1fr]">
                            <label class="block">
                                <span class="text-sm text-ink-dim">Provider</span>
                                <select
                                    v-model="form.provider"
                                    class="mt-1 block w-full min-h-[2.25rem] rounded-none border-border-hi bg-bg text-sm text-ink"
                                >
                                    <option v-for="p in providers" :key="p" :value="p">{{ label(p) }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-sm text-ink-dim">API key</span>
                                <input
                                    v-model="form.api_key"
                                    type="password"
                                    autocomplete="off"
                                    spellcheck="false"
                                    placeholder="sk-…"
                                    class="mt-1 block w-full min-h-[2.25rem] rounded-none border-border-hi bg-bg font-mono text-sm text-ink"
                                />
                            </label>
                        </div>

                        <p v-if="form.errors.api_key" class="mt-3 text-sm text-state-bad-ink">
                            {{ form.errors.api_key }}
                        </p>

                        <div class="mt-5">
                            <PrimaryButton class="min-h-[2.25rem]" :disabled="form.processing || !form.api_key">
                                {{ form.processing ? 'Verifying…' : 'Verify and save' }}
                            </PrimaryButton>
                        </div>
                    </form>
                </template>
            </div>
        </div>
    </AppLayout>
</template>
