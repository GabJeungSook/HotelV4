<!-- Mockup 7 -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { computed, ref } from 'vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import TotalRow from '@/Components/TotalRow.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatDuration } from '@/Composables/useDateTime';
import { useIdempotencyKey } from '@/Composables/useIdempotencyKey';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Confirm a kiosk hold (7): room, hours and discount are what the guest picked at the kiosk; the
 * front desk only takes the money. The hours start when "Check in" is pressed. The countdown is
 * the server's hold_expires_at; once it has passed, or the server refuses with "This hold has
 * expired", the form is closed and the room is back on sale. "Release" cancels the hold after
 * the confirm dialog.
 */
interface Hold {
    id: number;
    code: string;
    guest_name: string;
    room: string | null;
    type: string;
    floor: string;
    stay: string;
    discount: string;
    expires_at: string | null;
    expired: boolean;
    state: string;
}

const props = defineProps<{
    hold: Hold;
    lines: { label: string; amount: number }[];
    total: number | null;
    missing: string | null;
    methods: { value: string; label: string; needs_reference: boolean }[];
    now: string;
}>();

const { flash } = useShared();
const { key } = useIdempotencyKey();
const confirm = useConfirmDialog();

const offset = Date.parse(props.now) - Date.now();
const tick = ref(Date.now());
useIntervalFn(() => (tick.value = Date.now()), 1000);
const secondsLeft = computed(() => (props.hold.expires_at ? Math.max(0, Math.floor((Date.parse(props.hold.expires_at) - (tick.value + offset)) / 1000)) : 0));

const refused = computed(() => flash.value.error?.code === 'hold_expired');
const expired = computed(() => props.hold.expired || secondsLeft.value <= 0 || refused.value);

const form = useForm({
    method: props.methods[0]?.value ?? 'cash',
    tendered: 0,
    reference_no: '',
    keep_change: false,
    idempotency_key: key.value,
});

const total = computed(() => props.total ?? 0);
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

const ready = computed(() => !expired.value && props.total !== null && (isCash.value ? form.tendered >= total.value : form.reference_no.trim() !== ''));
const error = computed(() => (flash.value.error && !refused.value ? flash.value.error.message : undefined));

function submit(): void {
    if (!ready.value) {
        return;
    }

    form.transform((data) => ({
        ...data,
        tendered: isCash.value ? data.tendered : null,
        reference_no: isCash.value ? null : data.reference_no.trim(),
        keep_change: isCash.value && data.keep_change && change.value > 0,
    })).post(route('stays.confirm.store', props.hold.id), { preserveState: true, preserveScroll: true, replace: true });
}

async function release(): Promise<void> {
    const ok = await confirm({
        title: `Release Room ${props.hold.room}?`,
        description: `${props.hold.guest_name}'s hold is cancelled and the room goes back on sale. Nothing was paid, so nothing is refunded.`,
        confirmLabel: 'Release room',
        cancelLabel: 'Keep hold',
        confirmColor: 'error',
    });

    if (ok) {
        router.post(route('stays.release', props.hold.id));
    }
}
</script>

<template>
    <FrontDeskLayout current="board" :title="`Confirm kiosk check-in — Room ${hold.room}`">
        <PageHeader>
            <template #status>
                <StatusLabel v-if="!expired" tone="warn" :label="`Hold ends in ${formatDuration(secondsLeft, 'countdown')}`" data-test="hold-countdown" />
                <StatusLabel v-else tone="bad" label="Hold expired" />
            </template>
        </PageHeader>
        <p class="mb-4 text-[13px] text-muted">{{ hold.type }} · {{ hold.floor }}</p>
        <UAlert
            v-if="expired"
            color="error"
            variant="subtle"
            icon="i-solar-danger-triangle-bold-duotone"
            class="mb-4"
            title="This hold has expired"
            description="The room is back on sale. If it is still free you can check the guest in as a walk-in from the board."
            data-test="hold-expired"
        />
        <UAlert v-else-if="error" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" class="mb-4" :description="error" />
        <UAlert v-if="missing" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" class="mb-4" :title="missing" description="Ask the admin to add the rate. The app will not check in a guest without a price." />

        <form class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1" @submit.prevent="submit">
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Picked at the kiosk</h2>
                <div class="flex flex-col gap-1.5">
                    <MoneyRow label="Guest" :text="hold.guest_name" />
                    <MoneyRow label="Room" :text="`${hold.room} · ${hold.type}`" />
                    <MoneyRow label="Stay" :text="hold.stay" />
                    <MoneyRow label="Discount" :text="hold.discount" />
                    <MoneyRow label="Hold code" :text="hold.code" />
                </div>
                <UAlert class="mt-4" color="info" variant="subtle" icon="i-solar-clock-circle-bold-duotone" :description="`The ${hold.stay.replace(' hrs', ' hours')} start when you press Check in, not when the guest tapped the kiosk.`" />
            </section>

            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">To collect</h2>
                <div class="flex flex-col gap-1.5">
                    <MoneyRow v-for="l in lines" :key="l.label" :label="l.label" :amount="l.amount" />
                </div>
                <TotalRow label="Total" :amount="total" />
                <OptionTiles v-if="methods.length > 1" v-model="form.method" :options="methodTiles" label="Payment method" class="mt-4" />
                <template v-if="isCash">
                    <UFormField label="Cash received" name="tendered" class="mt-4" :error="form.errors.tendered">
                        <MoneyInput v-model="form.tendered" :due="total" :disabled="expired" />
                    </UFormField>
                    <MoneyRow class="mt-2" label="Change" :amount="Math.max(0, change)" :tone="change > 0 ? 'ok' : 'default'" />
                    <p v-if="form.tendered > 0 && change < 0" class="mt-1 text-xs font-semibold text-error">Cash received must cover the total of {{ formatMoney(total) }}.</p>
                    <OptionTiles v-if="change > 0" v-model="changeMode" :options="changeTiles" label="Change" class="mt-3" />
                </template>
                <UFormField v-else :label="`${method?.label ?? ''} reference no.`" name="reference_no" class="mt-4" :error="form.errors.reference_no">
                    <UInput v-model="form.reference_no" class="w-full" autocomplete="off" :disabled="expired" />
                </UFormField>
                <div class="mt-5 flex gap-2">
                    <UButton type="submit" class="flex-1 justify-center" size="xl" label="Check in and print slip" :disabled="!ready" :loading="form.processing" />
                    <UButton v-if="!expired" size="xl" color="neutral" variant="outline" label="Release" @click="release" />
                    <UButton v-else size="xl" color="neutral" variant="outline" label="Back to room board" @click="router.visit(route('board'))" />
                </div>
            </section>
        </form>
    </FrontDeskLayout>
</template>
