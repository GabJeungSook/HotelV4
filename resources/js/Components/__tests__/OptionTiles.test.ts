import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import OptionTiles from '../OptionTiles.vue';

const options = [
    { value: '6', label: '6 hrs', sub: '₱450' },
    { value: '12', label: '12 hrs', sub: '₱650' },
    { value: '24', label: '24 hrs', disabled: true, reason: 'no rate' },
    { value: 'night', label: 'Overnight', sub: '₱950' },
];

function mountTiles(modelValue: string | null = '6') {
    return mount(OptionTiles, { props: { options, modelValue, label: 'Stay length' }, global: { plugins: [ui] }, attachTo: document.body });
}

describe('OptionTiles', () => {
    it('is a radio group with one radio per option and the second line', () => {
        const wrapper = mountTiles();

        expect(wrapper.find('[role="radiogroup"]').attributes('aria-label')).toBe('Stay length');
        expect(wrapper.findAll('[role="radio"]')).toHaveLength(4);
        expect(wrapper.text()).toContain('₱650');
    });

    it('marks the selected tile and selects on click', async () => {
        const wrapper = mountTiles();
        const radios = wrapper.findAll('[role="radio"]');

        expect(radios[0].attributes('aria-checked')).toBe('true');
        expect(radios[0].attributes('data-state')).toBe('selected');

        await radios[1].trigger('click');

        expect(wrapper.emitted('update:modelValue')).toEqual([['12']]);
    });

    it('shows an unavailable tile with its reason and never selects it', async () => {
        const wrapper = mountTiles();
        const off = wrapper.findAll('[role="radio"]')[2];

        expect(off.attributes('data-state')).toBe('unavailable');
        expect(off.attributes('disabled')).toBeDefined();
        expect(off.text()).toContain('no rate');

        await off.trigger('click');

        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
    });

    it('moves with arrow keys, skipping unavailable tiles, and wraps', async () => {
        const wrapper = mountTiles('12');
        const radios = wrapper.findAll('[role="radio"]');

        await radios[1].trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['night']);

        await radios[0].trigger('keydown', { key: 'ArrowLeft' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['night']);
    });

    it('gives Tab focus only to the selected tile (roving tabindex)', () => {
        const radios = mountTiles('12').findAll('[role="radio"]');

        expect(radios.map((r) => r.attributes('tabindex'))).toEqual(['-1', '0', '-1', '-1']);
    });

    it('focuses the first available tile when nothing is selected', () => {
        const radios = mountTiles(null).findAll('[role="radio"]');

        expect(radios[0].attributes('tabindex')).toBe('0');
    });
});
