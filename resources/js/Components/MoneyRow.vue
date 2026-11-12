<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/Composables/useMoney';

/** One label and amount on a line (DESIGN_SYSTEM §5.2). Amounts are integer centavos. */
const props = withDefaults(
    defineProps<{
        label: string;
        amount?: number | null;
        text?: string;
        tone?: 'default' | 'ok' | 'bad' | 'primary';
        sign?: 'auto' | 'none';
    }>(),
    { amount: undefined, text: undefined, tone: 'default', sign: 'auto' },
);

const value = computed(() => (props.text !== undefined ? props.text : formatMoney(props.amount, props.sign === 'none' ? 'column' : 'standard')));
const toneClass = computed(() => ({ default: 'text-highlighted', ok: 'text-ok', bad: 'text-bad', primary: 'text-primary' })[props.tone]);
</script>

<template>
    <div class="flex items-baseline justify-between gap-4 py-1 text-sm">
        <span class="text-default">{{ label }}</span>
        <span class="num" :class="toneClass" data-amount>{{ value }}</span>
    </div>
</template>
