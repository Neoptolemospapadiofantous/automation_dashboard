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
                // The FILL yellow (app.css :root). The accent slot above is
                // per-sheet and readable as TEXT; this one is constant and only
                // ever a fill, always under near-black type. Without this entry
                // `bg-signal` compiles to nothing at all.
                signal: 'var(--signal)',
                // Status palette (app.css). Four families, five roles —
                // bg-state-ok-surface, text-state-bad-ink, border-state-warn-line,
                // bg-state-bad-solid, text-state-bad-on. Reach for these instead
                // of Tailwind's default palette, which does not flip with the sheet.
                state: {
                    'ok-surface': 'var(--state-ok-surface)',
                    'ok-ink': 'var(--state-ok-ink)',
                    'ok-line': 'var(--state-ok-line)',
                    'ok-solid': 'var(--state-ok-solid)',
                    'ok-on': 'var(--state-ok-on)',
                    'warn-surface': 'var(--state-warn-surface)',
                    'warn-ink': 'var(--state-warn-ink)',
                    'warn-line': 'var(--state-warn-line)',
                    'warn-solid': 'var(--state-warn-solid)',
                    'warn-on': 'var(--state-warn-on)',
                    'bad-surface': 'var(--state-bad-surface)',
                    'bad-ink': 'var(--state-bad-ink)',
                    'bad-line': 'var(--state-bad-line)',
                    'bad-solid': 'var(--state-bad-solid)',
                    'bad-on': 'var(--state-bad-on)',
                    'info-surface': 'var(--state-info-surface)',
                    'info-ink': 'var(--state-info-ink)',
                    'info-line': 'var(--state-info-line)',
                    'info-solid': 'var(--state-info-solid)',
                    'info-on': 'var(--state-info-on)',
                },
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
