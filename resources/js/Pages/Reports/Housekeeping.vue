<!-- Mockup 41 -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DateNav from '@/Components/DateNav.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PersonPill from '@/Components/PersonPill.vue';
import { formatDateTime, formatDuration } from '@/Composables/useDateTime';
import { useReport } from '@/Composables/useReport';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Housekeeping report (41): cleans, average time and late rooms per housekeeper, for a business
 * day or one of its shifts, from cleaning_tasks. A room is late when it was ready after its
 * deadline. The report counts late rooms only (a peso penalty is on the Later list).
 */
const props = defineProps<{
    report: {
        date: string;
        shift_id: number | null;
        people: {
            user_id: number;
            name: string;
            floors: string;
            cleans: number;
            average_minutes: number;
            late: number;
            rooms: { room: string; ready: string | null; minutes: number; late: boolean }[];
        }[];
        total: { cleans: number; late: number };
        late: { id: number; room: string; guest_left: string; deadline: string; ready: string; late_minutes: number; by: string }[];
    };
    shifts: { id: number; label: string }[];
}>();

const r = useReport();
const date = ref(props.report.date);
const shift = ref<number | 'day'>(props.report.shift_id ?? 'day');
watch(date, (d) => r.go('reports.housekeeping', {}, { date: d }));
watch(shift, (s) => r.go('reports.housekeeping', {}, s === 'day' ? { date: props.report.date } : { shift: s }));

// Which housekeepers' room lists are expanded.
const expanded = ref(new Set<number>());
function toggle(id: number): void {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
}

const title = computed(() => `Housekeeping · ${formatDateTime(`${props.report.date}T12:00:00+08:00`, 'businessDate').replace(',', '')}`);
const scope = computed(() => [{ label: 'Whole day', value: 'day' as const }, ...props.shifts.map((s) => ({ label: s.label, value: s.id }))]);
const exportUrl = computed(() => route('reports.housekeeping', { ...(props.report.shift_id ? { shift: props.report.shift_id } : { date: props.report.date }), export: 'csv' }));
</script>

<template>
    <BackOfficeLayout current="housekeeping-report" :title="title">
        <Head title="Housekeeping" />
        <PageHeader>
            <ExportMenu csv :print="false" @csv="r.download(exportUrl)" />
        </PageHeader>
        <div class="no-print mb-4 flex flex-wrap items-center gap-3">
            <DateNav v-model="date" />
            <USelect v-model="shift" :items="scope" value-key="value" class="w-40" aria-label="Day or shift" />
        </div>

        <div class="print-a4">
            <div class="overflow-x-auto rounded-card border border-default bg-default p-4">
                <p v-if="report.people.length === 0" class="px-3 py-6 text-sm text-muted">No room was cleaned in this period.</p>
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
                        <template v-for="p in report.people" :key="p.user_id">
                            <tr>
                                <td>
                                    <button type="button" class="hm-focus inline-flex items-center gap-2 text-left" :aria-expanded="expanded.has(p.user_id)" @click="toggle(p.user_id)">
                                        <UIcon :name="expanded.has(p.user_id) ? 'i-solar-alt-arrow-down-linear' : 'i-solar-alt-arrow-right-linear'" class="size-3.5 shrink-0 text-faint" aria-hidden="true" />
                                        <PersonPill :name="p.name" />
                                    </button>
                                </td>
                                <td>{{ p.floors }}</td>
                                <td class="r">{{ p.cleans }}</td>
                                <td class="r">{{ p.average_minutes }} min</td>
                                <td class="r" :class="p.late ? '!text-bad' : ''">{{ p.late }}</td>
                            </tr>
                            <tr v-if="expanded.has(p.user_id)">
                                <td colspan="5" class="!py-0">
                                    <div class="flex flex-wrap gap-x-5 gap-y-1.5 rounded-lg bg-muted px-4 py-2.5 ps-7 text-[12.5px]">
                                        <span v-for="(room, i) in p.rooms" :key="i" class="inline-flex items-baseline gap-1.5">
                                            <span class="num font-medium" :class="room.late ? 'text-bad' : 'text-highlighted'">{{ room.room }}</span>
                                            <span class="text-muted">{{ room.ready ? formatDateTime(room.ready, 'time') : '—' }}</span>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot v-if="report.people.length > 1">
                        <tr>
                            <td>Total</td>
                            <td></td>
                            <td class="r">{{ report.total.cleans }}</td>
                            <td></td>
                            <td class="r">{{ report.total.late }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <h5 class="mt-5 mb-2 font-semibold text-highlighted">Late rooms</h5>
            <div class="overflow-x-auto rounded-card border border-default bg-default p-4">
                <p v-if="report.late.length === 0" class="px-3 py-6 text-sm text-muted">Every room was ready before its deadline.</p>
                <table v-else class="dtable">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Guest left</th>
                            <th>Deadline</th>
                            <th>Ready</th>
                            <th class="text-right">Late by</th>
                            <th>Housekeeper</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in report.late" :key="l.id">
                            <td class="num">{{ l.room }}</td>
                            <td>{{ formatDateTime(l.guest_left, 'time') }}</td>
                            <td>{{ formatDateTime(l.deadline, 'time') }}</td>
                            <td>{{ formatDateTime(l.ready, 'time') }}</td>
                            <td class="r">{{ l.late_minutes < 60 ? `${l.late_minutes} m` : formatDuration(l.late_minutes) }}</td>
                            <td><PersonPill v-if="l.by" :name="l.by" /><span v-else class="text-muted">—</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </BackOfficeLayout>
</template>
