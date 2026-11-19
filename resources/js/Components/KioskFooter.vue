<script setup lang="ts">
/**
 * Kiosk actions pinned to the bottom (DESIGN_SYSTEM §5.3): an optional `summary` strip above the
 * buttons, then "Back" (secondary, 72 px) on the left when the `back` slot is filled and the
 * primary (72 px) filling the rest.
 */
</script>

<template>
    <div class="mt-auto pt-8">
        <div v-if="$slots.summary" class="mb-4"><slot name="summary" /></div>
        <div class="flex gap-4">
            <div v-if="$slots.back" class="w-[240px] shrink-0"><slot name="back" /></div>
            <div class="flex-1"><slot /></div>
        </div>
    </div>
</template>
