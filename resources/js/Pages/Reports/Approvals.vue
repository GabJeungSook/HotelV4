<!-- Approvals & overrides (new report; no mockup frame). Every supervisor-approved action — voids,
     cancels, transfers, shift overrides — over a day, week or month, with who asked, who approved
     and why. Filterable by kind; exports to CSV. -->
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
import { useReport } from '@/Composables/useReport';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

type Range = 'day' | 'week' | 'month';
type Kind = 'all' | 'void' | 'cancel' | 'transfer' | 'shift';

const props = defineProps<{
    report: {
        date: string;
        range: Range;
        from: string;
        to: string;
        type: Kind;
        counts: { void: number; cancel: number; transfer: number; shift: number; other: number };
        rows: { at: string; group: string; action: string; requester: string; approver: string; reason: string; details: string }[];
    };
}>();

const r = useReport();
const date = ref(props.report.date);
const range = ref<Range>(props.report.range);
const type = ref<Kind>(props.report.type);
watch([date, range, type], ([d, rg, t]) => r.go('reports.approvals', {}, { date: d, range: rg === 'day' ? null : rg, type: t === 'all' ? null : t }));

const heading = computed(() => (props.report.from === props.report.to ? formatDateTime(`${props.report.from}T12:00:00+08:00`, 'businessDate').replace(',', '') : `${props.report.from} to ${props.report.to}`));
const tone: Record<string, 'warn' | 'bad' | 'info' | 'neutral'> = { void: 'warn', cancel: 'bad', transfer: 'info', shift: 'neutral', other: 'neutral' };
</script>

<template>
    <BackOfficeLayout current="reports" :title="`Approvals & overrides · ${heading}`">
        <Head title="Approvals & overrides" />
        <PageHeader>
            <ExportMenu csv :print="false" @csv="r.download(route('reports.approvals', { date: report.date, range: report.range, type: report.type, export: 'csv' }))" />
        </PageHeader>

        <div class="no-print mb-4 flex flex-wrap items-center gap-3">
            <DateNav v-model="date" :step="range === 'month' ? 30 : range === 'week' ? 7 : 1" />
            <PeriodSegments v-model="range" label="Range" :items="[{ value: 'day', label: 'Day' }, { value: 'week', label: 'Week' }, { value: 'month', label: 'Month' }]" />
            <PeriodSegments
                v-model="type"
                label="Type"
                :items="[
                    { value: 'all', label: 'All' },
                    { value: 'void', label: 'Voids' },
                    { value: 'cancel', label: 'Cancels' },
                    { value: 'transfer', label: 'Transfers' },
                    { value: 'shift', label: 'Shift' },
                ]"
            />
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div v-for="k in (['void', 'cancel', 'transfer', 'shift'] as const)" :key="k" class="rounded-card border border-default bg-default px-4 py-3">
                <p class="text-xs text-muted capitalize">{{ k }}s</p>
                <p class="mt-0.5 text-xl font-semibold text-highlighted tabular">{{ report.counts[k] }}</p>
            </div>
        </div>

        <div class="print-a4 overflow-x-auto rounded-card border border-default bg-default p-4">
            <EmptyState v-if="report.rows.length === 0" title="No approvals" text="No approved or overridden action matches these filters." />
            <table v-else class="dtable">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Requested by</th>
                        <th>Approved by</th>
                        <th>Reason / details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in report.rows" :key="i">
                        <td class="whitespace-nowrap">{{ formatDateTime(row.at, report.from === report.to ? 'time' : 'dayTime') }}</td>
                        <td><StatusLabel :tone="tone[row.group] ?? 'neutral'" :label="row.action" /></td>
                        <td><PersonPill v-if="row.requester" :name="row.requester" /><span v-else class="text-muted">—</span></td>
                        <td><PersonPill v-if="row.approver" :name="row.approver" /><span v-else class="text-muted">—</span></td>
                        <td class="!whitespace-normal">{{ [row.details, row.reason].filter(Boolean).join(' · ') || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </BackOfficeLayout>
</template>
