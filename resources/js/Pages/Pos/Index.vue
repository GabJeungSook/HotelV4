<!-- Mockup 19 -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApprovalPin, { type Approver } from '@/Components/ApprovalPin.vue';
import type { ConfirmReason } from '@/Components/ConfirmDialog.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import TotalRow from '@/Components/TotalRow.vue';
import PersonPill from '@/Components/PersonPill.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatDateTime } from '@/Composables/useDateTime';
import { useIdempotencyKey } from '@/Composables/useIdempotencyKey';
import { formatMoney } from '@/Composables/useMoney';
import { firstName, shortName, useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Point of sale (19, APP_FLOW §4.13). One product list; a sale is either paid in cash by a
 * walk-in (in full) or charged to an occupied room as unpaid folio lines. Out-of-stock items
 * cannot be added; the server checks the stock again on save. The cart is page state and is
 * lost on Back. Each sale of this shift can be voided with a reason and a supervisor PIN (14).
 */
interface Product {
    id: number;
    name: string;
    category: string;
    price: number;
    stock: number | null;
    low: boolean;
}

interface Sale {
    id: number;
    order_no: string;
    occurred_at: string;
    mode: 'walk_in' | 'room_charge';
    room: string | null;
    items: string;
    amount: number;
    by: string | null;
    voided: boolean;
    room_checked_out: boolean;
}

const props = defineProps<{
    products: Product[];
    categories: string[];
    rooms: { stay_id: number; room: string; guest: string }[];
    sales: Sale[];
    reasons: ConfirmReason[];
    approvers: Approver[];
}>();

const { props: shared, flash } = useShared();
const confirm = useConfirmDialog();

const category = ref<string>('All');
const q = ref('');
const qty = ref<Record<number, number>>({});
const { key, renew } = useIdempotencyKey();
const form = useForm({
    mode: 'walk_in' as Sale['mode'],
    items: {} as Record<string, number>,
    tendered: 0,
    stay_id: null as number | null,
    idempotency_key: '',
});
const saleError = ref<string | undefined>(undefined);

const segments = computed(() => ['All', ...props.categories]);
const shown = computed(() => {
    const term = q.value.trim().toLowerCase();

    return props.products.filter((p) => (category.value === 'All' || p.category === category.value) && (term === '' || p.name.toLowerCase().includes(term)));
});
const cart = computed(() => props.products.filter((p) => (qty.value[p.id] ?? 0) > 0));
const total = computed(() => cart.value.reduce((sum, p) => sum + p.price * (qty.value[p.id] ?? 0), 0));
const change = computed(() => Math.max(0, form.tendered - total.value));
const roomItems = computed(() => props.rooms.map((r) => ({ label: `Room ${r.room} · ${r.guest}`, value: r.stay_id })));
const canComplete = computed(
    () => !form.processing && cart.value.length > 0 && (form.mode === 'walk_in' ? form.tendered >= total.value : form.stay_id !== null),
);

function out(p: Product): boolean {
    return p.stock !== null && p.stock <= 0;
}

function stockLabel(p: Product): string {
    return out(p) ? 'Out' : p.stock === null ? '' : `${p.stock} left`;
}

function add(p: Product, step: 1 | -1 = 1): void {
    const next = Math.max(0, (qty.value[p.id] ?? 0) + step);
    qty.value = { ...qty.value, [p.id]: p.stock === null ? next : Math.min(next, p.stock) };
    saleError.value = undefined;
}

function setMode(mode: Sale['mode']): void {
    form.mode = mode;
    saleError.value = undefined;
}

function complete(): void {
    saleError.value = undefined;
    form.items = Object.fromEntries(cart.value.map((p) => [String(p.id), qty.value[p.id] ?? 0]));
    form.idempotency_key = key.value;

    form.transform((data) => ({
        mode: data.mode,
        items: data.items,
        idempotency_key: data.idempotency_key,
        ...(data.mode === 'walk_in' ? { tendered: data.tendered } : { stay_id: data.stay_id }),
    })).post(route('pos.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { message: string } } | undefined)?.error;
            shownError.value = e ?? null;

            if (e) {
                saleError.value = e.message;

                return;
            }

            // The sale is saved: clear the cart and open a fresh form.
            qty.value = {};
            form.tendered = 0;
            form.stay_id = null;
            renew();
        },
    });
}

// Void a sale: reason first (ConfirmDialog with the void list), then the supervisor PIN (14).
const voidForm = useForm({ reason_id: null as number | null, note: '' as string | null, approver_id: null as number | null, pin: '' });
const voiding = ref<Sale | null>(null);
const approving = ref(false);
const approvalError = ref<string | undefined>(undefined);
const attempt = ref(0);
const shownError = ref<unknown>(null);
const pageError = computed(() => (!approving.value && flash.value.error && flash.value.error !== shownError.value ? flash.value.error.message : undefined));

async function voidSale(s: Sale): Promise<void> {
    const result = await confirm({
        title: `Void sale ${s.order_no}?`,
        description: `**${s.items}** (${formatMoney(s.amount)}) is reversed${s.mode === 'walk_in' ? ', the cash is refunded' : ' on the guest account'} and the stock goes back on the shelf.`,
        confirmLabel: 'Void sale',
        cancelLabel: 'Keep sale',
        confirmColor: 'error',
        reasons: props.reasons,
        needsApproval: true,
        loggedAs: shared.value.auth ? shortName(shared.value.auth.user.name) : undefined,
    });

    if (result) {
        voiding.value = s;
        voidForm.reason_id = result.reasonId;
        voidForm.note = result.note;
        approvalError.value = undefined;
        approving.value = true;
    }
}

function approve(payload: { approver_id: number; pin: string }): void {
    if (!voiding.value) {
        return;
    }

    voidForm.approver_id = payload.approver_id;
    voidForm.pin = payload.pin;
    voidForm.post(route('pos.void', voiding.value.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string } } | undefined)?.error;
            shownError.value = e ?? null;

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

function actions(s: Sale) {
    return [[{ label: 'Void this sale', icon: 'i-solar-undo-left-round-linear', color: 'error' as const, onSelect: () => voidSale(s) }]];
}
</script>

<template>
    <FrontDeskLayout current="pos" title="Point of sale">
        <UAlert v-if="pageError" class="mb-4" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="pageError" />

        <div class="grid grid-cols-[minmax(0,1fr)_320px] items-start gap-4 max-[900px]:grid-cols-1">
            <section>
                <UInput v-model="q" icon="i-solar-magnifer-linear" placeholder="Search products" class="mb-3 w-full sm:w-72" aria-label="Search products" />
                <div role="tablist" aria-label="Category" class="mb-3 inline-flex flex-wrap gap-1 rounded-md bg-grey-50 p-1">
                    <button
                        v-for="c in segments"
                        :key="c"
                        type="button"
                        role="tab"
                        :aria-selected="category === c"
                        class="hm-focus cursor-pointer rounded px-3 py-1.5 text-sm"
                        :class="category === c ? 'bg-default font-medium text-highlighted shadow-xs' : 'text-muted'"
                        @click="category = c"
                    >
                        {{ c }}
                    </button>
                </div>
                <div class="grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-3">
                    <button
                        v-for="p in shown"
                        :key="p.id"
                        type="button"
                        class="hm-focus flex cursor-pointer flex-col items-start gap-1 rounded-card border border-default bg-default p-3 text-left transition-colors hover:border-accented disabled:cursor-not-allowed disabled:opacity-45"
                        :disabled="out(p) || (p.stock !== null && (qty[p.id] ?? 0) >= p.stock)"
                        :aria-label="`Add ${p.name}, ${formatMoney(p.price)}${out(p) ? ', out of stock' : ''}`"
                        :data-product="p.id"
                        @click="add(p)"
                    >
                        <span class="font-semibold text-highlighted">{{ p.name }}</span>
                        <span class="num text-[17px] text-highlighted">{{ formatMoney(p.price, 'tile') }}</span>
                        <span class="text-xs" :class="out(p) ? 'font-semibold text-bad' : p.low ? 'font-medium text-warn' : 'text-muted'">{{ stockLabel(p) }}</span>
                    </button>
                </div>
                <p v-if="shown.length === 0" class="rounded-card border border-default bg-default px-4 py-10 text-center text-sm text-muted">No products match "{{ q }}".</p>
            </section>

            <form class="sticky top-4 rounded-card border border-default bg-default p-4" aria-label="Sale" @submit.prevent="canComplete && complete()">
                <div role="tablist" aria-label="How the sale is paid" class="flex gap-1 rounded-md bg-grey-50 p-1">
                    <button
                        v-for="m in [
                            { value: 'walk_in', label: 'Walk-in · cash' },
                            { value: 'room_charge', label: 'Charge to room' },
                        ] as const"
                        :key="m.value"
                        type="button"
                        role="tab"
                        :aria-selected="form.mode === m.value"
                        class="hm-focus flex-1 cursor-pointer rounded px-2 py-1.5 text-sm"
                        :class="form.mode === m.value ? 'bg-default font-medium text-highlighted shadow-xs' : 'text-muted'"
                        @click="setMode(m.value)"
                    >
                        {{ m.label }}
                    </button>
                </div>

                <UAlert v-if="saleError" class="mt-3" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="saleError" data-sale-error />

                <table v-if="cart.length" class="dtable mt-3 w-full">
                    <tbody>
                        <tr v-for="p in cart" :key="p.id">
                            <td class="text-highlighted">{{ p.name }} × {{ qty[p.id] }}</td>
                            <td class="w-16 text-right">
                                <UButton size="xs" color="neutral" variant="ghost" icon="i-solar-minus-circle-linear" :aria-label="`One less ${p.name}`" @click="add(p, -1)" />
                            </td>
                            <td class="num text-right">{{ formatMoney(p.price * (qty[p.id] ?? 0), 'column') }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="mt-3 py-4 text-center text-sm text-muted">Tap an item to add it.</p>

                <TotalRow class="mt-2" label="Total" :amount="total" />

                <template v-if="form.mode === 'walk_in'">
                    <UFormField class="mt-3" label="Cash received" name="tendered" :error="form.errors.tendered">
                        <MoneyInput v-model="form.tendered" :due="total" :error="form.errors.tendered" />
                    </UFormField>
                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span class="text-muted">Change</span>
                        <b class="num text-highlighted">{{ formatMoney(change) }}</b>
                    </div>
                </template>
                <UFormField v-else class="mt-3" label="Occupied room" name="stay_id" :error="form.errors.stay_id" help="Added unpaid to the guest's account.">
                    <USelect v-model="form.stay_id" :items="roomItems" placeholder="Pick a room" class="w-full" :disabled="!rooms.length" />
                </UFormField>

                <UButton class="mt-4" block label="Complete sale" :disabled="!canComplete" :loading="form.processing" type="submit" />
            </form>
        </div>

        <section class="mt-6">
            <h3 class="mb-2 text-sm font-semibold text-highlighted">Sales on this shift</h3>
            <div class="overflow-x-auto rounded-card border border-default bg-default p-4">
                <table v-if="sales.length" class="dtable w-full">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Sale</th>
                            <th>Items</th>
                            <th>By</th>
                            <th class="text-right">Amount</th>
                            <th class="w-10"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in sales" :key="s.id" :data-sale="s.id" :data-voided="s.voided ? 'true' : 'false'">
                            <td class="tabular" :class="s.voided ? 'text-muted' : ''">{{ formatDateTime(s.occurred_at, 'time') }}</td>
                            <td>
                                <span class="tabular" :class="s.voided ? 'text-muted line-through' : 'text-highlighted'">{{ s.order_no }}</span>
                                <StatusLabel v-if="s.voided" class="ms-2" tone="neutral" label="Voided" />
                                <StatusLabel v-else class="ms-2" :tone="s.mode === 'walk_in' ? 'ok' : 'info'" :label="s.mode === 'walk_in' ? 'Cash' : `Room ${s.room}`" />
                            </td>
                            <td :class="s.voided ? 'text-muted line-through' : ''">{{ s.items }}</td>
                            <td><PersonPill v-if="s.by" :name="firstName(s.by)" /></td>
                            <td class="num text-right" :class="s.voided ? 'text-muted line-through' : 'text-highlighted'">{{ formatMoney(s.amount, 'column') }}</td>
                            <td class="text-right">
                                <UDropdownMenu v-if="!s.voided && !s.room_checked_out" :items="actions(s)" :content="{ align: 'end' }">
                                    <UButton size="xs" color="neutral" variant="ghost" icon="i-solar-menu-dots-linear" :aria-label="`Actions for ${s.order_no}`" />
                                </UDropdownMenu>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="px-5 py-6 text-sm text-muted">No sales on this shift yet.</p>
            </div>
        </section>

        <ApprovalPin
            v-model:open="approving"
            :summary="voiding ? `Void sale ${voiding.order_no} · ${formatMoney(voiding.amount)}` : ''"
            :approvers="approvers"
            :error="approvalError"
            :locked="shared.pinLocked"
            :processing="voidForm.processing"
            :attempt="attempt"
            @approve="approve"
        />
    </FrontDeskLayout>
</template>
