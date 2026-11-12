import ui from '@nuxt/ui/vue-plugin';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { useKioskIdle } from '@/Composables/useKioskIdle';
import KioskHoldCard from '../KioskHoldCard.vue';
import KioskTypeCard from '../KioskTypeCard.vue';

vi.mock('qrcode', () => ({ default: { toCanvas: vi.fn(() => Promise.reject(new Error('no canvas in happy-dom'))) } }));

describe('KioskTypeCard', () => {
    it('shows the offered room and emits select when tapped', async () => {
        const wrapper = mount(KioskTypeCard, { props: { name: 'Deluxe', description: 'Queen bed', room: 'Room 210 · 2nd floor', selected: true }, global: { plugins: [ui] } });

        expect(wrapper.text()).toContain('Room 210 · 2nd floor');
        expect(wrapper.attributes('data-state')).toBe('selected');
        expect(wrapper.attributes('aria-pressed')).toBe('true');

        await wrapper.trigger('click');
        expect(wrapper.emitted('select')).toHaveLength(1);
    });

    it('cannot be picked when the type is full', async () => {
        const wrapper = mount(KioskTypeCard, { props: { name: 'Suite', full: true }, global: { plugins: [ui] } });

        expect(wrapper.text()).toContain('Full');
        expect(wrapper.attributes('data-state')).toBe('full');
        expect(wrapper.classes()).toContain('opacity-50');

        await wrapper.trigger('click');
        expect(wrapper.emitted('select')).toBeUndefined();
    });
});

describe('KioskHoldCard', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('counts down to the server expiry and always prints the code, even when the QR fails to draw', async () => {
        vi.setSystemTime(new Date('2026-11-12T01:00:00Z'));
        const wrapper = mount(KioskHoldCard, {
            props: { code: 'DGS-26-004127', amount: 60000, expiresAt: '2026-11-12T01:10:00Z', now: '2026-11-12T01:00:02Z' },
            global: { plugins: [ui] },
        });

        expect(wrapper.get('[data-code]').text()).toBe('DGS-26-004127');
        expect(wrapper.text()).toContain('₱600.00');
        expect(wrapper.get('[data-countdown]').text()).toBe('Held for 9:58');

        await vi.advanceTimersByTimeAsync(3000);
        expect(wrapper.get('[data-countdown]').text()).toBe('Held for 9:55');

        await vi.advanceTimersByTimeAsync(10 * 60 * 1000);
        expect(wrapper.get('[data-countdown]').text()).toBe('This hold has ended');
        expect(wrapper.get('[data-code]').text()).toBe('DGS-26-004127');
    });
});

describe('useKioskIdle', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('runs after the idle time, and any touch starts the count again', async () => {
        const onIdle = vi.fn();
        const wrapper = mount(defineComponent({ setup: () => (useKioskIdle(60, onIdle), () => h('div')) }), { attachTo: document.body });

        await vi.advanceTimersByTimeAsync(59_000);
        window.dispatchEvent(new Event('pointerdown'));
        await vi.advanceTimersByTimeAsync(59_000);
        expect(onIdle).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1_000);
        expect(onIdle).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });
});
