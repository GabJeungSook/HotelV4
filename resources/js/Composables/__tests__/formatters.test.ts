import { formatAxisHour, formatDateRange, formatDateTime, formatDuration, formatHour, formatStayLength } from '../useDateTime';
import { formatCount, formatMoney, formatPesoShort, parseMoney, toWholePesos } from '../useMoney';

describe('formatMoney (DESIGN_SYSTEM §7.1)', () => {
    it('formats standard amounts with ₱, separators and two decimals', () => {
        expect(formatMoney(123400)).toBe('₱1,234.00');
        expect(formatMoney(0)).toBe('₱0.00');
        expect(formatMoney(5)).toBe('₱0.05');
        expect(formatMoney(123456789)).toBe('₱1,234,567.89');
    });

    it('puts a real minus sign (U+2212) before the peso sign', () => {
        expect(formatMoney(-5000)).toBe('−₱50.00');
    });

    it('writes an em dash for no value', () => {
        expect(formatMoney(null)).toBe('—');
        expect(formatMoney(undefined)).toBe('—');
    });

    it('has the column, tile, whole, slip and CSV forms', () => {
        expect(formatMoney(-25000, 'column')).toBe('−250.00');
        expect(formatMoney(45000, 'tile')).toBe('₱450');
        expect(formatMoney(45050, 'tile')).toBe('₱450.50');
        expect(formatMoney(942050, 'whole')).toBe('₱9,421');
        expect(formatMoney(942049, 'whole')).toBe('₱9,420');
        expect(formatMoney(-18000, 'whole')).toBe('−₱180');
        expect(formatMoney(1435000, 'wholeColumn')).toBe('14,350');
        expect(formatMoney(-34000, 'slip')).toBe('-340.00');
        expect(formatMoney(1816000, 'csv')).toBe('18160.00');
    });

    it('refuses floats: money is integer centavos', () => {
        expect(() => formatMoney(12.5)).toThrow(TypeError);
    });

    it('rounds to whole pesos half up and shortens axis labels', () => {
        expect(toWholePesos(150)).toBe(2);
        expect(toWholePesos(149)).toBe(1);
        expect(toWholePesos(-150)).toBe(-2);
        expect(formatPesoShort(7130000)).toBe('₱71.3k');
        expect(formatPesoShort(10000000)).toBe('₱100k');
        expect(formatPesoShort(250000000)).toBe('₱2.5M');
        expect(formatPesoShort(90000)).toBe('₱900');
        expect(formatCount(12345)).toBe('12,345');
    });

    it('parses typed text into centavos', () => {
        expect(parseMoney('1,000.5')).toBe(100050);
        expect(parseMoney('.75')).toBe(75);
        expect(parseMoney('')).toBeNull();
    });
});

describe('formatDateTime (DESIGN_SYSTEM §7.2), shown in Asia/Manila', () => {
    const now = '2026-11-11T03:44:00Z'; // Wed 11 Nov 2026, 11:44 AM Manila

    it('shows the time today, weekday + time within a week, and day month + time further away', () => {
        expect(formatDateTime('2026-11-11T01:12:00Z', 'auto', now)).toBe('9:12 AM');
        expect(formatDateTime('2026-11-10T13:12:00Z', 'auto', now)).toBe('Tue 9:12 PM');
        expect(formatDateTime('2026-11-11T19:12:00Z', 'auto', now)).toBe('Thu 3:12 AM');
        expect(formatDateTime('2026-11-01T15:20:00Z', 'auto', now)).toBe('1 Nov 11:20 PM');
    });

    it('converts UTC to Manila across midnight', () => {
        expect(formatDateTime('2026-11-10T16:30:00Z', 'date')).toBe('11 Nov 2026');
    });

    it('writes noon as 12:00 NN and midnight as 12:00 AM', () => {
        expect(formatDateTime('2026-11-13T04:00:00Z', 'dayTime')).toBe('Fri 13 Nov, 12:00 NN');
        expect(formatDateTime('2026-11-12T16:00:00Z', 'time')).toBe('12:00 AM');
        expect(formatDateTime('2026-11-13T04:05:00Z', 'time')).toBe('12:05 PM');
    });

    it('has the business date, shift date, slip and eyebrow forms', () => {
        expect(formatDateTime(now, 'businessDate')).toBe('Wed, 11 Nov 2026');
        expect(formatDateTime(now, 'shiftDate')).toBe('Wed 11 Nov');
        expect(formatDateTime('2026-11-11T01:12:00Z', 'slip')).toBe('11 Nov 2026 09:12 AM');
        expect(formatDateTime(now, 'eyebrow')).toBe('Wednesday, 11 November');
        expect(formatDateTime(null)).toBe('—');
    });

    it('formats ranges with an en dash and the month once', () => {
        expect(formatDateRange('2026-11-04T00:00:00Z', '2026-11-10T00:00:00Z')).toBe('4–10 Nov 2026');
        expect(formatDateRange('2026-10-28T00:00:00Z', '2026-11-03T00:00:00Z')).toBe('28 Oct – 3 Nov 2026');
        expect(formatDateRange('2026-12-30T00:00:00Z', '2027-01-02T00:00:00Z')).toBe('30 Dec 2026 – 2 Jan 2027');
    });

    it('writes chart and text hours', () => {
        expect([8, 12, 14, 0].map(formatAxisHour)).toEqual(['8a', '12n', '2p', '12m']);
        expect([9, 21, 12].map(formatHour)).toEqual(['9 AM', '9 PM', '12 NN']);
    });
});

describe('formatDuration (DESIGN_SYSTEM §7.3)', () => {
    it('has the text, list, board, countdown and timer forms', () => {
        expect(formatDuration(568)).toBe('9 h 28 m');
        expect(formatDuration(48)).toBe('48 m');
        expect(formatDuration(120)).toBe('2 h');
        expect(formatDuration(9, 'min')).toBe('9 min');
        expect(formatDuration(-42, 'min')).toBe('42 min over');
        expect(formatDuration(190, 'board')).toBe('3h 10m left');
        expect(formatDuration(22, 'board')).toBe('22m left');
        expect(formatDuration(-42, 'board')).toBe('42m over');
        expect(formatDuration(402, 'countdown')).toBe('6:42');
        expect(formatDuration(372, 'timer')).toBe('06:12');
    });

    it('writes stay lengths', () => {
        expect(formatStayLength(12, 'hour')).toBe('12 hrs');
        expect(formatStayLength(1, 'hour')).toBe('1 hr');
        expect(formatStayLength(2, 'night')).toBe('2 nights');
        expect(formatStayLength(3, 'day')).toBe('3 days');
    });
});
