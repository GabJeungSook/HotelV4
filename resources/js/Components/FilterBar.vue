<script setup lang="ts">
import { refDebounced } from '@vueuse/core';
import { ref, watch } from 'vue';
import PeriodSegments from './PeriodSegments.vue';

/**
 * The filter row above a list (DESIGN_SYSTEM §0, mockup v6 `.flt`): the List / Calendar switch
 * where it applies, then the dropdown chips (default slot: `FilterSelect`s and a date-range chip),
 * and the search box at the right end. Search is debounced 300 ms; the page reloads with the query.
 */
const props = withDefaults(
    defineProps<{
        views?: { value: string; label: string }[];
        searchPlaceholder?: string;
        searchable?: boolean;
        loading?: boolean;
    }>(),
    { views: undefined, searchPlaceholder: 'Search', searchable: true, loading: false },
);

const view = defineModel<string>('view');
const search = defineModel<string>('search', { default: '' });

const typed = ref(search.value ?? '');
const debounced = refDebounced(typed, 300);
watch(debounced, (v) => {
    if (v !== search.value) {
        search.value = v;
    }
});
watch(search, (v) => {
    if (v !== typed.value) {
        typed.value = v ?? '';
    }
});
void props;
</script>

<template>
    <div class="mb-3.5 flex flex-wrap items-center gap-2" role="search" data-filter-bar>
        <PeriodSegments v-if="views && view !== undefined" v-model="view" :items="views" label="View" />
        <slot />
        <span class="flex-1" />
        <UIcon v-if="loading" name="i-solar-restart-linear" class="size-4 animate-spin text-muted" aria-label="Loading" />
        <UInput
            v-if="searchable"
            v-model="typed"
            icon="i-solar-magnifer-linear"
            :placeholder="searchPlaceholder"
            :aria-label="searchPlaceholder"
            size="md"
            class="w-[220px] max-md:w-full"
            :ui="{ base: 'h-8 ring-default text-[12.5px]', leadingIcon: 'size-[15px] text-faint' }"
            data-search
        />
    </div>
</template>
