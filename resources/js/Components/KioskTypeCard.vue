<script setup lang="ts">
/**
 * A room type on the kiosk (28, DESIGN_SYSTEM §5.3): a tall, tappable card — a room icon, the
 * type name with its lowest price, a short description and the room being offered as a chip.
 * Selected shows the select border and fill with a check; full is dimmed with "Full" and cannot
 * be picked.
 */
const props = withDefaults(defineProps<{ name: string; description?: string | null; room?: string | null; fromPrice?: string | null; full?: boolean; selected?: boolean }>(), {
    description: null,
    room: null,
    fromPrice: null,
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
        class="hm-focus flex w-full cursor-pointer items-center gap-5 rounded-card border px-6 py-5 text-left transition-colors"
        :class="[full ? 'cursor-not-allowed border-default bg-default opacity-50' : selected ? 'border-select-line bg-select' : 'border-default bg-default hover:border-select-line']"
        :aria-pressed="selected"
        :aria-disabled="full"
        :data-state="full ? 'full' : selected ? 'selected' : 'idle'"
        @click="pick"
    >
        <span class="grid size-16 shrink-0 place-items-center rounded-2xl transition-colors" :class="selected ? 'bg-primary text-on-primary' : 'bg-muted text-muted'">
            <UIcon name="i-solar-bed-bold-duotone" class="size-9" aria-hidden="true" />
        </span>

        <span class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="flex items-baseline justify-between gap-4">
                <span class="truncate text-[28px] leading-9 font-semibold text-highlighted">{{ name }}</span>
                <span v-if="full" class="shrink-0 text-2xl font-semibold text-bad">Full</span>
                <span v-else-if="fromPrice" class="shrink-0 text-xl text-muted">from <b class="text-2xl text-highlighted tabular">{{ fromPrice }}</b></span>
            </span>
            <span v-if="description" class="truncate text-lg leading-7 text-muted">{{ description }}</span>
            <span v-if="room && !full" class="mt-1 inline-flex w-fit items-center gap-2 rounded-full bg-primary-50 px-3.5 py-1 text-base font-medium text-primary-ink">
                <UIcon name="i-solar-key-minimalistic-bold-duotone" class="size-4" aria-hidden="true" />{{ room }}
            </span>
        </span>

        <UIcon v-if="selected" name="i-solar-check-circle-bold" class="size-9 shrink-0 text-primary" aria-hidden="true" />
    </button>
</template>
