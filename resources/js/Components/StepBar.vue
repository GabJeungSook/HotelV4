<script setup lang="ts">
/**
 * Wizard steps as one bordered strip (DESIGN_SYSTEM §0, mockup v6 `.steps`): the current step has a
 * primary-50 fill, primary-ink text and a 2 px primary underline; done steps show ok text.
 * Not clickable; Back and Next move between them.
 */
withDefaults(defineProps<{ steps: string[]; current: number; size?: 'md' | 'kiosk' }>(), { size: 'md' });
</script>

<template>
    <ol class="flex overflow-hidden rounded-[10px] border border-default bg-default shadow-xs" aria-label="Steps">
        <li
            v-for="(step, i) in steps"
            :key="step"
            class="flex flex-1 items-center justify-center gap-2 text-center whitespace-nowrap not-first:border-l not-first:border-default"
            :class="[
                size === 'kiosk' ? 'h-14 px-3 text-xl' : 'px-2 py-2.5 text-[12.5px] leading-[18px]',
                i + 1 < current
                    ? 'font-medium text-ok'
                    : i + 1 === current
                      ? 'bg-primary-50 font-semibold text-primary-ink shadow-[inset_0_-2px_0_var(--hm-primary)]'
                      : 'font-medium text-muted',
            ]"
            :aria-current="i + 1 === current ? 'step' : undefined"
            :data-state="i + 1 < current ? 'done' : i + 1 === current ? 'current' : 'next'"
        >
            <UIcon v-if="i + 1 < current" name="i-lucide-check" class="size-3.5" aria-hidden="true" />
            {{ i + 1 }} {{ step }}
        </li>
    </ol>
</template>
