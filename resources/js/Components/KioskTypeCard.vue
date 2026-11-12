<script setup lang="ts">
import StatusLabel from './StatusLabel.vue';

/**
 * A room type on the kiosk (28, DESIGN_SYSTEM §5.3): full-width card, 28 px title, muted
 * description, the offered room as a primary label on the right. Selected: select-line border
 * and select fill. Full: 50 % opacity with a "Full" label, and it cannot be picked.
 */
const props = withDefaults(defineProps<{ name: string; description?: string | null; room?: string | null; full?: boolean; selected?: boolean }>(), {
    description: null,
    room: null,
    full: false,
    selected: false,
});

const emit = defineEmits<{ select: [] }>();

function pick(): void {
    if (!props.full) {
        emit('select');
    }
}
</script>

<template>
    <button
        type="button"
        class="hm-focus flex min-h-[96px] w-full cursor-pointer flex-col items-start gap-1 rounded-card border px-7 py-5 text-left transition-colors"
        :class="[full ? 'cursor-not-allowed border-default bg-default opacity-50' : selected ? 'border-select-line bg-select' : 'border-default bg-default hover:border-select-line']"
        :aria-pressed="selected"
        :aria-disabled="full"
        :data-state="full ? 'full' : selected ? 'selected' : 'idle'"
        @click="pick"
    >
        <span class="flex w-full items-center justify-between gap-4">
            <span class="text-[28px] leading-9 font-semibold text-highlighted">{{ name }}</span>
            <StatusLabel v-if="full" tone="bad" label="Full" size="lg" />
            <StatusLabel v-else-if="room" tone="primary" :label="room" size="lg" />
        </span>
        <span v-if="description" class="text-lg leading-7 text-muted">{{ description }}</span>
    </button>
</template>
