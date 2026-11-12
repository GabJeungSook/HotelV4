<!-- Mockup 58 -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, shallowRef, watch } from 'vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useIdempotencyKey } from '@/Composables/useIdempotencyKey';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * New reservation (58): guest, arrival, stay option and room type are required; a specific room
 * is optional. While the form is filled in, the server checks (partial reload of `quote`)
 * whether a room is free for the whole period and prices it from the rate table. Money paid
 * ahead is a payment on the open shift, held as the guest's deposit and taken off the bill at
 * check-in. The page never sends a price.
 */
interface Quote {
    ok: boolean;
    message: string;
    missing_rate: boolean;
    description?: string;
    room_amount?: number;
    deposit?: number;
    total?: number;
    checkout_at?: string;
}

const props = defineProps<{
    types: { value: number; label: string; rooms: { value: number; label: string }[] }[];
    options: { value: string; label: string; kind: 'hourly' | 'overnight' | 'long_stay'; quantity: number }[];
    methods: { value: string; label: string; needs_reference: boolean }[];
    deposit: { name: string; amount: number };
    today: string;
    quote?: Quote | null;
}>();

const { flash } = useShared();
const { key } = useIdempotencyKey();

const times = Array.from({ length: 36 }, (_, i) => {
    const minutes = 6 * 60 + i * 30;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    const value = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    const label = `${h % 12 === 0 ? 12 : h % 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;

    return { value, label };
});

const form = useForm({
    guest_name: '',
    guest_mobile: '',
    arrival_date: props.today,
    arrival_time: '14:00',
    option: props.options.find((o) => o.kind === 'overnight')?.value ?? props.options[0]?.value ?? '',
    room_type_id: props.types[0]?.value ?? null,
    room_id: null as number | null,
    method: '' as string,
    paid_amount: 0,
    reference_no: '',
    idempotency_key: key.value,
});

const calendar = shallowRef<{ toString(): string } | undefined>(undefined);
watch(calendar, (d) => d && (form.arrival_date = d.toString()));

const option = computed(() => props.options.find((o) => o.value === form.option));
const type = computed(() => props.types.find((t) => t.value === form.room_type_id));
const roomItems = computed(() => [{ value: null, label: `Any ${type.value?.label ?? ''} room` }, ...(type.value?.rooms ?? [])]);
watch(
    () => form.room_type_id,
    () => (form.room_id = null),
);

const methodTiles = computed(() => [{ value: '', label: 'None' }, ...props.methods.map((m) => ({ value: m.value, label: m.label, sub: m.needs_reference ? 'ref. no.' : undefined }))]);
const method = computed(() => props.methods.find((m) => m.value === form.method));
const paying = computed(() => form.method !== '');
const left = computed(() => Math.max(0, (props.quote?.total ?? 0) - (paying.value ? form.paid_amount : 0)));

function check(): void {
    if (!option.value || !form.room_type_id) {
        return;
    }

    router.reload({
        only: ['quote'],
        data: {
            room_type_id: form.room_type_id,
            room_id: form.room_id ?? undefined,
            kind: option.value.kind,
            quantity: option.value.quantity,
            arrival_date: form.arrival_date,
            arrival_time: form.arrival_time,
        },
    });
}

watchDebounced(() => [form.room_type_id, form.room_id, form.option, form.arrival_date, form.arrival_time], check, { debounce: 250, immediate: true });

const ready = computed(
    () =>
        (form.guest_name.match(/\p{L}/gu) ?? []).length >= 3 &&
        (form.guest_mobile === '' || /^09\d{9}$/.test(form.guest_mobile.replace(/\D/g, ''))) &&
        props.quote?.ok === true &&
        (!paying.value || (form.paid_amount > 0 && (!method.value?.needs_reference || form.reference_no.trim() !== ''))),
);

function submit(): void {
    if (!ready.value || !option.value) {
        return;
    }

    form.transform((data) => ({
        guest_name: data.guest_name.trim(),
        guest_mobile: data.guest_mobile.replace(/\D/g, '') || null,
        arrival_date: data.arrival_date,
        arrival_time: data.arrival_time,
        kind: option.value?.kind,
        quantity: option.value?.quantity,
        room_type_id: data.room_type_id,
        room_id: data.room_id,
        paid_amount: paying.value ? data.paid_amount : null,
        method: paying.value ? data.method : null,
        reference_no: paying.value && method.value?.needs_reference ? data.reference_no.trim() : null,
        idempotency_key: data.idempotency_key,
    })).post(route('reservations.store'), { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <FrontDeskLayout current="reservations" title="New reservation">
        <UAlert v-if="flash.error" color="error" variant="subtle" icon="i-lucide-triangle-alert" class="mb-4" :description="flash.error.message" data-test="reserve-error" />
        <form class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1" @submit.prevent="submit">
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Guest and stay</h2>
                <UFormField label="Full name" name="guest_name" :error="form.errors.guest_name">
                    <UInput v-model="form.guest_name" class="w-full" autofocus autocomplete="off" />
                </UFormField>
                <UFormField label="Mobile (optional)" name="guest_mobile" class="mt-3" :error="form.errors.guest_mobile">
                    <UInput v-model="form.guest_mobile" inputmode="numeric" placeholder="0918 555 0177" class="w-full" autocomplete="off" />
                </UFormField>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <UFormField label="Arrival" name="arrival_date" :error="form.errors.arrival_date">
                        <div class="flex gap-2">
                            <UPopover>
                                <UButton color="neutral" variant="outline" icon="i-lucide-calendar" class="flex-1" :label="formatDateTime(`${form.arrival_date}T12:00:00+08:00`, 'shiftDate')" />
                                <template #content>
                                    <UCalendar v-model="calendar" :week-starts-on="1" class="p-2" />
                                </template>
                            </UPopover>
                            <USelect v-model="form.arrival_time" :items="times" class="w-32" aria-label="Arrival time" />
                        </div>
                    </UFormField>
                    <UFormField label="Stay" name="option">
                        <USelect v-model="form.option" :items="options" class="w-full" />
                    </UFormField>
                </div>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    <UFormField label="Room type" name="room_type_id">
                        <USelect v-model="form.room_type_id" :items="types" class="w-full" />
                    </UFormField>
                    <UFormField label="Room (optional)" name="room_id">
                        <USelect v-model="form.room_id" :items="roomItems" class="w-full" />
                    </UFormField>
                </div>
                <UAlert
                    v-if="quote"
                    class="mt-4"
                    :color="quote.ok ? 'success' : 'error'"
                    variant="subtle"
                    :icon="quote.ok ? 'i-lucide-check' : 'i-lucide-triangle-alert'"
                    :description="quote.message"
                    data-test="availability"
                />
            </section>

            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Price</h2>
                <div class="flex flex-col gap-1.5">
                    <MoneyRow :label="quote?.description ?? 'Room'" :amount="quote?.room_amount ?? null" />
                    <MoneyRow v-if="(quote?.deposit ?? 0) > 0" :label="`${deposit.name} deposit`" :amount="quote?.deposit ?? 0" />
                </div>
                <TotalRow label="Total at check-in" :amount="quote?.total ?? null" />

                <h3 class="mt-5 mb-2 text-sm font-semibold text-highlighted">Paid ahead (optional)</h3>
                <OptionTiles v-model="form.method" :options="methodTiles" label="Paid ahead by" />
                <div v-if="paying" class="mt-3 grid grid-cols-2 gap-3">
                    <UFormField label="Amount" name="paid_amount" :error="form.errors.paid_amount">
                        <MoneyInput v-model="form.paid_amount" />
                    </UFormField>
                    <UFormField v-if="method?.needs_reference" label="Reference no." name="reference_no" :error="form.errors.reference_no">
                        <UInput v-model="form.reference_no" class="w-full" autocomplete="off" />
                    </UFormField>
                </div>
                <MoneyRow class="mt-3" label="Left to pay at check-in" :amount="quote?.total !== undefined ? left : null" />
                <p v-if="paying && form.paid_amount > (quote?.total ?? 0)" class="mt-1 text-xs text-muted">More than the total is kept as the guest's deposit.</p>
                <UButton type="submit" class="mt-5" size="xl" block label="Save reservation" :disabled="!ready" :loading="form.processing" />
                <p class="mt-2 text-center text-xs text-muted">Money paid ahead is recorded on this shift as the guest's deposit.</p>
            </section>
        </form>
    </FrontDeskLayout>
</template>
