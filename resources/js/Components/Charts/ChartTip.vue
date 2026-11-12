<script setup lang="ts">
import { tip } from './chart';

/** The one chart tooltip, mounted once beside the page; charts write to the shared `tip` state. */
</script>

<template>
    <div
        v-if="tip.visible"
        role="tooltip"
        class="pointer-events-none fixed z-50 -translate-x-1/2 -translate-y-[calc(100%+10px)] rounded-md border border-default bg-default px-2.5 py-1.5 text-xs leading-4 whitespace-nowrap shadow-(--hm-shadow-segment)"
        :style="{ left: `${tip.x}px`, top: `${tip.y}px` }"
        data-chart-tip
    >
        <span class="block text-muted">{{ tip.title }}</span>
        <b class="block font-semibold text-highlighted tabular">{{ tip.text }}</b>
    </div>
</template>
