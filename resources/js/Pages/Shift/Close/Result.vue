<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ApprovalPin, { type Approver } from '@/Components/ApprovalPin.vue';
import type { ConfirmReason } from '@/Components/ConfirmDialog.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import { type Tone, toneBlock, toneTextClass } from '@/types/ui';

/**
 * Close step 3 — result (24). Only now, with the count saved, does the server send how the
 * expected cash is made up, line by line, next to the count. A shortage or overage needs a reason
 * and what happened. "Next: hand over" saves expected, counted, variance and the explanation;
 * the shift stays closing until the handover (25).
 */
export interface ResultView {
    lines: { label: string; amount: number }[];
    expected: number;
    counted: number;
    variance: number;
    counted_by: { id: number; name: string };
    reasons: ConfirmReason[];
    approvers: Approver[];
    saved: { reason_id: number | null; note: string | null } | null;
}

const props = defineProps<{ result: ResultView; period: string }>();

const form = useForm({ step: 'result', reason_id: props.result.saved?.reason_id ?? (null as number | null), note: '' });
const reason = computed(() => props.result.reasons.find((r) => r.id === form.reason_id));
const reasonItems = computed(() => props.result.reasons.map((r) => ({ label: r.name, value: r.id })));
const off = computed(() => props.result.variance !== 0);
const canNext = computed(() => !form.processing && (!off.value || (form.reason_id !== null && (!reason.value?.needs_note || form.note.trim() !== ''))));

const tone = computed<Tone>(() => (props.result.variance === 0 ? 'ok' : props.result.variance < 0 ? 'bad' : 'warn'));
const varianceState = computed(() => (props.result.variance === 0 ? 'zero' : props.result.variance < 0 ? 'short' : 'over'));
const varianceLabel = computed(() => (props.result.variance === 0 ? 'Count matches' : props.result.variance < 0 ? 'Short by' : 'Over by'));

function next(): void {
    form.post(route('shift.close.store'), { preserveScroll: true });
}

// Redo the count (a typo): a supervisor PIN reopens it and the wizard returns to Count cash.
const { props: shared } = useShared();
const redo = useForm({ step: 'reopen_count', approver_id: null as number | null, pin: '' });
const redoing = ref(false);
const redoError = ref<string | undefined>(undefined);
const redoAttempt = ref(0);

function reopenCount(payload: { approver_id: number; pin: string }): void {
    redo.approver_id = payload.approver_id;
    redo.pin = payload.pin;
    redo.post(route('shift.close.store'), {
        preserveScroll: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { code: string; message: string } } | undefined)?.error;

            if (e) {
                redoError.value = e.code === 'pin_invalid' ? 'PIN not recognised' : e.message;
                redoAttempt.value++;

                return;
            }

            redoing.value = false;
        },
    });
}
</script>

<template>
    <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
        <section class="rounded-card border border-default bg-default p-5" data-result>
            <MoneyRow v-for="line in result.lines" :key="line.label" :label="line.label" :amount="Math.abs(line.amount)" sign="none" />
            <TotalRow label="Expected" :amount="result.expected" data-expected />
            <MoneyRow label="Counted" :amount="result.counted" data-counted />
        </section>
        <div class="flex flex-col gap-4">
            <section class="rounded-card border p-5" :class="toneBlock[tone]" :data-variance="varianceState">
                <div class="flex items-center gap-3.5">
                    <UIcon :name="off ? 'i-solar-danger-triangle-bold-duotone' : 'i-solar-check-circle-bold-duotone'" class="size-7 shrink-0" :class="toneTextClass[tone]" aria-hidden="true" />
                    <div class="min-w-0">
                        <p class="text-[13px] font-medium" :class="toneTextClass[tone]">{{ varianceLabel }}</p>
                        <p v-if="off" class="text-[26px] leading-8 font-semibold tabular" :class="toneTextClass[tone]">{{ formatMoney(Math.abs(result.variance)) }}</p>
                    </div>
                </div>
                <p class="mt-2.5 text-[13px] text-muted">
                    {{ off ? `This ${result.variance < 0 ? 'shortage' : 'overage'} will be recorded against the ${period} shift. Pick a reason below.` : 'The counted cash matches the expected cash — nothing to explain.' }}
                </p>
            </section>
            <template v-if="off">
                <UFormField label="Reason" name="reason_id" :error="form.errors.reason_id">
                    <USelect v-model="form.reason_id" :items="reasonItems" placeholder="Pick a reason" class="w-full" />
                </UFormField>
                <UFormField label="What happened? (required when short or over)" name="note" :error="form.errors.note">
                    <UTextarea v-model="form.note" :rows="2" class="w-full" :placeholder="result.saved?.note ?? 'Gave wrong change to Room 203, ₱50.'" />
                </UFormField>
            </template>
            <p class="text-[13px] text-muted">Counted by {{ result.counted_by.name }}, who cannot receive the shift or approve this result.</p>
            <UButton size="xl" block label="Next: hand over" :disabled="!canNext" :loading="form.processing" data-next @click="next" />
            <UButton block color="neutral" variant="ghost" label="Redo count — fix a typo" data-redo-count @click="redoing = true" />
        </div>
    </div>

    <ApprovalPin
        v-model:open="redoing"
        :summary="`Redo the count for the ${period} shift`"
        :approvers="result.approvers"
        :error="redoError"
        :locked="shared.pinLocked"
        :processing="redo.processing"
        :attempt="redoAttempt"
        @approve="reopenCount"
    />
</template>
