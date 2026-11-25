<!-- Mockup 12 · 52 (missing rate) -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import PaymentFields, { type PaymentMethodOption, type PaymentValue } from '@/Components/PaymentFields.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useIdempotencyKey } from '@/Composables/useIdempotencyKey';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Extend stay (12): only lengths whose every needed rate exists can be picked; the price and how
 * it was worked out come from PricingService. "Extend and take payment" or "Extend, pay later".
 */
interface Option {
    hours: number;
    amount: number | null;
    parts: { label: string; amount: number }[];
    cycle_hours_used: number | null;
    new_checkout_at: string | null;
    disabled: boolean;
    missing: string | null;
}

const props = defineProps<{
    stay: { id: number; guest_name: string; room: string | null; type: string; expected_checkout_at: string | null; now: string };
    options: Option[];
    rule: 'cycle' | 'per_hour' | 'block';
    cycle_hours: number;
    cycle_hours_used: number;
    methods: PaymentMethodOption[];
    balance: number;
}>();

const { flash } = useShared();
const { key, renew } = useIdempotencyKey();
const first = props.options.find((o) => !o.disabled);
const form = useForm({ hours: first?.hours ?? 0, pay_now: true, method: 'cash', amount: 0, reference_no: '', keep_change: false });
const pay = ref<PaymentValue>({ method: props.methods[0]?.value ?? 'cash', amount: first?.amount ?? 0, reference_no: '', keep_change: false });

const option = computed(() => props.options.find((o) => o.hours === form.hours));
watch(option, (o) => (pay.value.amount = o?.amount ?? 0));

const tiles = computed(() => props.options.map((o) => ({ value: o.hours, label: `${o.hours} hrs`, sub: o.disabled ? 'no rate' : formatMoney(o.amount ?? 0, 'tile'), disabled: o.disabled })));
const missing = computed(() => props.options.filter((o) => o.disabled).map((o) => o.missing ?? ''));
const none = computed(() => !first);

const explain = computed(() => {
    if (props.rule !== 'cycle') {
        return props.rule === 'per_hour' ? 'Each extra hour is charged at the branch’s per-hour extension price.' : 'An extension is charged at the extension rate for the hours added.';
    }

    const left = Math.max(0, props.cycle_hours - props.cycle_hours_used);
    let text = `${props.cycle_hours_used} of ${props.cycle_hours} cycle hours used. Up to ${left} more hours are at the extension rate. Beyond that the regular ${props.stay.type} rate applies`;
    const o = option.value;

    if (o && o.parts.length > 1) {
        text += `, so ${o.hours} hours is ${o.parts.map((p) => formatMoney(p.amount, 'tile')).join(' + ')} = ${formatMoney(o.amount ?? 0, 'tile')}`;
    }

    return `${text}.`;
});

const errorText = computed(() => flash.value.error?.message);

function save(payNow: boolean): void {
    form.transform(() => ({
        idempotency_key: key.value,
        hours: form.hours,
        pay_now: payNow,
        ...(payNow ? { method: pay.value.method, amount: pay.value.amount, reference_no: pay.value.reference_no.trim() || null, keep_change: pay.value.keep_change } : {}),
    })).post(route('stays.extend.store', props.stay.id), { preserveScroll: true, preserveState: true, onSuccess: () => renew() });
}
</script>

<template>
    <FrontDeskLayout current="board" :title="`Extend stay — Room ${stay.room ?? ''} · ${stay.guest_name}`">
        <UAlert v-if="errorText" class="mb-4" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="errorText" />
        <form class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1" @submit.prevent="option && !option.disabled && pay.amount > 0 && save(true)">
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Add hours</h2>
                <OptionTiles v-model="form.hours" :options="tiles" label="Hours to add" />
                <UAlert
                    v-for="m in missing"
                    :key="m"
                    class="mt-3"
                    color="error"
                    variant="subtle"
                    icon="i-solar-danger-triangle-bold-duotone"
                    :title="m"
                    description="Pick another length, or ask the admin to add the rate. Nothing is ever charged at ₱0."
                    data-test="missing-rate"
                />
                <UAlert v-if="!none" class="mt-3" color="info" variant="subtle" icon="i-solar-clock-circle-bold-duotone" :description="explain" />
                <div class="mt-4 flex flex-col gap-1.5">
                    <MoneyRow label="Expected check-out now" :text="formatDateTime(stay.expected_checkout_at, 'auto', stay.now)" />
                    <MoneyRow label="After extending" :text="option?.new_checkout_at ? formatDateTime(option.new_checkout_at, 'auto', stay.now) : '—'" tone="primary" />
                </div>
            </section>
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">To collect</h2>
                <div class="flex flex-col gap-1.5">
                    <MoneyRow v-for="p in option?.parts ?? []" :key="p.label" :label="p.label" :amount="p.amount" />
                </div>
                <TotalRow label="Total" :amount="option?.amount ?? null" />
                <MoneyRow v-if="balance > 0" class="mt-2" label="Already unpaid on the account" :amount="balance" />
                <PaymentFields v-model="pay" class="mt-4" :methods="methods" :due="(option?.amount ?? 0) + balance" :errors="form.errors" />
                <UButton class="mt-5" size="xl" block label="Extend and take payment" :disabled="!option || option.disabled || pay.amount <= 0" :loading="form.processing" type="submit" />
                <UButton class="mt-2" block color="neutral" variant="outline" label="Extend, pay later" :disabled="!option || option.disabled || form.processing" @click="save(false)" />
                <UButton class="mt-2" block color="neutral" variant="ghost" label="Back to account" @click="router.visit(route('stays.show', stay.id))" />
            </section>
        </form>
    </FrontDeskLayout>
</template>
