<script setup lang="ts">
import { onMounted, ref } from 'vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import OverdueBar from '@/Components/OverdueBar.vue';
import PinPad from '@/Components/PinPad.vue';
import RoomTable, { type BoardFloor } from '@/Components/RoomTable.vue';
import Slip from '@/Components/Slip.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import StepBar from '@/Components/StepBar.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatMoney } from '@/Composables/useMoney';
import Block from './Block.vue';

defineProps<{ kiosk: boolean; phone: boolean; state: 'data' | 'loading' | 'empty' }>();

const toast = useToast();
const confirm = useConfirmDialog();

/** A fixed "now" so the board's times read like the mockup: Wed 11 Nov 2026, 11:44 AM Manila. */
const now = '2026-11-11T03:44:00Z';

const stay = ref<string | null>('12');
const stayOptions = [
    { value: '6', label: 'Normal', sub: '₱450' },
    { value: '12', label: 'Selected', sub: '₱650' },
    { value: '24', label: 'Unavailable', disabled: true, reason: 'no rate' },
];
const payment = ref<string | null>('cash');
const paymentOptions = [
    { value: 'cash', label: 'Cash' },
    { value: 'gcash', label: 'GCash', sub: 'ref. no.' },
    { value: 'card', label: 'Card', sub: 'ref. no.' },
];

const name = ref('Rico Manalastas');
const focusedAmount = ref(100000);
const cash = ref(0);
const errorAmount = ref(0);

const floors: BoardFloor[] = [
    {
        label: 'Floor 1',
        rooms: [
            { id: 1, number: '101', type: 'Standard', status: 'vacant' },
            { id: 6, number: '106', type: 'Standard', status: 'due_out', guest: 'L. Ramos', dueOutAt: '2026-11-11T04:06:00Z', minutes: 22 },
            { id: 7, number: '108', type: 'Standard', status: 'blocked' },
            { id: 8, number: '110', type: 'Standard', status: 'vacant', reservedAt: '2026-11-11T06:00:00Z' },
            { id: 9, number: '112', type: 'Standard', status: 'held', holdSeconds: 402 },
            { id: 10, number: '117', type: 'Standard', status: 'overdue', guest: 'D. Abella', dueOutAt: '2026-11-11T03:02:00Z', minutes: -42, balance: 15000 },
        ],
    },
    {
        label: 'Floor 2',
        rooms: [
            { id: 11, number: '203', type: 'Deluxe', status: 'to_clean', minutes: 48 },
            { id: 12, number: '204', type: 'Deluxe', status: 'cleaning', minutes: 11 },
            { id: 13, number: '205', type: 'Deluxe', status: 'occupied', guest: 'R. Manalastas', dueOutAt: '2026-11-11T13:12:00Z', minutes: 568, balance: 11500 },
        ],
    },
];

const pin = ref<InstanceType<typeof PinPad> | null>(null);
const pinMessage = ref('');
onMounted(() => {
    pin.value?.press('1');
    pin.value?.press('2');
});

const reasons = [
    { id: 1, name: 'Guest changed their mind', needs_note: false, needs_manager: false },
    { id: 2, name: 'Other', needs_note: true, needs_manager: false },
    { id: 3, name: 'Wrong amount charged', needs_note: true, needs_manager: true },
];

const lastResult = ref('');

async function openConfirm(withReasons: boolean): Promise<void> {
    const result = await confirm({
        title: withReasons ? 'Cancel stay in Room 205?' : 'Release Room 210?',
        description: withReasons
            ? 'The stay is cancelled and **₱650.00** is refunded from the drawer. The room goes to To clean.'
            : "Carlo Dumandan's hold will be cancelled and the room goes back on sale.",
        confirmLabel: withReasons ? 'Cancel stay' : 'Release room',
        cancelLabel: withReasons ? 'Keep stay' : 'Keep hold',
        confirmColor: 'error',
        reasons: withReasons ? reasons : undefined,
        loggedAs: 'Jessa Mae D.',
    });
    lastResult.value = result === false ? 'Resolved: false (kept)' : `Resolved: ${JSON.stringify(result)}`;
}

const slipSections = [
    [
        { label: 'Stay', value: 'DGS-26-004126' },
        { label: 'Room', value: '205 · Deluxe' },
        { label: 'In', value: '11 Nov 2026 09:12 AM' },
        { label: 'Out', value: '11 Nov 2026 09:12 PM' },
    ],
    [
        { label: 'Room 12 hrs', value: formatMoney(65000, 'slip') },
        { label: 'Discount', value: formatMoney(-5000, 'slip') },
        { label: 'Key deposit', value: formatMoney(20000, 'slip') },
        { label: 'TOTAL', value: formatMoney(80000, 'slip'), bold: true },
        { label: 'Cash', value: formatMoney(100000, 'slip') },
        { label: 'Change', value: formatMoney(20000, 'slip') },
    ],
];

const tableRows = [
    { time: '9:12 AM', code: 'DGS-26-004126', what: 'Room · Deluxe 12 hrs', amount: 65000, void: false },
    { time: '9:14 AM', code: 'DGS-26-004126', what: 'Discount · Promo', amount: -5000, void: false },
    { time: '9:20 AM', code: 'DGS-26-004126', what: 'Cup noodles × 2', amount: 9000, void: true },
];

function saveToast(): void {
    toast.add({ title: 'Payment saved. Change ₱85.00', color: 'success', icon: 'i-lucide-check' });
}
</script>

<template>
    <div class="grid gap-4" :class="phone ? 'grid-cols-1' : 'grid-cols-[repeat(auto-fill,minmax(min(100%,330px),1fr))]'">
        <Block title="Buttons — UButton">
            <div class="flex flex-wrap gap-2">
                <UButton label="Primary" />
                <UButton color="neutral" variant="outline" label="Secondary" />
                <UButton color="primary" variant="link" label="Text" />
                <UButton color="neutral" variant="ghost" label="Cancel" />
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <UButton color="error" variant="outline" label="Void" />
                <UButton color="error" label="Release room" />
                <UButton label="Disabled" disabled />
                <UButton color="neutral" variant="outline" label="Disabled" disabled />
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <UButton label="Saving…" loading />
                <UButton icon="i-lucide-lock" label="Needs approval" />
                <UButton color="neutral" variant="outline" icon="i-lucide-plus" label="Add" />
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <UButton size="sm" label="sm 28" color="neutral" variant="outline" />
                <UButton size="md" label="md 32" color="neutral" variant="outline" />
                <UButton size="lg" label="lg 36" color="neutral" variant="outline" />
            </div>
            <UButton class="mt-3" size="xl" block label="Large (main action)" />
            <UButton v-if="phone" class="mt-3 h-12" size="xl" block label="Start cleaning" />
            <UButton v-if="kiosk" class="mt-3 h-[72px] text-2xl" size="xl" block label="Tap to start" />
        </Block>

        <Block title="Inputs — UFormField + UInput, MoneyInput">
            <div class="grid grid-cols-2 gap-x-3 gap-y-3.5 max-sm:grid-cols-1">
                <UFormField label="Normal" name="g-normal">
                    <UInput v-model="name" class="w-full" />
                </UFormField>
                <UFormField label="Focused" name="g-focused">
                    <MoneyInput v-model="focusedAmount" autofocus />
                </UFormField>
                <UFormField label="Error" name="g-error" error="Enter all 11 digits.">
                    <UInput model-value="0917 55" class="w-full" />
                </UFormField>
                <UFormField label="Disabled" name="g-disabled">
                    <UInput model-value="Dalisay GenSan" disabled class="w-full" />
                </UFormField>
                <UFormField label="Cash received" name="g-cash" hint="Due ₱800.00">
                    <MoneyInput v-model="cash" :due="80000" size="xl" />
                </UFormField>
                <UFormField label="Amount" name="g-amount" error="Enter an amount above ₱0.00.">
                    <MoneyInput v-model="errorAmount" error="Enter an amount above ₱0.00." />
                </UFormField>
                <UFormField label="Disabled amount" name="g-amount-off">
                    <MoneyInput :model-value="45000" disabled />
                </UFormField>
                <UFormField label="Search" name="g-search">
                    <UInput icon="i-lucide-search" placeholder="Room or guest" class="w-full" />
                </UFormField>
            </div>
            <p class="mt-2 text-xs text-muted">Values: {{ formatMoney(focusedAmount) }} · {{ formatMoney(cash) }} (integer centavos {{ cash }})</p>
            <UInput v-if="kiosk" class="kiosk mt-3 w-full" size="xl" :ui="{ base: 'h-[72px] text-[28px]' }" placeholder="Your name" />
        </Block>

        <Block title="Choice tiles — OptionTiles · Steps — StepBar">
            <OptionTiles v-model="stay" :options="stayOptions" label="Stay length" />
            <OptionTiles v-model="payment" class="mt-3" :options="paymentOptions" label="Payment method" add-label="Add" />
            <OptionTiles v-if="kiosk" v-model="stay" class="mt-3" :options="stayOptions" :columns="2" size="kiosk" label="Hours (kiosk)" />
            <StepBar class="mt-4" :steps="['Done', 'Current', 'Next']" :current="2" />
            <StepBar class="mt-2" :steps="['Check', 'Count cash', 'Result', 'Handover']" :current="3" />
            <StepBar v-if="kiosk" class="mt-2" :steps="['Room', 'Your name', 'Pay at desk']" :current="1" size="kiosk" />
        </Block>

        <Block title="Status — StatusLabel · Alerts — UAlert">
            <div class="flex flex-wrap gap-2">
                <StatusLabel tone="ok" label="Paid" />
                <StatusLabel tone="warn" label="Unpaid" />
                <StatusLabel tone="primary" label="Held" />
                <StatusLabel tone="info" label="Occupied" />
                <StatusLabel tone="bad" label="Overdue" />
                <StatusLabel tone="violet" label="Cleaning" />
                <StatusLabel tone="clay" label="To clean" />
                <StatusLabel label="Closed" />
            </div>
            <div class="mt-2 flex flex-wrap gap-2">
                <StatusLabel tone="bad" label="FWD" />
                <StatusLabel tone="warn" label="EXT" />
                <StatusLabel tone="warn" size="lg" label="6:42 left" />
            </div>
            <div class="mt-3 flex flex-col gap-2">
                <UAlert color="success" icon="i-lucide-check" title="Success" />
                <UAlert color="warning" icon="i-lucide-triangle-alert" title="Warning, can continue" />
                <UAlert color="error" icon="i-lucide-triangle-alert" title="Error, cannot continue" role="alert" />
                <UAlert color="info" icon="i-lucide-clock" title="Note: check-out is at 12:00 NN" />
            </div>
        </Block>

        <Block title="Shift running late — OverdueBar (54)" wide>
            <div class="flex flex-col gap-2 overflow-hidden rounded-card border border-default">
                <OverdueBar period-name="AM" ended-at="2026-11-11T12:00:00Z" :minutes-past="40" />
                <OverdueBar period-name="AM" ended-at="2026-11-11T12:00:00Z" :minutes-past="245" blocked />
                <OverdueBar period-name="AM" ended-at="2026-11-11T12:00:00Z" :minutes-past="40" :closable="false" />
            </div>
        </Block>

        <Block title="Room table — RoomTable, RoomRow" wide>
            <RoomTable :floors="state === 'empty' ? [] : floors" :loading="state === 'loading'" :now="now" @select="(r) => toast.add({ title: `Room ${r.number} selected`, color: 'info' })" />
            <p class="mt-2 text-sm text-default">Rows are never tinted. Only the status label has colour; the time turns ochre when due out and brick when overdue.</p>
            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                <div class="min-w-0">
                    <p class="mb-2 text-xs text-muted">filter="occupied" · sort="attention"</p>
                    <RoomTable :floors="floors" filter="occupied" sort="attention" :now="now" />
                </div>
                <div class="min-w-0">
                    <p class="mb-2 text-xs text-muted">limit=2 (compact view)</p>
                    <RoomTable :floors="floors" :limit="2" :now="now" />
                </div>
            </div>
        </Block>

        <Block title="Money rows — MoneyRow, TotalRow · Stat card">
            <MoneyRow label="Room · Deluxe 12 hrs" :amount="65000" />
            <MoneyRow label="Discount" :amount="-5000" />
            <MoneyRow label="Expected out" text="Wed 9:12 PM" />
            <TotalRow label="Total" :amount="60000" />
            <MoneyRow class="mt-2" label="Change" :amount="8500" tone="ok" />
            <MoneyRow label="Balance due" :amount="11500" tone="bad" />
            <div class="mt-4 grid grid-cols-2 gap-3">
                <StatCard label="Checked in" :value="58" />
                <StatCard label="Short" :value="formatMoney(-5000)" tone="bad" />
                <StatCard label="Sales today" value="₱9,420" sub="8% vs yesterday" trend="up" to="#dash" />
                <StatCard label="Occupancy now" value="50%" sub="23 of 46 rooms" />
            </div>
        </Block>

        <Block title="PIN pad — PinPad">
            <PinPad ref="pin" :keyboard="false" :size="kiosk || phone ? 'touch' : 'md'" @complete="pinMessage = 'complete emitted (digits not shown)'" />
            <p class="mt-2 text-center text-xs text-muted">{{ pinMessage || 'Two digits typed' }}</p>
            <div class="mt-4 flex flex-col gap-4 border-t border-default pt-4">
                <PinPad :keyboard="false" :show-keys="false" error="PIN not recognised" :tries-left="2" />
                <PinPad :keyboard="false" :show-keys="false" locked />
            </div>
        </Block>

        <Block title="Confirm dialog — ConfirmDialog">
            <ConfirmDialog
                inline
                title="Release Room 210?"
                description="Carlo Dumandan's hold will be cancelled and the room goes back on sale."
                confirm-label="Release room"
                cancel-label="Keep hold"
                confirm-color="error"
                @close="(r) => (lastResult = r === false ? 'Inline: kept' : 'Inline: confirmed')"
            />
            <div class="mt-3 flex flex-wrap gap-2">
                <UButton color="neutral" variant="outline" label="Open dialog" @click="openConfirm(false)" />
                <UButton color="neutral" variant="outline" icon="i-lucide-lock" label="Open with reasons" @click="openConfirm(true)" />
            </div>
            <p v-if="lastResult" class="mt-2 text-xs break-all text-muted" role="status">{{ lastResult }}</p>
        </Block>

        <Block title="Empty and loading — EmptyState, USkeleton">
            <EmptyState title="No expenses yet this shift" text="Expenses you record appear here." icon="i-lucide-list" />
            <EmptyState title="All rooms are clean" icon="i-lucide-check" tone="ok" action-label="Check again" />
            <EmptyState compact title="No sales in this period" />
            <USkeleton class="mt-2 h-3.5 w-full" />
            <USkeleton class="mt-1.5 h-3.5 w-[70%]" />
            <EmptyState v-if="kiosk" title="All rooms are taken" text="Please ask at the front desk." icon="i-lucide-bed" size="kiosk" />
        </Block>

        <Block title="Slip — 80 mm thermal (preview)">
            <Slip preview header="Hotel Dalisay GenSan" title="CHECK-IN SLIP" :sections="slipSections" :footer="['Front desk: Jessa Mae D.', 'Keep this slip.']" />
            <Slip class="mt-3" preview reprint header="Hotel Dalisay GenSan" title="SHIFT REPORT · AM · 11 NOV 2026" number="Z-0629" :sections="[[{ label: 'Cash collected', value: formatMoney(1816000, 'slip'), bold: true }]]" />
        </Block>

        <Block title="Tables — .dtable" wide>
            <div class="overflow-x-auto">
                <table class="dtable">
                    <thead>
                        <tr>
                            <th scope="col">Time</th>
                            <th scope="col">Stay</th>
                            <th scope="col">Line</th>
                            <th scope="col" class="r">Amount ₱</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="grp">
                            <td colspan="4">Room 205 · Deluxe</td>
                        </tr>
                        <tr v-for="row in tableRows" :key="row.what" :class="row.void ? 'void' : 'link'">
                            <td>{{ row.time }}</td>
                            <td class="code">{{ row.code }}</td>
                            <td>{{ row.what }}</td>
                            <td class="r">{{ formatMoney(row.amount, 'column') }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">Total</td>
                            <td class="r">{{ formatMoney(60000, 'column') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="mt-3 mb-1 text-xs text-muted">dense</p>
            <table class="dtable dense">
                <thead>
                    <tr>
                        <th scope="col">Room</th>
                        <th scope="col">Guest</th>
                        <th scope="col" class="r">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="num">117</td>
                        <td>D. Abella</td>
                        <td class="r">150.00</td>
                    </tr>
                    <tr class="forwarded">
                        <td class="num">208</td>
                        <td>G. Tan <StatusLabel tone="bad" label="FWD" /></td>
                        <td class="r">0.00</td>
                    </tr>
                </tbody>
            </table>
            <UButton class="mt-3" color="neutral" variant="outline" label="Show a toast" @click="saveToast" />
        </Block>
    </div>
</template>
