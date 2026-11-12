<!-- Mockup 18 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Slip from '@/Components/Slip.vue';
import { formatDateTime, formatDuration } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';

/**
 * Check-out step 3 — done (18): how long the guest stayed, the deposit handed back, and the
 * room's new status with its clean-by time. "Print receipt" prints the 80 mm receipt.
 */
export interface DoneView {
    stayed_minutes: number;
    left_at: string | null;
    deposit_returned: number;
    deposit_forfeited: number;
    room_status: string | null;
    clean_by: string | null;
    receipt: { header: string; lines: { label: string; amount: number }[]; payments: { label: string; amount: number }[]; total: number; by: string };
}

const props = defineProps<{ room: string | null; guest: string; code: string; done: DoneView }>();

const statusWord = computed(() => ({ uncleaned: 'Uncleaned', available: 'Available', maintenance: 'Maintenance', cleaning: 'Cleaning' })[props.done.room_status ?? ''] ?? props.done.room_status);
const depositText = computed(() => {
    if (props.done.deposit_returned > 0) {
        return `${formatMoney(props.done.deposit_returned)} deposit returned`;
    }

    return props.done.deposit_forfeited > 0 ? `${formatMoney(props.done.deposit_forfeited)} deposit not claimed` : 'no deposit to return';
});
const sections = computed(() => [
    [
        { label: 'Stay', value: props.code },
        { label: 'Room', value: props.room ?? '' },
        { label: 'Guest', value: props.guest },
        { label: 'Out', value: formatDateTime(props.done.left_at, 'slip') },
    ],
    [...props.done.receipt.lines.map((l) => ({ label: l.label, value: formatMoney(l.amount, 'slip') })), { label: 'TOTAL', value: formatMoney(props.done.receipt.total, 'slip'), bold: true }],
    props.done.receipt.payments.map((p) => ({ label: p.label, value: formatMoney(p.amount, 'slip') })),
]);

function print(): void {
    window.print();
}
</script>

<template>
    <div>
        <section class="no-print flex flex-col items-center rounded-card border border-default bg-default px-6 py-12 text-center">
            <UIcon name="i-lucide-circle-check" class="mb-3 size-10 text-ok" aria-hidden="true" />
            <p class="text-[15px] font-semibold text-highlighted">Room {{ room }} is checked out</p>
            <p class="mt-1 text-sm text-muted">Stayed {{ formatDuration(done.stayed_minutes) }} · left {{ formatDateTime(done.left_at, 'auto') }} · {{ depositText }}</p>
            <p class="mt-1 text-[13px] text-muted">
                Room {{ room }} is now <b class="text-highlighted">{{ statusWord }}</b>
                <template v-if="done.clean_by"> and is in the housekeeper queue. Clean by {{ formatDateTime(done.clean_by, 'time') }}.</template>
                <template v-else>.</template>
            </p>
            <div class="mt-5 flex justify-center gap-2">
                <UButton label="Back to room board" @click="router.visit(route('board'))" />
                <UButton color="neutral" variant="outline" icon="i-lucide-printer" label="Print receipt" @click="print" />
            </div>
        </section>
        <div class="print-only">
            <Slip :header="done.receipt.header" title="RECEIPT" :sections="sections" :footer="[`Front desk: ${done.receipt.by}`, 'Thank you for staying with us.']" />
        </div>
    </div>
</template>
