<!-- Mockup 13 · 14 (supervisor approval) · 52 · 53 -->
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ApprovalPin, { type Approver } from '@/Components/ApprovalPin.vue';
import ConflictDialog from '@/Components/ConflictDialog.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import PaymentFields, { type PaymentMethodOption, type PaymentValue } from '@/Components/PaymentFields.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Transfer room (13): only free rooms are offered, each with the price difference worked out by
 * PricingService. The original room charge is never touched; an upgrade adds one line to
 * collect, a downgrade credits the guest deposit. Saving asks for a supervisor PIN (14).
 */
interface FreeRoom {
    id: number;
    number: string;
    type: string;
    type_id: number;
    difference: number | null;
    new_price: number | null;
    missing: string | null;
}

const props = defineProps<{
    stay: { id: number; guest_name: string; room: string | null; type: string; expected_checkout_at: string | null; now: string };
    charged: number;
    charged_label: string;
    option_label: string;
    rooms: FreeRoom[];
    reasons: { id: number; name: string; needs_note: boolean; needs_manager: boolean }[];
    approvers: Approver[];
    methods: PaymentMethodOption[];
}>();

const { props: shared, flash } = useShared();
const form = useForm({
    room_id: null as number | null,
    reason_id: null as number | null,
    old_room: 'uncleaned',
    note: '',
    approver_id: null as number | null,
    pin: '',
});
const pay = ref<PaymentValue>({ method: props.methods[0]?.value ?? 'cash', amount: 0, reference_no: '', keep_change: false });
const approving = ref(false);
const attempt = ref(0);
const pinError = ref<string | undefined>(undefined);
const conflict = ref(false);
const conflictText = ref({ title: '', message: '' });

const target = computed(() => props.rooms.find((r) => r.id === form.room_id));
const difference = computed(() => target.value?.difference ?? 0);
const reason = computed(() => props.reasons.find((r) => r.id === form.reason_id));
const reasonItems = computed(() => props.reasons.map((r) => ({ label: r.name, value: r.id })));
const oldRoomTiles = [
    { value: 'uncleaned', label: 'Uncleaned' },
    { value: 'maintenance', label: 'Maintenance' },
];
const missing = computed(() => [...new Set(props.rooms.filter((r) => r.missing).map((r) => r.missing as string))]);

watch(difference, (d) => (pay.value.amount = Math.max(0, d)));

const ready = computed(() => !!target.value && target.value.difference !== null && !!reason.value && (!reason.value.needs_note || form.note.trim() !== ''));
const summary = computed(() => {
    const t = target.value;

    if (!t) {
        return '';
    }

    const money = difference.value > 0 ? `collect ${formatMoney(difference.value)}` : difference.value < 0 ? `credit ${formatMoney(-difference.value)} to deposit` : 'same price';

    return `Transfer ${props.stay.room} → ${t.number} · ${money} · "${reason.value?.name ?? ''}"`;
});

const error = computed(() => (flash.value.error && !['pin_invalid', 'room_taken'].includes(flash.value.error.code) && !approving.value ? flash.value.error.message : undefined));

function diffText(r: FreeRoom): string {
    if (r.difference === null) {
        return 'no rate';
    }

    return r.difference === 0 ? 'same price' : r.difference > 0 ? `+${formatMoney(r.difference)}` : `${formatMoney(r.difference)} credit`;
}

function approve(payload: { approver_id: number; pin: string }): void {
    form.approver_id = payload.approver_id;
    form.pin = payload.pin;
    const withPay = difference.value > 0 && pay.value.amount > 0;

    form.transform((data) => ({
        ...data,
        note: data.note.trim() || null,
        ...(withPay ? { method: pay.value.method, amount: pay.value.amount, reference_no: pay.value.reference_no.trim() || null } : {}),
    })).post(route('stays.transfer.store', props.stay.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string; room?: string; taken_by?: string | null } } | undefined)?.error;

            if (!e) {
                approving.value = false;

                return;
            }

            if (e.code === 'room_taken') {
                approving.value = false;
                form.room_id = null;
                conflictText.value = {
                    title: `Room ${e.room ?? ''} was just taken`,
                    message: `${e.taken_by ? `${e.taken_by} checked a guest in to Room ${e.room} a moment ago.` : `Room ${e.room} is no longer free.`} Nothing was saved: no approval, no payment. Pick another room.`,
                };
                conflict.value = true;

                return;
            }

            pinError.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
            attempt.value++;
        },
    });
}
</script>

<template>
    <FrontDeskLayout current="board" :title="`Transfer room — ${stay.guest_name}, Room ${stay.room ?? ''}`">
        <UAlert v-if="error" class="mb-4" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="error" />
        <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Move to</h2>
                <div v-if="rooms.length" role="radiogroup" aria-label="New room" class="max-h-72 overflow-y-auto rounded-lg border border-default">
                    <label
                        v-for="r in rooms"
                        :key="r.id"
                        class="flex cursor-pointer items-center gap-3 border-b border-default px-3 py-2 text-sm last:border-b-0"
                        :class="[form.room_id === r.id ? 'bg-select' : 'hover:bg-elevated', r.difference === null ? 'cursor-not-allowed opacity-60' : '']"
                    >
                        <input v-model="form.room_id" type="radio" name="room_id" :value="r.id" :disabled="r.difference === null" class="accent-(--hm-primary)" />
                        <span class="w-12 font-semibold text-highlighted tabular">{{ r.number }}</span>
                        <span class="flex-1">{{ r.type }}</span>
                        <span class="tabular" :class="r.difference === null ? 'text-error' : r.difference === 0 ? 'text-muted' : ''">{{ diffText(r) }}</span>
                    </label>
                </div>
                <p v-else class="text-sm text-muted">No room is free right now.</p>
                <UAlert v-for="m in missing" :key="m" class="mt-3" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="m" data-test="missing-rate" />
                <UFormField label="Reason" name="reason_id" class="mt-4" :error="form.errors.reason_id">
                    <USelect v-model="form.reason_id" :items="reasonItems" placeholder="Choose a reason" class="w-full" />
                </UFormField>
                <UFormField v-if="reason?.needs_note" label="Note (required)" name="note" class="mt-3" :error="form.errors.note">
                    <UTextarea v-model="form.note" :rows="2" class="w-full" />
                </UFormField>
                <UFormField :label="`Room ${stay.room} becomes`" name="old_room" class="mt-4">
                    <OptionTiles v-model="form.old_room" :options="oldRoomTiles" :columns="2" :label="`Room ${stay.room} becomes`" />
                </UFormField>
            </section>
            <section class="rounded-card border border-default bg-default p-5">
                <h2 class="mb-3 text-base font-semibold text-highlighted">Price difference</h2>
                <div class="flex flex-col gap-1.5">
                    <MoneyRow :label="`${charged_label} (charged)`" :amount="charged" />
                    <MoneyRow v-if="target" :label="`${target.type} ${option_label}`" :amount="target.new_price" />
                </div>
                <TotalRow :label="difference < 0 ? 'Credit to guest deposit' : 'To collect'" :amount="target ? Math.abs(difference) : null" />
                <p class="mt-2 text-[13px] text-muted">
                    The check-out time stays {{ formatDateTime(stay.expected_checkout_at, 'auto', stay.now) }}. Moving to a cheaper room adds the difference to the guest's deposit instead.
                </p>
                <PaymentFields v-if="difference > 0" v-model="pay" class="mt-3" :methods="methods" :due="difference" :allow-keep-change="false" :errors="form.errors" />
                <UButton class="mt-5" size="xl" block icon="i-lucide-lock" label="Transfer — needs approval" :disabled="!ready" @click="(pinError = undefined), (approving = true)" />
                <UButton class="mt-2" block color="neutral" variant="ghost" label="Back to folio" @click="router.visit(route('stays.show', stay.id))" />
            </section>
        </div>
        <ApprovalPin
            v-model:open="approving"
            :summary="summary"
            :approvers="approvers"
            :error="pinError"
            :locked="shared.pinLocked"
            :processing="form.processing"
            :attempt="attempt"
            @approve="approve"
        />
        <ConflictDialog v-model:open="conflict" :title="conflictText.title" :message="conflictText.message" primary-label="Pick another room" secondary-label="Back to board" @primary="(conflict = false), router.reload({ only: ['rooms'] })" @secondary="router.visit(route('board'))" />
    </FrontDeskLayout>
</template>
