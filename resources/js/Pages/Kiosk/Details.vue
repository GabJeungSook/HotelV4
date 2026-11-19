<!-- Mockup 29 · 53 at the kiosk (room just taken → back to 28) -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import KioskFooter from '@/Components/KioskFooter.vue';
import KioskHeader from '@/Components/KioskHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { useIdempotencyKey } from '@/Composables/useIdempotencyKey';
import { useKioskIdle } from '@/Composables/useKioskIdle';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import KioskLayout from '@/Layouts/KioskLayout.vue';

/**
 * Your name (29): name (at least 3 letters) and an optional mobile number (all 11 digits or
 * blank), checked here and again on the server. The discount flagged for the kiosk is shown as
 * applied; every amount is the server's. "Hold my room" sends the type, hours, name, mobile and
 * a key — never a price — and stays grey until the form is valid. A room taken a moment earlier
 * sends the guest back to 28, where the next room is offered. Idle 60 s → welcome (27).
 */
interface Offer {
    type: { id: number; name: string };
    room: { id: number; number: string; label: string };
    kind: 'hourly' | 'long_stay';
    quantity: number;
    stay_label: string;
    room_amount: number;
    discount: { name: string; amount: number; text: string } | null;
    deposit: { name: string; amount: number };
    total: number;
}

const props = defineProps<{ offer: Offer }>();

const { flash } = useShared();
const { key } = useIdempotencyKey();
useKioskIdle(60, () => router.visit(route('kiosk.index')));

const form = useForm({
    room_type_id: props.offer.type.id,
    kind: props.offer.kind,
    hours: props.offer.kind === 'hourly' ? props.offer.quantity : null,
    days: props.offer.kind === 'long_stay' ? props.offer.quantity : null,
    guest_name: '',
    guest_mobile: '',
    idempotency_key: key.value,
});
const mobileTouched = ref(false);

const digits = computed(() => form.guest_mobile.replace(/\D/g, ''));
const nameOk = computed(() => (form.guest_name.match(/\p{L}/gu) ?? []).length >= 3);
const mobileOk = computed(() => digits.value === '' || /^09\d{9}$/.test(digits.value));
const mobileError = computed(() => form.errors.guest_mobile ?? (mobileTouched.value && !mobileOk.value ? 'Enter all 11 digits, or leave it blank.' : undefined));
const ready = computed(() => nameOk.value && mobileOk.value);

watch(digits, (d) => {
    if (d.length >= 11) {
        mobileTouched.value = true;
    }
});

watch(
    () => flash.value.error,
    (e) => {
        if (e?.code === 'room_taken') {
            router.visit(route('kiosk.start', { room_type_id: props.offer.type.id, kind: props.offer.kind, quantity: props.offer.quantity, taken: e.room ?? props.offer.room.number }));
        }
    },
);

const rows = computed(() => [
    { label: `${props.offer.type.name} · Room ${props.offer.room.number} · ${props.offer.stay_label}`, amount: props.offer.room_amount },
    ...(props.offer.discount ? [{ label: 'Discount', amount: -props.offer.discount.amount }] : []),
    ...(props.offer.deposit.amount > 0 ? [{ label: `${props.offer.deposit.name} deposit`, amount: props.offer.deposit.amount }] : []),
]);

const error = computed(() => (flash.value.error && flash.value.error.code !== 'room_taken' ? flash.value.error.message : undefined));

function back(): void {
    router.visit(route('kiosk.start', { room_type_id: props.offer.type.id, kind: props.offer.kind, quantity: props.offer.quantity }));
}

function submit(): void {
    if (!ready.value) {
        mobileTouched.value = true;

        return;
    }

    form.transform((data) => ({ ...data, guest_name: data.guest_name.trim(), guest_mobile: digits.value || null })).post(route('kiosk.hold'), { preserveState: true });
}
</script>

<template>
    <KioskLayout title="Your name">
        <KioskHeader :current="2" />
        <UAlert v-if="error" class="mt-6 text-xl" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="error" />

        <h1 class="mt-8 text-[40px] leading-[48px] font-semibold text-highlighted">Your details</h1>
        <p class="mt-2 text-2xl leading-9 text-muted">We hold the room under your name. Pay at the front desk to check in.</p>

        <form class="mt-7 flex flex-col gap-6" @submit.prevent="submit">
            <UFormField label="Your name" name="guest_name" :error="form.errors.guest_name" :ui="{ label: 'text-2xl leading-9', error: 'text-xl' }">
                <UInput v-model="form.guest_name" class="kiosk w-full" size="xl" :ui="{ base: 'h-[72px] text-[28px]' }" autocomplete="off" autofocus />
            </UFormField>
            <UFormField label="Mobile number (optional)" name="guest_mobile" :error="mobileError" :ui="{ label: 'text-2xl leading-9', error: 'text-xl' }">
                <UInput
                    v-model="form.guest_mobile"
                    class="kiosk w-full"
                    size="xl"
                    :ui="{ base: 'h-[72px] text-[28px]' }"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="13"
                    @blur="mobileTouched = true"
                />
            </UFormField>

            <div v-if="offer.discount" class="rounded-card border border-(--hm-promo-line) bg-accent-50 px-7 py-5" data-test="kiosk-discount">
                <div class="flex items-center justify-between gap-4">
                    <b class="text-2xl text-highlighted">{{ formatMoney(offer.discount.amount, 'tile') }} off this stay</b>
                    <StatusLabel tone="ok" label="Applied" size="lg" />
                </div>
                <p class="mt-1 text-lg">{{ offer.discount.text }}</p>
            </div>

            <!-- Kiosk scale (DESIGN_SYSTEM §3.3): the rows are 24 px, so they are drawn here rather than with MoneyRow. -->
            <dl class="rounded-card border border-default bg-default px-7 py-5 text-2xl leading-[44px]" data-test="kiosk-summary">
                <div v-for="row in rows" :key="row.label" class="flex justify-between gap-4">
                    <dt class="text-muted">{{ row.label }}</dt>
                    <dd class="font-medium text-highlighted tabular">{{ formatMoney(row.amount) }}</dd>
                </div>
                <div class="mt-2 flex justify-between gap-4 border-t border-default pt-3 text-[28px] font-semibold text-highlighted">
                    <dt>Pay at front desk</dt>
                    <dd class="tabular">{{ formatMoney(offer.total) }}</dd>
                </div>
            </dl>
        </form>

        <KioskFooter>
            <template #back>
                <UButton class="h-[72px] justify-center text-2xl" size="xl" block color="neutral" variant="outline" label="Back" @click="back" />
            </template>
            <UButton class="h-[72px] justify-center text-2xl" size="xl" block label="Hold my room" :disabled="!ready" :loading="form.processing" data-test="hold" @click="submit" />
        </KioskFooter>
    </KioskLayout>
</template>
