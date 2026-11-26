<!-- Guest movements (new report; no mockup frame). A per-guest log of arrivals, departures,
     extensions and transfers over a day, week or month, with arrivals by room type. Filterable
     by kind; exports to CSV. Built to the report design system. -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DateNav from '@/Components/DateNav.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PersonPill from '@/Components/PersonPill.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useReport } from '@/Composables/useReport';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

type Range = 'day' | 'week' | 'month';
type Kind = 'all' | 'arrival' | 'departure' | 'extension' | 'transfer';

const props = defineProps<{
    report: {
        date: string;
        range: Range;
        from: string;
        to: string;
        type: Kind;
        counts: { arrival: number; departure: number; extension: number; transfer: number };
        rows: { at: string; kind: string; guest: string; code: string; room: string; room_type: string; details: string; by: string; amount: number }[];
        mix: { type: string; count: number }[];
    };
}>();

const r = useReport();
const date = ref(props.report.date);
const range = ref<Range>(props.report.range);
const type = ref<Kind>(props.report.type);
watch([date, range, type], ([d, rg, t]) => r.go('reports.guests', {}, { date: d, range: rg === 'day' ? null : rg, type: t === 'all' ? null : t }));

const heading = computed(() => (props.report.from === props.report.to ? formatDateTime(`${props.report.from}T12:00:00+08:00`, 'businessDate').replace(',', '') : `${props.report.from} to ${props.report.to}`));
const tone: Record<string, 'ok' | 'info' | 'warn' | 'neutral'> = { arrival: 'ok', departure: 'info', extension: 'warn', transfer: 'neutral' };
const label: Record<string, string> = { arrival: 'Arrival', departure: 'Departure', extension: 'Extension', transfer: 'Transfer' };
const totalArrivals = computed(() => props.report.mix.reduce((n, m) => n + m.count, 0));
</script>

<template>
    <BackOfficeLayout current="reports" :title="`Guest movements · ${heading}`">
        <Head title="Guest movements" />
        <PageHeader>
            <ExportMenu csv :print="false" @csv="r.download(route('reports.guests', { date: report.date, range: report.range, type: report.type, export: 'csv' }))" />
        </PageHeader>

        <div class="no-print mb-4 flex flex-wrap items-center gap-3">
            <DateNav v-model="date" :step="range === 'month' ? 30 : range === 'week' ? 7 : 1" />
            <PeriodSegments v-model="range" label="Range" :items="[{ value: 'day', label: 'Day' }, { value: 'week', label: 'Week' }, { value: 'month', label: 'Month' }]" />
            <PeriodSegments
                v-model="type"
                label="Type"
                :items="[
                    { value: 'all', label: 'All' },
                    { value: 'arrival', label: 'Arrivals' },
                    { value: 'departure', label: 'Departures' },
                    { value: 'extension', label: 'Extensions' },
                    { value: 'transfer', label: 'Transfers' },
                ]"
            />
        </div>

        <!-- Counts + arrivals by room type -->
        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="k in (['arrival', 'departure', 'extension', 'transfer'] as const)" :key="k" class="rounded-card border border-default bg-default px-4 py-3">
                <p class="text-xs text-muted">{{ label[k] }}s</p>
                <p class="mt-0.5 text-xl font-semibold text-highlighted tabular">{{ report.counts[k] }}</p>
            </div>
        </div>

        <div v-if="totalArrivals > 0" class="mb-4 flex flex-wrap items-center gap-x-5 gap-y-1 rounded-card border border-default bg-default px-4 py-3 text-sm">
            <span class="text-muted">Arrivals by room type:</span>
            <span v-for="m in report.mix" :key="m.type" class="text-default">{{ m.type }} <b class="text-highlighted tabular">{{ m.count }}</b></span>
        </div>

        <div class="print-a4 overflow-x-auto rounded-card border border-default bg-default p-4">
            <EmptyState v-if="report.rows.length === 0" title="No movements" text="No arrival, departure, extension or transfer matches these filters." />
            <table v-else class="dtable">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Guest</th>
                        <th>Room</th>
                        <th>Details</th>
                        <th>By</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in report.rows" :key="i">
                        <td class="whitespace-nowrap">{{ formatDateTime(row.at, report.from === report.to ? 'time' : 'dayTime') }}</td>
                        <td><StatusLabel :tone="tone[row.kind] ?? 'neutral'" :label="label[row.kind] ?? row.kind" /></td>
                        <td class="whitespace-nowrap">{{ row.guest }} <span class="text-muted">· {{ row.code }}</span></td>
                        <td class="whitespace-nowrap tabular">{{ row.room || '—' }}<span v-if="row.room_type" class="text-muted"> · {{ row.room_type }}</span></td>
                        <td class="!whitespace-normal">{{ row.details }}</td>
                        <td><PersonPill v-if="row.by" :name="row.by" /><span v-else class="text-muted">—</span></td>
                        <td class="text-right num">{{ row.amount ? formatMoney(row.amount) : '' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </BackOfficeLayout>
</template>
