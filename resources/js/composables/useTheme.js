import { ref } from 'vue';

/**
 * Dark/light toggle for the dashboard.
 *
 * "Two sheets, one ink": light = the white sheet (.sheet-white), dark = the
 * black sheet (.sheet-black) — the same marketing-site palette. Both live in
 * resources/css/tokens.css, so flipping the class on <html> re-themes every
 * token-driven component for free.
 *
 * The initial class is set by a tiny inline script in app.blade.php BEFORE
 * first paint (no flash). This composable keeps the in-app toggle in sync and
 * persists the choice to localStorage under `fs-theme` ('light' | 'dark').
 */
const STORAGE_KEY = 'fs-theme';

function currentTheme() {
    if (typeof document === 'undefined') return 'light';
    return document.documentElement.classList.contains('sheet-black') ? 'dark' : 'light';
}

const theme = ref(currentTheme());

function apply(next) {
    const el = document.documentElement;
    el.classList.toggle('sheet-white', next === 'light');
    el.classList.toggle('sheet-black', next === 'dark');
    el.style.colorScheme = next === 'dark' ? 'dark' : 'light';
    try {
        localStorage.setItem(STORAGE_KEY, next);
    } catch (e) {
        // Private mode / storage disabled — the choice just won't persist.
    }
    theme.value = next;
}

export function useTheme() {
    return {
        theme,
        isDark: () => theme.value === 'dark',
        toggle: () => apply(theme.value === 'dark' ? 'light' : 'dark'),
        set: apply,
    };
}
