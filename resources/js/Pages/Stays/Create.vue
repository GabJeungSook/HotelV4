<!-- Mockup 6 · 52 (missing rate) · 53 (room just taken) -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ConflictDialog from '@/Components/ConflictDialog.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useIdempotencyKey } from '@/Composables/useIdempotencyKey';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Walk-in check-in (6). The save replaces this page in the history, so Back from the slip (8)
 * goes to the board, never to the form. Every price was worked out by the server for each option and discount;
 * this page only picks one. An option without a rate is greyed out with the missing-rate alert
 * (52). A room taken by someone else at the same moment opens dialog 53, which keeps the guest's
 * details and reopens the form on the next free room of the type.
 */
interface Choice {
    quantity: number;
    description: string;
    room_amount: number;
    expected_checkout_at: string;
    discounts: Record<string, number | null>;
}
interface Option {
    key: string;
    kind: 'hourly' | 'overnight' | 'long_stay';
    label: string;
    price: number | null;
    per_unit: string;
    disabled: boolean;
    missing: string | null;
    choices: Choice[];
}

const props = defineProps<{
    room: { id: number; number: string; type: string; type_id: number; floor: string; status: string };
    options: Option[];
    deposit: { name: string; amount: number };
    discounts: { id: number; name: string }[];
    methods: { value: string; label: string; needs_reference: boolean }[];
    now: string;
}>();

const { flash } = useShared();
const { key } = useIdempotencyKey();
const firstEnabled = props.options.find((o) => !o.disabled && o.key === 'hourly-12') ?? props.options.find((o) => !o.disabled);

const optionKey = ref<string | null>(firstEnabled?.key ?? null);
const form = useForm({
    room_id: props.room.id,
    guest_name: '',
    guest_mobile: '',
    plate_number: '',
    kind: firstEnabled?.kind ?? 'hourly',
    quantity: firstEnabled?.choices[0]?.quantity ?? 1,
    discount_id: null as number | null,
    method: props.methods[0]?.value ?? 'cash',
    tendered: 0,
    reference_no: '',
    keep_change: false,
    idempotency_key: key.value,
});

// "Pick another room" keeps the guest's details: only the room changes.
watch(
    () => props.room.id,
    (id) => (form.room_id = id),
);

const option = computed(() => props.options.find((o) => o.key === optionKey.value));
const multi = computed(() => option.value?.kind === 'overnight' || option.value?.kind === 'long_stay');
const choice = computed(() => option.value?.choices.find((c) => c.quantity === form.quantity) ?? option.value?.choices[0]);
const maxQuantity = computed(() => option.value?.choices.at(-1)?.quantity ?? 1);

watch(optionKey, () => {
    if (option.value) {
        form.kind = option.value.kind;
        form.quantity = option.value.choices[0]?.quantity ?? 1;
    }
});

const tiles = computed(() =>
    props.options.map((o) => ({
        value: o.key,
        label: o.label,
        sub: o.disabled ? 'no rate' : `${formatMoney(o.price ?? 0, 'tile')}${o.per_unit}`,
        disabled: o.disabled,
    })),
);
const missing = computed(() => props.options.filter((o) => o.disabled).map((o) => o.missing ?? ''));

const discountItems = computed(() => [
    { label: 'None', value: null },
    ...props.discounts.map((d) => ({ label: d.name, value: d.id, disabled: (choice.value?.discounts[String(d.id)] ?? null) === null })),
]);
const discountAmount = computed(() => (form.discount_id === null ? 0 : (choice.value?.discounts[String(form.discount_id)] ?? 0)));
const discountName = computed(() => props.discounts.find((d) => d.id === form.discount_id)?.name ?? 'Discount');

watch(choice, () => {
    if (form.discount_id !== null && (choice.value?.discounts[String(form.discount_id)] ?? null) === null) {
        form.discount_id = null;
    }
});

const roomAmount = computed(() => choice.value?.room_amount ?? 0);
const total = computed(() => roomAmount.value - discountAmount.value + props.deposit.amount);
const isCash = computed(() => form.method === 'cash');
const method = computed(() => props.methods.find((m) => m.value === form.method));
const change = computed(() => (isCash.value ? form.tendered - total.value : 0));
const methodTiles = computed(() => props.methods.map((m) => ({ value: m.value, label: m.label, sub: m.needs_reference ? 'ref. no.' : undefined })));
const changeTiles = computed(() => [
    { value: 'give', label: 'Give change' },
    { value: 'keep', label: `Keep ${formatMoney(Math.max(0, change.value), 'tile')} as guest deposit` },
]);
const changeMode = computed({
    get: () => (form.keep_change ? 'keep' : 'give'),
    set: (v: string) => (form.keep_change = v === 'keep'),
});

const ready = computed(
    () =>
        !!choice.value &&
        form.guest_name.trim().length >= 2 &&
        (isCash.value ? form.tendered >= total.value : form.reference_no.trim() !== '') &&
        (form.guest_mobile === '' || /^09\d{9}$/.test(form.guest_mobile.replace(/\s/g, ''))),
);

const error = computed(() => (flash.value.error && flash.value.error.code !== 'room_taken' ? flash.value.error : undefined));
const conflict = ref(false);
const conflictInfo = ref<{ room: string; by: string | null; type: string | null; next: number | null }>({ room: '', by: null, type: null, next: null });

watch(
    () => flash.value.error,
    (e) => {
        if (e?.code === 'room_taken') {
            conflictInfo.value = { room: e.room ?? props.room.number, by: e.taken_by ?? null, type: e.type ?? props.room.type, next: e.next_room_id ?? null };
            conflict.value = true;
        }
    },
);

const conflictMessage = computed(() => {
    const who = conflictInfo.value.by ? `${conflictInfo.value.by} checked in another guest to Room ${conflictInfo.value.room} a moment ago.` : `Room ${conflictInfo.value.room} was taken a moment ago.`;

    return `${who} Nothing was saved and no payment was recorded${form.guest_name ? ` for ${form.guest_name.trim()}` : ''}.`;
});

function pickAnother(): void {
    conflict.value = false;

    if (conflictInfo.value.next) {
        router.get(route('stays.create'), { room: conflictInfo.value.next }, { preserveState: true });
    }
}

function submit(): void {
    if (!ready.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        guest_mobile: data.guest_mobile.replace(/\s/g, '') || null,
        plate_number: data.plate_number.trim() || null,
        tendered: isCash.value ? data.tendered : null,
        reference_no: isCash.value ? null : data.reference_no.trim(),
        keep_change: isCash.value && data.keep_change && change.value > 0,
    })).post(route('stays.store'), { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <FrontDeskLayout current="board" :title="`Check in — Room ${room.number}`">
        <div class="mb-4 flex items-center gap-2">
            <StatusLabel :label="`${room.type} · ${room.floor}`" />
        </div>
        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-triangle-alert" class="mb-4" :description="error.message" />
        <form class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1" @submit.prevent="submit">
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Stay</h2>
                <OptionTiles v-model="optionKey" :options="tiles" label="Length of stay" />
                <UAlert
                    v-for="m in missing"
                    :key="m"
                    class="mt-3"
                    color="error"
                    variant="subtle"
                    icon="i-lucide-triangle-alert"
                    :title="m"
                    description="Pick another length of stay, or ask the admin to add the rate. The app will not check in a guest without a price."
                    data-test="missing-rate"
                />
                <UFormField v-if="multi" :label="option?.kind === 'overnight' ? 'Nights' : 'Days'" name="quantity" class="mt-4 max-w-40">
                    <UInputNumber v-model="form.quantity" :min="1" :max="maxQuantity" class="w-full" />
                </UFormField>
                <div class="mt-4 flex flex-col gap-1.5">
                    <MoneyRow label="Check-in" :text="formatDateTime(now, 'auto', now)" />
                    <MoneyRow label="Expected check-out" :text="choice ? formatDateTime(choice.expected_checkout_at, 'auto', now) : '—'" />
                </div>

                <h2 class="mt-6 mb-3 text-base font-semibold text-highlighted">Guest</h2>
                <UFormField label="Full name" name="guest_name" :error="form.errors.guest_name">
                    <UInput v-model="form.guest_name" class="w-full" autofocus autocomplete="off" />
                </UFormField>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <UFormField label="Mobile (optional)" name="guest_mobile" :error="form.errors.guest_mobile">
                        <UInput v-model="form.guest_mobile" inputmode="numeric" placeholder="0917 555 0142" class="w-full" autocomplete="off" />
                    </UFormField>
                    <UFormField label="Plate no. (optional)" name="plate_number" :error="form.errors.plate_number">
                        <UInput v-model="form.plate_number" placeholder="e.g. LAB 1234" class="w-full" autocomplete="off" />
                    </UFormField>
                </div>
                <UFormField v-if="discounts.length" label="Discount" name="discount_id" class="mt-3">
                    <USelect v-model="form.discount_id" :items="discountItems" class="w-full" />
                </UFormField>
            </section>

            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">To collect</h2>
                <div class="flex flex-col gap-1.5">
                    <MoneyRow :label="choice ? `Room · ${choice.description}` : 'Room'" :amount="choice ? roomAmount : null" />
                    <MoneyRow v-if="discountAmount > 0" :label="discountName" :amount="-discountAmount" />
                    <MoneyRow v-if="deposit.amount > 0" :label="`${deposit.name} deposit`" :amount="deposit.amount" />
                </div>
                <TotalRow label="Total" :amount="choice ? total : null" />
                <OptionTiles v-if="methods.length > 1" v-model="form.method" :options="methodTiles" label="Payment method" class="mt-4" />
                <template v-if="isCash">
                    <UFormField label="Cash received" name="tendered" class="mt-4" :error="form.errors.tendered">
                        <MoneyInput v-model="form.tendered" :due="total" />
                    </UFormField>
                    <MoneyRow class="mt-2" label="Change" :amount="Math.max(0, change)" :tone="change > 0 ? 'ok' : 'default'" />
                    <p v-if="form.tendered > 0 && change < 0" class="mt-1 text-xs font-semibold text-error">Cash received must cover the total of {{ formatMoney(total) }}.</p>
                    <OptionTiles v-if="change > 0" v-model="changeMode" :options="changeTiles" label="Change" class="mt-3" />
                </template>
                <UFormField v-else :label="`${method?.label ?? ''} reference no.`" name="reference_no" class="mt-4" :error="form.errors.reference_no">
                    <UInput v-model="form.reference_no" class="w-full" autocomplete="off" />
                </UFormField>
                <UButton type="submit" class="mt-5" size="xl" block label="Check in and print slip" :disabled="!ready" :loading="form.processing" />
            </section>
        </form>

        <ConflictDialog
            v-model:open="conflict"
            :title="`Room ${conflictInfo.room} was just taken`"
            :message="conflictMessage"
            :primary-label="conflictInfo.next ? `Pick another ${conflictInfo.type ?? ''} room` : undefined"
            @primary="pickAnother"
            @secondary="router.visit(route('board'))"
        />
    </FrontDeskLayout>
</template>
