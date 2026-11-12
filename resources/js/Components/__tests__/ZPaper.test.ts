import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import CheckResult from '../CheckResult.vue';
import ExportMenu from '../ExportMenu.vue';
import ZPaper from '../ZPaper.vue';
import { demoPaper } from './fixtures/zpaper';

const global = { plugins: [ui], stubs: { HomiLogo: true } };

describe('ZPaper', () => {
    it('renders the builder array as given: header, waterfall, tables, grand total and signatures', () => {
        const wrapper = mount(ZPaper, { props: { paper: demoPaper }, global });
        const text = wrapper.text();

        expect(text).toContain('Z-0629');
        expect(text).toContain('Z-READ · AM shift · Wed 11 Nov 2026');
        expect(text).toContain('Printed 8:06 PM by Jessa Mae D.');
        expect(wrapper.get('[data-line="Gross sales"]').text()).toBe('Gross sales18,460.00');
        expect(wrapper.get('[data-line="Less Promo ₱50"]').text()).toBe('Less Promo ₱50 · 4−200.00');
        expect(wrapper.get('[data-line="Net sales"]').classes()).toContain('font-bold');
        expect(wrapper.get('[data-line="Short"]').classes()).toContain('text-bad');
        expect(text).toContain('"Gave wrong change to Room 203, ₱50."');
        expect(text).toContain('10,480.00');
        expect(text).toContain('11,500,500.00');
        expect(text).toContain('Slip reprints2—');
        expect(text).toContain('Mark Anthony Sumagaysay');
        expect(text).not.toContain('Preview. Do not sign.');
    });

    it('marks a preview with the watermark and no Z number', () => {
        const wrapper = mount(ZPaper, { props: { paper: { ...demoPaper, preview: true, header: { ...demoPaper.header, number: null } } }, global });

        expect(wrapper.text()).toContain('Preview. Do not sign.');
        expect(wrapper.text()).not.toContain('Z-0629');
    });

    it('opens the ledger rows of a drillable line and nothing else', async () => {
        const wrapper = mount(ZPaper, { props: { paper: demoPaper, drillable: true }, global });

        await wrapper.get('[data-line="Less Promo ₱50"]').trigger('click');
        expect(wrapper.emitted('drill')).toEqual([['discount:Promo', 'Less Promo ₱50']]);
        expect(wrapper.get('[data-line="Counted"]').element.tagName).toBe('DIV');

        const plain = mount(ZPaper, { props: { paper: demoPaper }, global });
        expect(plain.get('[data-line="Gross sales"]').element.tagName).toBe('DIV');
    });
});

describe('ExportMenu and CheckResult', () => {
    it('disables the export of a preview', () => {
        const wrapper = mount(ExportMenu, { props: { pdf: true, csv: true, disabled: true }, global: { plugins: [ui], stubs: { UTooltip: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' } } } });

        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
        expect(wrapper.text()).toContain('Export');
    });

    it('shows a red line when a report does not add up, green when the check passes', () => {
        const bad = mount(CheckResult, { props: { problems: ['Net sales shows ₱1.00 but the lines above add up to ₱2.00.'] }, global: { plugins: [ui] } });
        expect(bad.text()).toContain('This report does not add up');
        expect(bad.text()).toContain('Net sales shows');

        const diff = mount(CheckResult, {
            props: { differences: [{ shift_id: 1, label: 'AM Z-0629', figure: 'sales.total', stored: 1816000, recomputed: 1806000 }] },
            global: { plugins: [ui] },
        });
        expect(diff.text()).toContain('frozen ₱18,160.00, ledger ₱18,060.00');

        const ok = mount(CheckResult, { props: { passText: 'Matches the ledger for the business day.' }, global: { plugins: [ui] } });
        expect(ok.text()).toContain('Matches the ledger');
    });
});
