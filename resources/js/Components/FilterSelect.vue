<script setup lang="ts">
/**
 * A dropdown chip in a filter row (DESIGN_SYSTEM §0, mockup v6 `.dd`): 32 px, a 1 px line border,
 * 12.5 px / 500 text and a quiet chevron. A `USelect` underneath, so keyboard and screen readers work.
 */
withDefaults(defineProps<{ items: { value: string | number; label: string }[]; label: string; icon?: string }>(), { icon: undefined });

const model = defineModel<string | number | undefined>();
</script>

<template>
    <USelect
        v-model="model"
        :items="items"
        value-key="value"
        label-key="label"
        :aria-label="label"
        :leading-icon="icon"
        size="md"
        trailing-icon="i-lucide-chevron-down"
        :ui="{
            base: 'h-8 rounded-control ring-default text-[12.5px] font-medium text-default shadow-xs ps-3',
            trailingIcon: 'size-3.5 text-faint',
            leadingIcon: 'size-3.5 text-faint',
            content: 'min-w-44',
        }"
        class="w-auto shrink-0"
        data-filter-select
    />
</template>
