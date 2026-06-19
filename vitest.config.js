import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

// Frontend unit/component layer. Kept separate from vite.config.js (the Laravel
// build) so tests run without a PHP runtime or the laravel-vite-plugin. Mirrors
// the `@` → resources/js alias used by knip.json and the app.
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        include: ['resources/js/**/*.spec.js'],
        setupFiles: ['resources/js/test/setup.js'],
        coverage: {
            provider: 'v8',
            reportsDirectory: 'coverage/js',
            include: ['resources/js/**/*.{js,vue}'],
            exclude: [
                'resources/js/**/*.spec.js',
                'resources/js/test/**',
                'resources/js/app.js',
                'resources/js/bootstrap.js',
            ],
        },
    },
});
