<script setup lang="ts">
import { computed } from 'vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import { formatMoney } from '@/Composables/useMoney';

/**
 * The Z-report paper (37b), the day paper (38b) and their previews (DESIGN_SYSTEM ZPaper). It
 * renders the one builder array from ReportService::zReport() / dayZ() and computes nothing; the
 * PDF Blade view and the CSV render the same array. Always light. A line with a `drill` code is
 * a button that opens the ledger rows behind it.
 */
export interface ZLine {
    label: string;
    count?: number;
    amount?: number | null;
    cells?: number[];
    style?: 'plain' | 'indent' | 'equals';
    tone?: 'bad' | null;
    note?: string | null;
    drill?: string;
}

export interface ZSection {
    key: string;
    title: string;
    layout: 'waterfall' | 'table' | 'lines';
    column: 'left' | 'right';
    columns?: { label: string; type?: 'count' | 'money' }[];
    lines: ZLine[];
}

export interface ZPaperData {
    kind: 'shift' | 'day';
    preview: boolean;
    header: { branch: string; title: string; number: string | null; opened: string | null; closed: string | null; printed: string | null; tin: string | null; registered_name: string | null };
    sections: ZSection[];
    signatures: { name: string | null; role: string }[];
    checks: string[];
}

const props = withDefaults(defineProps<{ paper: ZPaperData; drillable?: boolean }>(), { drillable: false });
const emit = defineEmits<{ drill: [code: string, label: string] }>();

const left = computed(() => props.paper.sections.filter((s) => s.column === 'left'));
const right = computed(() => props.paper.sections.filter((s) => s.column === 'right'));
const money = (c: number | null | undefined) => formatMoney(c ?? null, 'column');
const label = (l: ZLine) => (l.count === undefined ? l.label : `${l.label} · ${l.count}`);
const canDrill = (l: ZLine) => props.drillable && !!l.drill;
</script>

<template>
    <article class="hm-light zpaper relative mx-auto w-full max-w-[880px] overflow-hidden rounded-card border border-default bg-default px-7 py-6 text-[13px] leading-5 text-(--hm-text)" aria-label="Z-report paper">
        <div v-if="paper.preview" class="pointer-events-none absolute inset-0 flex items-center justify-center" aria-hidden="true">
            <span class="-rotate-[24deg] text-[56px] font-semibold whitespace-nowrap text-muted opacity-[0.12]">Preview. Do not sign.</span>
        </div>
        <p v-if="paper.preview" class="sr-only">Preview. Do not sign.</p>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <HomiLogo class="mb-1" :width="112" :height="34" />
                <p class="font-semibold text-highlighted">{{ paper.header.branch }}</p>
                <p v-if="paper.header.registered_name" class="text-xs text-muted">{{ paper.header.registered_name }} · TIN {{ paper.header.tin }}</p>
                <p class="text-xs text-muted">{{ paper.header.title }}</p>
            </div>
            <div class="text-right">
                <p class="text-[19px] leading-6 font-semibold tracking-[-0.02em] text-highlighted">{{ paper.header.number ?? (paper.preview ? 'Preview' : '') }}</p>
                <p class="text-xs text-muted">
                    {{ paper.header.opened }}<template v-if="paper.header.closed"> · {{ paper.header.closed }}</template>
                </p>
                <p v-if="paper.header.printed" class="text-xs text-muted">{{ paper.header.printed }}</p>
            </div>
        </header>

        <div class="mt-2 grid grid-cols-2 gap-6 max-[767px]:grid-cols-1">
            <div>
                <section v-for="section in left" :key="section.key">
                    <h6 class="mt-3 mb-1 text-[11px] font-semibold tracking-[0.06em] text-muted uppercase">{{ section.title }}</h6>
                    <template v-for="(line, i) in section.lines" :key="i">
                        <component
                            :is="canDrill(line) ? 'button' : 'div'"
                            :type="canDrill(line) ? 'button' : undefined"
                            class="flex w-full justify-between gap-3 py-0.5 text-left"
                            :class="[
                                line.style === 'indent' ? 'ps-3 text-muted' : '',
                                line.style === 'equals' ? 'mt-0.5 border-t border-(--hm-ink) pt-1 font-bold text-highlighted' : '',
                                canDrill(line) ? 'hm-focus cursor-pointer rounded-sm hover:bg-elevated' : '',
                                line.tone === 'bad' ? 'text-bad' : '',
                            ]"
                            :data-line="line.label"
                            @click="canDrill(line) && emit('drill', line.drill!, line.label)"
                        >
                            <span :class="line.tone === 'bad' ? 'font-semibold' : ''">{{ label(line) }}</span>
                            <b class="tabular font-medium" :class="[line.style === 'equals' ? 'font-bold' : '', line.tone === 'bad' ? 'text-bad' : 'text-highlighted']">{{ money(line.amount) }}</b>
                        </component>
                        <p v-if="line.note" class="text-xs text-muted">{{ line.note }}</p>
                    </template>
                </section>
            </div>
            <div>
                <section v-for="section in right" :key="section.key">
                    <h6 class="mt-3 mb-1 text-[11px] font-semibold tracking-[0.06em] text-muted uppercase">{{ section.title }}</h6>
                    <table v-if="section.layout === 'table'" class="dtable zpaper-table">
                        <thead>
                            <tr>
                                <th v-for="(col, c) in section.columns" :key="c" :class="col.type ? 'text-right' : ''">{{ col.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, i) in section.lines" :key="i">
                                <td :class="line.tone === 'bad' ? 'text-bad' : ''">{{ line.label }}</td>
                                <template v-if="line.cells">
                                    <td v-for="(cell, c) in line.cells" :key="c" class="r">{{ section.columns?.[c + 1]?.type === 'money' ? money(cell) : cell }}</td>
                                </template>
                                <template v-else>
                                    <td class="r">{{ line.count }}</td>
                                    <td class="r">{{ money(line.amount) }}</td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                    <template v-else>
                        <div
                            v-for="(line, i) in section.lines"
                            :key="i"
                            class="flex justify-between gap-3 py-0.5"
                            :class="line.style === 'equals' ? 'mt-0.5 border-t border-(--hm-ink) pt-1 font-bold text-highlighted' : ''"
                        >
                            <span>{{ line.label }}</span>
                            <b class="tabular font-medium text-highlighted" :class="line.style === 'equals' ? 'font-bold' : ''">{{ line.amount !== undefined ? money(line.amount) : line.count }}</b>
                        </div>
                    </template>
                </section>
            </div>
        </div>

        <footer class="mt-10 grid gap-6" :style="{ gridTemplateColumns: `repeat(${paper.signatures.length}, minmax(0, 1fr))` }">
            <div v-for="(s, i) in paper.signatures" :key="i" class="border-t border-(--hm-ink) pt-1">
                <p class="min-h-5 text-highlighted">{{ s.name ?? '' }}</p>
                <p class="text-xs text-muted">{{ s.role }}</p>
            </div>
        </footer>
    </article>
</template>

<style scoped>
.zpaper-table {
    font-size: 12.5px;
}

.zpaper-table :deep(th),
.zpaper-table :deep(td) {
    padding: 4px 6px;
}

@media print {
    .zpaper {
        border: 0 !important;
        border-radius: 0 !important;
        max-width: none;
        padding: 0;
    }

    .zpaper footer {
        break-inside: avoid;
    }
}
</style>
