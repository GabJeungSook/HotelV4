<script setup lang="ts">
import { formatMoney } from '@/Composables/useMoney';

/**
 * The result line of the reconcile check (36, 38, 38b; APP_FLOW §4.21): green when the shifts'
 * frozen totals agree with the ledger, red with the figures that differ when they do not.
 * Report pages also pass their own self-checks (a line that does not add up) as `problems`.
 */
export interface Difference {
    shift_id: number | null;
    label: string;
    figure: string;
    stored: number;
    recomputed: number;
}

withDefaults(defineProps<{ differences?: Difference[]; problems?: string[]; passText?: string; idleText?: string; checked?: boolean }>(), {
    differences: () => [],
    problems: () => [],
    passText: undefined,
    idleText: undefined,
    checked: true,
});

const isCount = (figure: string) => /count|guests\.|sales_counts|\.checkins|reprints|force_closed/.test(figure);
const value = (figure: string, n: number) => (isCount(figure) ? String(n) : formatMoney(n));
</script>

<template>
    <UAlert v-if="problems.length" class="mt-4" color="error" variant="subtle" icon="i-lucide-triangle-alert" title="This report does not add up. Do not sign it; report it to the admin.">
        <template #description>
            <ul class="mt-1 list-disc ps-4 text-sm">
                <li v-for="(p, i) in problems" :key="i">{{ p }}</li>
            </ul>
        </template>
    </UAlert>
    <UAlert v-if="differences.length" class="mt-4" color="error" variant="subtle" icon="i-lucide-triangle-alert" :title="`The check found ${differences.length} figure(s) that differ from the ledger.`">
        <template #description>
            <ul class="mt-1 list-disc ps-4 text-sm">
                <li v-for="(d, i) in differences.slice(0, 12)" :key="i">{{ d.label }} · {{ d.figure }}: frozen {{ value(d.figure, d.stored) }}, ledger {{ value(d.figure, d.recomputed) }}</li>
            </ul>
        </template>
    </UAlert>
    <UAlert v-else-if="checked && passText && !problems.length" class="mt-4" color="success" variant="subtle" icon="i-lucide-check" :description="passText" />
    <UAlert v-else-if="!checked && idleText" class="mt-4" color="neutral" variant="subtle" icon="i-lucide-info" :description="idleText" />
</template>
