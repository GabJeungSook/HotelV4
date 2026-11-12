import { fileURLToPath, URL } from 'node:url';
import ui from '@nuxt/ui/vite';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';
import { nuxtUiOptions } from './nuxt-ui.config.ts';

// Component tests (TRD §10). Uses the same Vue and Nuxt UI plugins as the app,
// without the Laravel plugin, in a happy-dom browser environment.
export default defineConfig({
    plugins: [vue(), ui(nuxtUiOptions)],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            'ziggy-js': fileURLToPath(new URL('./vendor/tightenco/ziggy', import.meta.url)),
        },
    },
    test: {
        environment: 'happy-dom',
        globals: true,
        include: ['resources/js/**/*.{test,spec}.ts'],
        server: {
            deps: {
                inline: ['@nuxt/ui'],
            },
        },
    },
});
