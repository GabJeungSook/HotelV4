<script setup lang="ts">
import { useNow } from '@vueuse/core';
import { computed } from 'vue';
import { formatDuration } from '@/Composables/useDateTime';

/**
 * The cleaning timer (33, DESIGN_SYSTEM §5.2): minutes and seconds since the start in 56 px
 * tabular figures, the target line and a violet progress bar that fills at the target.
 */
const props = withDefaults(defineProps<{ startedAt: string; targetMinutes: number; now?: number }>(), { now: undefined });

const tick = useNow({ interval: 1000 });
const seconds = computed(() => Math.max(0, Math.floor(((props.now ?? tick.value.getTime()) - new Date(props.startedAt).getTime()) / 1000)));
const percent = computed(() => Math.min(100, Math.round((seconds.value / (props.targetMinutes * 60)) * 100)));
const over = computed(() => seconds.value > props.targetMinutes * 60);
</script>

<template>
    <div class="text-center" data-clean-timer>
        <div class="mt-5 text-[56px] leading-[64px] font-semibold tabular" :class="over ? 'text-warn' : 'text-highlighted'" role="timer" aria-live="off">
            {{ formatDuration(seconds, 'timer') }}
        </div>
        <p class="mb-3 text-muted">Target: {{ targetMinutes }} minutes</p>
        <div class="h-2.5 overflow-hidden rounded-full bg-grey-50" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" aria-label="Time against the target">
            <div class="h-full rounded-full transition-[width]" :class="over ? 'bg-warn' : 'bg-violet'" :style="{ width: `${percent}%` }" />
        </div>
    </div>
</template>
