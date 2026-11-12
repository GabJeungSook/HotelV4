<script setup lang="ts">
import { computed, watch } from 'vue';
import { formatMoney } from '@/Composables/useMoney';
import MoneyInput from './MoneyInput.vue';
import MoneyRow from './MoneyRow.vue';
import OptionTiles from './OptionTiles.vue';

/**
 * The payment part of take payment (11), and of extend (12), transfer (13) and check-out (17):
 * method tiles, the amount, the reference number for GCash or card, change, and "keep the change
 * as a guest deposit". The server applies the payment; this only collects what was handed over.
 */
export interface PaymentMethodOption {
    value: string;
    label: string;
    needs_reference: boolean;
}

export interface PaymentValue {
    method: string;
    amount: number;
    reference_no: string;
    keep_change: boolean;
}

const props = withDefaults(
    defineProps<{
        methods: PaymentMethodOption[];
        due: number;
        /** Guest deposit available; the deposit tile is disabled at ₱0.00. */
        guestDeposit?: number;
        allowKeepChange?: boolean;
        errors?: Record<string, string | undefined>;
    }>(),
    { guestDeposit: 0, allowKeepChange: true, errors: () => ({}) },
);

const model = defineModel<PaymentValue>({ required: true });

const tiles = computed(() =>
    props.methods.map((m) => ({
        value: m.value,
        label: m.label,
        sub: m.value === 'deposit' ? `${formatMoney(props.guestDeposit, 'tile')} available` : m.needs_reference ? 'ref. no.' : undefined,
        disabled: m.value === 'deposit' && props.guestDeposit <= 0,
    })),
);
const method = computed(() => props.methods.find((m) => m.value === model.value.method));
const isCash = computed(() => model.value.method === 'cash');
const change = computed(() => (isCash.value ? model.value.amount - props.due : 0));
const label = computed(() => (isCash.value ? 'Cash received' : model.value.method === 'deposit' ? 'Amount from guest deposit' : 'Amount paid'));

// GCash, card and deposit pay the balance exactly unless the person types less.
watch(
    () => model.value.method,
    (m) => {
        if (m !== 'cash') {
            model.value.amount = m === 'deposit' ? Math.min(props.due, props.guestDeposit) : props.due;
            model.value.keep_change = false;
        }
    },
);

const changeTiles = computed(() => [
    { value: 'give', label: 'Give change' },
    { value: 'keep', label: `Keep ${formatMoney(Math.max(0, change.value), 'tile')} as guest deposit` },
]);
const changeMode = computed({
    get: () => (model.value.keep_change ? 'keep' : 'give'),
    set: (v: string) => (model.value.keep_change = v === 'keep'),
});
</script>

<template>
    <div class="flex flex-col gap-3">
        <OptionTiles v-if="methods.length > 1" v-model="model.method" :options="tiles" label="Payment method" />
        <UFormField :label="label" name="amount" :error="errors.amount">
            <MoneyInput v-model="model.amount" :due="due" :max="model.method === 'deposit' ? guestDeposit : undefined" />
        </UFormField>
        <UFormField v-if="method?.needs_reference" :label="`${method.label} reference no.`" name="reference_no" :error="errors.reference_no">
            <UInput v-model="model.reference_no" class="w-full" autocomplete="off" />
        </UFormField>
        <template v-if="isCash">
            <MoneyRow label="Change" :amount="Math.max(0, change)" :tone="change > 0 ? 'ok' : 'default'" />
            <p v-if="model.amount > 0 && change < 0" class="text-xs text-muted">Less than the balance: the oldest lines are paid first, the rest stay unpaid.</p>
            <OptionTiles v-if="allowKeepChange && change > 0" v-model="changeMode" :options="changeTiles" label="Change" />
        </template>
    </div>
</template>
