<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { formatDateRange, formatDateTime, formatDuration, formatStayLength } from '@/Composables/useDateTime';
import { formatMoney, formatPesoShort } from '@/Composables/useMoney';
import Block from './Block.vue';

/** Peso sign check (ASSETS §1.1): ₱ must render in Geist 400/500/600 (latin-ext file), not the fallback. */
const fontChecks = ref<{ face: string; ok: boolean }[]>([]);

/** True when a FontFace unicode-range ("U+0-FF, U+20AD-20C0, …") covers the code point. */
function covers(range: string, codePoint: number): boolean {
    return range.split(',').some((part) => {
        const [from, to] = part.trim().replace(/^U\+/i, '').split('-');
        const start = Number.parseInt(from, 16);

        return codePoint >= start && codePoint <= (to ? Number.parseInt(to, 16) : start);
    });
}

const faces: [string, string, boolean][] = [
    ['Geist', '400', true],
    ['Geist', '500', true],
    ['Geist', '600', true],
    ['Geist Mono', '500', false],
    ['Geist Mono', '600', false],
];

function check(): void {
    const loaded = [...document.fonts].filter((face) => face.status === 'loaded');

    fontChecks.value = faces.map(([family, weight, peso]) => ({
        face: `${family} ${weight}${peso ? ' with ₱ (latin-ext)' : ''}`,
        ok: loaded.some((face) => face.family.replace(/"/g, '') === family && String(face.weight) === weight && (!peso || covers(face.unicodeRange, 0x20b1))),
    }));
}

onMounted(async () => {
    if (!('fonts' in document)) {
        return;
    }

    // Ask for each face with the peso sign, then check which files actually loaded.
    await Promise.all(faces.map(([family, weight]) => document.fonts.load(`${weight} 16px "${family}"`, '₱1,234.00 Ab').catch(() => [])));
    await document.fonts.ready;
    check();
    document.fonts.addEventListener('loadingdone', check);
});
onBeforeUnmount(() => ('fonts' in document ? document.fonts.removeEventListener('loadingdone', check) : undefined));

const now = '2026-11-11T03:44:00Z';
const moneyRows: [string, string][] = [
    ['standard 123400', formatMoney(123400)],
    ['standard −5000', formatMoney(-5000)],
    ['standard 0', formatMoney(0)],
    ['null', formatMoney(null)],
    ['column −25000', formatMoney(-25000, 'column')],
    ['tile 45000', formatMoney(45000, 'tile')],
    ['whole 942050', formatMoney(942050, 'whole')],
    ['wholeColumn 1435000', formatMoney(1435000, 'wholeColumn')],
    ['slip −34000', formatMoney(-34000, 'slip')],
    ['csv 1816000', formatMoney(1816000, 'csv')],
    ['axis 7130000', formatPesoShort(7130000)],
];
const timeRows: [string, string][] = [
    ['auto, today', formatDateTime('2026-11-11T01:12:00Z', 'auto', now)],
    ['auto, this week', formatDateTime('2026-11-10T13:12:00Z', 'auto', now)],
    ['auto, older', formatDateTime('2026-11-01T15:20:00Z', 'auto', now)],
    ['noon', formatDateTime('2026-11-13T04:00:00Z', 'dayTime', now)],
    ['date', formatDateTime(now, 'date')],
    ['businessDate', formatDateTime(now, 'businessDate')],
    ['shiftDate', formatDateTime(now, 'shiftDate')],
    ['slip', formatDateTime(now, 'slip')],
    ['eyebrow', formatDateTime(now, 'eyebrow')],
    ['range', formatDateRange('2026-11-04T00:00:00Z', '2026-11-10T00:00:00Z')],
    ['duration text 568', formatDuration(568)],
    ['duration min −42', formatDuration(-42, 'min')],
    ['duration board 190', formatDuration(190, 'board')],
    ['countdown 402 s', formatDuration(402, 'countdown')],
    ['timer 372 s', formatDuration(372, 'timer')],
    ['stay length', `${formatStayLength(12, 'hour')} · ${formatStayLength(2, 'night')}`],
];
</script>

<template>
    <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,330px),1fr))] gap-4">
        <Block title="Typefaces and the peso sign">
            <p class="text-sm font-normal">Geist 400 · <span class="tabular">₱1,234.00</span></p>
            <p class="text-sm font-medium">Geist 500 · <span class="tabular">₱1,234.00</span></p>
            <p class="num text-sm">Geist 600 · ₱1,234.00 · −₱50.00</p>
            <p class="mt-2 font-mono text-sm font-medium">Geist Mono 500 · DGS-26-004126</p>
            <p class="font-mono text-sm font-semibold">Geist Mono 600 · TOTAL 800.00</p>
            <p class="hm-light mt-2 text-[30px] leading-[1.15] font-semibold tracking-[-0.03em] text-highlighted">Three ways to stay</p>
            <ul class="mt-3 flex flex-col gap-1 text-xs" data-font-checks>
                <li v-for="check in fontChecks" :key="check.face" class="flex items-center gap-1.5">
                    <UIcon :name="check.ok ? 'i-lucide-check' : 'i-lucide-triangle-alert'" class="size-3.5" :class="check.ok ? 'text-ok' : 'text-bad'" aria-hidden="true" />
                    <span :data-font-ok="check.ok">{{ check.face }} {{ check.ok ? 'loaded' : 'not loaded' }}</span>
                </li>
            </ul>
        </Block>

        <Block title="Desktop type scale">
            <p class="text-[19px] leading-6 font-semibold tracking-[-0.01em] text-highlighted">Page title 19 / 600</p>
            <p class="text-[18px] leading-[26px] font-semibold text-highlighted">In-page title 18 / 600</p>
            <p class="text-[15px] leading-[22px] font-semibold text-highlighted">Card title 15 / 600</p>
            <p class="text-[13px] leading-[18px] font-semibold text-highlighted">Section label 13 / 600</p>
            <p class="text-sm">Body 14 / 400, with <b>bold in ink</b>.</p>
            <p class="text-xs text-muted">Small helper 12 / 400</p>
            <p class="text-[26px] leading-8 font-semibold tracking-[-0.01em] text-highlighted tabular">₱9,420 KPI 26 / 600</p>
        </Block>

        <Block title="formatMoney()">
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <template v-for="[k, v] in moneyRows" :key="k">
                    <dt class="text-muted">{{ k }}</dt>
                    <dd class="text-right num">{{ v }}</dd>
                </template>
            </dl>
        </Block>

        <Block title="formatDateTime() · formatDuration()">
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <template v-for="[k, v] in timeRows" :key="k">
                    <dt class="text-muted">{{ k }}</dt>
                    <dd class="text-right tabular text-highlighted">{{ v }}</dd>
                </template>
            </dl>
        </Block>
    </div>
</template>
