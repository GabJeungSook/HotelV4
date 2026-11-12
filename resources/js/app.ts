import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import ui from '@nuxt/ui/vue-plugin';
import UApp from '@nuxt/ui/components/App.vue';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import ChartTip from './Components/Charts/ChartTip.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Homi';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    // Pages load lazily, one chunk each, so the dev gallery and rarely used pages stay out of the main bundle.
    resolve: async (name) => {
        const pages = import.meta.glob<DefineComponent>(['./Pages/**/*.vue', '!./Pages/**/Partials/**']);
        const page = pages[`./Pages/${name}.vue`];

        if (!page) {
            throw new Error(`Page not found: ${name}`);
        }

        return page();
    },
    setup({ el, App, props, plugin }) {
        // Every page sits inside one <UApp> (toaster, tooltips, overlays) with the one chart tooltip beside it.
        createApp({
            render: () => h(UApp, { toaster: { position: 'bottom-right', duration: 4000 }, tooltip: { delayDuration: 400 } }, () => [h(App, props), h(ChartTip)]),
        })
            .use(plugin)
            .use(ui)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: 'var(--ui-primary)',
    },
});
