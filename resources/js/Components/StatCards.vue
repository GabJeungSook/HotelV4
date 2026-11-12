<script setup lang="ts">
import { computed } from 'vue';
import StatCard, { type StatCardProps } from './StatCard.vue';

/** The KPI row: 4 equal columns (5 with five items), 2 under 768 px, 12 px gap (DESIGN_SYSTEM §5.2). */
const props = withDefaults(defineProps<{ items?: StatCardProps[]; loading?: boolean; count?: number }>(), { items: () => [], loading: false, count: 4 });

const columns = computed(() => ((props.loading ? props.count : props.items.length) >= 5 ? 'md:grid-cols-5' : 'md:grid-cols-4'));
</script>

<template>
    <div class="grid grid-cols-2 gap-3" :class="columns">
        <template v-if="loading">
            <div v-for="i in count" :key="i" class="rounded-card border border-default bg-default px-[18px] py-4" aria-hidden="true" data-skeleton>
                <USkeleton class="h-[18px] w-24" />
                <USkeleton class="mt-1.5 h-7 w-20" />
                <USkeleton class="mt-1.5 h-4 w-28" />
            </div>
        </template>
        <StatCard v-for="item in items" v-else :key="item.label" v-bind="item" />
    </div>
</template>
