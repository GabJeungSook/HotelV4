<script setup lang="ts">
import HomiLogo from './HomiLogo.vue';
import StepBar from './StepBar.vue';

/**
 * The chrome for the kiosk's inner screens (28–30): the Homi mark, the step out of three, and the
 * step bar beneath it, so the guest always knows where they are in the booking.
 */
defineProps<{ current: number }>();

const steps = ['Room', 'Your name', 'Pay at desk'];
</script>

<template>
    <div class="mb-5 flex items-center justify-between gap-4">
        <HomiLogo variant="blue" :width="124" :height="38" />
        <span class="text-xl font-medium text-muted">Step {{ current }} of {{ steps.length }}</span>
    </div>
    <StepBar :steps="steps" :current="current" size="kiosk" />
</template>
