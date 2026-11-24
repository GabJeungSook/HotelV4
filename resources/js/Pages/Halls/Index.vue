<!-- Function halls (event-hall module) -->
<script setup lang="ts">
import type { UseFormSubmitOptions } from '@inertiajs/core';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import type { FlashData } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import type { Tone } from '@/types/ui';

/**
 * Function halls (DATABASE_SCHEMA §4.14): book a hall for a time slot, built from per-pax and flat
 * packages; take a deposit; complete the event (post the charges, apply the deposit, collect the
 * balance) or cancel (refund or forfeit). Overlapping slots are refused by the server.
 */
type Pricing = 'per_pax' | 'flat';

interface Option {
    id: number;
    name: string;
}
interface PackageOpt extends Option {
    kind: string;
    pricing: Pricing;
    unit_amount: number;
}
interface Item {
    label: string;
    pricing: Pricing;
    quantity: number;
    unit_amount: number;
    amount: number;
}
interface BookingItem {
    id: number;
    label: string;
    pricing: Pricing;
    quantity: number;
    unit_amount: number | null;
    amount: number | null;
}
interface Booking {
    id: number;
    code: string;
    hall: string;
    status: 'reserved' | 'completed' | 'cancelled';
    customer_name: string;
    customer_mobile: string | null;
    event_date: string | null;
    start_at: string | null;
    end_at: string | null;
    pax: number;
    quoted: number;
    deposit_held: number;
    remaining: number;
    cancel_reason: string | null;
    items: BookingItem[];
}

const props = defineProps<{
    halls: (Option & { capacity: number; base_rate_amount: number | null })[];
    packages: PackageOpt[];
    bookings: Booking[];
    methods: { value: string; label: string }[];
    approvers: { id: number; name: string; role: string }[];
    now: string;
}>();

const uuid = (): string => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`);
const formError = ref<string | undefined>(undefined);
function done(close: () => void): UseFormSubmitOptions {
    formError.value = undefined;

    return {
        preserveScroll: true,
        onSuccess: (page) => {
            const e = (page.flash as FlashData | undefined)?.error;
            if (e) {
                formError.value = e.message;

                return;
            }
            close();
        },
    };
}

const STATUS: Record<Booking['status'], { label: string; tone: Tone }> = {
    reserved: { label: 'Reserved', tone: 'primary' },
    completed: { label: 'Completed', tone: 'ok' },
    cancelled: { label: 'Cancelled', tone: 'bad' },
};
const slot = (b: Booking): string => (b.start_at && b.end_at ? `${formatDateTime(b.start_at, 'businessDate')} · ${formatDateTime(b.start_at, 'time')}–${formatDateTime(b.end_at, 'time')}` : '—');

// New booking --------------------------------------------------------------------------------
const createOpen = ref(false);
const bookForm = useForm({
    hall_id: null as number | null,
    customer_name: '',
    customer_mobile: '',
    event_date: '',
    start_time: '14:00',
    end_time: '18:00',
    pax: 50,
    items: [] as Item[],
    deposit_amount: 0,
    method: 'cash',
    reference_no: '',
    idempotency_key: '',
});

function openCreate(): void {
    formError.value = undefined;
    bookForm.clearErrors();
    bookForm.hall_id = props.halls[0]?.id ?? null;
    bookForm.customer_name = '';
    bookForm.customer_mobile = '';
    bookForm.event_date = (props.now ?? '').slice(0, 10);
    bookForm.start_time = '14:00';
    bookForm.end_time = '18:00';
    bookForm.pax = 50;
    bookForm.items = [];
    bookForm.deposit_amount = 0;
    bookForm.method = 'cash';
    bookForm.reference_no = '';
    bookForm.idempotency_key = uuid();
    createOpen.value = true;
}

const packageItems = computed(() => props.packages.map((p) => ({ label: `${p.name} · ${p.pricing === 'per_pax' ? `${formatMoney(p.unit_amount, 'tile')}/pax` : formatMoney(p.unit_amount, 'tile')}`, value: p.id })));
const addPackageId = ref<number | null>(null);

function addPackage(): void {
    const p = props.packages.find((x) => x.id === addPackageId.value);
    if (!p) {
        return;
    }
    bookForm.items.push({ label: p.name, pricing: p.pricing, quantity: 1, unit_amount: p.unit_amount, amount: 0 });
    addPackageId.value = null;
}
function addCustom(): void {
    bookForm.items.push({ label: '', pricing: 'flat', quantity: 1, unit_amount: 0, amount: 0 });
}
function removeItem(i: number): void {
    bookForm.items.splice(i, 1);
}
function lineAmount(item: Item): number {
    return item.pricing === 'per_pax' ? item.unit_amount * (bookForm.pax || 0) : item.unit_amount * (item.quantity || 0);
}
const total = computed(() => bookForm.items.reduce((sum, i) => sum + lineAmount(i), 0));
const canSave = computed(() => bookForm.hall_id !== null && bookForm.customer_name.trim().length >= 3 && bookForm.event_date !== '' && bookForm.items.length > 0 && bookForm.items.every((i) => i.label.trim() !== '' && i.unit_amount > 0) && bookForm.deposit_amount <= total.value);

function saveBooking(): void {
    bookForm
        .transform((d) => ({
            ...d,
            items: d.items.map((i) => ({ label: i.label, pricing: i.pricing, unit_amount: i.unit_amount, quantity: i.pricing === 'per_pax' ? d.pax : i.quantity })),
            deposit_amount: d.deposit_amount > 0 ? d.deposit_amount : null,
        }))
        .post(route('halls.store'), done(() => (createOpen.value = false)));
}

// Deposit / complete / cancel ----------------------------------------------------------------
const active = ref<Booking | null>(null);
const depositOpen = ref(false);
const completeOpen = ref(false);
const cancelOpen = ref(false);

const depositForm = useForm({ method: 'cash', amount: 0, reference_no: '', idempotency_key: '' });
const completeForm = useForm({ method: 'cash', amount: 0, reference_no: '' });
const cancelForm = useForm({ reason: '', approver_id: null as number | null, pin: '', refund: true });

function openDeposit(b: Booking): void {
    active.value = b;
    formError.value = undefined;
    depositForm.reset();
    depositForm.clearErrors();
    depositForm.idempotency_key = uuid();
    depositOpen.value = true;
}
function openComplete(b: Booking): void {
    active.value = b;
    formError.value = undefined;
    completeForm.reset();
    completeForm.clearErrors();
    completeForm.amount = b.remaining;
    completeOpen.value = true;
}
function openCancel(b: Booking): void {
    active.value = b;
    formError.value = undefined;
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelOpen.value = true;
}

function saveDeposit(): void {
    if (!active.value) {
        return;
    }
    depositForm.transform((d) => ({ ...d, amount: d.amount })).post(route('halls.deposit', active.value.id), done(() => (depositOpen.value = false)));
}
function saveComplete(): void {
    if (!active.value) {
        return;
    }
    completeForm.post(route('halls.complete', active.value.id), done(() => (completeOpen.value = false)));
}
function saveCancel(): void {
    if (!active.value) {
        return;
    }
    cancelForm.post(route('halls.cancel', active.value.id), done(() => (cancelOpen.value = false)));
}
</script>

<template>
    <FrontDeskLayout current="halls" title="Function halls">
        <Head title="Function halls" />

        <div class="mb-4 flex items-center justify-between">
            <p class="text-[13px] text-muted">Book an event hall for a time slot, take a deposit, then complete or cancel it. Overlapping bookings are refused.</p>
            <UButton icon="i-solar-add-square-linear" label="New booking" :disabled="halls.length === 0" @click="openCreate" />
        </div>

        <section class="overflow-x-auto rounded-card border border-default bg-default">
            <EmptyState v-if="bookings.length === 0" icon="i-solar-confetti-bold-duotone" title="No bookings yet" :text="halls.length === 0 ? 'Ask an admin to add a hall in Setup → Event halls.' : 'Create the first booking.'" />
            <table v-else class="dtable flush w-full">
                <thead>
                    <tr>
                        <th>Booking</th>
                        <th>Hall</th>
                        <th>Customer</th>
                        <th>When</th>
                        <th class="r">Pax</th>
                        <th class="r">Quoted</th>
                        <th class="r">Deposit</th>
                        <th class="r">Remaining</th>
                        <th>Status</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in bookings" :key="b.id">
                        <td class="b">{{ b.code }}</td>
                        <td class="text-highlighted">{{ b.hall }}</td>
                        <td>{{ b.customer_name }}</td>
                        <td class="!whitespace-nowrap text-muted">{{ slot(b) }}</td>
                        <td class="num r">{{ b.pax }}</td>
                        <td class="num r">{{ formatMoney(b.quoted, 'column') }}</td>
                        <td class="num r">{{ formatMoney(b.deposit_held, 'column') }}</td>
                        <td class="num r" :class="b.status === 'reserved' && b.remaining > 0 ? 'text-warn' : ''">{{ b.status === 'reserved' ? formatMoney(b.remaining, 'column') : '—' }}</td>
                        <td><StatusLabel v-bind="STATUS[b.status]" /></td>
                        <td class="text-right">
                            <div v-if="b.status === 'reserved'" class="flex justify-end gap-1">
                                <UButton color="neutral" variant="ghost" size="xs" icon="i-solar-wallet-money-linear" label="Deposit" @click="openDeposit(b)" />
                                <UButton color="neutral" variant="ghost" size="xs" icon="i-solar-check-circle-linear" label="Complete" @click="openComplete(b)" />
                                <UButton color="error" variant="ghost" size="xs" icon="i-solar-close-circle-linear" label="Cancel" @click="openCancel(b)" />
                            </div>
                            <span v-else-if="b.cancel_reason" class="text-xs text-muted">{{ b.cancel_reason }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- New booking -->
        <USlideover v-model:open="createOpen" title="New hall booking" :ui="{ content: 'max-w-[560px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Hall" :error="bookForm.errors.hall_id"><USelect v-model="bookForm.hall_id" :items="halls.map((h) => ({ label: h.name, value: h.id }))" value-key="value" class="w-full" /></UFormField>
                        <UFormField label="Guests (pax)" :error="bookForm.errors.pax"><UInput v-model.number="bookForm.pax" type="number" :min="0" class="w-full" /></UFormField>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Customer" :error="bookForm.errors.customer_name"><UInput v-model="bookForm.customer_name" class="w-full" maxlength="120" placeholder="Dela Cruz wedding" /></UFormField>
                        <UFormField label="Mobile" :error="bookForm.errors.customer_mobile"><UInput v-model="bookForm.customer_mobile" class="w-full" maxlength="20" placeholder="0917…" /></UFormField>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <UFormField label="Date" :error="bookForm.errors.event_date"><UInput v-model="bookForm.event_date" type="date" class="w-full" /></UFormField>
                        <UFormField label="Start" :error="bookForm.errors.start_time"><UInput v-model="bookForm.start_time" type="time" class="w-full" /></UFormField>
                        <UFormField label="End" :error="bookForm.errors.end_time"><UInput v-model="bookForm.end_time" type="time" class="w-full" /></UFormField>
                    </div>

                    <div class="rounded-card border border-default p-3">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[13px] font-semibold text-highlighted">Items</span>
                            <span class="num text-[15px] font-semibold text-highlighted">{{ formatMoney(total, 'tile') }}</span>
                        </div>
                        <div v-if="bookForm.items.length === 0" class="py-2 text-center text-xs text-muted">Add a package or a custom line.</div>
                        <div v-for="(item, i) in bookForm.items" :key="i" class="mb-2 flex items-center gap-2">
                            <UInput v-model="item.label" class="flex-1" placeholder="Item" maxlength="120" />
                            <USelect v-model="item.pricing" :items="[{ label: 'Flat', value: 'flat' }, { label: 'Per pax', value: 'per_pax' }]" class="w-28" />
                            <MoneyInput v-model="item.unit_amount" class="w-28" />
                            <UInput v-if="item.pricing === 'flat'" v-model.number="item.quantity" type="number" :min="1" class="w-16" aria-label="Quantity" />
                            <span v-else class="w-16 text-center text-xs text-muted">× {{ bookForm.pax }}</span>
                            <span class="num w-24 text-right text-highlighted">{{ formatMoney(lineAmount(item), 'column') }}</span>
                            <UButton color="error" variant="ghost" size="xs" icon="i-solar-trash-bin-trash-linear" :aria-label="`Remove ${item.label}`" @click="removeItem(i)" />
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <USelect v-model="addPackageId" :items="packageItems" value-key="value" placeholder="Add a package" class="flex-1" />
                            <UButton color="neutral" variant="outline" size="sm" label="Add" :disabled="addPackageId === null" @click="addPackage" />
                            <UButton color="neutral" variant="outline" size="sm" icon="i-solar-add-square-linear" label="Custom" @click="addCustom" />
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Deposit now" :error="bookForm.errors.deposit_amount" help="Optional."><MoneyInput v-model="bookForm.deposit_amount" /></UFormField>
                        <UFormField v-if="bookForm.deposit_amount > 0" label="Paid by" :error="bookForm.errors.method"><USelect v-model="bookForm.method" :items="methods.map((m) => ({ label: m.label, value: m.value }))" value-key="value" class="w-full" /></UFormField>
                    </div>
                    <UFormField v-if="bookForm.deposit_amount > 0 && bookForm.method !== 'cash'" label="Reference no." :error="bookForm.errors.reference_no"><UInput v-model="bookForm.reference_no" class="w-full" maxlength="40" /></UFormField>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="createOpen = false" />
                    <UButton label="Book hall" :disabled="!canSave" :loading="bookForm.processing" @click="saveBooking" />
                </div>
            </template>
        </USlideover>

        <!-- Deposit -->
        <UModal v-model:open="depositOpen" :title="active ? `Deposit · ${active.code}` : ''" :ui="{ content: 'max-w-[380px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Amount" :error="depositForm.errors.amount"><MoneyInput v-model="depositForm.amount" autofocus /></UFormField>
                    <UFormField label="Paid by" :error="depositForm.errors.method"><USelect v-model="depositForm.method" :items="methods.map((m) => ({ label: m.label, value: m.value }))" value-key="value" class="w-full" /></UFormField>
                    <UFormField v-if="depositForm.method !== 'cash'" label="Reference no." :error="depositForm.errors.reference_no"><UInput v-model="depositForm.reference_no" class="w-full" maxlength="40" /></UFormField>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="depositOpen = false" />
                    <UButton label="Record deposit" :disabled="depositForm.amount <= 0" :loading="depositForm.processing" @click="saveDeposit" />
                </div>
            </template>
        </UModal>

        <!-- Complete -->
        <UModal v-model:open="completeOpen" :title="active ? `Complete · ${active.code}` : ''" :ui="{ content: 'max-w-[380px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <p class="text-[13px] text-muted">Posts the charges and applies the deposit. Collect the remaining balance of <span class="num font-semibold text-highlighted">{{ formatMoney(active?.remaining ?? 0) }}</span>.</p>
                    <UFormField label="Amount received" :error="completeForm.errors.amount"><MoneyInput v-model="completeForm.amount" autofocus /></UFormField>
                    <UFormField label="Paid by" :error="completeForm.errors.method"><USelect v-model="completeForm.method" :items="methods.map((m) => ({ label: m.label, value: m.value }))" value-key="value" class="w-full" /></UFormField>
                    <UFormField v-if="completeForm.method !== 'cash'" label="Reference no." :error="completeForm.errors.reference_no"><UInput v-model="completeForm.reference_no" class="w-full" maxlength="40" /></UFormField>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="completeOpen = false" />
                    <UButton label="Complete booking" :loading="completeForm.processing" @click="saveComplete" />
                </div>
            </template>
        </UModal>

        <!-- Cancel -->
        <UModal v-model:open="cancelOpen" :title="active ? `Cancel · ${active.code}` : ''" :ui="{ content: 'max-w-[400px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Reason" :error="cancelForm.errors.reason"><UInput v-model="cancelForm.reason" class="w-full" maxlength="255" placeholder="Customer called off" /></UFormField>
                    <USwitch v-model="cancelForm.refund" :label="cancelForm.refund ? 'Refund the deposit' : 'Forfeit the deposit (keep as income)'" />
                    <UFormField label="Approved by" :error="cancelForm.errors.approver_id"><USelect v-model="cancelForm.approver_id" :items="approvers.map((a) => ({ label: `${a.name} · ${a.role}`, value: a.id }))" value-key="value" placeholder="Choose a supervisor" class="w-full" /></UFormField>
                    <UFormField label="Supervisor PIN" :error="cancelForm.errors.pin"><UInput v-model="cancelForm.pin" type="password" inputmode="numeric" maxlength="4" class="w-full" /></UFormField>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Back" @click="cancelOpen = false" />
                    <UButton color="error" label="Cancel booking" :disabled="cancelForm.reason.trim() === '' || cancelForm.approver_id === null || cancelForm.pin.length < 4" :loading="cancelForm.processing" @click="saveCancel" />
                </div>
            </template>
        </UModal>
    </FrontDeskLayout>
</template>
