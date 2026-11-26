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
 * blank), checked here and again on the server. A promo is shown as applied automatically; a
 * senior-citizen or PWD discount offered on this rate is shown as a toggle the guest ticks — it
 * goes on the bill, and the guest presents the ID at the front desk, which verifies or revokes it.
 * Only one discount applies. "Hold my room" sends the type, hours, name, mobile, the ticked
 * discount and a key — never a price — and stays grey until the form is valid. Idle 60 s → 27.
 */
interface Selectable {
    id: number;
    name: string;
    discount_amount: number;
    total: number;
}

interface Offer {
    type: { id: number; name: string };
    room: { id: number; number: string; label: string };
    kind: 'hourly' | 'long_stay';
    quantity: number;
    stay_label: string;
    room_amount: number;
    discount: { name: string; amount: number; text: string } | null;
    selectable: Selectable[];
    deposit: { name: string; amount: number };
    total: number;
}

const props = defineProps<{ offer: Offer }>();

const { flash } = useShared();
const { key } = useIdempotencyKey();
useKioskIdle(60, () => router.visit(route('kiosk.index')));

// The guest's ticked discount (senior citizen / PWD), or none — one discount per stay.
const chosenId = ref<number | null>(null);
const chosen = computed(() => props.offer.selectable.find((s) => s.id === chosenId.value) ?? null);

function pick(id: number, on: boolean): void {
    chosenId.value = on ? id : null;
}

// The discount actually on the bill: the ticked one, else the auto promo.
const activeDiscount = computed(() => (chosen.value ? { name: chosen.value.name, amount: chosen.value.discount_amount } : props.offer.discount));
const total = computed(() => (chosen.value ? chosen.value.total : props.offer.total));

const form = useForm({
    room_type_id: props.offer.type.id,
    kind: props.offer.kind,
    hours: props.offer.kind === 'hourly' ? props.offer.quantity : null,
    days: props.offer.kind === 'long_stay' ? props.offer.quantity : null,
    guest_name: '',
    guest_mobile: '',
    discount_id: null as number | null,
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
    ...(activeDiscount.value ? [{ label: 'Discount', amount: -activeDiscount.value.amount }] : []),
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

    form.transform((data) => ({ ...data, guest_name: data.guest_name.trim(), guest_mobile: digits.value || null, discount_id: chosenId.value })).post(route('kiosk.hold'), { preserveState: true });
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

            <!-- Guest-ticked discounts (senior citizen, PWD) offered on this rate. One applies. -->
            <div v-if="offer.selectable.length" class="flex flex-col gap-3" data-test="kiosk-selectable">
                <div class="text-2xl font-semibold text-highlighted">Senior citizen or PWD?</div>
                <p class="text-lg leading-7 text-muted">Tick one and show your ID at the front desk. Staff confirm it when you pay.</p>
                <label
                    v-for="s in offer.selectable"
                    :key="s.id"
                    class="flex cursor-pointer items-center justify-between gap-4 rounded-card border px-7 py-5 transition-colors"
                    :class="chosenId === s.id ? 'border-select-line bg-select' : 'border-default bg-default'"
                    :data-test="`kiosk-discount-${s.id}`"
                >
                    <div>
                        <div class="text-2xl font-medium text-highlighted">{{ s.name }}</div>
                        <div class="mt-0.5 text-lg text-muted">−{{ formatMoney(s.discount_amount, 'tile') }} · present ID at the desk</div>
                    </div>
                    <USwitch :model-value="chosenId === s.id" size="xl" :aria-label="`Apply ${s.name}`" @update:model-value="(v) => pick(s.id, v === true)" />
                </label>
            </div>

            <div v-if="activeDiscount" class="rounded-card border border-(--hm-promo-line) bg-accent-50 px-7 py-5" data-test="kiosk-discount">
                <div class="flex items-center justify-between gap-4">
                    <b class="text-2xl text-highlighted">{{ formatMoney(activeDiscount.amount, 'tile') }} off this stay</b>
                    <StatusLabel tone="ok" label="Applied" size="lg" />
                </div>
                <p class="mt-1 text-lg">{{ chosen ? `${chosen.name} — show your ID at the front desk.` : (offer.discount?.text ?? '') }}</p>
            </div>

            <!-- Kiosk scale (DESIGN_SYSTEM §3.3): the rows are 24 px, so they are drawn here rather than with MoneyRow. -->
            <dl class="rounded-card border border-default bg-default px-7 py-5 text-2xl leading-[44px]" data-test="kiosk-summary">
                <div v-for="row in rows" :key="row.label" class="flex justify-between gap-4">
                    <dt class="text-muted">{{ row.label }}</dt>
                    <dd class="font-medium text-highlighted tabular">{{ formatMoney(row.amount) }}</dd>
                </div>
                <div class="mt-2 flex justify-between gap-4 border-t border-default pt-3 text-[28px] font-semibold text-highlighted">
                    <dt>Pay at front desk</dt>
                    <dd class="tabular">{{ formatMoney(total) }}</dd>
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
