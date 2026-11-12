/**
 * Shared helpers of the chart kit (DESIGN_SYSTEM §5.2 Charts). No chart library: small SVG
 * components draw from these. Colours are token variables only, so dark mode follows.
 */
import { reactive } from 'vue';
import { formatCount, formatMoney, formatPesoShort } from '@/Composables/useMoney';

/** Series order: primary, info, ok, accent, violet; "Other" is grey. */
export const seriesColors = ['var(--hm-primary)', 'var(--hm-info)', 'var(--hm-ok)', 'var(--hm-accent)', 'var(--hm-violet)'];
export const otherColor = 'var(--hm-grey)';

/** The one tooltip's state, read by ChartTip. */
export const tip = reactive({ visible: false, x: 0, y: 0, title: '', text: '' });

/** Shows the tooltip at the pointer, or above the focused element for keyboard focus. */
export function showTip(event: MouseEvent | FocusEvent, title: string, text: string): void {
    if (event instanceof MouseEvent && event.type !== 'focus') {
        tip.x = event.clientX;
        tip.y = event.clientY;
    } else {
        const rect = (event.currentTarget as Element | null)?.getBoundingClientRect();
        tip.x = rect ? rect.left + rect.width / 2 : 0;
        tip.y = rect ? rect.top : 0;
    }

    tip.title = title;
    tip.text = text;
    tip.visible = true;
}

export function hideTip(): void {
    tip.visible = false;
}

/** 1,234 */
export function num(n: number): string {
    return formatCount(n);
}

/** Whole pesos for value labels and legends: ₱71,300. */
export function peso(centavos: number): string {
    return formatMoney(centavos, 'whole');
}

/** Axis label: ₱71.3k. */
export function pesoShort(centavos: number): string {
    return formatPesoShort(centavos);
}

/**
 * A round top for the value axis that splits into four round steps (1, 2, 2.5 or 5 times a power
 * of ten each), so the four grid lines read ₱20k · ₱40k · ₱60k · ₱80k rather than thirds.
 */
export function niceTop(max: number, steps = 4): number {
    if (max <= 0) {
        return steps;
    }

    const raw = max / steps;
    const power = 10 ** Math.floor(Math.log10(raw));

    for (const nice of [1, 2, 2.5, 5, 10]) {
        if (nice * power >= raw) {
            return nice * power * steps;
        }
    }

    return 10 * power * steps;
}

/** A vertical bar with rounded top corners (r is clamped to the bar size). */
export function barPath(x: number, y: number, w: number, h: number, r = 4): string {
    if (h <= 0 || w <= 0) {
        return '';
    }

    const rr = Math.min(r, w / 2, h);

    return `M${x},${y + h}V${y + rr}Q${x},${y} ${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h}Z`;
}

/** A horizontal bar with fully rounded ends. */
export function hbarPath(x: number, y: number, w: number, h: number, r = 4): string {
    if (h <= 0 || w <= 0) {
        return '';
    }

    const rr = Math.min(r, h / 2, w / 2);

    return `M${x + rr},${y}H${x + w - rr}Q${x + w},${y} ${x + w},${y + rr}V${y + h - rr}Q${x + w},${y + h} ${x + w - rr},${y + h}H${x + rr}Q${x},${y + h} ${x},${y + h - rr}V${y + rr}Q${x},${y} ${x + rr},${y}Z`;
}

/** The index and value of the largest value (first one on a tie), or null when all are zero. */
export function peakOf(values: number[]): { index: number; value: number } | null {
    let index = -1;
    let value = 0;

    values.forEach((v, i) => {
        if (v > value) {
            value = v;
            index = i;
        }
    });

    return index < 0 ? null : { index, value };
}
