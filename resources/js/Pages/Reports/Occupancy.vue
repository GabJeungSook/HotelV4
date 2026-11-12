<!-- Mockup 40 -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CheckResult from '@/Components/CheckResult.vue';
import DateNav from '@/Components/DateNav.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useReport } from '@/Composables/useReport';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Occupancy and guests (40) for a business day: guests in house at the start, checked in,
 * checked out (who actually left) and in house at the end, from the stays' real check-in and
 * check-out times, so start + in − out = end. Each count opens the stays behind it.
 */
interface StayRow {
    id: number;
    code: string;
    guest_name: string;
    checked_in_at: string;
    checked_out_at: string | null;
    expected_checkout_at: string | null;
    state: string;
}

type ListKey = 'start' | 'in' | 'out' | 'end' | 'cancelled' | 'overstayed';

const props = defineProps<{
    report: {
        date: string;
        start: number;
        in: number;
        out: number;
        end: number;
        cancelled: number;
        types: { id: number; name: string; rooms: number; checkins: number; extensions: number; transfers: number; overstayed: number }[];
        total: { rooms: number; checkins: number; extensions: number; transfers: number; overstayed: number };
        lists: Record<ListKey, StayRow[]>;
        checks: string[];
    };
}>();

const r = useReport();
const date = ref(props.report.date);
watch(date, (d) => r.go('reports.occupancy', {}, { date: d }));
const title = computed(() => `Occupancy & guests · ${formatDateTime(`${props.report.date}T12:00:00+08:00`, 'businessDate').replace(',', '')}`);

const listKey = ref<ListKey | null>(null);
const listOpen = computed({ get: () => listKey.value !== null, set: (v: boolean) => !v && (listKey.value = null) });
const listTitle: Record<ListKey, string> = { start: 'In house at start', in: 'Checked in', out: 'Checked out', end: 'In house at end', cancelled: 'Cancelled after check-in', overstayed: 'Overstayed' };

const cards = computed(() => [
    { label: 'In house at start', value: String(props.report.start) },
    { label: 'Checked in', value: String(props.report.in) },
    { label: 'Checked out', value: String(props.report.out) },
    { label: 'In house at end', value: String(props.report.end) },
]);
</script>

<template>
    <BackOfficeLayout current="occupancy" :title="title">
        <Head title="Occupancy" />
        <PageHeader>
            <ExportMenu csv :print="false" @csv="r.download(route('reports.occupancy', { date: report.date, export: 'csv' }))" />
        </PageHeader>
        <div class="no-print mb-4"><DateNav v-model="date" /></div>

        <div class="print-a4">
            <div class="grid grid-cols-4 gap-3 max-[767px]:grid-cols-2">
                <button v-for="(card, i) in cards" :key="card.label" type="button" class="hm-focus cursor-pointer text-left" @click="listKey = (['start', 'in', 'out', 'end'] as ListKey[])[i]">
                    <StatCard v-bind="card" />
                </button>
            </div>

            <div class="mt-4 overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
                <table class="dtable">
                    <thead>
                        <tr>
                            <th>Room type</th>
                            <th class="text-right">Rooms</th>
                            <th class="text-right">Check-ins</th>
                            <th class="text-right">Extensions</th>
                            <th class="text-right">Transfers</th>
                            <th class="text-right">Overstayed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in report.types" :key="t.id">
                            <td>{{ t.name }}</td>
                            <td class="r">{{ t.rooms }}</td>
                            <td class="r">{{ t.checkins }}</td>
                            <td class="r">{{ t.extensions }}</td>
                            <td class="r">{{ t.transfers }}</td>
                            <td class="r">{{ t.overstayed }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="r">{{ report.total.rooms }}</td>
                            <td class="r">{{ report.total.checkins }}</td>
                            <td class="r">{{ report.total.extensions }}</td>
                            <td class="r">{{ report.total.transfers }}</td>
                            <td class="r">
                                <button v-if="report.total.overstayed" type="button" class="hm-focus cursor-pointer hover:underline" @click="listKey = 'overstayed'">{{ report.total.overstayed }}</button>
                                <span v-else>0</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="mt-2 text-[13px] text-muted">
                {{ report.start }} in house + {{ report.in }} in − {{ report.out }} out = {{ report.end }}. "Checked out" counts guests who actually left, not guests who were due.
                <template v-if="report.cancelled">
                    <button type="button" class="hm-focus cursor-pointer underline" @click="listKey = 'cancelled'">{{ report.cancelled }} {{ report.cancelled === 1 ? 'stay was' : 'stays were' }} cancelled after check-in</button>
                    and {{ report.cancelled === 1 ? 'is' : 'are' }} not counted as guests.
                </template>
            </p>
            <CheckResult :problems="report.checks" />
        </div>

        <USlideover v-model:open="listOpen" side="right" :overlay="false" :title="listKey ? listTitle[listKey] : ''" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <p v-if="listKey && report.lists[listKey].length === 0" class="text-sm text-muted">No stays.</p>
                <table v-else-if="listKey" class="dtable">
                    <tbody>
                        <tr v-for="s in report.lists[listKey]" :key="s.id">
                            <td class="!whitespace-normal">
                                <p class="text-highlighted">{{ s.guest_name }}</p>
                                <p class="text-xs text-muted">{{ s.code }} · in {{ formatDateTime(s.checked_in_at, 'dayTime') }}<template v-if="s.checked_out_at"> · out {{ formatDateTime(s.checked_out_at, 'dayTime') }}</template></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </USlideover>
    </BackOfficeLayout>
</template>
