<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApprovalPin, { type Approver } from '@/Components/ApprovalPin.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import PinPad from '@/Components/PinPad.vue';
import PinUnlock from '@/Components/PinUnlock.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { firstName, useShared } from '@/Composables/useShared';
import type { ResultView } from './Result.vue';

/**
 * Close step 4 — handover (25). The incoming front desk sees what they take on, counts the drawer
 * themselves and accepts with their PIN; the person who counted is not offered. One save closes
 * this shift (Z number, frozen totals, forwarded guests) and opens the next with the cash they
 * counted. A different count is reported with a note. With nobody to receive, a supervisor other
 * than the counter approves a close without handover (14).
 */
export interface HandoverView {
    counted: number;
    guests: number;
    key_deposits: number;
    unpaid_count: number;
    unpaid_total: number;
    stays: { stay_id: number; room: string; guest_name: string; expected_checkout_at: string; late: boolean; unpaid: number }[];
    next_period: string;
    receivers: { id: number; name: string }[];
    approvers: Approver[];
}

const props = defineProps<{ handover: HandoverView; result: ResultView; period: string }>();

const { props: shared, flash } = useShared();
const form = useForm({
    step: 'handover',
    receiver_id: (props.handover.receivers[0]?.id ?? null) as number | null,
    received_cash: 0,
    pin: '',
    handover_note: '',
    difference_note: '',
});
const different = ref(false);
const showAll = ref(false);
const attempt = ref(0);
const pinError = ref<string | undefined>(undefined);

const receiverItems = computed(() => props.handover.receivers.map((r) => ({ label: r.name, value: r.id })));
const matches = computed(() => form.received_cash === props.handover.counted);
const visibleStays = computed(() => (showAll.value ? props.handover.stays : props.handover.stays.slice(0, 3)));
const ready = computed(() => !form.processing && form.receiver_id !== null && form.pin.length === 4);
const canAccept = computed(() => ready.value && matches.value);
const canReport = computed(() => ready.value && form.difference_note.trim() !== '');
const me = computed(() => (shared.value.auth ? firstName(shared.value.auth.user.name) : ''));

function send(withDifference: boolean): void {
    pinError.value = undefined;
    form.transform((data) => ({ ...data, difference_note: withDifference ? data.difference_note : '' })).post(route('shift.close.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string } } | undefined)?.error;

            if (e && ['pin_invalid', 'pin_locked'].includes(e.code)) {
                pinError.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
                form.pin = '';
                attempt.value++;
            }
        },
    });
}

// Close without handover (APP_FLOW §4.16 step 11).
const without = useForm({ step: 'without_handover', approver_id: null as number | null, pin: '', handover_note: '' });
const approving = ref(false);
const approvalError = ref<string | undefined>(undefined);
const approvalAttempt = ref(0);

function approveWithout(payload: { approver_id: number; pin: string }): void {
    without.approver_id = payload.approver_id;
    without.pin = payload.pin;
    without.handover_note = form.handover_note;
    without.post(route('shift.close.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string } } | undefined)?.error;

            if (e) {
                approvalError.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
                approvalAttempt.value++;
            }
        },
    });
}

const flashMessage = computed(() => flash.value.error?.message);
</script>

<template>
    <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
        <section class="rounded-card border border-default bg-default p-5" data-receiving>
            <h2 class="mb-2 text-sm font-semibold text-highlighted">You are receiving</h2>
            <MoneyRow label="Cash in drawer" :amount="handover.counted" />
            <MoneyRow label="Forwarded guests" :text="String(handover.guests)" />
            <MoneyRow label="Key deposits held" :amount="handover.key_deposits" />
            <MoneyRow label="Unpaid balances" :text="`${handover.unpaid_count} · ${formatMoney(handover.unpaid_total)}`" />
            <table v-if="handover.stays.length" class="dtable dense mt-2 w-full">
                <thead>
                    <tr>
                        <th>Room</th>
                        <th>Guest</th>
                        <th class="text-right">Due out</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in visibleStays" :key="s.stay_id">
                        <td class="tabular">{{ s.room }}</td>
                        <td>
                            {{ s.guest_name }}
                            <span v-if="s.unpaid > 0" class="block text-xs text-muted">owes {{ formatMoney(s.unpaid) }}</span>
                        </td>
                        <td class="text-right" :class="s.late ? 'text-bad' : ''">{{ formatDateTime(s.expected_checkout_at, 'dayTime') }}{{ s.late ? ', late' : '' }}</td>
                    </tr>
                </tbody>
            </table>
            <UButton
                v-if="handover.stays.length > 3"
                class="mt-1"
                size="xs"
                color="neutral"
                variant="ghost"
                :label="showAll ? 'Show fewer' : `+ ${handover.stays.length - 3} more · view all with balances`"
                @click="showAll = !showAll"
            />
            <UFormField :label="`Note from ${me}`" name="handover_note" class="mt-4">
                <UTextarea v-model="form.handover_note" :rows="2" class="w-full" placeholder="Room 117 asked to extend, will pay when he comes down." />
            </UFormField>
        </section>

        <section class="rounded-card border border-default bg-default p-5" data-incoming>
            <h2 class="mb-2 text-sm font-semibold text-highlighted">Incoming front desk</h2>
            <UAlert v-if="flashMessage && !pinError" class="mb-3" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="flashMessage" />
            <UFormField label="Name" name="receiver_id" :error="form.errors.receiver_id">
                <USelect v-model="form.receiver_id" :items="receiverItems" class="w-full" placeholder="Pick your name" />
            </UFormField>
            <p class="mt-1 text-xs text-muted">{{ result.counted_by.name }} counted the drawer and cannot receive it.</p>
            <UFormField label="I counted the drawer and it has" name="received_cash" class="mt-3" :error="form.errors.received_cash">
                <MoneyInput v-model="form.received_cash" />
            </UFormField>
            <div class="mt-4">
                <template v-if="shared.pinLocked">
                    <PinPad locked :show-keys="false" />
                    <PinUnlock />
                </template>
                <PinPad v-else :key="attempt" :error="pinError ?? form.errors.pin" :disabled="form.processing" @complete="form.pin = $event" />
            </div>
            <template v-if="different">
                <UAlert
                    class="mt-4"
                    color="warning"
                    variant="subtle"
                    icon="i-lucide-triangle-alert"
                    :description="`You counted ${formatMoney(form.received_cash)}; the closing count is ${formatMoney(handover.counted)}. Say what you found. The difference is kept on the handover for the supervisor.`"
                />
                <UFormField label="What is different (required)" name="difference_note" class="mt-3">
                    <UTextarea v-model="form.difference_note" :rows="2" class="w-full" />
                </UFormField>
                <UButton class="mt-4" size="xl" block :label="`Report it and open ${handover.next_period} shift`" :disabled="!canReport" :loading="form.processing" @click="send(true)" />
                <UButton class="mt-2" block color="neutral" variant="ghost" label="Back" @click="different = false" />
            </template>
            <template v-else>
                <UButton class="mt-4" size="xl" block :label="`Accept and open ${handover.next_period} shift`" :disabled="!canAccept" :loading="form.processing" data-accept @click="send(false)" />
                <UButton class="mt-2" block color="neutral" variant="ghost" label="Amount is different — report it" @click="different = true" />
            </template>
        </section>
    </div>
    <div class="mt-4 flex justify-end">
        <UButton color="neutral" variant="ghost" icon="i-lucide-lock" label="Close without handover" @click="approving = true" />
    </div>
    <ApprovalPin
        v-model:open="approving"
        :summary="`Close the ${period} shift without handover · counted ${formatMoney(handover.counted)}${result.variance !== 0 ? ` · ${result.variance < 0 ? 'short' : 'over'} ${formatMoney(Math.abs(result.variance))}` : ''}`"
        :approvers="handover.approvers"
        :error="approvalError"
        :locked="shared.pinLocked"
        :processing="without.processing"
        :attempt="approvalAttempt"
        @approve="approveWithout"
    />
</template>
