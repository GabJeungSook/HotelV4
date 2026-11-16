<!-- Mockup 37 -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import CheckResult from '@/Components/CheckResult.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import LedgerSheet, { type Ledger } from '@/Components/LedgerSheet.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useReport } from '@/Composables/useReport';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Shift report (37): what was charged this shift, what came into and left the drawer, and the
 * three roll-forwards (guests, key deposits, unpaid balances) that carry the shift into the
 * next. A closed shift is read from its frozen totals; an open one is a live preview. Every
 * figure opens the ledger rows behind it.
 */
interface Line {
    key?: string;
    label: string;
    count?: number;
    amount: number;
    drill?: string;
}

const props = defineProps<{
    report: {
        shift: { id: number; period_name: string; business_date: string; status: string; z_no: number | null; opened_at: string; closed_at: string | null; members: string[]; handed_to: string | null; is_force_closed: boolean };
        preview: boolean;
        sales: Line[];
        sales_total: number;
        cash: Line[];
        expected: number;
        counted: number | null;
        variance: number | null;
        variance_note: string | null;
        not_in_drawer: number;
        guests: { forwarded_in: number; checked_in: number; checked_out: number; forwarded_out: number; cancelled: number };
        deposits: { held_open: number; taken: number; returned: number; forfeited: number; applied: number; held_close: number };
        unpaid: { forwarded_in: number; charged: number; collected: number; forwarded_out: number };
        checks: string[];
    };
    ledger?: Ledger | null;
}>();

const r = useReport();
const s = computed(() => props.report.shift);
const m = (c: number) => formatMoney(c, 'column');
const title = computed(() => `${s.value.period_name} shift · ${formatDateTime(`${s.value.business_date}T12:00:00+08:00`, 'businessDate').replace(',', '')}`);
const people = computed(() => s.value.members.join(', ') + (s.value.handed_to ? ` · handed to ${s.value.handed_to}` : ''));
const varianceLabel = computed(() => (props.report.variance === null ? 'Short or over' : props.report.variance < 0 ? 'Short' : props.report.variance > 0 ? 'Over' : 'Short or over'));

function print(): void {
    void r.log.print(route('reports.shift.print', s.value.id), { document: 'report' });
}
</script>

<template>
    <BackOfficeLayout current="shift-reports" :title="title">
        <Head :title="title" />
        <PageHeader>
            <template #status>
                <StatusLabel v-if="s.closed_at" tone="neutral" :label="`Closed ${formatDateTime(s.closed_at, 'time')}`" />
                <StatusLabel v-else tone="warn" label="Open · preview" />
            </template>
            <UButton color="neutral" variant="outline" label="Z-report" icon="i-solar-list-bold-duotone" :to="route('reports.z', s.id)" />
            <ExportMenu csv :disabled="report.preview" @print="print" @csv="r.download(route('reports.shift', { shift: s.id, export: 'csv' }))" />
        </PageHeader>

        <div class="print-a4">
            <p class="mb-3 text-[13px] text-muted">{{ people }}<template v-if="s.is_force_closed"> · force closed</template></p>
            <UAlert v-if="report.preview" class="no-print mb-4" color="warning" variant="subtle" icon="i-solar-eye-bold-duotone" title="Preview. Do not sign." description="The shift is still open, so these figures are read live from the ledger and will change." />

            <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
                <div class="rounded-card border border-default bg-default p-4">
                    <table class="dtable">
                        <thead>
                            <tr>
                                <th>Sales</th>
                                <th class="text-right">Count</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in report.sales" :key="line.key">
                                <td>{{ line.label }}</td>
                                <td class="r">{{ line.count }}</td>
                                <td class="r">
                                    <button type="button" class="hm-focus cursor-pointer hover:underline" @click="r.drill(line.drill!, line.label)">{{ m(line.amount) }}</button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Sales charged</td>
                                <td></td>
                                <td class="r">
                                    <button type="button" class="hm-focus cursor-pointer hover:underline" @click="r.drill('net', 'Sales charged')">{{ m(report.sales_total) }}</button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="rounded-card border border-default bg-default px-5 py-4 text-sm">
                    <div v-for="line in report.cash" :key="line.label" class="flex justify-between py-1">
                        <span class="text-muted">{{ line.label }}</span>
                        <button v-if="line.drill" type="button" class="hm-focus num cursor-pointer hover:underline" @click="r.drill(line.drill, line.label)">{{ m(line.amount) }}</button>
                        <b v-else class="num">{{ m(line.amount) }}</b>
                    </div>
                    <div class="mt-1 flex justify-between border-t border-(--hm-ink) pt-1.5 font-bold text-highlighted">
                        <span>Expected</span><b class="num">{{ m(report.expected) }}</b>
                    </div>
                    <div class="flex justify-between py-1">
                        <span class="text-muted">Counted</span><b class="num">{{ report.counted === null ? '—' : m(report.counted) }}</b>
                    </div>
                    <div v-if="report.variance !== null" class="flex justify-between py-1">
                        <span :class="report.variance < 0 ? 'font-semibold text-bad' : 'text-muted'">{{ varianceLabel }}</span>
                        <b class="num" :class="report.variance < 0 ? '!text-bad' : ''">{{ m(report.variance) }}</b>
                    </div>
                    <p v-if="report.variance_note" class="text-xs text-muted">"{{ report.variance_note }}"</p>
                    <div class="mt-2 flex justify-between border-t border-default pt-2">
                        <span class="text-muted">GCash / card (not in drawer)</span><b class="num">{{ m(report.not_in_drawer) }}</b>
                    </div>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-3 gap-4 max-[900px]:grid-cols-1">
                <div class="rounded-card border border-default bg-default px-5 py-4 text-sm">
                    <h5 class="mb-1 font-semibold text-highlighted">Guests</h5>
                    <div class="flex justify-between py-0.5"><span class="text-muted">Forwarded in</span><b class="num">{{ report.guests.forwarded_in }}</b></div>
                    <div class="flex justify-between py-0.5"><span class="text-muted">Checked in</span><b class="num">{{ report.guests.checked_in }}</b></div>
                    <div class="flex justify-between py-0.5">
                        <span class="text-muted">Checked out<template v-if="report.guests.cancelled"> (incl. {{ report.guests.cancelled }} cancelled)</template></span>
                        <b class="num">{{ report.guests.checked_out === 0 ? 0 : `−${report.guests.checked_out}` }}</b>
                    </div>
                    <div class="mt-1 flex justify-between border-t border-(--hm-ink) pt-1 font-bold text-highlighted"><span>Forwarded out</span><b class="num">{{ report.guests.forwarded_out }}</b></div>
                </div>
                <div class="rounded-card border border-default bg-default px-5 py-4 text-sm">
                    <h5 class="mb-1 font-semibold text-highlighted">Key deposits</h5>
                    <div class="flex justify-between py-0.5"><span class="text-muted">Held at open</span><b class="num">{{ m(report.deposits.held_open) }}</b></div>
                    <div class="flex justify-between py-0.5"><span class="text-muted">Taken</span><b class="num">{{ m(report.deposits.taken) }}</b></div>
                    <div class="flex justify-between py-0.5"><span class="text-muted">Returned</span><b class="num">{{ m(-report.deposits.returned) }}</b></div>
                    <div class="flex justify-between py-0.5"><span class="text-muted">Forfeited</span><b class="num">{{ m(-report.deposits.forfeited) }}</b></div>
                    <div v-if="report.deposits.applied" class="flex justify-between py-0.5"><span class="text-muted">Used for damages</span><b class="num">{{ m(-report.deposits.applied) }}</b></div>
                    <div class="mt-1 flex justify-between border-t border-(--hm-ink) pt-1 font-bold text-highlighted"><span>Held at close</span><b class="num">{{ m(report.deposits.held_close) }}</b></div>
                </div>
                <div class="rounded-card border border-default bg-default px-5 py-4 text-sm">
                    <h5 class="mb-1 font-semibold text-highlighted">Unpaid balances</h5>
                    <div class="flex justify-between py-0.5"><span class="text-muted">Forwarded in</span><b class="num">{{ m(report.unpaid.forwarded_in) }}</b></div>
                    <div class="flex justify-between py-0.5"><span class="text-muted">Charged</span><b class="num">{{ m(report.unpaid.charged) }}</b></div>
                    <div class="flex justify-between py-0.5">
                        <span class="text-muted">Collected</span>
                        <button type="button" class="hm-focus num cursor-pointer hover:underline" @click="r.drill('collected', 'Collected')">{{ m(-report.unpaid.collected) }}</button>
                    </div>
                    <div class="mt-1 flex justify-between border-t border-(--hm-ink) pt-1 font-bold text-highlighted"><span>Forwarded out</span><b class="num">{{ m(report.unpaid.forwarded_out) }}</b></div>
                </div>
            </div>

            <CheckResult :problems="report.checks" />
            <UAlert v-if="r.log.error.value" class="no-print mt-4" color="error" variant="subtle" :description="r.log.error.value" />
        </div>

        <LedgerSheet v-model:open="r.sheetOpen.value" :ledger="ledger ?? null" :label="r.sheetLabel.value" :loading="r.sheetLoading.value" />
    </BackOfficeLayout>
</template>
