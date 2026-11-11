import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import Welcome from '../Welcome.vue';

// Inertia's <Head> needs a live Inertia app; outside one it renders nothing.
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { defineComponent } = await vi.importActual<typeof import('vue')>('vue');

    return {
        ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
        Head: defineComponent({ render: () => null }),
    };
});

describe('Welcome (Phase 0 placeholder)', () => {
    it('renders one Nuxt UI button', () => {
        const wrapper = mount(Welcome, {
            props: { app: { name: 'Homi' } },
            global: { plugins: [ui] },
        });

        const buttons = wrapper.findAll('button');

        expect(buttons).toHaveLength(1);
        expect(buttons[0].text()).toContain('Homi is set up');
    });
});
