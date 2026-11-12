<script setup lang="ts">
/**
 * Lobby kiosk, 1080 × 1920 portrait (DESIGN_SYSTEM §6.8): no chrome, white page, 32 px padding,
 * light only. Every touch target is 56 px or taller; primary buttons 72 px.
 */
</script>

<template>
    <div class="hm-light flex min-h-dvh justify-center bg-default text-lg leading-7 text-default">
        <main class="flex w-full max-w-[1080px] flex-col p-8"><slot /></main>
    </div>
</template>
