<script setup lang="ts">
import { shallowRef, watch } from 'vue';
import { formatDateTime } from '@/Composables/useDateTime';

/**
 * A "from–to" business-date range for the dashboards (DESIGN_SYSTEM UCalendar): two calendar
 * popovers, "‹date› to ‹date›". Picking either end emits `apply` with both plain "YYYY-MM-DD"
 * dates; the page puts them in the query string. Mirrors DateNav's look.
 */
const props = defineProps<{ from: string; to: string }>();
const emit = defineEmits<{ apply: [from: string, to: string] }>();

const fromPick = shallowRef<{ toString(): string } | undefined>(undefined);
const toPick = shallowRef<{ toString(): string } | undefined>(undefined);
watch(fromPick, (d) => d && emit('apply', d.toString(), props.to));
watch(toPick, (d) => d && emit('apply', props.from, d.toString()));

const fmt = (s: string) => formatDateTime(`${s}T12:00:00+08:00`, 'businessDate');
</script>

<template>
    <div class="inline-flex items-center gap-1.5" role="group" aria-label="Date range">
        <UPopover>
            <UButton color="neutral" variant="outline" size="sm" icon="i-solar-calendar-bold-duotone" :label="fmt(from)" />
            <template #content><UCalendar v-model="fromPick" :week-starts-on="1" class="p-2" /></template>
        </UPopover>
        <span class="text-xs text-muted">to</span>
        <UPopover>
            <UButton color="neutral" variant="outline" size="sm" :label="fmt(to)" />
            <template #content><UCalendar v-model="toPick" :week-starts-on="1" class="p-2" /></template>
        </UPopover>
    </div>
</template>
