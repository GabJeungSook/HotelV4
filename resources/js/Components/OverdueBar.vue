<script setup lang="ts">
import { computed } from 'vue';
import { formatDateTime } from '@/Composables/useDateTime';

/**
 * Shift running late (54, DESIGN_SYSTEM §5.2). Rendered by FrontDeskLayout once the shared
 * `shift` prop says the shift is `shift_overdue_warn_minutes` past its defined end. Red so the desk
 * sees it at once; a solid red once it is past `shift_stale_block_minutes` and payments and charges
 * are refused. It cannot be dismissed; "Close shift" opens close step 1 (22).
 */
const props = withDefaults(defineProps<{ periodName: string; endedAt: string; blocked?: boolean; minutesPast?: number; closable?: boolean }>(), {
    blocked: false,
    minutesPast: 0,
    closable: true,
});

defineEmits<{ close: [] }>();

const hours = computed(() => Math.floor(props.minutesPast / 60));
const text = computed(() =>
    props.blocked
        ? `The ${props.periodName} shift is ${hours.value} ${hours.value === 1 ? 'hour' : 'hours'} past its end. Payments and charges are stopped until it is closed.`
        : `The ${props.periodName} shift should have ended at ${formatDateTime(props.endedAt, 'time')}. You can keep working, and everything still goes to the ${props.periodName} shift.`,
);
</script>

<template>
    <div
        class="no-print flex items-center justify-between gap-3 px-4 py-2 text-[13px] leading-[18px] font-semibold"
        :class="blocked ? 'bg-bad-50 text-bad border-l-4 border-l-bad' : 'bg-bad-50 text-bad'"
        role="status"
        data-overdue-bar
        :data-blocked="blocked ? 'true' : 'false'"
    >
        <span class="flex items-center gap-2">
            <UIcon name="i-solar-danger-triangle-bold-duotone" class="size-4 shrink-0" />
            {{ text }}
        </span>
        <UButton v-if="closable" size="xs" color="neutral" variant="outline" label="Close shift" @click="$emit('close')" />
    </div>
</template>
