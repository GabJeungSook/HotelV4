import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import MoneyInput from '../MoneyInput.vue';

function mountInput(props: Record<string, unknown> = {}) {
    return mount(MoneyInput, { props: { modelValue: 0, ...props }, global: { plugins: [ui] }, attachTo: document.body });
}

function emitted(wrapper: ReturnType<typeof mountInput>): number[] {
    return (wrapper.emitted('update:modelValue') ?? []).map((args) => args[0] as number);
}

describe('MoneyInput', () => {
    it('is a decimal text field, never type="number"', () => {
        const input = mountInput().find('input');

        expect(input.attributes('type')).toBe('text');
        expect(input.attributes('inputmode')).toBe('decimal');
    });

    it('emits integer centavos as the person types', async () => {
        const wrapper = mountInput();
        const input = wrapper.find('input');

        await input.trigger('focus');
        await input.setValue('1000');
        await input.setValue('1000.5');
        await input.setValue('1000.55');

        expect(emitted(wrapper)).toEqual([100000, 100050, 100055]);
        expect(emitted(wrapper).every(Number.isInteger)).toBe(true);
    });

    it('keeps digits and one point only, at most two decimals, no negatives or letters', async () => {
        const wrapper = mountInput();
        const input = wrapper.find('input');

        await input.trigger('focus');
        await input.setValue('-12a3.4.567e');

        expect((input.element as HTMLInputElement).value).toBe('123.45');
        expect(emitted(wrapper).at(-1)).toBe(12345);
    });

    it('drops leading zeros', async () => {
        const wrapper = mountInput();
        const input = wrapper.find('input');

        await input.trigger('focus');
        await input.setValue('0075');

        expect((input.element as HTMLInputElement).value).toBe('75');
        expect(emitted(wrapper).at(-1)).toBe(7500);
    });

    it('shows thousands separators and two decimals with the peso sign after blur', async () => {
        const wrapper = mountInput({ modelValue: 100000 });

        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('1,000.00');
        expect(wrapper.text()).toContain('₱');
    });

    it('fills the amount due with "Exact" — emits it and shows it in the field', async () => {
        const wrapper = mountInput({ due: 80000 });
        const exact = wrapper.findAll('button').find((b) => b.text() === 'Exact');

        await exact!.trigger('click');

        expect(emitted(wrapper).at(-1)).toBe(80000);
        // The field must actually show the amount — focusing after the fill must not wipe it.
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('800.00');
    });

    it('caps at max', async () => {
        const wrapper = mountInput({ max: 50000 });
        const input = wrapper.find('input');

        await input.trigger('focus');
        await input.setValue('999');

        expect(emitted(wrapper).at(-1)).toBe(50000);
    });

    it('shows the error and disabled states', () => {
        expect(mountInput({ error: 'Enter an amount.' }).find('[data-state]').attributes('data-state')).toBe('error');
        expect(mountInput({ error: 'Enter an amount.' }).find('input').attributes('aria-invalid')).toBe('true');

        const disabled = mountInput({ disabled: true, due: 100 });
        expect(disabled.find('input').attributes('disabled')).toBeDefined();
        expect(disabled.findAll('button')).toHaveLength(0);
    });
});
