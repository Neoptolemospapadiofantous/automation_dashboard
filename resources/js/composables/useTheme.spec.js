import { describe, it, expect, beforeEach } from 'vitest';
import { useTheme } from '@/composables/useTheme';

describe('useTheme', () => {
    beforeEach(() => {
        document.documentElement.className = '';
        document.documentElement.style.colorScheme = '';
        localStorage.clear();
    });

    it('set("dark") flips the html class, colorScheme, and persists', () => {
        const { set, isDark } = useTheme();

        set('dark');

        expect(document.documentElement.classList.contains('sheet-black')).toBe(true);
        expect(document.documentElement.classList.contains('sheet-white')).toBe(false);
        expect(document.documentElement.style.colorScheme).toBe('dark');
        expect(localStorage.getItem('fs-theme')).toBe('dark');
        expect(isDark()).toBe(true);
    });

    it('set("light") flips back to the white sheet', () => {
        const { set, isDark } = useTheme();

        set('dark');
        set('light');

        expect(document.documentElement.classList.contains('sheet-white')).toBe(true);
        expect(document.documentElement.classList.contains('sheet-black')).toBe(false);
        expect(document.documentElement.style.colorScheme).toBe('light');
        expect(localStorage.getItem('fs-theme')).toBe('light');
        expect(isDark()).toBe(false);
    });

    it('toggle() alternates between light and dark', () => {
        const { set, toggle, theme } = useTheme();

        set('light');
        toggle();
        expect(theme.value).toBe('dark');

        toggle();
        expect(theme.value).toBe('light');
    });
});
