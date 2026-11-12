<script setup lang="ts">
/**
 * Kiosk buttons pinned to the bottom (DESIGN_SYSTEM §5.3): "Back" (secondary, 72 px) on the left
 * when the `back` slot is filled, the primary (72 px) filling the rest.
 */
</script>

<template>
    <div class="mt-auto flex gap-4 pt-8">
        <div v-if="$slots.back" class="w-[240px] shrink-0"><slot name="back" /></div>
        <div class="flex-1"><slot /></div>
    </div>
</template>
