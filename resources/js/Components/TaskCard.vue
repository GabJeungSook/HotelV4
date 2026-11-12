<script setup lang="ts">
import { computed } from 'vue';
import StatusLabel from './StatusLabel.vue';
import { formatDuration } from '@/Composables/useDateTime';

/**
 * One room waiting to be cleaned on the housekeeper's phone (32, DESIGN_SYSTEM §5.2 PhoneLayout
 * pieces): room number, a waiting label, one helper line and one full-width 48 px button.
 * Urgent: a 4 px red left border. Not startable: 55% opacity; the first of those carries a
 * disabled "Next in line" button.
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
</script>

<template>
    <article
        class="rounded-[10px] border border-default bg-default p-3"
        :class="[urgent ? 'border-l-4 border-l-bad' : '', startable ? '' : 'opacity-55']"
        :aria-label="`Room ${room}, ${waiting}`"
        data-task-card
    >
        <div class="flex items-center justify-between gap-2">
            <b class="text-xl leading-7 font-semibold text-highlighted tabular">Room {{ room }}</b>
            <StatusLabel :tone="tone" :label="waiting" class="text-[13px]" />
        </div>
        <p v-if="detail" class="text-[13px] leading-[18px] text-muted">{{ detail }}</p>
        <UButton v-if="startable" class="mt-3 h-12 justify-center" size="xl" block :label="actionLabel" :loading="processing" @click="$emit('start')" />
        <UButton v-else-if="nextInLine" class="mt-3 h-12 justify-center" size="xl" block color="neutral" variant="soft" label="Next in line" disabled />
    </article>
</template>
