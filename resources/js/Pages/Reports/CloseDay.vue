<!-- Mockup 38b -->
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CheckResult from '@/Components/CheckResult.vue';
import DateNav from '@/Components/DateNav.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ZPaper, { type ZPaperData } from '@/Components/ZPaper.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useReport } from '@/Composables/useReport';
import { shortName, useShared } from '@/Composables/useShared';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Close the day (38b): the date's shifts with their Z numbers, net sales and short and over (never
 * netted), the day number, and the grand total carried forward. "Close the day" is offered only
 * when every shift of the date is closed, the earlier day is closed and the check agrees; the
 * server checks the same again (CloseDay). Once closed, the day paper (37b layout, D-number)
 * prints and exports like a Z-report.
 */
interface ShiftRow {
    id: number;
    period_name: string;
    z_no: number | null;
    status: string;
    members: string[];
    net_sales: number | null;
    short: number;
    over: number;
}

const props = defineProps<{
    day: {
        date: string;
        shifts: ShiftRow[];
        total: { net_sales: number; short: number; over: number };
        day_z: string;
        closed: { id: number; day_z_no: number; closed_at: string; closed_by: string; net_sales: number; short: number; over: number; grand_total_before: number; grand_total_after: number } | null;
        grand_total_before: number | null;
        grand_total_after: number | null;
        reason: string | null;
        previous: { date: string; day_z: string } | null;
        paper: (ZPaperData & { figures: Record<string, number | null> }) | null;
    };
    can_close: boolean;
}>();

const { flash } = useShared();
const r = useReport();
const confirm = useConfirmDialog();
const date = ref(props.day.date);
watch(date, (d) => r.go('reports.daily.close', {}, { date: d }));

const title = computed(() => `Close the day · ${formatDateTime(`${props.day.date}T12:00:00+08:00`, 'businessDate').replace(',', '')}`);
const m = (c: number | null) => formatMoney(c, 'column');
const z = (n: number | null) => (n === null ? 'open' : `Z-${String(n).padStart(4, '0')}`);
const error = computed(() => flash.value.error?.message);
const saving = ref(false);

async function closeDay(): Promise<void> {
    const ok = await confirm({
        title: `Close ${formatDateTime(`${props.day.date}T12:00:00+08:00`, 'shiftDate')} as ${props.day.day_z}?`,
        description: 'A day is closed once. Its paper cannot change afterwards.',
        confirmLabel: 'Close the day',
        cancelLabel: 'Not now',
    });

    if (ok) {
        router.post(route('reports.daily.close.store'), { date: props.day.date }, { preserveScroll: true, onStart: () => (saving.value = true), onFinish: () => (saving.value = false) });
    }
}

function print(): void {
    void r.log.print(route('reports.daily.print', props.day.date));
}
</script>

<template>
    <BackOfficeLayout current="daily" :title="title">
        <Head title="Close the day" />
        <PageHeader>
            <ExportMenu
                v-if="day.closed"
                pdf
                csv
                @print="print"
                @pdf="r.download(route('reports.daily.z.pdf', day.date))"
                @csv="r.download(route('reports.daily.z.csv', day.date))"
            />
            <UButton v-if="!day.closed && can_close" label="Close the day" :disabled="day.reason !== null" :loading="saving" @click="closeDay" />
        </PageHeader>

        <div class="no-print mb-4"><DateNav v-model="date" /></div>
        <UAlert v-if="error" class="no-print mb-4" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="error" />

        <div class="print-a4 grid grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] gap-4 max-[900px]:grid-cols-1" :class="day.closed ? 'no-print' : ''">
            <div class="overflow-x-auto rounded-card border border-default bg-default px-1 py-1">
                <table class="dtable">
                    <thead>
                        <tr>
                            <th>Shift</th>
                            <th class="text-right">Net sales</th>
                            <th class="text-right">Short</th>
                            <th class="text-right">Over</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in day.shifts" :key="s.id">
                            <td>
                                {{ s.period_name }} · <span class="num">{{ z(s.z_no) }}</span>
                                <div class="text-xs text-muted">{{ s.members.map(shortName).join(', ') }}</div>
                            </td>
                            <td class="r">{{ s.net_sales === null ? '—' : m(s.net_sales) }}</td>
                            <td class="r" :class="s.short < 0 ? '!text-bad' : ''">{{ m(s.short) }}</td>
                            <td class="r">{{ m(s.over) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Day</td>
                            <td class="r">{{ m(day.total.net_sales) }}</td>
                            <td class="r" :class="day.total.short < 0 ? '!text-bad' : ''">{{ m(day.total.short) }}</td>
                            <td class="r">{{ m(day.total.over) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="rounded-card border border-default bg-default px-5 py-4 text-sm">
                <div class="flex justify-between py-1"><span class="text-muted">Day Z number</span><b class="num">{{ day.day_z }}</b></div>
                <div class="flex justify-between py-1"><span class="text-muted">Grand total before</span><b class="num">{{ m(day.grand_total_before) }}</b></div>
                <div class="flex justify-between py-1"><span class="text-muted">Net sales of the day</span><b class="num">{{ m(day.total.net_sales) }}</b></div>
                <div class="mt-1 flex justify-between border-t border-(--hm-ink) pt-1.5 text-[13.5px] font-bold text-highlighted"><span>Grand total after</span><b class="num">{{ m(day.grand_total_after) }}</b></div>
                <UAlert v-if="day.closed" class="mt-3" color="success" variant="subtle" icon="i-solar-lock-bold-duotone" :description="`Closed as ${day.day_z} by ${day.closed.closed_by}, ${formatDateTime(day.closed.closed_at, 'dayTime')}.`" />
                <UAlert v-else-if="day.reason === null" class="mt-3" color="success" variant="subtle" icon="i-solar-check-circle-linear" description="Every shift is closed and the day agrees with the ledger." />
                <UAlert v-else class="mt-3" color="warning" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="day.reason" />
            </div>
        </div>
        <p class="no-print mt-3 text-[13px] text-muted">
            A day can be closed once, in date order, and only after every shift of that date is closed.
            <template v-if="day.previous"> {{ formatDateTime(`${day.previous.date}T12:00:00+08:00`, 'date').replace(/ \d{4}$/, '') }} was closed as {{ day.previous.day_z }}.</template>
        </p>

        <template v-if="day.paper">
            <h5 class="no-print mt-6 mb-2 font-semibold text-highlighted">Day paper</h5>
            <div class="print-a4">
                <ZPaper :paper="day.paper" />
                <CheckResult :problems="day.paper.checks" />
            </div>
        </template>
        <UAlert v-if="r.log.error.value" class="no-print mt-4" color="error" variant="subtle" :description="r.log.error.value" />
    </BackOfficeLayout>
</template>
