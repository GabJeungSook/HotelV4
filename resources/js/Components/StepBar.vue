<script setup lang="ts">
/** Wizard steps as equal pills (DESIGN_SYSTEM §5.2). Not clickable; Back and Next move between them. */
withDefaults(defineProps<{ steps: string[]; current: number; size?: 'md' | 'kiosk' }>(), { size: 'md' });
</script>

<template>
    <ol class="flex gap-2" aria-label="Steps">
        <li
            v-for="(step, i) in steps"
            :key="step"
            class="flex-1 rounded-full text-center whitespace-nowrap"
            :class="[
                size === 'kiosk' ? 'h-12 px-3 text-xl leading-[48px]' : 'px-2 py-1.5 text-xs leading-[18px]',
                i + 1 < current ? 'bg-ok-50 font-medium text-ok' : i + 1 === current ? 'bg-primary font-semibold text-on-primary' : 'bg-step font-medium text-default',
            ]"
            :aria-current="i + 1 === current ? 'step' : undefined"
            :data-state="i + 1 < current ? 'done' : i + 1 === current ? 'current' : 'next'"
        >
            {{ i + 1 }} {{ step }}
        </li>
    </ol>
</template>
