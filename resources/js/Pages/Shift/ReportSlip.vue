<!-- Mockup 26 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Slip from '@/Components/Slip.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { shortName, useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Shift report slip (26): the frozen record of the close, with its Z number, read from the
 * shift's totals and handover; reprinting it later gives the same figures. Every print is logged
 * first (print_logs); a second print says REPRINT. The outgoing front desk then signs out.
 */
type Row = { label: string; amount?: number; text?: string; bold?: boolean };

const props = defineProps<{
    closed: {
        id: number;
        period_name: string;
        business_date: string;
        status: string;
        z_no: number | null;
        opened_at: string;
        closed_at: string | null;
        opened_by: string;
        closed_by: string;
        with: string[];
        is_force_closed: boolean;
        variance_note: string | null;
    };
    header: string;
    sections: Row[][];
    handover: { received_by: string | null; received_cash: number | null; difference: number; note: string | null } | null;
}>();

const { props: shared } = useShared();
const reprint = ref(false);
const printing = ref(false);
const printError = ref<string | undefined>(undefined);

const zNo = computed(() => (props.closed.z_no === null ? undefined : `Z-${String(props.closed.z_no).padStart(4, '0')}`));
const title = computed(() => `SHIFT REPORT · ${props.closed.period_name} · ${formatDateTime(`${props.closed.business_date}T12:00:00+08:00`, 'date').toUpperCase()}`);
const time = (iso: string | null) => formatDateTime(iso, 'slip').replace(/^\d{1,2} \w{3} \d{4} /, '');

const sections = computed(() => [
    [
        { label: `Opened ${time(props.closed.opened_at)}`, value: shortName(props.closed.opened_by) },
        { label: `Closed ${time(props.closed.closed_at)}`, value: shortName(props.closed.closed_by) },
        ...props.closed.with.map((name) => ({ label: 'With', value: name })),
        ...(props.closed.is_force_closed ? [{ label: 'FORCE CLOSED', bold: true }] : []),
    ],
    ...props.sections.map((rows) => rows.map((r) => ({ label: r.label, value: r.text ?? formatMoney(r.amount ?? 0, 'slip'), bold: r.bold }))),
]);

const footer = computed(() => {
    if (!props.handover?.received_by) {
        return ['Closed without handover'];
    }

    const lines = [`Received by ${shortName(props.handover.received_by)}`];

    if (props.handover.difference !== 0) {
        lines.push(`Drawer difference ${formatMoney(props.handover.difference, 'slip')}`);
    }

    return lines;
});

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function print(): Promise<void> {
    printing.value = true;
    printError.value = undefined;

    try {
        const response = await fetch(route('shift.report.print', props.closed.id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        reprint.value = ((await response.json()) as { reprint: boolean }).reprint;
        setTimeout(() => window.print(), 50);
    } catch {
        printError.value = 'The print could not be logged, so it was not printed. Try again.';
    } finally {
        printing.value = false;
    }
}

function signOut(): void {
    router.post(route('logout'));
}
</script>

<template>
    <FrontDeskLayout current="shift" guide title="Shift report">
        <PageHeader>
            <UButton v-if="shared.auth" color="neutral" variant="outline" icon="i-solar-logout-2-bold-duotone" label="Sign out" @click="signOut" />
            <UButton icon="i-solar-printer-2-bold-duotone" label="Print" :loading="printing" data-print @click="print" />
        </PageHeader>
        <div class="no-print mb-4">
            <UAlert
                class="max-w-[560px]"
                color="success"
                variant="subtle"
                icon="i-solar-check-circle-linear"
                :title="`The ${closed.period_name} shift is closed${zNo ? ` as ${zNo}` : ''}.`"
                :description="handover?.received_by ? `${handover.received_by} received the drawer. Print the slip, then sign out.` : 'Nobody received the drawer. The next front desk uses Open shift.'"
            />
        </div>
        <UAlert v-if="printError" class="no-print mb-4" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="printError" />
        <Slip :header="header" :title="title" :number="zNo" :sections="sections" :footer="footer" :reprint="reprint" preview />
    </FrontDeskLayout>
</template>
