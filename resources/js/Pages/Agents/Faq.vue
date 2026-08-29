<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import EmptyState from '@/Components/EmptyState.vue';

const props = defineProps({
    draftAnswers: { type: Array, default: null }, // null = no draft yet
    publishedAnswers: { type: Array, default: () => [] },
    hasDraft: { type: Boolean, default: false },
});

const clone = (rows) =>
    rows.map((a) => ({
        category: a.category ?? '',
        keywords: a.keywords ?? '',
        answer: a.answer ?? '',
    }));

// Edit the draft if one exists, else seed from what's live, else blank.
const seed = props.draftAnswers ?? props.publishedAnswers ?? [];

const form = useForm({ answers: clone(seed) });

const err = (i, field) => form.errors[`answers.${i}.${field}`];

const addAnswer = () => form.answers.push({ category: '', keywords: '', answer: '' });
const removeAnswer = (i) => form.answers.splice(i, 1);

const save = () => form.post(route('agents.faq.save'), { preserveScroll: true });

const publishForm = useForm({});
const publish = () => {
    // Save the editor first so what's on screen is exactly what goes live,
    // then publish the shared draft (behavior + actions + FAQ together).
    form.post(route('agents.faq.save'), {
        preserveScroll: true,
        onSuccess: () => publishForm.post(route('agents.versions.publish'), { preserveScroll: true }),
    });
};

const dirty = computed(() => form.isDirty);
</script>

<template>
    <AppLayout title="FAQ">
        <PageHeader width="max-w-5xl"
            title="FAQ"
            description="Answer the common questions for free. Each entry becomes a quick-reply chip in the widget and matches typed questions by keyword — served instantly with no AI call and no credits spent. Stage changes as a draft, then publish."
        />

        <div class="mx-auto max-w-5xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Live summary -->
            <div class="rounded-none border border-border-line bg-bg p-5 shadow-sheet">
                <span class="bp-ref">AGENT/FAQ</span>
                <div class="mb-1 mt-1 flex items-center justify-between">
                    <h2 class="text-base font-medium text-ink">Canned answers</h2>
                    <span class="font-mono text-xs text-ink-mute">
                        {{ publishedAnswers.length }} live · {{ form.answers.length }} in draft
                    </span>
                </div>
                <p class="text-xs text-ink-dim">
                    A visitor who taps a category chip or types a matching keyword gets the stored answer instantly —
                    no model call, no credit charged. Anything that doesn't match falls through to the AI. Drafts are
                    invisible to the widget until published.
                </p>
            </div>

            <!-- Editor -->
            <EmptyState
                v-if="form.answers.length === 0"
                title="No canned answers yet"
                description="Add a category to answer a common question for free and show it as a quick-reply chip."
            >
                <template #actions>
                    <PrimaryButton @click="addAnswer">Add answer</PrimaryButton>
                </template>
            </EmptyState>

            <div
                v-for="(answer, i) in form.answers"
                :key="i"
                class="rounded-none border border-border-line bg-bg p-5"
            >
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-mono text-sm font-semibold text-ink">Answer {{ i + 1 }}</h3>
                    <button
                        type="button"
                        aria-label="Remove answer"
                        class="font-mono text-xs text-state-bad-ink hover:underline"
                        @click="removeAnswer(i)"
                    >
                        Remove
                    </button>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <InputLabel :for="`category-${i}`" value="Category" />
                        <p class="mt-0.5 text-xs text-ink-dim">The chip label, e.g. Pricing. Also matches when typed.</p>
                        <input
                            :id="`category-${i}`"
                            v-model="answer.category"
                            type="text"
                            maxlength="64"
                            class="mt-2 block w-full rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                            placeholder="Pricing"
                        />
                        <InputError :message="err(i, 'category')" class="mt-1" />
                    </div>

                    <div>
                        <InputLabel :for="`keywords-${i}`" value="Keywords" />
                        <p class="mt-0.5 text-xs text-ink-dim">
                            Comma-separated. A typed message containing any of these routes here.
                        </p>
                        <input
                            :id="`keywords-${i}`"
                            v-model="answer.keywords"
                            type="text"
                            maxlength="500"
                            class="mt-2 block w-full rounded-none border-border-hi bg-bg font-mono text-xs text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                            placeholder="cost, how much, price, plans"
                        />
                        <InputError :message="err(i, 'keywords')" class="mt-1" />
                    </div>
                </div>

                <div class="mt-4">
                    <InputLabel :for="`answer-${i}`" value="Answer" />
                    <p class="mt-0.5 text-xs text-ink-dim">Served verbatim to the visitor. Keep it short and factual.</p>
                    <textarea
                        :id="`answer-${i}`"
                        v-model="answer.answer"
                        rows="3"
                        maxlength="2000"
                        class="mt-2 block w-full rounded-none border-border-hi bg-bg text-sm text-ink focus:border-ink focus:ring-2 focus:ring-ink focus:ring-offset-1"
                        placeholder="We have a free tier, and paid plans run €9–€39/mo — full pricing is at flowstack.run/pricing."
                    />
                    <InputError :message="err(i, 'answer')" class="mt-1" />
                </div>
            </div>

            <div v-if="form.answers.length" class="flex justify-start">
                <SecondaryButton @click="addAnswer">+ Add another answer</SecondaryButton>
            </div>

            <!-- Save / publish -->
            <div class="flex flex-wrap items-center gap-3 border-t border-border-line pt-5">
                <SecondaryButton :disabled="form.processing || !dirty" @click="save">
                    {{ form.processing ? 'Saving…' : 'Save draft' }}
                </SecondaryButton>
                <PrimaryButton :disabled="form.processing || publishForm.processing" @click="publish">
                    {{ publishForm.processing ? 'Publishing…' : 'Publish' }}
                </PrimaryButton>
                <p class="text-xs text-ink-dim">
                    Publishing stages every draft change — behavior, actions, and FAQ — live on the next message.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
