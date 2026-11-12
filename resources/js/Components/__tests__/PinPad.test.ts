import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import PinPad from '../PinPad.vue';

function mountPad(props: Record<string, unknown> = {}) {
    return mount(PinPad, { props: { keyboard: false, ...props }, global: { plugins: [ui] } });
}

async function tap(wrapper: ReturnType<typeof mountPad>, key: string) {
    await wrapper.find(`[data-key="${key}"]`).trigger('click');
}

describe('PinPad', () => {
    it('draws one cell per digit and a 3 × 4 key grid', () => {
        const wrapper = mountPad();

        expect(wrapper.findAll('[data-pin-cell]')).toHaveLength(4);
        expect(wrapper.findAll('[data-key]')).toHaveLength(11);
    });

    it('fills cells with a dot, never the digit, and emits complete on the last digit', async () => {
        const wrapper = mountPad();

        for (const key of ['4', '7', '1']) {
            await tap(wrapper, key);
        }

        const cells = wrapper.findAll('[data-pin-cell]').map((c) => c.text());
        expect(cells).toEqual(['•', '•', '•', '']);
        expect(wrapper.text()).not.toContain('47');
        expect(wrapper.emitted('complete')).toBeUndefined();

        await tap(wrapper, '9');

        expect(wrapper.emitted('complete')).toEqual([['4719']]);
    });

    it('deletes the last digit with ⌫ and ignores keys past the length', async () => {
        const wrapper = mountPad({ length: 4 });

        await tap(wrapper, '1');
        await tap(wrapper, '2');
        await tap(wrapper, 'back');
        await tap(wrapper, '3');
        await tap(wrapper, '4');
        await tap(wrapper, '5');
        await tap(wrapper, '6');

        expect(wrapper.emitted('complete')).toEqual([['1345']]);
    });

    it('respects the configured length', async () => {
        const wrapper = mountPad({ length: 6 });

        expect(wrapper.findAll('[data-pin-cell]')).toHaveLength(6);
    });

    it('clears the digits and shows the message and tries left on error', async () => {
        const wrapper = mountPad();

        await tap(wrapper, '1');
        await tap(wrapper, '2');
        await wrapper.setProps({ error: 'PIN not recognised', triesLeft: 2 });

        expect(wrapper.findAll('[data-pin-cell]').map((c) => c.text())).toEqual(['', '', '', '']);
        expect(wrapper.find('[role="alert"]').text()).toContain('PIN not recognised');
        expect(wrapper.text()).toContain('2 tries left');
    });

    it('refuses input when locked and says how to unlock', async () => {
        const wrapper = mountPad({ locked: true });

        await tap(wrapper, '1');

        expect(wrapper.findAll('[data-pin-cell]')[0].text()).toBe('');
        expect(wrapper.find('[data-key="1"]').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('PIN entry is locked on this computer');
    });

    it('accepts the physical keyboard when enabled', async () => {
        const wrapper = mount(PinPad, { props: { keyboard: true }, global: { plugins: [ui] }, attachTo: document.body });

        for (const key of ['5', '5', '0', '1']) {
            window.dispatchEvent(new KeyboardEvent('keydown', { key }));
        }

        expect(wrapper.emitted('complete')).toEqual([['5501']]);
        wrapper.unmount();
    });
});
