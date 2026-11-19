<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui';
import { computed } from 'vue';

/**
 * "Export ▾" (DESIGN_SYSTEM ExportMenu): Print, PDF, CSV in a dropdown. A preview cannot be
 * exported: the button is disabled with "Close the shift first". The page records the print
 * (print_logs) before calling window.print(), and the server logs every file it sends.
 */
const props = withDefaults(defineProps<{ print?: boolean; pdf?: boolean; csv?: boolean; html?: boolean; disabled?: boolean; loading?: boolean; disabledReason?: string }>(), {
    print: true,
    pdf: false,
    csv: false,
    html: false,
    disabled: false,
    loading: false,
    disabledReason: 'Close the shift first',
});

const emit = defineEmits<{ print: []; pdf: []; csv: []; html: [] }>();

const items = computed<DropdownMenuItem[]>(() => [
    ...(props.print ? [{ label: 'Print', icon: 'i-solar-printer-2-bold-duotone', onSelect: () => emit('print') }] : []),
    ...(props.html ? [{ label: 'HTML file', icon: 'i-solar-code-square-bold-duotone', onSelect: () => emit('html') }] : []),
    ...(props.pdf ? [{ label: 'PDF', icon: 'i-solar-document-text-bold-duotone', onSelect: () => emit('pdf') }] : []),
    ...(props.csv ? [{ label: 'CSV', icon: 'i-solar-document-bold-duotone', onSelect: () => emit('csv') }] : []),
]);
</script>

<template>
    <UTooltip v-if="disabled" :text="disabledReason">
        <span tabindex="0"><UButton color="neutral" variant="outline" label="Export" trailing-icon="i-solar-alt-arrow-down-linear" disabled /></span>
    </UTooltip>
    <UDropdownMenu v-else :items="items" :content="{ align: 'end' }">
        <UButton color="neutral" variant="outline" label="Export" trailing-icon="i-solar-alt-arrow-down-linear" :loading="loading" />
    </UDropdownMenu>
</template>
