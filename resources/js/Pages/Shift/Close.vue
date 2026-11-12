<!-- Mockup 22 · 23 · 24 · 25 (the steps are Close/Check, Count, Result and Handover) -->
<script setup lang="ts">
import { computed } from 'vue';
import StepBar from '@/Components/StepBar.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import Check, { type CheckRow, type Paper } from './Close/Check.vue';
import Count, { type CountForm } from './Close/Count.vue';
import Handover, { type HandoverView } from './Close/Handover.vue';
import Result, { type ResultView } from './Close/Result.vue';

/**
 * Close the shift and hand over (22–25, APP_FLOW §4.16). The step comes from the server (shift
 * status, whether the count is saved, whether the result is saved), so Back and reload always
 * land on the current step, and a reload on step 2 brings back the pieces typed so far. Steps 1
 * and 2 carry no expected cash: the count stays blind until it is confirmed.
 */
defineProps<{
    period: { id: number; period_name: string; business_date: string; status: string };
    step: 1 | 2 | 3 | 4;
    closing_by: string | null;
    check?: CheckRow[];
    papers?: Paper[];
    count?: CountForm;
    result?: ResultView;
    handover?: HandoverView;
}>();

const { flash } = useShared();
const error = computed(() => (flash.value.error && !['pin_invalid', 'pin_locked'].includes(flash.value.error.code) ? flash.value.error.message : undefined));
</script>

<template>
    <FrontDeskLayout
        current="shift"
        guide
        :title="step === 4 && handover ? `Hand over to ${handover.next_period} shift` : `Close ${period.period_name} shift · ${formatDateTime(`${period.business_date}T12:00:00+08:00`, 'shiftDate')}`"
    >
        <StepBar class="mb-4" :steps="['Check', 'Count cash', 'Result', 'Handover']" :current="step" />
        <UAlert v-if="error" class="mb-4" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="error" data-close-error />
        <UAlert
            v-if="step > 1 && closing_by"
            class="mb-4"
            color="info"
            variant="subtle"
            icon="i-lucide-lock"
            :description="`${closing_by} started the close. Payments, charges and cash entries of the ${period.period_name} shift are paused.`"
        />

        <Check v-if="step === 1 && check && papers" :rows="check" :papers="papers" />
        <Count v-else-if="step === 2 && count" :form="count" />
        <Result v-else-if="step === 3 && result" :result="result" :period="period.period_name" />
        <Handover v-else-if="step === 4 && handover && result" :handover="handover" :result="result" :period="period.period_name" />
    </FrontDeskLayout>
</template>
