<script setup lang="ts">
import { computed } from 'vue';
import StatCard, { type StatCardProps } from './StatCard.vue';

/** The KPI row (DESIGN_SYSTEM §0, §5.2): 3–6 equal columns by item count, 2 under 768 px, 16 px gap. */
const props = withDefaults(defineProps<{ items?: StatCardProps[]; loading?: boolean; count?: number }>(), { items: () => [], loading: false, count: 4 });

const n = computed(() => (props.loading ? props.count : props.items.length));
const columns = computed(() =>
    n.value >= 6 ? 'md:grid-cols-3 lg:grid-cols-6 hm-k5' : n.value === 5 ? 'md:grid-cols-5 hm-k5' : n.value === 3 ? 'md:grid-cols-3' : 'md:grid-cols-4',
);
</script>

<template>
    <div class="grid grid-cols-2 gap-4" :class="columns">
        <template v-if="loading">
            <div v-for="i in count" :key="i" class="rounded-card border border-default bg-default px-[18px] py-4" aria-hidden="true" data-skeleton>
                <USkeleton class="h-[18px] w-24" />
                <USkeleton class="mt-2 h-[31px] w-20" />
                <USkeleton class="mt-2.5 h-4 w-28" />
            </div>
        </template>
        <StatCard v-for="item in items" v-else :key="item.label" v-bind="item" />
    </div>
</template>
