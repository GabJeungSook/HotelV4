<script setup lang="ts">
/** A gallery tile: white card, small uppercase heading (the mockup's Building blocks frame). */
withDefaults(defineProps<{ title: string; wide?: boolean }>(), { wide: false });
</script>

<template>
    <div class="min-w-0 rounded-card border border-default bg-default p-4" :class="wide ? 'col-span-full' : ''">
        <h3 class="mb-3 text-[11px] leading-4 font-semibold tracking-[0.07em] text-muted uppercase">{{ title }}</h3>
        <slot />
    </div>
</template>
