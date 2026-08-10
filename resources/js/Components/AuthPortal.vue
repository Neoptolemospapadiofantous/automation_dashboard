<script setup>
import { computed, nextTick, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    initialMode: { type: String, default: 'login' }, // login | register | forgot
    canResetPassword: { type: Boolean, default: true },
    status: { type: String, default: '' },
});

const page = usePage();

// Active panel. `forgot` is a sub-mode of the sign-in side.
const mode = ref(props.initialMode);
const panelRef = ref(null);

// Reference label shown in the mono header, per mode.
const refLabel = computed(() => ({
    login: 'ACCESS/LOGIN',
    register: 'ACCESS/NEW',
    forgot: 'ACCESS/RESET',
}[mode.value]));

// Human heading + supporting line, per mode — the tabs switch modes, these
// say where you are.
const heading = computed(() => ({
    login: 'Welcome back',
    register: 'Create your account',
    forgot: 'Reset your password',
}[mode.value]));
const subline = computed(() => ({
    login: 'Sign in to your workspace.',
    register: 'Set up your workspace and agent in minutes.',
    forgot: "We'll email you a secure reset link.",
}[mode.value]));

// The segmented control has two segments; `forgot` sits under the sign-in one.
const tabs = [
    { key: 'login', label: 'Sign in' },
    { key: 'register', label: 'Create account' },
];
const activeTab = computed(() => (mode.value === 'register' ? 'register' : 'login'));
const indicatorIndex = computed(() => (activeTab.value === 'register' ? 1 : 0));

function switchMode(next) {
    if (next === mode.value) return;
    mode.value = next;
}

function focusFirstField() {
    nextTick(() => {
        const el = panelRef.value?.querySelector('input:not([type=hidden]):not([type=checkbox])');
        el?.focus();
    });
}

const loginForm = useForm({ email: '', password: '', remember: false });
const registerForm = useForm({
    name: '', email: '', password: '', password_confirmation: '', terms: false,
});
const forgotForm = useForm({ email: '' });

const submitLogin = () => {
    loginForm.transform((data) => ({
        ...data,
        remember: loginForm.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => loginForm.reset('password'),
    });
};

const submitRegister = () => {
    registerForm.post(route('register'), {
        onFinish: () => registerForm.reset('password', 'password_confirmation'),
    });
};

const submitForgot = () => {
    forgotForm.post(route('password.email'));
};
</script>

<template>
    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <!-- Segmented toggle: cycle between Sign in / Create account. -->
        <div class="auth-seg relative grid grid-cols-2 border border-border-line bg-bg-elev" role="tablist">
            <span
                class="auth-seg-ind pointer-events-none absolute inset-y-0 left-0 w-1/2 border border-violet bg-surface-hi shadow-sheet"
                :style="{ transform: `translateX(${indicatorIndex * 100}%)` }"
                aria-hidden="true"
            />
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="activeTab === t.key"
                class="relative z-10 px-3 py-2 text-center text-sm font-medium transition-colors duration-200"
                :class="activeTab === t.key ? 'text-ink' : 'text-ink-dim hover:text-ink'"
                @click="switchMode(t.key)"
            >
                {{ t.label }}
            </button>
        </div>

        <!-- Mode header: human heading left, mono sheet reference right. -->
        <div class="mt-6 flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-lg font-semibold leading-6 text-ink">{{ heading }}</h2>
                <p class="mt-1 text-sm text-ink-dim">{{ subline }}</p>
            </div>
            <span class="bp-ref mt-1 flex-shrink-0">{{ refLabel }}</span>
        </div>
        <div class="bp-dim mb-6 mt-4" aria-hidden="true" />

        <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <Transition name="auth-swap" mode="out-in" @after-enter="focusFirstField">
            <!-- ── Sign in ─────────────────────────────────────────── -->
            <form v-if="mode === 'login'" key="login" ref="panelRef" @submit.prevent="submitLogin">
                <div class="bp-rise" style="--rise-delay: 40ms">
                    <InputLabel for="login-email" value="Email" />
                    <TextInput id="login-email" v-model="loginForm.email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                    <InputError class="mt-2" :message="loginForm.errors.email" />
                </div>

                <div class="bp-rise mt-4" style="--rise-delay: 90ms">
                    <InputLabel for="login-password" value="Password" />
                    <TextInput id="login-password" v-model="loginForm.password" type="password" class="mt-1 block w-full" required autocomplete="current-password" />
                    <InputError class="mt-2" :message="loginForm.errors.password" />
                </div>

                <div class="bp-rise mt-4 flex items-center justify-between" style="--rise-delay: 140ms">
                    <label class="flex items-center">
                        <Checkbox v-model:checked="loginForm.remember" name="remember" />
                        <span class="ms-2 text-sm text-ink-dim">Remember me</span>
                    </label>
                    <button
                        v-if="canResetPassword"
                        type="button"
                        class="text-sm text-ink-dim underline transition-colors hover:text-ink focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ink"
                        @click="switchMode('forgot')"
                    >
                        Forgot your password?
                    </button>
                </div>

                <div class="bp-rise mt-6 flex items-center justify-end" style="--rise-delay: 190ms">
                    <PrimaryButton :class="{ 'opacity-25': loginForm.processing }" :disabled="loginForm.processing">
                        Log in
                    </PrimaryButton>
                </div>
            </form>

            <!-- ── Create account ──────────────────────────────────── -->
            <form v-else-if="mode === 'register'" key="register" ref="panelRef" @submit.prevent="submitRegister">
                <div class="bp-rise" style="--rise-delay: 40ms">
                    <InputLabel for="reg-name" value="Name" />
                    <TextInput id="reg-name" v-model="registerForm.name" type="text" class="mt-1 block w-full" required autocomplete="name" />
                    <InputError class="mt-2" :message="registerForm.errors.name" />
                </div>

                <div class="bp-rise mt-4" style="--rise-delay: 90ms">
                    <InputLabel for="reg-email" value="Email" />
                    <TextInput id="reg-email" v-model="registerForm.email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                    <InputError class="mt-2" :message="registerForm.errors.email" />
                </div>

                <div class="bp-rise mt-4" style="--rise-delay: 140ms">
                    <InputLabel for="reg-password" value="Password" />
                    <TextInput id="reg-password" v-model="registerForm.password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                    <InputError class="mt-2" :message="registerForm.errors.password" />
                </div>

                <div class="bp-rise mt-4" style="--rise-delay: 190ms">
                    <InputLabel for="reg-password-confirm" value="Confirm Password" />
                    <TextInput id="reg-password-confirm" v-model="registerForm.password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                    <InputError class="mt-2" :message="registerForm.errors.password_confirmation" />
                </div>

                <div v-if="page.props.jetstream.hasTermsAndPrivacyPolicyFeature" class="bp-rise mt-4" style="--rise-delay: 240ms">
                    <InputLabel for="terms">
                        <div class="flex items-center">
                            <Checkbox id="terms" v-model:checked="registerForm.terms" name="terms" required />
                            <div class="ms-2">
                                I agree to the <a target="_blank" :href="route('terms.show')" class="text-sm text-ink-dim underline hover:text-ink focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ink">Terms of Service</a> and <a target="_blank" :href="route('policy.show')" class="text-sm text-ink-dim underline hover:text-ink focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ink">Privacy Policy</a>
                            </div>
                        </div>
                        <InputError class="mt-2" :message="registerForm.errors.terms" />
                    </InputLabel>
                </div>

                <div class="bp-rise mt-6 flex items-center justify-end" style="--rise-delay: 290ms">
                    <PrimaryButton :class="{ 'opacity-25': registerForm.processing }" :disabled="registerForm.processing">
                        Create account
                    </PrimaryButton>
                </div>
            </form>

            <!-- ── Reset password (request link) ───────────────────── -->
            <form v-else key="forgot" ref="panelRef" @submit.prevent="submitForgot">
                <p class="bp-rise text-sm text-ink-dim" style="--rise-delay: 40ms">
                    Forgot your password? Enter your email and we'll send a reset link so you can choose a new one.
                </p>

                <div class="bp-rise mt-4" style="--rise-delay: 90ms">
                    <InputLabel for="forgot-email" value="Email" />
                    <TextInput id="forgot-email" v-model="forgotForm.email" type="email" class="mt-1 block w-full" required autocomplete="username" />
                    <InputError class="mt-2" :message="forgotForm.errors.email" />
                </div>

                <div class="bp-rise mt-6 flex items-center justify-between" style="--rise-delay: 140ms">
                    <button
                        type="button"
                        class="text-sm text-ink-dim underline transition-colors hover:text-ink focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ink"
                        @click="switchMode('login')"
                    >
                        ← Back to sign in
                    </button>
                    <PrimaryButton :class="{ 'opacity-25': forgotForm.processing }" :disabled="forgotForm.processing">
                        Email reset link
                    </PrimaryButton>
                </div>
            </form>
        </Transition>
    </AuthenticationCard>
</template>

<style scoped>
.auth-seg-ind {
    transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1);
}

.auth-swap-enter-active,
.auth-swap-leave-active {
    transition: opacity 0.22s ease;
}
.auth-swap-enter-from,
.auth-swap-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .auth-seg-ind,
    .auth-swap-enter-active,
    .auth-swap-leave-active {
        transition: none;
    }
}
</style>
