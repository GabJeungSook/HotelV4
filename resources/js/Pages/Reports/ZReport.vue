<!-- Mockup 37b -->
<!-- Mockup 37c (?tab=rooms) -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CheckResult from '@/Components/CheckResult.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import LedgerSheet, { type Ledger } from '@/Components/LedgerSheet.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import ZPaper, { type ZPaperData } from '@/Components/ZPaper.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useReport } from '@/Composables/useReport';
import { shortName } from '@/Composables/useShared';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Z-report of one shift (37b) and its tabs: rooms by floor (37c), cleaning chart, housekeeping
 * log, stocks, expenses. The paper is the one builder array of ReportService::zReport(); the
 * PDF, the CSV and the 80 mm slip are the same array. An open shift is a preview: no Z number,
 * the watermark, and no export or print.
 */
type Tab = 'sales' | 'rooms' | 'cleaning' | 'housekeeping' | 'stocks' | 'expenses';

interface RoomsTab {
    columns: { key: string; label: string }[];
    rows: { key: string; label: string; cells: Record<string, number>; total: number }[];
    gross: { cells: Record<string, number>; total: number };
    deposits: { cells: Record<string, number>; total: number };
    charts: { floor: string; room_count: number; rows: ChartRow[] }[];
    net_sales: number;
    checks: string[];
}

interface ChartRow {
    room: string;
    vacant: boolean;
    code?: string | null;
    guest?: string;
    in?: string | null;
    out?: string | null;
    forwarded?: boolean;
    extended?: boolean;
    room_charge?: number;
    ext?: number;
    foods?: number;
    drinks?: number;
    misc?: number;
    deposit?: number;
}

const props = defineProps<{
    tab: Tab;
    paper: ZPaperData & { shift_id: number; z_no: number | null; figures: Record<string, number | null> };
    shift: { id: number; period_name: string; business_date: string; status: string };
    rooms: RoomsTab | null;
    cleaning: { id: number; room: string; status: string; queued_at: string; due_at: string; started_at: string | null; finished_at: string | null; by: string | null; is_late: boolean; result: string | null }[] | null;
    housekeeping: { people: { user_id: number; name: string; floors: string; cleans: number; average_minutes: number; late: number }[]; late: { id: number; room: string; guest_left: string; deadline: string; ready: string; late_minutes: number; by: string }[] } | null;
    stocks: { product_id: number; name: string; opening: number; in: number; sold: number; wastage: number; closing: number }[] | null;
    expenses: { id: number; occurred_at: string; kind: string; category: string | null; note: string | null; by: string; amount: number; voided: boolean; void_reason: string | null }[] | null;
    ledger?: Ledger | null;
}>();

const r = useReport();
const tabs: { value: Tab; label: string }[] = [
    { value: 'sales', label: 'Sales and cash' },
    { value: 'rooms', label: 'Rooms by floor' },
    { value: 'cleaning', label: 'Cleaning chart' },
    { value: 'housekeeping', label: 'Housekeeping log' },
    { value: 'stocks', label: 'Stocks' },
    { value: 'expenses', label: 'Expenses' },
];
const current = ref<Tab | null>(props.tab);
watch(current, (t) => t && t !== props.tab && r.go('reports.z', { shift: props.shift.id }, { tab: t === 'sales' ? null : t }));

const title = computed(() => `Z-report · ${props.shift.period_name} shift · ${formatDateTime(`${props.shift.business_date}T12:00:00+08:00`, 'businessDate').replace(',', '')}`);
const whole = (c: number | undefined) => formatMoney(c ?? 0, 'wholeColumn');
const time = (iso: string | null | undefined) => (iso ? formatDateTime(iso, 'time') : '—');
const when = (iso: string | null | undefined) => {
    if (!iso) {
        return '—';
    }

    const sameDay = iso.slice(0, 10) === props.shift.business_date || formatDateTime(iso, 'date') === formatDateTime(`${props.shift.business_date}T12:00:00+08:00`, 'date');

    return sameDay ? formatDateTime(iso, 'time') : `${formatDateTime(iso, 'date').replace(/ \d{4}$/, '')} ${formatDateTime(iso, 'time')}`;
};
const kindLabel: Record<string, string> = { expense: 'Expense', remittance: 'Remittance', shortage_payment: 'Shortage payment' };

function print(): void {
    void r.log.print(route('reports.shift.print', props.shift.id), { document: 'z_report' });
}
</script>

<template>
    <BackOfficeLayout current="z-report" :title="title">
        <Head :title="title" />
        <PageHeader>
            <StatusLabel v-if="paper.preview" tone="warn" label="Preview" />
            <ExportMenu
                pdf
                csv
                :print="false"
                :disabled="paper.preview"
                @pdf="r.download(route('reports.z.pdf', shift.id))"
                @csv="r.download(route('reports.z.csv', shift.id))"
            />
            <UButton color="neutral" variant="outline" icon="i-lucide-printer" label="Print" :disabled="paper.preview" :loading="r.log.printing.value" @click="print" />
        </PageHeader>

        <div class="no-print mb-4">
            <OptionTiles v-model="current" :options="tabs" label="Z-report tab" />
        </div>
        <UAlert v-if="r.log.error.value" class="no-print mb-4" color="error" variant="subtle" :description="r.log.error.value" />

        <div class="print-a4" :class="tab === 'rooms' ? 'print-landscape' : ''">
            <template v-if="tab === 'sales'">
                <ZPaper :paper="paper" drillable @drill="(code, label) => r.drill(code, label)" />
                <CheckResult :problems="paper.checks" />
            </template>

            <template v-else-if="tab === 'rooms' && rooms">
                <div class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
                    <table class="dtable">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th v-for="c in rooms.columns" :key="c.key" class="text-right">{{ c.label }}</th>
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in rooms.rows" :key="row.key">
                                <td>{{ row.label }}</td>
                                <td v-for="c in rooms.columns" :key="c.key" class="r">{{ c.key === 'none' && row.cells[c.key] === 0 ? '—' : whole(row.cells[c.key]) }}</td>
                                <td class="r">{{ whole(row.total) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Gross total</td>
                                <td v-for="c in rooms.columns" :key="c.key" class="r">{{ whole(rooms.gross.cells[c.key]) }}</td>
                                <td class="r">{{ whole(rooms.gross.total) }}</td>
                            </tr>
                            <tr>
                                <td class="!border-t-0">Deposits held at close</td>
                                <td v-for="c in rooms.columns" :key="c.key" class="r !border-t-0">{{ c.key === 'none' ? '—' : whole(rooms.deposits.cells[c.key]) }}</td>
                                <td class="r !border-t-0">{{ whole(rooms.deposits.total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <CheckResult :problems="rooms.checks" />

                <section v-for="chart in rooms.charts" :key="chart.floor" class="mt-5">
                    <h5 class="mb-2 font-semibold text-highlighted">{{ chart.floor }} · room chart</h5>
                    <div class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
                        <table class="dtable zchart">
                            <thead>
                                <tr>
                                    <th>Room</th>
                                    <th>Guest</th>
                                    <th>In</th>
                                    <th>Out</th>
                                    <th class="text-right">Room</th>
                                    <th class="text-right">Ext.</th>
                                    <th class="text-right">Food</th>
                                    <th class="text-right">Drinks</th>
                                    <th class="text-right">Misc</th>
                                    <th class="text-right">Dep.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(row, i) in chart.rows" :key="`${row.room}-${i}`" :class="row.forwarded ? 'forwarded' : ''">
                                    <td class="num">{{ row.room }}</td>
                                    <template v-if="row.vacant">
                                        <td class="text-muted">Vacant all shift</td>
                                        <td></td>
                                        <td></td>
                                        <td v-for="n in 6" :key="n" class="r">0</td>
                                    </template>
                                    <template v-else>
                                        <td>
                                            {{ row.guest }}
                                            <StatusLabel v-if="row.forwarded" tone="bad" label="FWD" class="ms-1" />
                                            <StatusLabel v-if="row.extended" tone="warn" label="EXT" class="ms-1" />
                                        </td>
                                        <td>{{ when(row.in) }}</td>
                                        <td>{{ when(row.out) }}</td>
                                        <td class="r">{{ whole(row.room_charge) }}</td>
                                        <td class="r">{{ whole(row.ext) }}</td>
                                        <td class="r">{{ whole(row.foods) }}</td>
                                        <td class="r">{{ whole(row.drinks) }}</td>
                                        <td class="r">{{ whole(row.misc) }}</td>
                                        <td class="r">{{ whole(row.deposit) }}</td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <p class="mt-2 text-[13px] text-muted">Red rows are forwarded guests: their room charge was a sale of the shift that checked them in, so it shows 0 here.</p>
            </template>

            <div v-else-if="tab === 'cleaning' && cleaning" class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
                <p v-if="cleaning.length === 0" class="px-3 py-6 text-sm text-muted">No room was queued or cleaned during this shift.</p>
                <table v-else class="dtable">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Guest left</th>
                            <th>Deadline</th>
                            <th>Started</th>
                            <th>Ready</th>
                            <th>Housekeeper</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in cleaning" :key="t.id">
                            <td class="num">{{ t.room }}</td>
                            <td>{{ time(t.queued_at) }}</td>
                            <td>{{ time(t.due_at) }}</td>
                            <td>{{ time(t.started_at) }}</td>
                            <td>{{ time(t.finished_at) }}</td>
                            <td>{{ t.by ? shortName(t.by) : '—' }}</td>
                            <td>
                                <StatusLabel v-if="t.is_late" tone="bad" label="Late" />
                                <StatusLabel v-else-if="t.status !== 'done'" tone="violet" :label="t.status === 'in_progress' ? 'Cleaning' : 'Waiting'" />
                                <StatusLabel v-else-if="t.result === 'problem'" tone="warn" label="Problem" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <template v-else-if="tab === 'housekeeping' && housekeeping">
                <div class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
                    <p v-if="housekeeping.people.length === 0" class="px-3 py-6 text-sm text-muted">No room was cleaned during this shift.</p>
                    <table v-else class="dtable">
                        <thead>
                            <tr>
                                <th>Housekeeper</th>
                                <th>Floors</th>
                                <th class="text-right">Cleans</th>
                                <th class="text-right">Average</th>
                                <th class="text-right">Late</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in housekeeping.people" :key="p.user_id">
                                <td>{{ p.name }}</td>
                                <td>{{ p.floors }}</td>
                                <td class="r">{{ p.cleans }}</td>
                                <td class="r">{{ p.average_minutes }} min</td>
                                <td class="r" :class="p.late ? '!text-bad' : ''">{{ p.late }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <div v-else-if="tab === 'stocks' && stocks" class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
                <table class="dtable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-right">Opening</th>
                            <th class="text-right">In</th>
                            <th class="text-right">Sold</th>
                            <th class="text-right">Wastage</th>
                            <th class="text-right">Closing</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in stocks" :key="p.product_id">
                            <td>{{ p.name }}</td>
                            <td class="r">{{ p.opening }}</td>
                            <td class="r">{{ p.in }}</td>
                            <td class="r">{{ p.sold }}</td>
                            <td class="r">{{ p.wastage }}</td>
                            <td class="r">{{ p.closing }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else-if="tab === 'expenses' && expenses" class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
                <p v-if="expenses.length === 0" class="px-3 py-6 text-sm text-muted">No cash entries on this shift.</p>
                <table v-else class="dtable">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Entry</th>
                            <th>Note</th>
                            <th>By</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in expenses" :key="e.id" :class="e.voided ? 'void' : ''">
                            <td>{{ time(e.occurred_at) }}</td>
                            <td>{{ e.category ?? kindLabel[e.kind] }}</td>
                            <td class="!whitespace-normal">{{ e.note }}<template v-if="e.voided"> · voided: {{ e.void_reason }}</template></td>
                            <td>{{ shortName(e.by) }}</td>
                            <td class="r">{{ formatMoney(e.amount, 'column') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <LedgerSheet v-model:open="r.sheetOpen.value" :ledger="ledger ?? null" :label="r.sheetLabel.value" :loading="r.sheetLoading.value" />
    </BackOfficeLayout>
</template>

<style scoped>
.zchart {
    font-size: 12px;
}

.zchart :deep(th),
.zchart :deep(td) {
    padding: 6px 10px;
}

.zchart tr.forwarded td {
    color: var(--hm-bad);
}

@media print {
    .print-landscape {
        page: landscape;
    }
}
</style>
