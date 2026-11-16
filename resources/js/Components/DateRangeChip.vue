<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { formatDateRange, formatDateTime } from '@/Composables/useDateTime';

/**
 * The date-range chip of a filter row (mockup v6: calendar icon, "5 – 12 Nov 2026", chevron).
 * Opens a small popover with two native date fields and Apply; Clear removes the range.
 */
const props = withDefaults(defineProps<{ from?: string | null; to?: string | null; placeholder?: string }>(), { from: null, to: null, placeholder: 'Any date' });
const emit = defineEmits<{ change: [range: { from: string | null; to: string | null }] }>();

const open = ref(false);
const a = ref(props.from ?? '');
const b = ref(props.to ?? '');
watch(
    () => [props.from, props.to],
    ([f, t]) => {
        a.value = f ?? '';
        b.value = t ?? '';
    },
);

const at = (d: string) => `${d}T12:00:00+08:00`;
const day = (d: string) => formatDateTime(at(d), 'date');
const label = computed(() => {
    if (!props.from && !props.to) {
        return props.placeholder;
    }

    if (props.from && props.to) {
        return formatDateRange(at(props.from), at(props.to));
    }

    return props.from ? `From ${day(props.from)}` : `Until ${day(props.to as string)}`;
});

function apply(): void {
    emit('change', { from: a.value || null, to: b.value || null });
    open.value = false;
}

function clear(): void {
    emit('change', { from: null, to: null });
    open.value = false;
}
</script>

<template>
    <UPopover v-model:open="open" :content="{ align: 'start' }">
        <button
            type="button"
            class="hm-focus inline-flex h-8 shrink-0 cursor-pointer items-center gap-2 rounded-control border border-default bg-default ps-3 pe-2.5 text-[12.5px] font-medium whitespace-nowrap text-default shadow-xs hover:bg-elevated"
            data-date-range
        >
            <UIcon name="i-solar-calendar-bold-duotone" class="size-3.5 text-faint" aria-hidden="true" />
            {{ label }}
            <UIcon name="i-solar-alt-arrow-down-linear" class="size-3.5 text-faint" aria-hidden="true" />
        </button>
        <template #content>
            <form class="flex w-64 flex-col gap-3 p-3" @submit.prevent="apply">
                <UFormField label="From"><UInput v-model="a" type="date" class="w-full" /></UFormField>
                <UFormField label="To"><UInput v-model="b" type="date" class="w-full" :min="a || undefined" /></UFormField>
                <div class="flex justify-end gap-2">
                    <UButton color="neutral" variant="ghost" size="sm" label="Clear" @click="clear" />
                    <UButton type="submit" size="sm" label="Apply" />
                </div>
            </form>
        </template>
    </UPopover>
</template>
