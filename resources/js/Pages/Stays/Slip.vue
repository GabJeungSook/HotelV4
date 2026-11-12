<!-- Mockup 8 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';
import Slip from '@/Components/Slip.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Checked in — slip (8): the change to give, and the 80 mm check-in slip, which opens the print
 * dialog once. Shown only right after the check-in; the stay's page is the folio after that.
 */
const props = defineProps<{
    stay: { id: number; code: string; guest_name: string; room: string | null; type: string; checked_in_at: string | null; expected_checkout_at: string | null };
    slip: {
        header: string;
        deposit_name: string;
        lines: { label: string; amount: number }[];
        deposit: number | null;
        total: number;
        method: string;
        tendered: number | null;
        change: number;
        kept_as_deposit: number | null;
        reference_no: string | null;
        by: string;
    };
}>();

const m = (c: number) => formatMoney(c, 'slip');

const sections = computed(() => [
    [
        { label: 'Stay', value: props.stay.code },
        { label: 'Room', value: `${props.stay.room ?? ''} ${props.stay.type}` },
        { label: 'Guest', value: props.stay.guest_name },
        { label: 'In', value: formatDateTime(props.stay.checked_in_at, 'slip') },
        { label: 'Out', value: formatDateTime(props.stay.expected_checkout_at, 'slip') },
    ],
    [
        ...props.slip.lines.map((l) => ({ label: l.label, value: m(l.amount) })),
        ...(props.slip.deposit ? [{ label: `${props.slip.deposit_name} dep.`, value: m(props.slip.deposit) }] : []),
        { label: 'TOTAL', value: m(props.slip.total), bold: true },
        ...(props.slip.tendered !== null ? [{ label: props.slip.method, value: m(props.slip.tendered) }] : []),
        ...(props.slip.reference_no ? [{ label: 'Ref.', value: props.slip.reference_no }] : []),
        ...(props.slip.change > 0 ? [{ label: 'Change', value: m(props.slip.change) }] : []),
        ...(props.slip.kept_as_deposit ? [{ label: 'Kept as deposit', value: m(props.slip.kept_as_deposit) }] : []),
    ],
]);

const footer = computed(() => [`Front desk: ${props.slip.by}`, ...(props.slip.deposit ? ['Deposit is returned when the key and remote are returned.'] : [])]);
const giveText = computed(() => {
    const parts = [];

    if (props.slip.change > 0) {
        parts.push(`Give ${formatMoney(props.slip.change)} change`);
    }

    parts.push(parts.length ? 'and the key.' : 'Give the key.');

    return parts.join(' ');
});

function printSlip(): void {
    window.print();
}

onMounted(() => setTimeout(printSlip, 300));
</script>

<template>
    <FrontDeskLayout current="board" title="Checked in">
        <UAlert class="no-print mb-4" color="success" variant="subtle" icon="i-lucide-check" :title="`${stay.guest_name} is checked in to Room ${stay.room}.`" :description="giveText" />
        <div class="grid grid-cols-[minmax(0,340px)_220px] gap-6 max-[700px]:grid-cols-1">
            <Slip :header="slip.header" title="CHECK-IN SLIP" :sections="sections" :footer="footer" />
            <div class="no-print flex flex-col gap-2">
                <UButton block icon="i-lucide-printer" label="Print again" @click="printSlip" />
                <UButton block color="neutral" variant="outline" label="Open guest folio" @click="router.visit(route('stays.show', stay.id))" />
                <UButton block color="neutral" variant="ghost" label="Back to room board" @click="router.visit(route('board'))" />
            </div>
        </div>
    </FrontDeskLayout>
</template>
