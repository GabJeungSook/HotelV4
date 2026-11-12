<!-- Mockup 11 -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import PaymentFields, { type PaymentMethodOption, type PaymentValue } from '@/Components/PaymentFields.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { newKey } from '@/Composables/useIdempotencyKey';

/**
 * Take payment (11): one payment can settle several lines, oldest first; less than the balance
 * is allowed. Each opening of the sheet carries a new idempotency key, so a double tap saves one
 * payment. With `charge` set it is the second half of "Add and take payment now" (10): the charge
 * and the payment are saved together by AddCharges.
 */
const props = defineProps<{
    stayId: number;
    room: string | null;
    lines: { description: string; due: number }[];
    balance: number;
    guestDeposit: number;
    methods: PaymentMethodOption[];
    charge?: { items: Record<string, number>; total: number; key: string } | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const due = computed(() => props.balance + (props.charge?.total ?? 0));
const pay = ref<PaymentValue>({ method: 'cash', amount: 0, reference_no: '', keep_change: false });
const form = useForm({ idempotency_key: newKey() });

watch(open, (value) => {
    if (value) {
        pay.value = { method: props.methods[0]?.value ?? 'cash', amount: due.value, reference_no: '', keep_change: false };
        form.idempotency_key = newKey();
        form.clearErrors();
        error.value = undefined;
    }
});

const error = ref<string | undefined>(undefined);
const ready = computed(() => pay.value.amount > 0 && (!props.methods.find((m) => m.value === pay.value.method)?.needs_reference || pay.value.reference_no.trim() !== ''));

function save(): void {
    const payload = { method: pay.value.method, amount: pay.value.amount, reference_no: pay.value.reference_no.trim() || null, keep_change: pay.value.keep_change };
    const url = props.charge ? route('stays.charge', props.stayId) : route('stays.pay', props.stayId);
    const data = props.charge ? { ...payload, items: props.charge.items, pay_now: true, idempotency_key: props.charge.key } : { ...payload, idempotency_key: form.idempotency_key };

    form.transform(() => data).post(url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { message: string } } | undefined)?.error;
            error.value = e?.message;

            if (!e) {
                open.value = false;
            }
        },
    });
}
</script>

<template>
    <UModal v-model:open="open" title="Take payment" :description="`Room ${room} · ${lines.length + (charge ? 1 : 0)} unpaid ${lines.length + (charge ? 1 : 0) === 1 ? 'line' : 'lines'}`" :ui="{ content: 'max-w-md' }">
        <template #body>
            <UAlert v-if="error" class="mb-3" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="error" />
            <div class="flex flex-col gap-1.5">
                <MoneyRow v-for="(l, i) in lines" :key="i" :label="l.description" :amount="l.due" />
                <MoneyRow v-if="charge" label="New charges" :amount="charge.total" />
            </div>
            <TotalRow label="Balance due" :amount="due" />
            <PaymentFields v-model="pay" class="mt-4" :methods="methods" :due="due" :guest-deposit="guestDeposit" :errors="form.errors" />
        </template>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <UButton color="neutral" variant="ghost" label="Cancel" @click="open = false" />
                <UButton label="Save payment" :disabled="!ready" :loading="form.processing" @click="save" />
            </div>
        </template>
    </UModal>
</template>
