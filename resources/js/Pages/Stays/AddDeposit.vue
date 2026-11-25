<!-- Mockup 9 (Add deposit sheet) -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import type { PaymentMethodOption } from '@/Components/PaymentFields.vue';
import { newKey } from '@/Composables/useIdempotencyKey';

/**
 * Add deposit (9): the guest hands over cash (or GCash) to keep on their account. It is money in,
 * not a charge — never funded from a deposit, so the deposit method is left out. Each opening
 * carries a new idempotency key, so a double tap adds one deposit. It can then pay later charges
 * (Take payment → Guest deposit) and the rest is returned at check-out.
 */
const props = defineProps<{ stayId: number; room: string | null; methods: PaymentMethodOption[] }>();

const open = defineModel<boolean>('open', { default: false });

const payMethods = computed(() => props.methods.filter((m) => m.value !== 'deposit'));
const amount = ref(0);
const method = ref('cash');
const referenceNo = ref('');
const form = useForm({ idempotency_key: newKey() });
const error = ref<string | undefined>(undefined);

watch(open, (value) => {
    if (value) {
        amount.value = 0;
        method.value = payMethods.value[0]?.value ?? 'cash';
        referenceNo.value = '';
        form.idempotency_key = newKey();
        form.clearErrors();
        error.value = undefined;
    }
});

const current = computed(() => payMethods.value.find((m) => m.value === method.value));
const needsRef = computed(() => !!current.value?.needs_reference);
const tiles = computed(() => payMethods.value.map((m) => ({ value: m.value, label: m.label, sub: m.needs_reference ? 'ref. no.' : undefined })));
const ready = computed(() => amount.value > 0 && (!needsRef.value || referenceNo.value.trim() !== ''));

function save(): void {
    form
        .transform(() => ({ method: method.value, amount: amount.value, reference_no: referenceNo.value.trim() || null, idempotency_key: form.idempotency_key }))
        .post(route('stays.deposit', props.stayId), {
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
    <UModal v-model:open="open" title="Add deposit" :description="`Room ${room} · money kept on the guest account`" :ui="{ content: 'max-w-md' }">
        <template #body>
            <form id="add-deposit-form" @submit.prevent="save">
                <UAlert v-if="error" class="mb-3" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="error" />
                <OptionTiles v-if="tiles.length > 1" v-model="method" :options="tiles" label="Payment method" />
                <UFormField class="mt-4" label="Amount to deposit" name="amount" :error="form.errors.amount">
                    <MoneyInput v-model="amount" autofocus />
                </UFormField>
                <UFormField v-if="needsRef" class="mt-4" :label="`${current?.label ?? ''} reference no.`" name="reference_no" :error="form.errors.reference_no">
                    <UInput v-model="referenceNo" class="w-full" />
                </UFormField>
                <p class="mt-3 text-[13px] text-muted">It can pay later charges (Take payment → Guest deposit); the rest is returned at check-out.</p>
            </form>
        </template>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <UButton color="neutral" variant="ghost" label="Cancel" @click="open = false" />
                <UButton label="Add deposit" :disabled="!ready" :loading="form.processing" type="submit" form="add-deposit-form" />
            </div>
        </template>
    </UModal>
</template>
