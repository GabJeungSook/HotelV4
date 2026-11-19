import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import RoomTable, { type BoardFloor } from '../RoomTable.vue';

const now = '2026-11-11T03:44:00Z'; // Wed 11 Nov 2026, 11:44 AM in Manila

const floors: BoardFloor[] = [
    {
        label: 'Floor 1',
        rooms: [
            { id: 1, number: '117', type: 'Standard', status: 'overdue', guest: 'D. Abella', dueOutAt: '2026-11-11T03:02:00Z', minutes: -42, balance: 15000 },
            { id: 2, number: '101', type: 'Standard', status: 'vacant' },
            { id: 3, number: '106', type: 'Standard', status: 'due_out', guest: 'L. Ramos', dueOutAt: '2026-11-11T04:06:00Z', minutes: 22 },
            { id: 4, number: '108', type: 'Standard', status: 'blocked' },
        ],
    },
    {
        label: 'Floor 2',
        rooms: [
            { id: 5, number: '205', type: 'Deluxe', status: 'occupied', guest: 'R. Manalastas', dueOutAt: '2026-11-11T13:12:00Z', minutes: 568, balance: 11500 },
            { id: 6, number: '203', type: 'Deluxe', status: 'to_clean', minutes: 48 },
            { id: 7, number: '212', type: 'Deluxe', status: 'held', holdSeconds: 402 },
        ],
    },
];

function mountTable(props: Record<string, unknown> = {}) {
    return mount(RoomTable, { props: { floors, now, ...props }, global: { plugins: [ui] } });
}

const numbers = (wrapper: ReturnType<typeof mountTable>) => wrapper.findAll('tr[data-status]').map((r) => r.find('td').text());

describe('RoomTable', () => {
    it('renders one row per room, by number, grouped by floor', () => {
        const wrapper = mountTable();

        expect(numbers(wrapper)).toEqual(['101', '106', '108', '117', '203', '205', '212']);
        expect(wrapper.text()).toContain('Floor 1 · 4 rooms');
        expect(wrapper.text()).toContain('Floor 2 · 3 rooms');
    });

    it('shows the status word with its tone and reddens a room due within the hour or overdue', () => {
        const wrapper = mountTable();
        const row = wrapper.find('tr[data-status="overdue"]');
        const label = row.find('[data-status-label]');

        expect(label.text()).toBe('Overdue');
        expect(label.attributes('data-tone')).toBe('bad');
        // Overdue (-42 min) and due-out-soon (22 min) rows go red; a room with hours left does not.
        expect(row.classes()).toContain('bg-bad-50');
        expect(wrapper.find('tr[data-status="due_out"]').classes()).toContain('bg-bad-50');
        expect(wrapper.find('tr[data-status="occupied"]').classes().some((c) => c.startsWith('bg-'))).toBe(false);
    });

    it('formats guest, due-out clock time, time left or over, and balance', () => {
        const wrapper = mountTable();
        const overdue = wrapper.find('tr[data-status="overdue"]');
        const dueOut = wrapper.find('tr[data-status="due_out"]');

        expect(overdue.text()).toContain('D. Abella');
        expect(overdue.text()).toContain('11:02 AM');
        expect(overdue.find('[data-time]').text()).toBe('42m over');
        expect(overdue.find('[data-time]').classes()).toContain('text-bad');
        expect(overdue.text()).toContain('₱150.00');
        expect(dueOut.find('[data-time]').text()).toBe('22m left');
        expect(dueOut.find('[data-time]').classes()).toContain('text-warn');
        expect(wrapper.find('tr[data-status="occupied"] [data-time]').text()).toBe('9h 28m left');
        expect(wrapper.find('tr[data-status="held"] [data-time]').text()).toBe('hold 6:42');
        expect(wrapper.find('tr[data-status="to_clean"] [data-time]').text()).toBe('waiting 48m');
    });

    it('offers "Check in" on vacant rooms and gives each row an accessible name', () => {
        const wrapper = mountTable();

        expect(wrapper.find('tr[data-status="vacant"]').text()).toContain('Check in');
        expect(wrapper.find('tr[data-status="due_out"]').attributes('aria-label')).toBe('Room 106, Standard, Due out, L. Ramos, due 12:06 PM, 22 minutes left');
    });

    it('emits select for rooms that open something, not for blocked or housekeeping rows', async () => {
        const wrapper = mountTable();

        await wrapper.find('tr[data-status="occupied"]').trigger('click');
        await wrapper.find('tr[data-status="vacant"]').trigger('keydown', { key: 'Enter' });
        await wrapper.find('tr[data-status="blocked"]').trigger('click');
        await wrapper.find('tr[data-status="to_clean"]').trigger('click');

        expect(wrapper.emitted('select')?.map((e) => (e[0] as { number: string }).number)).toEqual(['205', '101']);
        expect(wrapper.find('tr[data-status="blocked"]').attributes('tabindex')).toBeUndefined();
    });

    it('filters occupied (with due out and overdue) and sorts by attention', () => {
        const wrapper = mountTable({ filter: 'occupied', sort: 'attention' });

        expect(numbers(wrapper)).toEqual(['117', '106', '205']);
    });

    it('limits rows per floor with a "more rooms" line', () => {
        const wrapper = mountTable({ limit: 2 });

        expect(numbers(wrapper)).toEqual(['101', '106', '203', '205']);
        expect(wrapper.text()).toContain('2 more rooms on Floor 1');
    });

    it('shows ten skeleton bars while loading and an empty state with nothing to show', () => {
        expect(mountTable({ loading: true }).findAll('[data-skeleton] > *')).toHaveLength(10);
        expect(mountTable({ filter: 'housekeeping', floors: [floors[0]] }).find('[data-empty-state]').text()).toContain('No rooms to show');
    });
});
