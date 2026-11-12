<!-- Mockup 36 -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import CheckResult, { type Difference } from '@/Components/CheckResult.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Report hub (36): one card per report, and the result of the check of the previous business
 * date (the same check as the nightly ReconcileBusinessDay job: shift totals, daily totals and
 * drawer cash recomputed from the ledger).
 */
const props = defineProps<{
    branch: { id: number; name: string };
    check: { date: string; checked: boolean; differences: Difference[] };
    latest_shift_id: number | null;
    today: string;
}>();

const cards = computed(() => [
    { title: 'Shift reports', text: 'Cash, sales and over/short for each shift', href: route('reports.shifts') },
    { title: 'Daily sales', text: 'Sales by category, by day, week or month', href: route('reports.daily') },
    { title: 'Z-report', text: 'Per-room chart and floor totals for one shift', href: props.latest_shift_id ? route('reports.z', props.latest_shift_id) : route('reports.shifts') },
    { title: 'Occupancy & guests', text: 'Check-ins, check-outs, extensions, transfers', href: route('reports.occupancy') },
    { title: 'Housekeeping', text: 'Cleans, time taken, late rooms', href: route('reports.housekeeping') },
    { title: 'Inventory', text: 'Stock in, sold, wastage, on hand', href: route('reports.inventory') },
    { title: 'Audit trail', text: 'Voids, cancels, transfers, approvals', href: route('reports.audit') },
]);

const day = computed(() => formatDateTime(`${props.check.date}T12:00:00+08:00`, 'date').replace(/ \d{4}$/, ''));
</script>

<template>
    <BackOfficeLayout current="reports" :title="`Reports · ${branch.name.replace(/^Hotel /, '')}`">
        <Head title="Reports" />
        <div class="grid grid-cols-3 gap-4 max-[900px]:grid-cols-2 max-[560px]:grid-cols-1">
            <Link v-for="card in cards" :key="card.title" :href="card.href" class="hm-focus block rounded-card border border-default bg-default px-[18px] py-4 transition-colors duration-120 ease-out hover:bg-elevated">
                <b class="text-highlighted">{{ card.title }}</b>
                <p class="mt-0.5 text-[13px] text-muted">{{ card.text }}</p>
            </Link>
        </div>

        <CheckResult
            :checked="check.checked"
            :differences="check.differences"
            :pass-text="`Last night's check passed: shift totals, daily totals and drawer cash all agree for ${day}.`"
            :idle-text="`No closed shift on ${day} to check.`"
        />
    </BackOfficeLayout>
</template>
