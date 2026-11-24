<script setup lang="ts">
import { computed } from 'vue';
import { formatDuration } from '@/Composables/useDateTime';

/**
 * One room waiting to be cleaned on the housekeeper's phone (32, DESIGN_SYSTEM §5.2 PhoneLayout
 * pieces): a coloured room tile, the room number, a clock chip for how long it has waited, one
 * helper line and one full-width 48 px button. Urgent: a red left edge and a red tile. Not
 * startable: dimmed; the first of those carries a disabled "Next in line" button.
 */
const props = withDefaults(
    defineProps<{
        room: string;
        waitingMinutes: number;
        tone?: 'neutral' | 'warn' | 'bad';
        detail?: string;
        startable?: boolean;
        urgent?: boolean;
        nextInLine?: boolean;
        processing?: boolean;
        actionLabel?: string;
    }>(),
    { tone: 'neutral', detail: undefined, startable: false, urgent: false, nextInLine: false, processing: false, actionLabel: 'Start cleaning' },
);

defineEmits<{ start: [] }>();

const waiting = computed(() => (props.urgent ? `Urgent · ${formatDuration(props.waitingMinutes)}` : `${formatDuration(props.waitingMinutes)} waiting`));
const toneText = computed(() => ({ neutral: 'text-muted', warn: 'text-warn', bad: 'text-bad' })[props.tone]);
const tile = computed(() => (props.urgent ? 'bg-bad-50 text-bad' : props.startable ? 'bg-primary-50 text-primary' : 'bg-grey-50 text-muted'));
</script>

<template>
    <article
        class="rounded-[10px] border bg-default p-3.5"
        :class="[urgent ? 'border-default border-l-4 border-l-bad' : 'border-default', startable ? '' : 'opacity-60']"
        :aria-label="`Room ${room}, ${waiting}`"
        data-task-card
    >
        <div class="flex items-center gap-3">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-[10px]" :class="tile">
                <UIcon name="i-solar-bed-bold-duotone" class="size-6" />
            </span>
            <div class="min-w-0 flex-1">
                <b class="block text-xl leading-6 font-semibold text-highlighted tabular">Room {{ room }}</b>
                <span class="mt-0.5 inline-flex items-center gap-1 text-[13px] font-medium" :class="toneText">
                    <UIcon name="i-solar-clock-circle-linear" class="size-3.5 shrink-0" />{{ waiting }}
                </span>
            </div>
            <span v-if="nextInLine && !startable" class="shrink-0 rounded-label bg-grey-50 px-2 py-0.5 text-[11px] font-semibold tracking-wide text-muted uppercase">Next</span>
        </div>
        <p v-if="detail" class="mt-2 text-[13px] leading-[18px] text-muted">{{ detail }}</p>
        <UButton v-if="startable" class="mt-3 h-12 justify-center" size="xl" block :label="actionLabel" :loading="processing" @click="$emit('start')" />
        <UButton v-else-if="nextInLine" class="mt-3 h-12 justify-center" size="xl" block color="neutral" variant="soft" label="Next in line" disabled />
    </article>
</template>
