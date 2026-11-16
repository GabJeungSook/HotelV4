/**
 * Date, time and duration formatting (DESIGN_SYSTEM §7.2, §7.3). Times are stored in UTC and
 * always shown in Asia/Manila, which is UTC+8 all year (no daylight saving), so the wall time
 * is the UTC instant shifted by eight hours. No seconds, no 24-hour times, no numeric dates.
 */

const MANILA_OFFSET_MS = 8 * 60 * 60 * 1000;
const DAYS_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const DAYS_LONG = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

export type DateInput = Date | string | number;

/** Wall-clock parts in Manila. */
interface Wall {
    year: number;
    month: number;
    day: number;
    weekday: number;
    hour: number;
    minute: number;
    dayNumber: number;
}

function toDate(value: DateInput): Date {
    const d = value instanceof Date ? value : new Date(value);

    if (Number.isNaN(d.getTime())) {
        throw new TypeError(`Not a date: ${String(value)}`);
    }

    return d;
}

function wall(value: DateInput): Wall {
    const shifted = new Date(toDate(value).getTime() + MANILA_OFFSET_MS);

    return {
        year: shifted.getUTCFullYear(),
        month: shifted.getUTCMonth(),
        day: shifted.getUTCDate(),
        weekday: shifted.getUTCDay(),
        hour: shifted.getUTCHours(),
        minute: shifted.getUTCMinutes(),
        dayNumber: Math.floor(shifted.getTime() / 86_400_000),
    };
}

/** 9:12 AM · 12:00 NN · 12:05 PM · 12:00 AM */
function clock(w: Wall, padHour = false): string {
    const mm = w.minute.toString().padStart(2, '0');

    if (w.hour === 12 && w.minute === 0) {
        return '12:00 NN';
    }

    const h12 = w.hour % 12 === 0 ? 12 : w.hour % 12;
    const hh = padHour ? h12.toString().padStart(2, '0') : h12.toString();

    return `${hh}:${mm} ${w.hour < 12 ? 'AM' : 'PM'}`;
}

export type DateTimeStyle =
    /** Today: 9:12 AM · within a week: Wed 9:12 PM · further: 10 Nov 11:20 PM */
    | 'auto'
    /** 9:12 AM */
    | 'time'
    /** 11 Nov 2026 */
    | 'date'
    /** Wed, 11 Nov 2026 (business date, page titles, Z paper) */
    | 'businessDate'
    /** Wed 11 Nov (shift label) */
    | 'shiftDate'
    /** Fri 13 Nov, 12:00 NN */
    | 'dayTime'
    /** 11 Nov 2026 09:12 AM (thermal slip) */
    | 'slip'
    /** Wednesday, 11 November (sign-in eyebrow) */
    | 'eyebrow';

export function formatDateTime(value: DateInput | null | undefined, style: DateTimeStyle = 'auto', now: DateInput = Date.now()): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const w = wall(value);

    switch (style) {
        case 'time':
            return clock(w);
        case 'date':
            return `${w.day} ${MONTHS_SHORT[w.month]} ${w.year}`;
        case 'businessDate':
            return `${DAYS_SHORT[w.weekday]}, ${w.day} ${MONTHS_SHORT[w.month]} ${w.year}`;
        case 'shiftDate':
            return `${DAYS_SHORT[w.weekday]} ${w.day} ${MONTHS_SHORT[w.month]}`;
        case 'dayTime':
            return `${DAYS_SHORT[w.weekday]} ${w.day} ${MONTHS_SHORT[w.month]}, ${clock(w)}`;
        case 'slip':
            return `${w.day} ${MONTHS_SHORT[w.month]} ${w.year} ${clock(w, true)}`;
        case 'eyebrow':
            return `${DAYS_LONG[w.weekday]}, ${w.day} ${MONTHS_LONG[w.month]}`;
        default: {
            const today = wall(now);
            const diff = Math.abs(w.dayNumber - today.dayNumber);

            if (diff === 0) {
                return clock(w);
            }

            if (diff < 7) {
                return `${DAYS_SHORT[w.weekday]} ${clock(w)}`;
            }

            return `${w.day} ${MONTHS_SHORT[w.month]} ${clock(w)}`;
        }
    }
}

/** 4–10 Nov 2026 · 28 Oct – 3 Nov 2026 · 30 Dec 2026 – 2 Jan 2027 */
export function formatDateRange(from: DateInput, to: DateInput): string {
    const a = wall(from);
    const b = wall(to);

    if (a.dayNumber === b.dayNumber) {
        return `${b.day} ${MONTHS_SHORT[b.month]} ${b.year}`;
    }

    if (a.year !== b.year) {
        return `${a.day} ${MONTHS_SHORT[a.month]} ${a.year} – ${b.day} ${MONTHS_SHORT[b.month]} ${b.year}`;
    }

    if (a.month !== b.month) {
        return `${a.day} ${MONTHS_SHORT[a.month]} – ${b.day} ${MONTHS_SHORT[b.month]} ${b.year}`;
    }

    return `${a.day}–${b.day} ${MONTHS_SHORT[b.month]} ${b.year}`;
}

/** Hour on a chart axis: 8a, 12n, 2p, 12m (midnight). */
export function formatAxisHour(hour: number): string {
    if (hour === 12) {
        return '12n';
    }

    if (hour === 0 || hour === 24) {
        return '12m';
    }

    return hour < 12 ? `${hour}a` : `${hour - 12}p`;
}

/** Hour in text: 9 AM, 9 PM, 12 NN. */
export function formatHour(hour: number): string {
    if (hour === 12) {
        return '12 NN';
    }

    const h12 = hour % 12 === 0 ? 12 : hour % 12;

    return `${h12} ${hour < 12 ? 'AM' : 'PM'}`;
}

export type DurationStyle =
    /** 9 h 28 m · 48 m (text and status words) */
    | 'text'
    /** 9 min · 42 min over (lists on Today, reports) */
    | 'min'
    /** 3h 10m left · 42m over (room board) */
    | 'board'
    /** 6:42 (hold countdown, m:ss) */
    | 'countdown'
    /** 06:12 (cleaning timer, mm:ss) */
    | 'timer';

/**
 * Formats a duration. `text`, `min` and `board` take whole minutes (negative = over);
 * `countdown` and `timer` take whole seconds.
 */
export function formatDuration(amount: number, style: DurationStyle = 'text'): string {
    const n = Math.trunc(amount);
    const abs = Math.abs(n);

    switch (style) {
        case 'min':
            return n < 0 ? `${abs} min over` : `${abs} min`;
        case 'board': {
            const h = Math.floor(abs / 60);
            const m = abs % 60;
            const span = h > 0 ? `${h}h ${m}m` : `${m}m`;

            return n < 0 ? `${span} over` : `${span} left`;
        }
        case 'countdown': {
            const m = Math.floor(abs / 60);

            return `${m}:${(abs % 60).toString().padStart(2, '0')}`;
        }
        case 'timer': {
            const m = Math.floor(abs / 60);

            return `${m.toString().padStart(2, '0')}:${(abs % 60).toString().padStart(2, '0')}`;
        }
        default: {
            const h = Math.floor(abs / 60);
            const m = abs % 60;

            if (h === 0) {
                return `${m} m`;
            }

            return m === 0 ? `${h} h` : `${h} h ${m} m`;
        }
    }
}

/** Stay length: 12 hrs · 1 hr · 2 nights · 3 days. */
export function formatStayLength(count: number, unit: 'hour' | 'night' | 'day'): string {
    const word = { hour: 'hr', night: 'night', day: 'day' }[unit];
    const label = unit === 'hour' ? (count === 1 ? 'hr' : 'hrs') : count === 1 ? word : `${word}s`;

    return `${count} ${label}`;
}

export function useDateTime() {
    return { formatDateTime, formatDateRange, formatDuration, formatStayLength, formatAxisHour, formatHour };
}

/** "just now", "8 min ago", "2 h ago", "5 d ago", then the date (Recent check-ins on 61). */
export function formatAgo(value: DateInput | null | undefined, now: DateInput = Date.now()): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const minutes = Math.floor((new Date(now as string | number | Date).getTime() - new Date(value as string | number | Date).getTime()) / 60000);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    if (minutes < 24 * 60) {
        return `${Math.floor(minutes / 60)} h ago`;
    }

    if (minutes < 7 * 24 * 60) {
        return `${Math.floor(minutes / (24 * 60))} d ago`;
    }

    return formatDateTime(value, 'date', now);
}
