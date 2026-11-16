<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusLabel from './StatusLabel.vue';

/** "Needs attention" card (DESIGN_SYSTEM §5.2, mockup v6 `.att`): a red count pill, then one row per thing to act on. Rows come from the server's AttentionList class. */
export interface AttentionRow {
    severity: 'high' | 'normal';
    title: string;
    detail?: string;
    route: string;
}

const props = withDefaults(defineProps<{ rows?: AttentionRow[]; limit?: number | null; moreRoute?: string; loading?: boolean }>(), {
    rows: () => [],
    limit: 5,
    moreRoute: undefined,
    loading: false,
});

const shown = computed(() => (props.limit ? props.rows.slice(0, props.limit) : props.rows));
const cut = computed(() => props.rows.length > shown.value.length);
</script>

<template>
    <section class="rounded-card border border-default bg-default px-5 py-[18px]" aria-label="Needs attention">
        <div class="mb-1 flex min-h-[30px] items-center gap-2.5">
            <h2 class="text-[14.5px] font-semibold text-highlighted">Needs attention</h2>
            <div class="ms-auto flex items-center gap-2">
                <Link v-if="cut && moreRoute" :href="moreRoute" class="hm-focus text-[12.5px] font-semibold text-primary">View all</Link>
                <StatusLabel v-if="!loading && rows.length" tone="bad" :label="String(rows.length > (limit ?? 99) ? `${limit}+` : rows.length)" />
            </div>
        </div>
        <div v-if="loading" class="mt-1" aria-hidden="true">
            <div v-for="i in 3" :key="i" class="flex gap-2.5 border-b border-muted py-2.5 last:border-b-0">
                <USkeleton class="mt-1 size-[7px] rounded-full" />
                <div class="flex-1">
                    <USkeleton class="h-4 w-4/5" />
                    <USkeleton class="mt-1.5 h-3 w-1/2" />
                </div>
            </div>
        </div>
        <div v-else-if="rows.length === 0" class="flex items-center gap-2 py-3 text-sm">
            <UIcon name="i-solar-check-circle-linear" class="size-4 text-ok" aria-hidden="true" />
            <span class="font-semibold text-highlighted">All good.</span>
        </div>
        <ul v-else class="mt-1">
            <li v-for="row in shown" :key="row.title" class="border-b border-muted last:border-b-0">
                <Link :href="row.route" class="hm-focus flex items-start gap-2.5 rounded-sm py-[11px]" :data-severity="row.severity">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="row.severity === 'high' ? 'bg-bad' : 'bg-(--hm-warn-line)'" aria-hidden="true" />
                    <span class="min-w-0">
                        <b class="block text-[12.5px] font-semibold text-highlighted">{{ row.title }}</b>
                        <small v-if="row.detail" class="block text-[11.5px] text-muted">{{ row.detail }}</small>
                    </span>
                </Link>
            </li>
        </ul>
    </section>
</template>
