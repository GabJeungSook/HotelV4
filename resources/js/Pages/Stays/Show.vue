<!-- Mockup 9 · 15 (cancel stay / void a line) · sheets 10, 11, 14 -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApprovalPin, { type Approver } from '@/Components/ApprovalPin.vue';
import type { ConfirmReason } from '@/Components/ConfirmDialog.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import type { PaymentMethodOption } from '@/Components/PaymentFields.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatDateTime, formatDuration } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { shortName, useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import type { Tone } from '@/types/ui';
import AddCharge, { type Product } from './AddCharge.vue';
import TakePayment from './TakePayment.vue';

/**
 * Guest folio (9): every line with when and by whom, the balance worked out by the server, and
 * the way to every other stay action. A line is never edited: "Void this line" (15) posts a
 * reversing line after a reason and a supervisor PIN (14).
 */
interface Line {
    id: number;
    kind: string;
    description: string;
    amount: number;
    paid: number;
    due: number;
    status: 'paid' | 'unpaid' | 'reversed';
    is_reversal: boolean;
    voidable: boolean;
    at: string | null;
    by: string;
}

const props = defineProps<{
    stay: {
        id: number;
        code: string;
        state: string;
        guest_name: string;
        guest_mobile: string | null;
        plate_number: string | null;
        room: string | null;
        type: string;
        checked_in_at: string | null;
        expected_checkout_at: string | null;
        checked_out_at: string | null;
        minutes_left: number | null;
        now: string;
    };
    lines: Line[];
    deposits: { type: string; label: string; amount: number }[];
    summary: { charges: number; paid: number; balance: number; deposit_held: number; guest_deposit: number };
    payable: { description: string; due: number }[];
    products: Product[];
    methods: PaymentMethodOption[];
    reasons: { void_line: ConfirmReason[]; cancel_stay: ConfirmReason[] };
    cancel: { refund: number; unpaid_count: number; unpaid_total: number };
    approvers: Approver[];
}>();

const { props: shared } = useShared();
const confirm = useConfirmDialog();

const inHouse = computed(() => props.stay.state === 'checked_in');
const stateLabel = computed<{ label: string; tone: Tone }>(
    () =>
        ({
            checked_in: { label: 'Checked in', tone: 'info' as Tone },
            checked_out: { label: 'Checked out', tone: 'neutral' as Tone },
            cancelled: { label: 'Cancelled', tone: 'neutral' as Tone },
        })[props.stay.state] ?? { label: props.stay.state, tone: 'neutral' as Tone },
);
const timeChip = computed(() => {
    const m = props.stay.minutes_left;

    if (!inHouse.value || m === null) {
        return null;
    }

    return m < 0 ? { tone: 'bad' as Tone, label: `${formatDuration(-m)} over` } : { tone: 'warn' as Tone, label: `${formatDuration(m)} left` };
});

const statusTone: Record<Line['status'], Tone> = { paid: 'ok', unpaid: 'warn', reversed: 'neutral' };
const statusText: Record<Line['status'], string> = { paid: 'Paid', unpaid: 'Unpaid', reversed: 'Reversed' };

// Sheets.
const adding = ref(false);
const paying = ref(false);
const pendingCharge = ref<{ items: Record<string, number>; total: number; key: string } | null>(null);

function openPayment(): void {
    pendingCharge.value = null;
    paying.value = true;
}

function payWithCharge(charge: { items: Record<string, number>; total: number; key: string }): void {
    pendingCharge.value = charge;
    paying.value = true;
}

// Void a line / cancel the stay: reason (15), then the supervisor PIN (14).
const approval = useForm({ reason_id: null as number | null, note: '' as string | null, approver_id: null as number | null, pin: '' });
const approving = ref(false);
const approvalSummary = ref('');
const approvalUrl = ref('');
const approvalError = ref<string | undefined>(undefined);
const attempt = ref(0);

async function voidLine(line: Line): Promise<void> {
    const parts = [`**${line.description}** (${formatMoney(line.amount)}) will be reversed by a new line; nothing is deleted.`];

    if (line.paid > 0) {
        parts.push(`**${formatMoney(line.paid)} cash must be returned** to the guest from the drawer.`);
    }

    const result = await confirm({
        title: `Void ${line.description}?`,
        description: parts.join(' '),
        confirmLabel: 'Void this line',
        cancelLabel: 'Keep line',
        confirmColor: 'error',
        reasons: props.reasons.void_line,
        needsApproval: true,
        loggedAs: shared.value.auth ? shortName(shared.value.auth.user.name) : undefined,
    });

    if (result) {
        askApproval(route('stays.void', [props.stay.id, line.id]), `Void ${line.description} · ${formatMoney(line.amount)}`, result.reasonId, result.note);
    }
}

async function cancelStay(): Promise<void> {
    const parts = [`Room ${props.stay.room} will go back to **Available**. Every charge and deposit will be reversed, and **${formatMoney(props.cancel.refund)} cash must be returned** to the guest from the drawer.`];

    if (props.cancel.unpaid_count > 0) {
        parts.push(`${props.cancel.unpaid_count} unpaid ${props.cancel.unpaid_count === 1 ? 'line' : 'lines'} (${formatMoney(props.cancel.unpaid_total)}) will be voided and the stock put back.`);
    }

    const result = await confirm({
        title: 'Cancel this stay?',
        description: parts.join(' '),
        confirmLabel: 'Cancel stay',
        cancelLabel: 'Keep stay',
        confirmColor: 'error',
        reasons: props.reasons.cancel_stay,
        needsApproval: true,
        loggedAs: shared.value.auth ? shortName(shared.value.auth.user.name) : undefined,
    });

    if (result) {
        askApproval(route('stays.cancel', props.stay.id), `Cancel stay ${props.stay.code} · return ${formatMoney(props.cancel.refund)}`, result.reasonId, result.note);
    }
}

function askApproval(url: string, summary: string, reasonId: number | null, note: string | null): void {
    approval.reason_id = reasonId;
    approval.note = note;
    approvalUrl.value = url;
    approvalSummary.value = summary;
    approvalError.value = undefined;
    approving.value = true;
}

function approve(payload: { approver_id: number; pin: string }): void {
    approval.approver_id = payload.approver_id;
    approval.pin = payload.pin;
    approval.post(approvalUrl.value, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string } } | undefined)?.error;

            if (e) {
                approvalError.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
                attempt.value++;

                return;
            }

            approving.value = false;
        },
    });
}

function lineMenu(line: Line) {
    return [[{ label: 'Void this line', icon: 'i-lucide-undo-2', color: 'error' as const, onSelect: () => voidLine(line) }]];
}
</script>

<template>
    <FrontDeskLayout current="board" :title="`Room ${stay.room ?? ''} · ${stay.guest_name}`">
        <div class="mb-1 flex flex-wrap items-center gap-2">
            <StatusLabel :tone="stateLabel.tone" :label="stateLabel.label" />
            <StatusLabel v-if="timeChip" :tone="timeChip.tone" :label="timeChip.label" />
        </div>
        <p class="mb-4 text-[13px] text-muted">
            {{ stay.type }} · in {{ formatDateTime(stay.checked_in_at, 'auto', stay.now) }} ·
            <template v-if="stay.checked_out_at">left {{ formatDateTime(stay.checked_out_at, 'auto', stay.now) }}</template>
            <template v-else>expected out {{ formatDateTime(stay.expected_checkout_at, 'auto', stay.now) }}</template>
            · stay {{ stay.code }}<template v-if="stay.guest_mobile"> · {{ stay.guest_mobile }}</template><template v-if="stay.plate_number"> · {{ stay.plate_number }}</template>
        </p>

        <div class="grid grid-cols-[minmax(0,1fr)_300px] gap-4 max-[900px]:grid-cols-1">
            <section class="overflow-hidden rounded-card border border-default bg-default">
                <table class="dtable w-full">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Status</th>
                            <th class="w-10"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in lines" :key="line.id" :data-line="line.id">
                            <td>
                                <span :class="line.status === 'reversed' && !line.is_reversal ? 'text-muted line-through' : 'text-highlighted'">{{ line.description }}</span>
                                <span class="block text-xs text-muted">{{ formatDateTime(line.at, 'auto', stay.now) }} · {{ line.by }}</span>
                            </td>
                            <td class="num text-right">{{ formatMoney(line.amount, 'column') }}</td>
                            <td class="text-right"><StatusLabel :tone="statusTone[line.status]" :label="statusText[line.status]" /></td>
                            <td class="text-right">
                                <UDropdownMenu v-if="line.voidable && inHouse" :items="lineMenu(line)" :content="{ align: 'end' }">
                                    <UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-ellipsis" :aria-label="`Actions for ${line.description}`" />
                                </UDropdownMenu>
                            </td>
                        </tr>
                        <template v-for="d in deposits" :key="d.type">
                            <tr v-if="d.amount > 0">
                                <td><span class="text-highlighted">{{ d.label }}</span><span class="block text-xs text-muted">returned at check-out</span></td>
                                <td class="num text-right">{{ formatMoney(d.amount, 'column') }}</td>
                                <td class="text-right"><StatusLabel tone="primary" label="Held" /></td>
                                <td />
                            </tr>
                        </template>
                    </tbody>
                </table>
            </section>

            <aside class="flex flex-col gap-2">
                <section class="rounded-card border border-default bg-default p-4">
                    <div class="flex flex-col gap-1.5">
                        <MoneyRow label="Charges" :amount="summary.charges" />
                        <MoneyRow label="Paid" :amount="summary.paid" />
                    </div>
                    <TotalRow label="Balance due" :amount="summary.balance" :tone="summary.balance > 0 ? 'bad' : 'ok'" />
                    <MoneyRow class="mt-3" label="Deposit held" :amount="summary.deposit_held" />
                </section>
                <template v-if="inHouse">
                    <UButton block label="Take payment" :disabled="summary.balance <= 0" @click="openPayment" />
                    <UButton block color="neutral" variant="outline" icon="i-lucide-plus" label="Add charge" @click="adding = true" />
                    <div class="grid grid-cols-2 gap-2">
                        <UButton block color="neutral" variant="outline" label="Extend" @click="router.visit(route('stays.extend', stay.id))" />
                        <UButton block color="neutral" variant="outline" label="Transfer" @click="router.visit(route('stays.transfer', stay.id))" />
                    </div>
                    <UButton block color="neutral" variant="outline" label="Check out" @click="router.visit(route('stays.checkout', stay.id))" />
                    <UButton block color="error" variant="ghost" label="Cancel stay" @click="cancelStay" />
                </template>
                <UButton v-else block color="neutral" variant="outline" label="Back to room board" @click="router.visit(route('board'))" />
            </aside>
        </div>

        <AddCharge v-model:open="adding" :stay-id="stay.id" :products="products" @pay-now="payWithCharge" />
        <TakePayment
            v-model:open="paying"
            :stay-id="stay.id"
            :room="stay.room"
            :lines="payable"
            :balance="summary.balance"
            :guest-deposit="summary.guest_deposit"
            :methods="methods"
            :charge="pendingCharge"
        />
        <ApprovalPin
            v-model:open="approving"
            :summary="approvalSummary"
            :approvers="approvers"
            :error="approvalError"
            :locked="shared.pinLocked"
            :processing="approval.processing"
            :attempt="attempt"
            @approve="approve"
        />
    </FrontDeskLayout>
</template>
