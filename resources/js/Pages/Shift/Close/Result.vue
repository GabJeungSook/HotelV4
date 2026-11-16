<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ConfirmReason } from '@/Components/ConfirmDialog.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import TotalRow from '@/Components/TotalRow.vue';
import { formatMoney } from '@/Composables/useMoney';

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
    saved: { reason_id: number | null; note: string | null } | null;
}

const props = defineProps<{ result: ResultView; period: string }>();

const form = useForm({ step: 'result', reason_id: props.result.saved?.reason_id ?? (null as number | null), note: '' });
const reason = computed(() => props.result.reasons.find((r) => r.id === form.reason_id));
const reasonItems = computed(() => props.result.reasons.map((r) => ({ label: r.name, value: r.id })));
const off = computed(() => props.result.variance !== 0);
const canNext = computed(() => !form.processing && (!off.value || (form.reason_id !== null && (!reason.value?.needs_note || form.note.trim() !== ''))));

function next(): void {
    form.post(route('shift.close.store'), { preserveScroll: true });
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
            <UAlert
                v-if="result.variance < 0"
                color="error"
                variant="subtle"
                icon="i-solar-danger-triangle-bold-duotone"
                :title="`Short by ${formatMoney(-result.variance)}.`"
                :description="`This will be recorded against the ${period} shift.`"
                data-variance="short"
            />
            <UAlert
                v-else-if="result.variance > 0"
                color="warning"
                variant="subtle"
                icon="i-solar-danger-triangle-bold-duotone"
                :title="`Over by ${formatMoney(result.variance)}.`"
                :description="`This will be recorded against the ${period} shift.`"
                data-variance="over"
            />
            <UAlert v-else color="success" variant="subtle" icon="i-solar-check-circle-linear" title="The count matches the expected cash." data-variance="zero" />
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
        </div>
    </div>
</template>
