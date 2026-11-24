import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import CleanTimer from '../CleanTimer.vue';
import TaskCard from '../TaskCard.vue';

describe('TaskCard', () => {
    it('marks an urgent startable room with a red edge and one Start cleaning button', async () => {
        const wrapper = mount(TaskCard, {
            props: { room: '117', waitingMinutes: 130, tone: 'bad', urgent: true, startable: true, detail: 'Standard · checked out 12:04 PM · clean by 4:04 PM' },
            global: { plugins: [ui] },
        });

        expect(wrapper.text()).toContain('Room 117');
        expect(wrapper.text()).toContain('Urgent · 2 h 10 m');
        expect(wrapper.classes()).toContain('border-l-bad');
        expect(wrapper.classes()).not.toContain('opacity-55');

        await wrapper.get('button').trigger('click');
        expect(wrapper.emitted('start')).toHaveLength(1);
    });

    it('greys out a room that is not startable; only the next one says Next in line', () => {
        const next = mount(TaskCard, { props: { room: '108', waitingMinutes: 12, nextInLine: true }, global: { plugins: [ui] } });
        const later = mount(TaskCard, { props: { room: '221', waitingMinutes: 5 }, global: { plugins: [ui] } });

        expect(next.classes()).toContain('opacity-60');
        expect(next.text()).toContain('12 m waiting');
        expect(next.get('button').attributes('disabled')).toBeDefined();
        expect(next.get('button').text()).toBe('Next in line');
        expect(later.find('button').exists()).toBe(false);
    });
});

describe('CleanTimer', () => {
    it('shows minutes and seconds since the start against the target', () => {
        const wrapper = mount(CleanTimer, {
            props: { startedAt: '2026-11-11T06:15:00Z', now: Date.parse('2026-11-11T06:21:12Z'), targetMinutes: 15 },
            global: { plugins: [ui] },
        });

        expect(wrapper.get('[role="timer"]').text()).toBe('06:12');
        expect(wrapper.text()).toContain('Target: 15 minutes');
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('41');
    });

    it('turns amber past the target and keeps the bar full', () => {
        const wrapper = mount(CleanTimer, {
            props: { startedAt: '2026-11-11T06:00:00Z', now: Date.parse('2026-11-11T06:20:00Z'), targetMinutes: 15 },
            global: { plugins: [ui] },
        });

        expect(wrapper.get('[role="timer"]').classes()).toContain('text-warn');
        expect(wrapper.get('[role="progressbar"]').attributes('aria-valuenow')).toBe('100');
    });
});
