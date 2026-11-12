<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui';
import { computed } from 'vue';

/**
 * "Export ▾" (DESIGN_SYSTEM ExportMenu): Print, PDF, CSV in a dropdown. A preview cannot be
 * exported: the button is disabled with "Close the shift first". The page records the print
 * (print_logs) before calling window.print(), and the server logs every file it sends.
 */
const props = withDefaults(defineProps<{ print?: boolean; pdf?: boolean; csv?: boolean; disabled?: boolean; loading?: boolean; disabledReason?: string }>(), {
    print: true,
    pdf: false,
    csv: false,
    disabled: false,
    loading: false,
    disabledReason: 'Close the shift first',
});

const emit = defineEmits<{ print: []; pdf: []; csv: [] }>();

const items = computed<DropdownMenuItem[]>(() => [
    ...(props.print ? [{ label: 'Print', icon: 'i-lucide-printer', onSelect: () => emit('print') }] : []),
    ...(props.pdf ? [{ label: 'PDF', icon: 'i-lucide-file-text', onSelect: () => emit('pdf') }] : []),
    ...(props.csv ? [{ label: 'CSV', icon: 'i-lucide-sheet', onSelect: () => emit('csv') }] : []),
]);
</script>

<template>
    <UTooltip v-if="disabled" :text="disabledReason">
        <span tabindex="0"><UButton color="neutral" variant="outline" label="Export" trailing-icon="i-lucide-chevron-down" disabled /></span>
    </UTooltip>
    <UDropdownMenu v-else :items="items" :content="{ align: 'end' }">
        <UButton color="neutral" variant="outline" label="Export" trailing-icon="i-lucide-chevron-down" :loading="loading" />
    </UDropdownMenu>
</template>
