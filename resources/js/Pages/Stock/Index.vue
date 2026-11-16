<!-- Mockup 20 -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApprovalPin, { type Approver } from '@/Components/ApprovalPin.vue';
import type { ConfirmReason } from '@/Components/ConfirmDialog.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { firstName, useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Stock (20, APP_FLOW §4.14): this shift's stock per product, where on hand = opening + in −
 * sold − wastage. Each number opens the movements behind it. "Receive stock" records a
 * delivery; "Record wastage" needs a reason from the wastage list and a note, and a supervisor
 * PIN when the reason asks for one. Stock never goes below zero.
 */
interface Row {
    product_id: number;
    name: string;
    opening: number;
    in: number;
    sold: number;
    wastage: number;
    closing: number;
    low_at: number;
}

type MovementKind = 'receive' | 'sale' | 'sale_reversal' | 'wastage' | 'adjustment';

interface Movement {
    id: number;
    product_id: number;
    kind: MovementKind;
    quantity: number;
    balance_after: number;
    occurred_at: string;
    by: string | null;
    reason: string | null;
    note: string | null;
}

type Column = 'opening' | 'in' | 'sold' | 'wastage' | 'closing';

const props = defineProps<{
    rows: Row[];
    movements: Movement[];
    products: { id: number; name: string; on_hand: number }[];
    reasons: ConfirmReason[];
    approvers: Approver[];
    opened_at: string;
    period_name: string;
}>();

const { props: shared } = useShared();

const kindLabel: Record<MovementKind, string> = { receive: 'Received', sale: 'Sold', sale_reversal: 'Sale voided', wastage: 'Wastage', adjustment: 'Adjustment' };
const columnKinds: Record<Column, MovementKind[]> = {
    opening: [],
    in: ['receive', 'adjustment'],
    sold: ['sale', 'sale_reversal'],
    wastage: ['wastage'],
    closing: ['receive', 'adjustment', 'sale', 'sale_reversal', 'wastage'],
};
const columnLabel: Record<Column, string> = { opening: 'Opening', in: 'In', sold: 'Sold', wastage: 'Wastage', closing: 'On hand' };
const columns: Column[] = ['opening', 'in', 'sold', 'wastage', 'closing'];

function chip(r: Row): { tone: 'bad' | 'warn'; label: string } | null {
    if (r.closing <= 0) {
        return { tone: 'bad', label: 'Out' };
    }

    return r.closing <= r.low_at ? { tone: 'warn', label: 'Low' } : null;
}

// The movements behind one figure; a figure with none behind it is plain text.
function hasMovements(r: Row, c: Column): boolean {
    return props.movements.some((m) => m.product_id === r.product_id && columnKinds[c].includes(m.kind));
}

const drill = ref<{ row: Row; column: Column } | null>(null);
const drillOpen = computed({ get: () => drill.value !== null, set: (v: boolean) => !v && (drill.value = null) });
const drillRows = computed(() => (drill.value ? props.movements.filter((m) => m.product_id === drill.value?.row.product_id && columnKinds[drill.value.column].includes(m.kind)) : []));

// Receive stock and record wastage share one form; kind picks the fields.
const productItems = computed(() => props.products.map((p) => ({ label: `${p.name} · ${p.on_hand} on hand`, value: p.id })));
const reasonItems = computed(() => props.reasons.map((r) => ({ label: r.needs_manager ? `${r.name} · supervisor PIN` : r.name, value: r.id })));
const open = ref(false);
const form = useForm({
    kind: 'receive' as 'receive' | 'wastage',
    product_id: null as number | null,
    quantity: 1,
    reason_id: null as number | null,
    note: '',
    approver_id: null as number | null,
    pin: '',
});
const formError = ref<string | undefined>(undefined);
const reason = computed(() => props.reasons.find((r) => r.id === form.reason_id));
const product = computed(() => props.products.find((p) => p.id === form.product_id));
const canSave = computed(
    () =>
        !form.processing &&
        form.product_id !== null &&
        form.quantity >= 1 &&
        (form.kind === 'receive' || (form.reason_id !== null && form.note.trim() !== '' && form.quantity <= (product.value?.on_hand ?? 0))),
);

function start(kind: 'receive' | 'wastage', productId: number | null = null): void {
    form.clearErrors();
    form.kind = kind;
    form.product_id = productId;
    form.quantity = 1;
    form.reason_id = null;
    form.note = '';
    form.approver_id = null;
    form.pin = '';
    formError.value = undefined;
    open.value = true;
}

const approving = ref(false);
const approvalError = ref<string | undefined>(undefined);
const attempt = ref(0);

function save(): void {
    if (form.kind === 'wastage' && reason.value?.needs_manager) {
        approvalError.value = undefined;
        approving.value = true;

        return;
    }

    submit();
}

function submit(approval?: { approver_id: number; pin: string }): void {
    form.approver_id = approval?.approver_id ?? null;
    form.pin = approval?.pin ?? '';
    formError.value = undefined;

    form.transform((data) => ({
        kind: data.kind,
        product_id: data.product_id,
        quantity: data.quantity,
        note: data.note.trim() === '' ? null : data.note,
        ...(data.kind === 'wastage' ? { reason_id: data.reason_id } : {}),
        ...(data.approver_id ? { approver_id: data.approver_id, pin: data.pin } : {}),
    })).post(route('stock.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string } } | undefined)?.error;

            if (e) {
                if (approving.value && ['pin_invalid', 'approval_refused', 'pin_locked'].includes(e.code)) {
                    approvalError.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
                    attempt.value++;

                    return;
                }

                approving.value = false;
                formError.value = e.message;

                return;
            }

            approving.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <FrontDeskLayout current="stock" title="Stock">
        <PageHeader>
            <UButton color="neutral" variant="outline" icon="i-lucide-trash-2" label="Record wastage" @click="start('wastage')" />
            <UButton icon="i-lucide-plus" label="Receive stock" @click="start('receive')" />
        </PageHeader>

        <section class="overflow-hidden rounded-card border border-default bg-default">
            <table v-if="rows.length" class="dtable w-full">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th v-for="c in columns" :key="c" class="text-right">{{ columnLabel[c] }}</th>
                        <th class="w-16"><span class="sr-only">Status</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.product_id" :data-product="r.product_id">
                        <td class="text-highlighted">{{ r.name }}</td>
                        <td v-for="c in columns" :key="c" class="num text-right">
                            <button
                                v-if="hasMovements(r, c)"
                                type="button"
                                class="hm-focus cursor-pointer rounded px-1 underline decoration-dotted underline-offset-4 hover:text-primary"
                                :class="c === 'closing' ? 'font-semibold text-highlighted' : ''"
                                :aria-label="`${columnLabel[c]} ${r[c]} of ${r.name}: show the movements`"
                                :data-cell="c"
                                @click="drill = { row: r, column: c }"
                            >
                                {{ r[c] }}
                            </button>
                            <span v-else :class="c === 'closing' ? 'font-semibold text-highlighted' : ''" :data-cell="c">{{ r[c] }}</span>
                        </td>
                        <td>
                            <StatusLabel v-if="chip(r)" :tone="chip(r)!.tone" :label="chip(r)!.label" />
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else icon="i-lucide-package" title="No products keep stock" text="Products are added on the admin Products page." />
        </section>
        <p class="mt-3 text-[13px] text-muted">
            Opening is the count when the {{ period_name }} shift opened ({{ formatDateTime(opened_at, 'time') }}). Each number opens the list of movements behind it.
        </p>

        <USlideover v-model:open="drillOpen" :title="drill ? `${drill.row.name} · ${columnLabel[drill.column]}` : ''" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <table v-if="drillRows.length" class="dtable w-full">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>What</th>
                            <th class="text-right">Pieces</th>
                            <th class="text-right">After</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in drillRows" :key="m.id">
                            <td class="tabular">{{ formatDateTime(m.occurred_at, 'time') }}</td>
                            <td>
                                <span class="text-highlighted">{{ kindLabel[m.kind] }}</span>
                                <span class="block text-xs text-muted">{{ [m.reason, m.note, m.by ? firstName(m.by) : null].filter(Boolean).join(' · ') }}</span>
                            </td>
                            <td class="num text-right">{{ m.quantity > 0 ? `+${m.quantity}` : `−${Math.abs(m.quantity)}` }}</td>
                            <td class="num text-right">{{ m.balance_after }}</td>
                        </tr>
                    </tbody>
                </table>
                <EmptyState v-else compact title="No movements on this shift." />
            </template>
        </USlideover>

        <UModal v-model:open="open" :title="form.kind === 'receive' ? 'Receive stock' : 'Record wastage'" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <form id="stock-form" class="flex flex-col gap-4" @submit.prevent="save">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="formError" data-form-error />
                    <UFormField label="Product" name="product_id" :error="form.errors.product_id">
                        <USelectMenu v-model="form.product_id" :items="productItems" value-key="value" placeholder="Pick a product" class="w-full" />
                    </UFormField>
                    <UFormField
                        :label="form.kind === 'receive' ? 'Pieces received' : 'Pieces wasted'"
                        name="quantity"
                        :error="form.errors.quantity"
                        :help="form.kind === 'wastage' && product ? `${product.on_hand} on hand` : undefined"
                    >
                        <UInput v-model.number="form.quantity" type="number" inputmode="numeric" :min="1" :max="form.kind === 'wastage' ? product?.on_hand : 9999" class="w-32" />
                    </UFormField>
                    <UFormField v-if="form.kind === 'wastage'" label="Reason" name="reason_id" :error="form.errors.reason_id">
                        <USelect v-model="form.reason_id" :items="reasonItems" placeholder="Pick a reason" class="w-full" />
                    </UFormField>
                    <UFormField :label="form.kind === 'receive' ? 'Delivery note (optional)' : 'What happened'" name="note" :error="form.errors.note">
                        <UTextarea v-model="form.note" :rows="2" class="w-full" :placeholder="form.kind === 'receive' ? 'Supplier or delivery receipt number' : 'Two bags torn in the delivery box'" />
                    </UFormField>
                    <UAlert
                        v-if="form.kind === 'wastage' && reason?.needs_manager"
                        color="warning"
                        variant="subtle"
                        icon="i-lucide-lock"
                        :description="`${reason.name} needs a supervisor PIN before it is saved.`"
                    />
                </form>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="open = false" />
                    <UButton
                        :label="form.kind === 'receive' ? 'Save delivery' : 'Save wastage'"
                        :icon="form.kind === 'wastage' && reason?.needs_manager ? 'i-lucide-lock' : undefined"
                        :disabled="!canSave"
                        :loading="form.processing"
                        type="submit"
                        form="stock-form"
                    />
                </div>
            </template>
        </UModal>

        <ApprovalPin
            v-model:open="approving"
            :summary="product ? `Wastage · ${product.name} × ${form.quantity} · ${reason?.name ?? ''}` : ''"
            :approvers="approvers"
            :error="approvalError"
            :locked="shared.pinLocked"
            :processing="form.processing"
            :attempt="attempt"
            @approve="submit"
        />
    </FrontDeskLayout>
</template>
