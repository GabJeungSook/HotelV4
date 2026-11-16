import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import Welcome from '../Welcome.vue';

const shared = vi.hoisted(() => ({ auth: null as null | object, home: null as null | string, app: { name: 'Homi' } }));

// Inertia's <Head> and <Link> need a live Inertia app; usePage gives the shared props.
vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const { defineComponent, h } = await vi.importActual<typeof import('vue')>('vue');

    return {
        ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
        Head: defineComponent({ render: () => null }),
        Link: defineComponent({ props: { href: String }, setup: (p, { slots }) => () => h('a', { href: p.href }, slots.default?.()) }),
        usePage: () => ({ props: shared, url: '/' }),
        router: { get: vi.fn() },
    };
});

const route = (name: string) => ({ login: '/login', home: '/home', welcome: '/' })[name] ?? '/';
vi.stubGlobal('route', route);

const props = {
    headline: 'Rest well, by the hour or the night.',
    subline: 'Clean, quiet rooms.',
    hero: [
        { src: '/images/photos/lobby.jpg', srcset: '' },
        { src: '/images/photos/signin-afternoon.jpg', srcset: '' },
        { src: '/images/photos/signin-evening.jpg', srcset: '' },
    ],
    branches: [{ id: 1, name: 'Hotel Dalisay GenSan' }],
    branch: { id: 1, name: 'Hotel Dalisay GenSan', address: 'Gensan Drive, General Santos City', place: 'General Santos City', deposit: { name: 'Key', amount: 20000 } },
    types: [
        { id: 1, name: 'Standard', line: 'Double bed', photo: '/images/photos/room-standard.jpg', price: { amount: 35000, hours: 6 }, overnight: 75000 },
        { id: 2, name: 'Deluxe', line: null, photo: null, price: { amount: 45000, hours: 6 }, overnight: null },
        { id: 3, name: 'Suite', line: null, photo: null, price: null, overnight: 130000 },
    ],
    credits: [{ file: 'lobby.jpg', by: 'Frames For Your Heart', url: 'https://unsplash.com/photos/zSG-kd-L6vw' }],
    year: 2026,
};

function render() {
    return mount(Welcome, { props, global: { plugins: [ui], mocks: { route } } });
}

describe('Welcome (59)', () => {
    it('shows each room type with its lowest prices in whole pesos', () => {
        const cards = render().findAll('[data-test="welcome-type"]');

        expect(cards).toHaveLength(3);
        expect(cards[0].text()).toContain('₱350');
        expect(cards[0].text()).toContain('6 hours');
        expect(cards[0].text()).toContain('Overnight ₱750');
        expect(cards[1].text()).not.toContain('Overnight');
        expect(cards[2].text()).toContain('₱1,300');
        expect(cards[2].text()).toContain('overnight');
    });

    it('names the number of room types, the place and the deposit line', () => {
        const text = render().text();

        expect(text).toContain('Three ways to stay');
        expect(text).toContain('General Santos City · Open 24 hours');
        expect(text).toContain('A ₱200 key deposit is added at check-in');
        expect(text).toContain('© 2026 Hotel Dalisay GenSan');
    });

    it('offers "Staff sign in" to visitors and "Dashboard" to signed-in staff', () => {
        expect(render().find('[data-test="welcome-sign-in"]').attributes('href')).toBe('/login');

        shared.auth = { user: { id: 1, name: 'Jessa', username: 'jessa' }, role: 'frontdesk', roleLabel: 'Front desk' };
        shared.home = '/home';
        const wrapper = render();
        expect(wrapper.find('[data-test="welcome-dashboard"]').attributes('href')).toBe('/home');
        expect(wrapper.find('[data-test="welcome-sign-in"]').exists()).toBe(false);
        shared.auth = null;
        shared.home = null;
    });

    it('shows no branch picker with one branch', () => {
        expect(render().find('[data-test="welcome-branch"]').exists()).toBe(false);
    });
});
