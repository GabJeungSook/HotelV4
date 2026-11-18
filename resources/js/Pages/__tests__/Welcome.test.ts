import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import Welcome from '../Welcome.vue';

const shared = vi.hoisted(() => ({ auth: null as null | object, home: null as null | string, app: { name: 'Homi' } }));

// Inertia's <Head> and <Link> need a live app; usePage gives the shared props.
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
        Head: defineComponent({ render: () => null }),
        Link: defineComponent({ props: { href: String }, setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default?.()) }),
        usePage: () => ({ props: shared, url: '/' }),
    };
});

const route = (name: string) => ({ login: '/login', home: '/home', welcome: '/' })[name] ?? '/';
vi.stubGlobal('route', route);

// The landing wires scroll reveals and a nav scroll-spy on mount; stub what jsdom lacks.
class IO {
    observe() {}
    unobserve() {}
    disconnect() {}
}
vi.stubGlobal('IntersectionObserver', IO);
vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener() {}, removeEventListener() {} }));

function render() {
    return mount(Welcome, { global: { plugins: [ui], mocks: { route } } });
}

describe('Welcome — HOMI landing (59)', () => {
    it('leads with the HOMI value proposition', () => {
        const text = render().text();

        expect(text).toContain('The smarter way to run your');
        expect(text).toContain('hotel.');
        expect(text).toContain('Less manpower. More efficiency. Happier guests.');
    });

    it('presents the system sections and contact details', () => {
        const text = render().text();

        expect(text).toContain('Everything your front desk needs');
        expect(text).toContain('Why hotels switch to HOMI');
        expect(text).toContain('Now with kiosk integration');
        expect(text).toContain('homi@gmail.com');
    });

    it('offers "Staff sign in" to visitors and "Dashboard" to signed-in staff', () => {
        expect(render().find('[data-test="welcome-sign-in"]').attributes('href')).toBe('/login');

        shared.auth = { user: { id: 1, name: 'Ramon', username: 'ramon.alvarez' }, role: 'owner', roleLabel: 'Owner' };
        shared.home = '/home';
        const wrapper = render();
        expect(wrapper.find('[data-test="welcome-dashboard"]').attributes('href')).toBe('/home');
        expect(wrapper.find('[data-test="welcome-sign-in"]').exists()).toBe(false);
        shared.auth = null;
        shared.home = null;
    });
});
