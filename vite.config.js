import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        rollupOptions: {
            // The Forge box caps the deploy user at 400 open files; Rollup's
            // default of 1000 parallel reads fails the build there with EMFILE.
            maxParallelFileOps: 50,
        },
    },
});
