import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import { barPath, hideTip, niceTop, peakOf, showTip, tip } from '../chart';
import HBars from '../HBars.vue';
import HeatGrid from '../HeatGrid.vue';
import Shares from '../Shares.vue';
import Spark from '../Spark.vue';
import VBars from '../VBars.vue';

const global = { plugins: [ui] };

describe('VBars', () => {
    const props = {
        labels: ['Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        series: [{ name: 'Sales', values: [4420600, 4135400, 4991000, 6274400, 7130000] }],
        money: true,
    };

    it('renders a bar per value with the value as text, and marks the peak in primary', () => {
        const wrapper = mount(VBars, { props, global });
        const bars = wrapper.findAll('[data-bar]');

        expect(bars).toHaveLength(5);
        expect(bars[4].attributes('aria-label')).toBe('Sun: ₱71,300');
        expect(bars[4].attributes('data-peak')).toBe('true');
        expect(bars[4].attributes('fill')).toBe('var(--hm-primary)');
        expect(bars[0].attributes('fill')).toBe('var(--hm-bar-soft)');
        expect(wrapper.find('table').text()).toContain('₱44,206');
    });

    it('draws two series side by side with a legend', () => {
        const wrapper = mount(VBars, { props: { labels: ['A', 'B'], series: [{ name: 'This week', values: [1, 2] }, { name: 'Last week', values: [2, 1] }] }, global });

        expect(wrapper.findAll('[data-bar]')).toHaveLength(4);
        expect(wrapper.text()).toContain('Last week');
    });

    it('shows the value on hover through the shared tip', async () => {
        const wrapper = mount(VBars, { props, global });

        await wrapper.findAll('[data-bar]')[2].trigger('mousemove', { clientX: 10, clientY: 20 });
        expect(tip).toMatchObject({ visible: true, title: 'Fri', text: '₱49,910' });

        await wrapper.findAll('[data-bar]')[2].trigger('mouseleave');
        expect(tip.visible).toBe(false);
    });

    it('renders the plain empty state with no data, never an empty axis', () => {
        const wrapper = mount(VBars, { props: { ...props, series: [{ name: 'Sales', values: [0, 0, 0, 0, 0] }], emptyText: 'No sales in this period' }, global });

        expect(wrapper.find('svg').exists()).toBe(false);
        expect(wrapper.find('[data-empty-state]').text()).toBe('No sales in this period');
    });
});

describe('HBars', () => {
    const rows = [
        { name: 'Standard', value: 168300, text: '168,300' },
        { name: 'Deluxe', value: 144950, text: '144,950' },
        { name: 'A very long product name that is cut', value: 30600, text: '30,600' },
    ];

    it('renders ranked rows with the value text and bars relative to the largest', () => {
        const wrapper = mount(HBars, { props: { rows }, global });
        const items = wrapper.findAll('[data-hbar]');

        expect(items).toHaveLength(3);
        expect(items[0].text()).toContain('168,300');
        expect(items[0].find('span span').attributes('style')).toContain('width: 100%');
        expect(items[2].text()).toContain('A very long product n…');
        expect(items[2].attributes('aria-label')).toBe('A very long product name that is cut: 30,600');
    });

    it('draws the track to `total` when given', () => {
        const wrapper = mount(HBars, { props: { rows: [{ name: 'GCash', value: 0, text: '0.00', total: 930500 }, { name: 'Cash', value: 930500, text: '9,305.00' }] }, global });

        expect(wrapper.findAll('[data-hbar]')[0].find('span span').attributes('style')).toContain('width: 0%');
    });

    it('renders the empty state with no rows', () => {
        expect(mount(HBars, { props: { rows: [], emptyText: 'No payments today' }, global }).find('[data-empty-state]').text()).toBe('No payments today');
    });
});

describe('Shares', () => {
    it('renders one part per row with value or percent in the legend', () => {
        const rows = [
            { name: 'Vacant', value: 14, color: 'var(--hm-ok)' },
            { name: 'Occupied', value: 23, color: 'var(--hm-info)' },
        ];
        const wrapper = mount(Shares, { props: { rows }, global });

        expect(wrapper.findAll('[data-share]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Vacant 14');
        expect(wrapper.findAll('[data-share]')[1].attributes('aria-label')).toBe('Occupied: 23, 62%');

        const pct = mount(Shares, { props: { rows: [{ name: '6 hrs', value: 38 }, { name: '12 hrs', value: 62 }], legend: 'percent' }, global });
        expect(pct.text()).toContain('6 hrs 38%');
    });

    it('folds a sixth part and more into "Other"', () => {
        const rows = ['A', 'B', 'C', 'D', 'E', 'F'].map((name, i) => ({ name, value: 60 - i * 10 }));
        const wrapper = mount(Shares, { props: { rows }, global });

        expect(wrapper.findAll('[data-share]')).toHaveLength(5);
        expect(wrapper.text()).toContain('Other 30');
    });

    it('renders the empty state when every value is zero', () => {
        expect(mount(Shares, { props: { rows: [{ name: 'A', value: 0 }] }, global }).find('[data-empty-state]').exists()).toBe(true);
    });
});

describe('HeatGrid', () => {
    it('renders 7 rows of hour cells with opacity by value and the value as text', () => {
        const wrapper = mount(HeatGrid, { props: { hours: ['8a', '9a'], grid: { Sat: [0, 10], Sun: [5, 0] } }, global });
        const cells = wrapper.findAll('[data-heat-cell]');

        expect(cells).toHaveLength(14);
        const sat9 = cells.find((c) => c.attributes('aria-label') === 'Sat 9a: 10 check-ins')!;
        const sat8 = cells.find((c) => c.attributes('aria-label') === 'Sat 8a: 0 check-ins')!;
        expect(sat9.attributes('style')).toContain('opacity: 1');
        expect(sat8.attributes('style')).toContain('opacity: 0.04');
        expect(wrapper.text()).toContain('darker is busier');
    });

    it('renders the empty state with no check-ins', () => {
        expect(mount(HeatGrid, { props: { hours: ['8a'], grid: {} }, global }).find('[data-empty-state]').text()).toBe('No check-ins in this period');
    });
});

describe('Spark', () => {
    it('draws a line through the values and names them for screen readers', () => {
        const wrapper = mount(Spark, { props: { values: [3, 5, 4, 8], tone: 'ok' }, global });

        expect(wrapper.find('polyline').attributes('points')?.split(' ')).toHaveLength(4);
        expect(wrapper.find('polyline').attributes('stroke')).toBe('var(--hm-ok)');
        expect(wrapper.find('svg').attributes('aria-label')).toBe('Trend: 3, 5, 4, 8');
    });

    it('renders a plain empty state with fewer than two points', () => {
        expect(mount(Spark, { props: { values: [3] }, global }).find('[data-spark-empty]').text()).toBe('No trend yet');
    });
});

describe('chart helpers', () => {
    it('picks an axis top that splits into four round steps', () => {
        expect([niceTop(0), niceTop(7), niceTop(71300), niceTop(2650), niceTop(100)]).toEqual([4, 8, 80000, 4000, 100]);
    });

    it('finds the peak, first on a tie, none when all zero', () => {
        expect(peakOf([1, 5, 5, 2])).toEqual({ index: 1, value: 5 });
        expect(peakOf([0, 0])).toBeNull();
    });

    it('builds no path for an empty bar', () => {
        expect(barPath(0, 0, 10, 0)).toBe('');
        expect(barPath(0, 0, 10, 20)).toMatch(/^M0,20V/);
    });

    it('shows and hides the tip', () => {
        showTip(new MouseEvent('mousemove', { clientX: 5, clientY: 6 }), 'Sun', '₱1');
        expect(tip).toMatchObject({ visible: true, x: 5, y: 6 });
        hideTip();
        expect(tip.visible).toBe(false);
    });
});
