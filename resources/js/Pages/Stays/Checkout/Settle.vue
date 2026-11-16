<!-- Mockup 17 -->
<script setup lang="ts">
import { computed } from 'vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import PaymentFields, { type PaymentMethodOption, type PaymentValue } from '@/Components/PaymentFields.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { formatMoney } from '@/Composables/useMoney';

/**
 * Check-out step 2 — settle (17): the bill and the deposits as CheckOutGuest will save them,
 * worked out by the server from the step 1 answers. A balance is collected here with the
 * payment panel of 11; then the deposit box is answered.
 */
export interface Settlement {
    charges: number;
    paid: number;
    balance: number;
    damages: number;
    key_held: number;
    guest_held: number;
    deposit_name: string;
    less_damages: number;
    return: number;
}

const props = defineProps<{ settle: Settlement; methods: PaymentMethodOption[]; loading?: boolean }>();

const pay = defineModel<PaymentValue>('payment', { required: true });
const claimed = defineModel<boolean | null>('claimed', { required: true });

const depositTiles = computed(() => [
    { value: 'yes', label: `I handed ${formatMoney(props.settle.return)} to the guest` },
    { value: 'no', label: 'Guest did not claim' },
]);
const claimTile = computed({ get: () => (claimed.value === null ? null : claimed.value ? 'yes' : 'no'), set: (v) => (claimed.value = v === 'yes') });
</script>

<template>
    <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1" :class="loading ? 'opacity-60' : ''">
        <section class="rounded-card border border-default bg-default p-5">
            <h2 class="mb-3 text-base font-semibold text-highlighted">Bill</h2>
            <div class="flex flex-col gap-1.5">
                <MoneyRow label="Total charges" :amount="settle.charges" />
                <MoneyRow v-if="settle.damages > 0" label="of which damages and missing items" :amount="settle.damages" />
                <MoneyRow label="Already paid" :amount="settle.paid" />
            </div>
            <TotalRow label="Balance due" :amount="settle.balance" :tone="settle.balance > 0 ? 'bad' : 'ok'" />
            <UAlert v-if="settle.balance <= 0" class="mt-3" color="success" variant="subtle" icon="i-solar-check-circle-linear" description="Nothing left to collect." />
            <PaymentFields v-else v-model="pay" class="mt-4" :methods="methods" :due="settle.balance" :allow-keep-change="false" />
        </section>
        <section class="rounded-card border border-default bg-default p-5">
            <h2 class="mb-3 text-base font-semibold text-highlighted">Deposit</h2>
            <div class="flex flex-col gap-1.5">
                <MoneyRow :label="`${settle.deposit_name} deposit held`" :amount="settle.key_held" />
                <MoneyRow v-if="settle.guest_held > 0" label="Guest deposit held" :amount="settle.guest_held" />
                <MoneyRow label="Less charges for missing items" :amount="settle.less_damages" />
            </div>
            <TotalRow label="Return to guest" :amount="settle.return" />
            <OptionTiles v-if="settle.return > 0" v-model="claimTile" class="mt-4" :options="depositTiles" :columns="1" label="Deposit" />
            <p v-else class="mt-3 text-[13px] text-muted">No deposit is left to hand back.</p>
        </section>
    </div>
</template>
