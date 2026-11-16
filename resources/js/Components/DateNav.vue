<script setup lang="ts">
import { computed, shallowRef, watch } from 'vue';
import { formatDateTime } from '@/Composables/useDateTime';

/**
 * The business-date control of the reports (DESIGN_SYSTEM UCalendar): "‹ Wed, 11 Nov 2026 ›".
 * The arrows step one day (or `step` days); the middle button opens the calendar. The value is a
 * plain "YYYY-MM-DD" business date; the page puts it in the query string.
 */
const props = withDefaults(defineProps<{ step?: number; label?: string }>(), { step: 1, label: undefined });
const model = defineModel<string>({ required: true });

function shift(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}

const text = computed(() => props.label ?? formatDateTime(`${model.value}T12:00:00+08:00`, 'businessDate'));
const picked = shallowRef<{ toString(): string } | undefined>(undefined);
watch(picked, (d) => d && (model.value = d.toString()));
</script>

<template>
    <div class="inline-flex items-center gap-1" role="group" aria-label="Business date">
        <UButton color="neutral" variant="ghost" icon="i-solar-alt-arrow-left-linear" aria-label="Earlier" @click="model = shift(model, -step)" />
        <UPopover>
            <UButton color="neutral" variant="outline" icon="i-solar-calendar-bold-duotone" :label="text" />
            <template #content>
                <UCalendar v-model="picked" :week-starts-on="1" class="p-2" />
            </template>
        </UPopover>
        <UButton color="neutral" variant="ghost" icon="i-solar-alt-arrow-right-linear" aria-label="Later" @click="model = shift(model, step)" />
    </div>
</template>
