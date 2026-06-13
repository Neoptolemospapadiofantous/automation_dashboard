<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    tiers: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    draft: { type: Object, default: null }, // null = no draft row yet
    agent: { type: Object, default: null },
});

// Editor starts from the draft if one exists, else from the live
// (published) config, else blank — so "edit what's live" is one click.
const published = computed(() => props.versions.find((v) => v.status === 'published') ?? null);
const seed = props.draft ?? published.value?.config ?? { instructions: '', greeting: '' };

const form = useForm({
    instructions: seed.instructions ?? '',
    greeting: seed.greeting ?? '',
    model_tier: seed.model_tier ?? 'haiku',
});

const tierLabel = (key) => props.tiers.find((t) => t.key === key)?.label ?? key;
const tierCost = (key) => props.tiers.find((t) => t.key === key)?.credits_per_message ?? 1;

const saveDraft = () => form.post(route('agents.versions.draft'), { preserveScroll: true });

const publishForm = useForm({});
const publish = () => {
    // Save the editor contents first so what you see is what goes live,
    // then publish in the redirect chain.
    form.post(route('agents.versions.draft'), {
        preserveScroll: true,
        onSuccess: () => publishForm.post(route('agents.versions.publish'), { preserveScroll: true }),
    });
};

const restoring = ref(null);
const restore = (version) => {
    restoring.value = version;
    useForm({}).post(route('agents.versions.restore', version), {
        preserveScroll: true,
        onFinish: () => (restoring.value = null),
    });
};

const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—');

const statusTone = (s) =>
    ({
        published: 'bg-emerald-50 text-emerald-700',
        draft: 'bg-amber-50 text-amber-700',
        archived: 'bg-surface-hi text-ink-dim',
    })[s] ?? 'bg-surface-hi text-ink-dim';

const dirty = computed(() => form.isDirty);
</script>

<template>
    <AppLayout title="Versions">
        <PageHeader
            title="Versions"
            description="Stage behavior changes as a draft, publish to make them live instantly, roll back anytime. The published version shapes every conversation."
        />

        <div class="mx-auto max-w-5xl space-y-6 px-4 pb-12 sm:px-6">
            <!-- Editor -->
            <div class="rounded-none border border-border-line bg-bg p-5 shadow-sheet">
                <span class="bp-ref">AGENT/VERSIONS</span>
                <div class="mb-4 mt-1 flex items-center justify-between">
                    <h2 class="text-base font-medium text-ink">Behavior draft</h2>
                    <span v-if="published" class="font-mono text-xs text-ink-mute">
                        Live now: v{{ published.version }} · published {{ fmt(published.published_at) }}
                    </span>
                    <span v-else class="text-xs text-ink-dim">Nothing published yet — the agent runs on defaults.</span>
                </div>

                <div class="space-y-4">
                    <div>
                        <InputLabel for="instructions" value="Custom instructions" />
                        <p class="mt-0.5 text-xs text-ink-dim">
                            Added to the agent's system prompt on every turn. Tone, what to emphasize, things to avoid, qualification criteria…
                        </p>
                        <textarea
                            id="instructions"
                            v-model="form.instructions"
                            rows="7"
                            maxlength="4000"
                            class="mt-2 block w-full rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                            placeholder="e.g. We sell to dental clinics. Always ask about practice size. Never discuss competitor pricing. If they mention enterprise, prioritize booking a call over email capture."
                        />
                        <InputError :message="form.errors.instructions" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="greeting" value="Greeting guidance (optional)" />
                        <p class="mt-0.5 text-xs text-ink-dim">Shapes only the first message of each conversation.</p>
                        <input
                            id="greeting"
                            v-model="form.greeting"
                            type="text"
                            maxlength="500"
                            class="mt-2 block w-full rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                            placeholder="e.g. Mention our March webinar in the greeting."
                        />
                        <InputError :message="form.errors.greeting" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel for="model_tier" value="Response quality" />
                        <p class="mt-0.5 text-xs text-ink-dim">
                            Smarter answers cost more credits per message. Applies to this agent only, from the moment you publish.
                        </p>
                        <div class="mt-2 grid gap-3 sm:grid-cols-3">
                            <label
                                v-for="t in tiers"
                                :key="t.key"
                                class="flex items-start gap-2.5 rounded-none border p-3 text-sm transition"
                                :class="[
                                    t.available === false ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
                                    form.model_tier === t.key ? 'border-ink bg-surface-hi ring-1 ring-ink' : 'border-border-line hover:border-border-hi',
                                ]"
                            >
                                <input v-model="form.model_tier" type="radio" :value="t.key" :disabled="t.available === false" class="mt-0.5 text-ink focus:ring-ink" />
                                <span>
                                    <span class="flex items-center gap-2 font-medium text-ink">
                                        {{ t.label }}
                                        <span class="rounded-none bg-surface-hi px-1.5 py-0.5 font-mono text-[10px] font-semibold text-ink-dim">
                                            {{ t.credits_per_message }} cr/msg
                                        </span>
                                    </span>
                                    <span class="mt-1 block text-xs leading-snug text-ink-dim">{{ t.available === false ? 'Not available yet on this workspace.' : t.description }}</span>
                                </span>
                            </label>
                        </div>
                        <InputError :message="form.errors.model_tier" class="mt-1" />
                    </div>

                    <InputError :message="form.errors.publish" class="mt-1" />

                    <div class="flex items-center gap-3">
                        <SecondaryButton :disabled="form.processing || !dirty" @click="saveDraft">
                            {{ form.processing ? 'Saving…' : 'Save draft' }}
                        </SecondaryButton>
                        <PrimaryButton :disabled="form.processing || publishForm.processing" @click="publish">
                            {{ publishForm.processing ? 'Publishing…' : 'Publish' }}
                        </PrimaryButton>
                        <p class="text-xs text-ink-dim">Publishing goes live on the very next message — no deploy.</p>
                    </div>
                </div>
            </div>

            <!-- History -->
            <div class="rounded-none border border-border-line bg-bg p-5">
                <h2 class="mb-4 text-base font-medium text-ink">History</h2>

                <EmptyState
                    v-if="versions.length === 0"
                    title="No versions yet"
                    message="Save a draft and publish it — every published version is kept here and can be restored."
                />

                <ul v-else class="divide-y divide-border-line">
                    <li v-for="v in versions" :key="v.version" class="flex items-center justify-between gap-4 py-3">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="bp-dot mt-1.5 shrink-0" :class="v.status === 'published' ? '' : 'opacity-40'" aria-hidden="true" />
                            <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-sm font-semibold text-ink">v{{ v.version }}</span>
                                <span
                                    v-if="v.status === 'published'"
                                    class="ins-stamp text-emerald-600"
                                >
                                    Published
                                </span>
                                <span v-else class="rounded-none px-2 py-0.5 font-mono text-[10px] font-medium" :class="statusTone(v.status)">
                                    {{ v.status }}
                                </span>
                                <span class="rounded-none bg-surface-hi px-2 py-0.5 font-mono text-[10px] font-medium text-ink-dim">
                                    {{ tierLabel(v.config?.model_tier ?? 'standard') }} · {{ tierCost(v.config?.model_tier ?? 'standard') }}cr/msg
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-xs text-ink-dim">
                                {{ v.config?.instructions ? v.config.instructions.slice(0, 110) : '(no custom instructions)' }}
                            </p>
                            <p class="mt-0.5 font-mono text-[10px] text-ink-dim">
                                {{ v.status === 'published' ? 'published ' + fmt(v.published_at) : 'updated ' + fmt(v.updated_at) }}
                            </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <a
                                :href="route('agents.versions.export', v.version)"
                                class="inline-flex items-center rounded-none border border-border-line bg-bg px-3 py-1.5 font-mono text-xs font-medium text-ink-dim hover:bg-surface-hi"
                            >
                                Export JSON
                            </a>
                            <SecondaryButton
                                v-if="v.status !== 'draft'"
                                :disabled="restoring === v.version"
                                @click="restore(v.version)"
                            >
                                {{ restoring === v.version ? 'Restoring…' : 'Restore to draft' }}
                            </SecondaryButton>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
