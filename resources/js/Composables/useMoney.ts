/**
 * Money formatting (DESIGN_SYSTEM §7.1). Amounts travel as integer centavos and are divided
 * by 100 only here. No arithmetic on money happens in Vue beyond display.
 */

const MINUS = '−';
const PESO = '₱';

export type MoneyStyle =
    /** ₱1,234.00 · −₱50.00 · ₱0.00 */
    | 'standard'
    /** 1,234.00 · −250.00 (table columns; the header or footer carries the ₱) */
    | 'column'
    /** ₱450 when the centavos are zero, else ₱450.50 (option tiles, product cards, rate cards) */
    | 'tile'
    /** ₱9,420, rounded half up (KPI cards, chart labels) */
    | 'whole'
    /** 14,350, rounded half up, no ₱ (rooms-by-floor grid, owner summary table) */
    | 'wholeColumn'
    /** 650.00 · -340.00 (thermal slip and CSV: ASCII hyphen, no ₱) */
    | 'slip'
    /** 18160.00 (CSV: no separators) */
    | 'csv';

function assertCentavos(centavos: number): void {
    if (!Number.isInteger(centavos)) {
        throw new TypeError(`Money must be integer centavos, got ${centavos}`);
    }
}

function group(n: number): string {
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/** Rounds centavos to whole pesos, half up (away from zero for the absolute value). */
export function toWholePesos(centavos: number): number {
    assertCentavos(centavos);
    const sign = centavos < 0 ? -1 : 1;

    return sign * Math.floor((Math.abs(centavos) + 50) / 100);
}

export function formatMoney(centavos: number | null | undefined, style: MoneyStyle = 'standard'): string {
    if (centavos === null || centavos === undefined) {
        return '—';
    }

    assertCentavos(centavos);
    const negative = centavos < 0;
    const abs = Math.abs(centavos);
    const pesos = Math.floor(abs / 100);
    const cents = (abs % 100).toString().padStart(2, '0');

    switch (style) {
        case 'column':
            return `${negative ? MINUS : ''}${group(pesos)}.${cents}`;
        case 'tile':
            return `${negative ? MINUS : ''}${PESO}${group(pesos)}${cents === '00' ? '' : `.${cents}`}`;
        case 'whole': {
            const whole = Math.abs(toWholePesos(centavos));

            return `${negative && whole !== 0 ? MINUS : ''}${PESO}${group(whole)}`;
        }
        case 'wholeColumn': {
            const whole = Math.abs(toWholePesos(centavos));

            return `${negative && whole !== 0 ? MINUS : ''}${group(whole)}`;
        }
        case 'slip':
            return `${negative ? '-' : ''}${group(pesos)}.${cents}`;
        case 'csv':
            return `${negative ? '-' : ''}${pesos}.${cents}`;
        default:
            return `${negative ? MINUS : ''}${PESO}${group(pesos)}.${cents}`;
    }
}

/** Axis label: ₱71.3k, ₱1.2M, ₱900 (whole pesos, shortened). */
export function formatPesoShort(centavos: number): string {
    const pesos = toWholePesos(centavos);
    const abs = Math.abs(pesos);
    const sign = pesos < 0 ? MINUS : '';

    if (abs >= 1_000_000) {
        return `${sign}${PESO}${trimZero((abs / 1_000_000).toFixed(1))}M`;
    }

    if (abs >= 1_000) {
        return `${sign}${PESO}${trimZero((abs / 1_000).toFixed(1))}k`;
    }

    return `${sign}${PESO}${abs}`;
}

function trimZero(s: string): string {
    return s.endsWith('.0') ? s.slice(0, -2) : s;
}

/** Plain count with thousands separators: 1,234. */
export function formatCount(n: number): string {
    return `${n < 0 ? MINUS : ''}${group(Math.abs(Math.round(n)))}`;
}

/**
 * Parses what a person typed into a money field into integer centavos.
 * Accepts digits, separators and one decimal point; returns null for nothing typed.
 */
export function parseMoney(text: string): number | null {
    const cleaned = text.replace(/[^\d.]/g, '');

    if (cleaned === '' || cleaned === '.') {
        return null;
    }

    const [whole, fraction = ''] = cleaned.split('.');
    const cents = (fraction + '00').slice(0, 2);

    return Number.parseInt(whole || '0', 10) * 100 + Number.parseInt(cents, 10);
}

export function useMoney() {
    return { formatMoney, formatPesoShort, formatCount, toWholePesos, parseMoney };
}
