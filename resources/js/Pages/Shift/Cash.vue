<!-- Mockup 21 -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApprovalPin, { type Approver } from '@/Components/ApprovalPin.vue';
import type { ConfirmReason } from '@/Components/ConfirmDialog.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import PersonPill from '@/Components/PersonPill.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatDateTime } from '@/Composables/useDateTime';
import { useIdempotencyKey } from '@/Composables/useIdempotencyKey';
import { formatMoney } from '@/Composables/useMoney';
import { firstName, shortName, useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Shift cash (21, APP_FLOW §4.15): expenses, remittances and shortage payments of the open shift,
 * whoever typed them. A wrong entry is voided with a reason and a supervisor PIN and stays in the
 * list, struck through. The expected drawer total is not in this page's props: it appears only
 * after the blind count at close (23).
 */
type Kind = 'expense' | 'remittance' | 'shortage_payment';

interface Movement {
    id: number;
    occurred_at: string;
    kind: Kind;
    category: string | null;
    for_shift: string | null;
    note: string;
    by: string;
    amount: number;
    voided: boolean;
    void_reason: string | null;
}

const props = defineProps<{
    period: { id: number; period_name: string; business_date: string; status: string };
    stats: { opening: number; expenses: number; remittance: number; shortage_payments: number };
    movements: Movement[];
    categories: ConfirmReason[];
    short_shifts: { id: number; label: string; short: number }[];
    reasons: ConfirmReason[];
    approvers: Approver[];
    can_void: boolean;
}>();

const { props: shared, flash } = useShared();
const confirm = useConfirmDialog();

const kindLabel: Record<Kind, string> = { expense: 'Expense', remittance: 'Remittance', shortage_payment: 'Shortage payment' };
const kindTone: Record<Kind, 'neutral' | 'info' | 'ok'> = { expense: 'neutral', remittance: 'info', shortage_payment: 'ok' };

// The entry form: one idempotency key per opening, so a double tap saves one row.
const { key, renew } = useIdempotencyKey();
const open = ref(false);
const form = useForm({
    kind: 'expense' as Kind,
    amount: 0,
    note: '',
    expense_category_id: null as number | null,
    for_shift_id: null as number | null,
    idempotency_key: '',
    approver_id: null as number | null,
    pin: '',
});
const formError = ref<string | undefined>(undefined);
const amountError = ref<string | undefined>(undefined);

const category = computed(() => props.categories.find((c) => c.id === form.expense_category_id));
const categoryItems = computed(() => props.categories.map((c) => ({ label: c.needs_manager ? `${c.name} · supervisor PIN` : c.name, value: c.id })));
const shortItems = computed(() => props.short_shifts.map((s) => ({ label: `${s.label} · short ${formatMoney(s.short)}`, value: s.id })));
const canSave = computed(
    () =>
        !form.processing &&
        form.amount > 0 &&
        form.note.trim() !== '' &&
        (form.kind !== 'expense' || form.expense_category_id !== null) &&
        (form.kind !== 'shortage_payment' || form.for_shift_id !== null),
);
const title = computed(() => `+ ${kindLabel[form.kind]}`);

function start(kind: Kind): void {
    // Set every field: after a save Inertia makes the saved values the form's defaults, so reset() would refill them.
    form.clearErrors();
    form.kind = kind;
    form.amount = 0;
    form.note = '';
    form.expense_category_id = null;
    form.for_shift_id = null;
    form.approver_id = null;
    form.pin = '';
    formError.value = undefined;
    amountError.value = undefined;
    renew();
    open.value = true;
}

function save(): void {
    if (form.kind === 'expense' && category.value?.needs_manager) {
        askApproval(`${category.value.name} · ${form.note.trim()} · −${formatMoney(form.amount)}`, 'movement');

        return;
    }

    submit();
}

function submit(approval?: { approver_id: number; pin: string }): void {
    form.idempotency_key = key.value;
    form.approver_id = approval?.approver_id ?? null;
    form.pin = approval?.pin ?? '';
    formError.value = undefined;
    amountError.value = undefined;

    form.post(route('shift.movement'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string } } | undefined)?.error;
            shownInDialog.value = e ?? null;

            if (e) {
                if (approving.value && ['pin_invalid', 'approval_refused', 'pin_locked'].includes(e.code)) {
                    approvalError.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
                    attempt.value++;

                    return;
                }

                approving.value = false;

                if (e.code === 'not_enough_cash') {
                    amountError.value = e.message;
                } else {
                    formError.value = e.message;
                }

                return;
            }

            approving.value = false;
            open.value = false;
            renew();
        },
    });
}

// Void: reason (ConfirmDialog with the void_cash_movement list), then the supervisor PIN (14).
const voidForm = useForm({ reason_id: null as number | null, note: '' as string | null, approver_id: null as number | null, pin: '' });
const voiding = ref<Movement | null>(null);
const approving = ref(false);
const approvalSummary = ref('');
const approvalFor = ref<'movement' | 'void'>('void');
const approvalError = ref<string | undefined>(undefined);
const attempt = ref(0);
// A refusal already shown inside the entry form or the PIN sheet is not repeated on the page.
const shownInDialog = ref<unknown>(null);
const pageError = computed(() => (!open.value && !approving.value && flash.value.error && flash.value.error !== shownInDialog.value ? flash.value.error.message : undefined));

async function voidEntry(m: Movement): Promise<void> {
    const result = await confirm({
        title: `Void this ${kindLabel[m.kind].toLowerCase()}?`,
        description: `**${m.note}** (${formatMoney(m.amount)}) stays in the list, struck through, and counts as ₱0.00. Type the correct entry again afterwards.`,
        confirmLabel: 'Void entry',
        cancelLabel: 'Keep entry',
        confirmColor: 'error',
        reasons: props.reasons,
        needsApproval: true,
        loggedAs: shared.value.auth ? shortName(shared.value.auth.user.name) : undefined,
    });

    if (result) {
        voiding.value = m;
        voidForm.reason_id = result.reasonId;
        voidForm.note = result.note;
        askApproval(`Void ${kindLabel[m.kind].toLowerCase()} · ${m.note} · −${formatMoney(m.amount)}`, 'void');
    }
}

function askApproval(summary: string, purpose: 'movement' | 'void'): void {
    approvalSummary.value = summary;
    approvalFor.value = purpose;
    approvalError.value = undefined;
    approving.value = true;
}

function approve(payload: { approver_id: number; pin: string }): void {
    if (approvalFor.value === 'movement') {
        submit(payload);

        return;
    }

    if (!voiding.value) {
        return;
    }

    voidForm.approver_id = payload.approver_id;
    voidForm.pin = payload.pin;
    voidForm.post(route('shift.movement.void', voiding.value.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string } } | undefined)?.error;
            shownInDialog.value = e ?? null;

            if (e) {
                approvalError.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
                attempt.value++;

                return;
            }

            approving.value = false;
            voiding.value = null;
        },
    });
}

function details(m: Movement): string {
    const parts = [m.category && m.category !== 'Other' ? `${m.category} · ${m.note}` : m.note];

    if (m.for_shift) {
        parts.push(`repays ${m.for_shift}`);
    }

    return parts.join(' · ');
}

function actions(m: Movement) {
    return [[{ label: 'Void this entry', icon: 'i-solar-undo-left-round-linear', color: 'error' as const, onSelect: () => voidEntry(m) }]];
}
</script>

<template>
    <FrontDeskLayout current="shift" :title="`${period.period_name} shift · ${formatDateTime(`${period.business_date}T12:00:00+08:00`, 'shiftDate')}`">
        <PageHeader>
            <UButton color="neutral" variant="outline" icon="i-solar-add-square-linear" label="Expense" :disabled="period.status !== 'open'" @click="start('expense')" />
            <UButton color="neutral" variant="outline" icon="i-solar-add-square-linear" label="Remittance" :disabled="period.status !== 'open'" @click="start('remittance')" />
            <UButton color="neutral" variant="outline" icon="i-solar-add-square-linear" label="Shortage payment" :disabled="period.status !== 'open' || !short_shifts.length" @click="start('shortage_payment')" />
        </PageHeader>

        <UAlert v-if="pageError" class="mb-4" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="pageError" />

        <div class="grid grid-cols-3 gap-3 max-[700px]:grid-cols-1">
            <StatCard label="Opening cash" :value="formatMoney(stats.opening)" />
            <StatCard label="Expenses so far" :value="formatMoney(stats.expenses)" />
            <StatCard label="Remitted so far" :value="formatMoney(stats.remittance)" :sub="stats.shortage_payments ? `Shortage payments ${formatMoney(stats.shortage_payments)}` : undefined" />
        </div>

        <section class="mt-4 overflow-hidden rounded-card border border-default bg-default">
            <table v-if="movements.length" class="dtable w-full">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Details</th>
                        <th>By</th>
                        <th class="text-right">Amount</th>
                        <th class="w-10"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in movements" :key="m.id" :data-movement="m.id" :data-voided="m.voided ? 'true' : 'false'">
                        <td class="tabular" :class="m.voided ? 'text-muted' : ''">{{ formatDateTime(m.occurred_at, 'time') }}</td>
                        <td>
                            <span v-if="m.voided" class="text-muted line-through">{{ kindLabel[m.kind] }}</span>
                            <StatusLabel v-else :tone="kindTone[m.kind]" :label="kindLabel[m.kind]" />
                        </td>
                        <td :class="m.voided ? 'text-muted' : 'text-highlighted'">
                            <span :class="m.voided ? 'line-through' : ''">{{ details(m) }}</span>
                            <span v-if="m.voided" class="text-muted"> — voided: {{ m.void_reason }}</span>
                        </td>
                        <td><PersonPill v-if="m.by" :name="firstName(m.by)" /></td>
                        <td class="num text-right" :class="m.voided ? 'text-muted line-through' : 'text-highlighted'">
                            {{ m.kind === 'shortage_payment' ? '+' : '−' }}{{ formatMoney(m.amount, 'column') }}
                        </td>
                        <td class="text-right">
                            <UDropdownMenu v-if="can_void && !m.voided" :items="actions(m)" :content="{ align: 'end' }">
                                <UButton size="xs" color="neutral" variant="ghost" icon="i-solar-menu-dots-linear" :aria-label="`Actions for ${m.note}`" />
                            </UDropdownMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="px-5 py-6 text-sm text-muted">No expenses, remittances or shortage payments on this shift yet.</p>
        </section>
        <p class="mt-3 text-[13px] text-muted">The expected drawer total is not shown here. It appears only after the blind count at close.</p>

        <UModal v-model:open="open" :title="title" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <form id="cash-movement-form" class="flex flex-col gap-4" @submit.prevent="save">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField v-if="form.kind === 'expense'" label="Category" name="expense_category_id" :error="form.errors.expense_category_id">
                        <USelect v-model="form.expense_category_id" :items="categoryItems" placeholder="Pick a category" class="w-full" />
                    </UFormField>
                    <UFormField v-if="form.kind === 'shortage_payment'" label="Shift that was short" name="for_shift_id" :error="form.errors.for_shift_id">
                        <USelect v-model="form.for_shift_id" :items="shortItems" placeholder="Pick the shift" class="w-full" />
                    </UFormField>
                    <UFormField label="Amount" name="amount" :error="amountError ?? form.errors.amount">
                        <MoneyInput v-model="form.amount" autofocus :error="amountError ?? form.errors.amount" />
                    </UFormField>
                    <UFormField :label="form.kind === 'remittance' ? 'Given to, and what for' : 'What it is for'" name="note" :error="form.errors.note">
                        <UTextarea v-model="form.note" :rows="2" class="w-full" :placeholder="form.kind === 'expense' ? 'Drinking water refill · 5 gallons' : ''" />
                    </UFormField>
                    <UAlert
                        v-if="category?.needs_manager"
                        color="warning"
                        variant="subtle"
                        icon="i-solar-lock-bold-duotone"
                        :description="`${category.name} needs a supervisor PIN before it is saved.`"
                    />
                    <p class="text-xs text-muted">Recorded on the {{ period.period_name }} shift by {{ shared.auth ? firstName(shared.auth.user.name) : '' }}.</p>
                </form>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="open = false" />
                    <UButton :label="`Save ${kindLabel[form.kind].toLowerCase()}`" :icon="category?.needs_manager ? 'i-solar-lock-bold-duotone' : undefined" :disabled="!canSave" :loading="form.processing" type="submit" form="cash-movement-form" />
                </div>
            </template>
        </UModal>

        <ApprovalPin
            v-model:open="approving"
            :summary="approvalSummary"
            :approvers="approvers"
            :error="approvalError"
            :locked="shared.pinLocked"
            :processing="voidForm.processing || form.processing"
            :attempt="attempt"
            @approve="approve"
        />
    </FrontDeskLayout>
</template>
