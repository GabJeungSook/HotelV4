<script setup lang="ts">
import TotalRow from '@/Components/TotalRow.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';

/**
 * The ledger rows behind a report figure (37, 37b, 38: "every figure can be clicked to list the
 * ledger lines behind it"). A side sheet (DESIGN_SYSTEM USlideover); the rows come from the
 * server and add up to the figure, which the footer shows.
 */
export interface LedgerRow {
    id: number;
    at: string;
    code: string | null;
    room: string | null;
    description: string;
    by: string;
    amount: number;
}

export interface Ledger {
    title: string;
    rows: LedgerRow[];
    total: number;
}

defineProps<{ ledger: Ledger | null; label?: string; loading?: boolean }>();
const open = defineModel<boolean>('open', { default: false });
</script>

<template>
    <USlideover v-model:open="open" side="right" :overlay="false" :title="label ?? ledger?.title ?? 'Ledger rows'" :ui="{ content: 'max-w-[420px]' }">
        <template #body>
            <div v-if="loading" class="flex flex-col gap-2">
                <USkeleton v-for="n in 5" :key="n" class="h-10 w-full" />
            </div>
            <p v-else-if="!ledger || ledger.rows.length === 0" class="text-sm text-muted">No ledger rows are behind this figure.</p>
            <table v-else class="dtable">
                <tbody>
                    <tr v-for="row in ledger.rows" :key="row.id">
                        <td class="!whitespace-normal">
                            <p class="text-highlighted">{{ row.description }}</p>
                            <p class="text-xs text-muted">
                                {{ formatDateTime(row.at, 'auto') }}<template v-if="row.code"> · {{ row.code }}</template
                                ><template v-if="row.room"> · Room {{ row.room }}</template> · {{ row.by }}
                            </p>
                        </td>
                        <td class="r">{{ formatMoney(row.amount, 'column') }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
        <template v-if="ledger && ledger.rows.length" #footer>
            <TotalRow class="w-full" :label="`${ledger.rows.length} ${ledger.rows.length === 1 ? 'row' : 'rows'}`" :amount="ledger.total" />
        </template>
    </USlideover>
</template>
