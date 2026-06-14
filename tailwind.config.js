import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
            },
            // Flowstack brand tokens (resources/css/tokens.css — vendored from
            // automation-landing/branding/tokens.css). Utility names mirror the
            // landing's Tailwind v4 @theme block so classes are portable between
            // the two repos: bg-bg, text-ink, border-border-line, …
            colors: {
                bg: 'var(--bg)',
                'bg-elev': 'var(--bg-elev)',
                surface: 'var(--surface)',
                'surface-hi': 'var(--surface-hi)',
                'border-line': 'var(--border-line)',
                'border-hi': 'var(--border-hi)',
                ink: 'var(--ink)',
                'ink-dim': 'var(--ink-dim)',
                'ink-mute': 'var(--ink-mute)',
                // DEFAULT keys deep-merge with the built-in violet/cyan scales,
                // so existing violet-600-style classes keep working: `bg-violet`
                // is the brand token, `bg-violet-600` is still Tailwind's.
                violet: { DEFAULT: 'var(--violet)' },
                'violet-soft': 'var(--violet-soft)',
                success: 'var(--success)',
                warn: 'var(--warn)',
                danger: 'var(--danger)',
                line: 'var(--line)',
                'line-strong': 'var(--line-strong)',
                draw: 'var(--draw)',
            },
        },
    },

    plugins: [forms, typography],
};
