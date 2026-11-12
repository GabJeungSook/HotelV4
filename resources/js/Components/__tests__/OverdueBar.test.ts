import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import OverdueBar from '../OverdueBar.vue';
import PinPad from '../PinPad.vue';

// 8:00 PM in Manila on 11 Nov 2026.
const endedAt = '2026-11-11T12:00:00Z';

describe('OverdueBar', () => {
    it('reminds the team in amber that everything still goes to the late shift', () => {
        const wrapper = mount(OverdueBar, { props: { periodName: 'AM', endedAt }, global: { plugins: [ui] } });

        expect(wrapper.attributes('data-blocked')).toBe('false');
        expect(wrapper.classes()).toContain('bg-warn-50');
        expect(wrapper.text()).toContain('The AM shift should have ended at 8:00 PM. You can keep working, and everything still goes to the AM shift.');
    });

    it('turns red once payments and charges are stopped, and its button asks to close', async () => {
        const wrapper = mount(OverdueBar, { props: { periodName: 'AM', endedAt, blocked: true, minutesPast: 245 }, global: { plugins: [ui] } });

        expect(wrapper.classes()).toContain('bg-bad-50');
        expect(wrapper.text()).toContain('The AM shift is 4 hours past its end. Payments and charges are stopped until it is closed.');

        await wrapper.get('button').trigger('click');
        expect(wrapper.emitted('close')).toHaveLength(1);
    });

    it('has no Close shift button where the close guide is already open', () => {
        const wrapper = mount(OverdueBar, { props: { periodName: 'AM', endedAt, closable: false }, global: { plugins: [ui] } });

        expect(wrapper.find('button').exists()).toBe(false);
    });
});

describe('PinPad on a page with fields', () => {
    it('leaves digits typed into a field to that field', async () => {
        const wrapper = mount(PinPad, { global: { plugins: [ui] }, attachTo: document.body });
        const input = document.createElement('input');
        document.body.appendChild(input);

        input.dispatchEvent(new KeyboardEvent('keydown', { key: '5', bubbles: true }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: '1' }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: '2' }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: '3' }));
        window.dispatchEvent(new KeyboardEvent('keydown', { key: '4' }));
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('complete')).toEqual([['1234']]);
        input.remove();
        wrapper.unmount();
    });
});
