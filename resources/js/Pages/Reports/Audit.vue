<!-- Mockup 43 -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DateNav from '@/Components/DateNav.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import PersonPill from '@/Components/PersonPill.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useReport } from '@/Composables/useReport';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Audit trail (43): every void, cancelled stay, transfer, shift close and other approved or audited
 * action of the business day, with who did it, who approved it (14) and the reason. Nothing in
 * the app is deleted, so the list is complete by design. Filtered by type and by staff member.
 */
type Type = 'all' | 'voids' | 'cancels' | 'transfers' | 'shift';

const props = defineProps<{
    report: {
        date: string;
        type: Type;
        user_id: number | null;
        rows: { id: string; at: string; type: string; action: string; details: string; by: string; approved_by: string }[];
        people: { id: number; name: string }[];
    };
}>();

const r = useReport();
const date = ref(props.report.date);
const type = ref<Type>(props.report.type);
const person = ref<number | 'all'>(props.report.user_id ?? 'all');
watch([date, type, person], ([d, t, p]) => r.go('reports.audit', {}, { date: d, type: t === 'all' ? null : t, user: p === 'all' ? null : p }));

const title = computed(() => `Audit trail · ${formatDateTime(`${props.report.date}T12:00:00+08:00`, 'businessDate').replace(',', '')}`);
const people = computed(() => [{ label: 'Everyone', value: 'all' as const }, ...props.report.people.map((p) => ({ label: p.name, value: p.id }))]);
const tone: Record<string, 'warn' | 'info' | 'bad' | 'neutral'> = { voids: 'warn', transfers: 'info', cancels: 'bad', shift: 'neutral', other: 'neutral' };
// Approval groups get a short chip; shift and other rows carry their own specific action label.
const chip: Record<string, string> = { voids: 'Void', transfers: 'Transfer', cancels: 'Cancel' };
</script>

<template>
    <BackOfficeLayout current="audit" :title="title">
        <Head title="Audit trail" />
        <PageHeader>
            <ExportMenu csv :print="false" @csv="r.download(route('reports.audit', { date: report.date, type: report.type, user: report.user_id ?? undefined, export: 'csv' }))" />
        </PageHeader>
        <div class="no-print mb-4 flex flex-wrap items-center gap-3">
            <DateNav v-model="date" />
            <PeriodSegments
                v-model="type"
                label="Type"
                :items="[
                    { value: 'all', label: 'All' },
                    { value: 'voids', label: 'Voids' },
                    { value: 'cancels', label: 'Cancels' },
                    { value: 'transfers', label: 'Transfers' },
                    { value: 'shift', label: 'Shift' },
                ]"
            />
            <USelect v-model="person" :items="people" value-key="value" class="w-52" aria-label="Done by" />
        </div>

        <div class="print-a4 overflow-x-auto rounded-card border border-default bg-default p-4">
            <EmptyState v-if="report.rows.length === 0" title="Nothing to show" text="No void, cancel, transfer or shift action matches these filters." />
            <table v-else class="dtable">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>Done by</th>
                        <th>Approved by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in report.rows" :key="row.id">
                        <td>{{ formatDateTime(row.at, 'time') }}</td>
                        <td><StatusLabel :tone="tone[row.type] ?? 'neutral'" :label="chip[row.type] ?? row.action" /></td>
                        <td class="!whitespace-normal">{{ row.details }}</td>
                        <td><PersonPill v-if="row.by" :name="row.by" /><span v-else class="text-muted">—</span></td>
                        <td><PersonPill v-if="row.approved_by" :name="row.approved_by" /><span v-else class="text-muted">—</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </BackOfficeLayout>
</template>
