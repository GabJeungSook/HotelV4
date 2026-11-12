<!-- Mockup 16 · 17 · 18 (the steps are Checkout/Returns, Settle and Done) -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { PaymentMethodOption, PaymentValue } from '@/Components/PaymentFields.vue';
import StepBar from '@/Components/StepBar.vue';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import Done, { type DoneView } from './Checkout/Done.vue';
import Returns from './Checkout/Returns.vue';
import Settle, { type Settlement } from './Checkout/Settle.vue';

/**
 * Check-out wizard (16–18). Steps 1 and 2 are page state, not history entries; step 2 asks the
 * server for the bill with the step 1 answers (a partial reload of `screen`). "Check out guest"
 * saves everything in one CheckOutGuest transaction; a second press does nothing.
 */
const props = defineProps<{
    screen: {
        stay: { id: number; code: string; guest_name: string; room: string | null };
        done: DoneView | null;
        lost?: { key: number; remote: number };
        damage_products?: { id: number; name: string; price: number; stock: number | null }[];
        settle?: Settlement;
        methods?: PaymentMethodOption[];
    };
}>();

const { flash } = useShared();
const step = ref<1 | 2>(1);
const key = ref<boolean | null>(null);
const remote = ref<boolean | null>(null);
const damages = ref<Record<string, number>>({});
const claimed = ref<boolean | null>(null);
const loading = ref(false);
const pay = ref<PaymentValue>({ method: props.screen.methods?.[0]?.value ?? 'cash', amount: 0, reference_no: '', keep_change: false });
const form = useForm({});

const current = computed(() => (props.screen.done ? 3 : step.value));
const settle = computed(() => props.screen.settle);
const answered = computed(() => key.value !== null && remote.value !== null);
const depositAnswered = computed(() => !settle.value || settle.value.return <= 0 || claimed.value !== null);
const covered = computed(() => !settle.value || settle.value.balance <= 0 || pay.value.amount >= settle.value.balance);
const canCheckOut = computed(() => !loading.value && depositAnswered.value && covered.value);
const error = computed(() => flash.value.error?.message);

function next(): void {
    loading.value = true;
    router.reload({
        only: ['screen'],
        data: { key: key.value ? 1 : 0, remote: remote.value ? 1 : 0, damages: damages.value },
        onSuccess: () => {
            step.value = 2;
            pay.value.amount = settle.value?.balance && settle.value.balance > 0 ? settle.value.balance : 0;
        },
        onFinish: () => (loading.value = false),
    });
}

function checkOut(): void {
    const balance = settle.value?.balance ?? 0;

    form.transform(() => ({
        key_returned: key.value,
        remote_returned: remote.value,
        damages: damages.value,
        deposit_claimed: claimed.value ?? true,
        ...(balance > 0 ? { method: pay.value.method, amount: pay.value.amount, reference_no: pay.value.reference_no.trim() || null } : {}),
    })).post(route('stays.checkout.store', props.screen.stay.id), { preserveScroll: true, replace: true });
}
</script>

<template>
    <FrontDeskLayout current="board" :title="`Check out — Room ${screen.stay.room ?? ''} · ${screen.stay.guest_name}`">
        <StepBar class="no-print mb-4" :steps="['Items returned', 'Settle', 'Done']" :current="current" />
        <UAlert v-if="error && current !== 3" class="mb-4" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="error" />

        <template v-if="screen.done">
            <Done :room="screen.stay.room" :guest="screen.stay.guest_name" :code="screen.stay.code" :done="screen.done" />
        </template>
        <template v-else-if="step === 1">
            <Returns v-model:key-returned="key" v-model:remote-returned="remote" v-model:damages="damages" :lost="screen.lost ?? { key: 0, remote: 0 }" :products="screen.damage_products ?? []" />
            <div class="mt-4 flex justify-end gap-2">
                <UButton color="neutral" variant="ghost" label="Back to folio" @click="router.visit(route('stays.show', screen.stay.id))" />
                <UButton label="Next: settle" :disabled="!answered" :loading="loading" @click="next" />
            </div>
        </template>
        <template v-else-if="settle">
            <Settle v-model:payment="pay" v-model:claimed="claimed" :settle="settle" :methods="screen.methods ?? []" :loading="loading" />
            <div class="mt-4 flex justify-end gap-2">
                <UButton color="neutral" variant="ghost" label="Back" @click="step = 1" />
                <UButton label="Check out guest" :disabled="!canCheckOut" :loading="form.processing" @click="checkOut" />
            </div>
        </template>
    </FrontDeskLayout>
</template>
