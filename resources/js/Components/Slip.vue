<script setup lang="ts">
/**
 * The 80 mm thermal slip (DESIGN_SYSTEM §5.2, §6.10): check-in slip (8), receipt (18),
 * shift report (26). IBM Plex Mono only; always light; amounts without ₱, ASCII hyphen.
 * Values arrive already formatted (formatMoney(…, 'slip'), formatDateTime(…, 'slip')).
 */
export interface SlipRow {
    label: string;
    value?: string;
    bold?: boolean;
}

withDefaults(
    defineProps<{
        header: string;
        title: string;
        number?: string;
        sections: SlipRow[][];
        footer?: string[];
        reprint?: boolean;
        preview?: boolean;
    }>(),
    { number: undefined, footer: () => [], reprint: false, preview: false },
);
</script>

<template>
    <article
        class="hm-light slip-print mx-auto w-full max-w-[340px] bg-default px-4 py-4 font-mono text-xs leading-[18px] font-medium text-(--hm-ink)"
        :class="preview ? 'rounded-md border border-dashed border-slip-line' : ''"
        aria-label="Slip"
    >
        <header class="text-center">
            <p class="font-semibold uppercase">{{ header }}</p>
            <p class="font-semibold">{{ title }}</p>
            <p v-if="number">{{ number }}</p>
            <p v-if="reprint" class="font-semibold">REPRINT</p>
        </header>
        <section v-for="(rows, s) in sections" :key="s" class="mt-2 border-t border-dashed border-current pt-2">
            <div v-for="(row, r) in rows" :key="r" class="flex justify-between gap-3" :class="row.bold ? 'font-semibold' : ''">
                <span class="min-w-0 break-words">{{ row.label }}</span>
                <span v-if="row.value !== undefined" class="shrink-0 text-right tabular">{{ row.value }}</span>
            </div>
        </section>
        <footer v-if="footer.length" class="mt-2 border-t border-dashed border-current pt-2">
            <p v-for="line in footer" :key="line">{{ line }}</p>
        </footer>
    </article>
</template>
