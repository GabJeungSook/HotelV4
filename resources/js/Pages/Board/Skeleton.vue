<!-- Mockup 4b -->
<script setup lang="ts">
/** Room board, first load only (4b): grey skeleton rows. Later refreshes update the rows in place. */
const widths = ['w-[96%]', 'w-[88%]', 'w-[92%]', 'w-[84%]', 'w-[95%]', 'w-[90%]', 'w-[86%]', 'w-[93%]', 'w-[89%]', 'w-[91%]'];
</script>

<template>
    <div aria-busy="true" aria-label="Loading the room board" data-test="board-skeleton">
        <div class="mb-3 flex gap-2">
            <USkeleton class="h-5 w-24" />
            <USkeleton class="h-5 w-24" />
            <USkeleton class="h-5 w-28" />
        </div>
        <div class="rounded-card border border-default bg-default px-4 py-2">
            <USkeleton v-for="w in widths" :key="w" class="my-3 h-3" :class="w" />
        </div>
    </div>
</template>
