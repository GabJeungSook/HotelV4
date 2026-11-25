<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { formatMoney, parseMoney } from '@/Composables/useMoney';

/**
 * Peso amount field (DESIGN_SYSTEM §5.2). A text input with inputmode="decimal" (never
 * type="number"); digits and one decimal point, at most two decimals, no negatives.
 * Emits integer centavos; shows "₱ 1,000.00" once the field loses focus.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        error?: boolean | string;
        min?: number;
        max?: number;
        due?: number;
        autofocus?: boolean;
        disabled?: boolean;
        placeholder?: string;
        size?: 'lg' | 'xl';
    }>(),
    { id: undefined, name: undefined, error: false, min: 0, max: undefined, due: undefined, autofocus: false, disabled: false, placeholder: '0.00', size: 'lg' },
);

const model = defineModel<number>({ default: 0 });
const focused = ref(false);
const text = ref(display(model.value));
const input = ref<HTMLInputElement | null>(null);

function display(centavos: number | null): string {
    return centavos ? formatMoney(centavos, 'column') : '';
}

watch(model, (value) => {
    if (!focused.value) {
        text.value = display(value);
    }
});

/** Keeps only digits and one decimal point with at most two decimals; drops leading zeros. */
function sanitize(raw: string): string {
    let cleaned = raw.replace(/[^\d.]/g, '');
    const dot = cleaned.indexOf('.');

    if (dot >= 0) {
        cleaned = cleaned.slice(0, dot + 1) + cleaned.slice(dot + 1).replace(/\./g, '').slice(0, 2);
    }

    return cleaned.replace(/^0+(?=\d)/, '');
}

function onInput(event: Event): void {
    const el = event.target as HTMLInputElement;
    const cleaned = sanitize(el.value);

    if (cleaned !== el.value) {
        el.value = cleaned;
    }

    text.value = cleaned;
    let centavos = parseMoney(cleaned) ?? 0;

    if (props.max !== undefined && centavos > props.max) {
        centavos = props.max;
    }

    model.value = centavos;
}

function onFocus(): void {
    focused.value = true;
    text.value = model.value ? sanitize(formatMoney(model.value, 'csv')) : '';
}

function onBlur(): void {
    focused.value = false;

    if (model.value < props.min) {
        model.value = props.min;
    }

    text.value = display(model.value);
}

function fillExact(): void {
    if (props.due === undefined) {
        return;
    }

    // Focus first so onFocus runs against the old value; writing model.value only emits to the
    // parent and does not update the local ref until it syncs back (Vue defineModel), so onFocus
    // would otherwise re-read the stale 0 and wipe the field. With focused already true, the model
    // watcher leaves our text alone when the new value arrives.
    input.value?.focus();
    model.value = props.due;
    text.value = sanitize(formatMoney(props.due, 'csv'));
}

const invalid = computed(() => Boolean(props.error));

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div
        class="relative flex items-center rounded-md bg-default ring ring-accented ring-inset transition-[box-shadow] duration-120 ease-out"
        :class="[
            size === 'xl' ? 'h-10' : 'h-9',
            invalid ? 'shadow-[0_0_0_3px_var(--hm-bad-50)] ring-1 ring-error' : 'focus-within:shadow-[0_0_0_3px_var(--hm-focus)] focus-within:ring-1 focus-within:ring-primary',
            disabled ? 'bg-elevated' : '',
        ]"
        :data-state="disabled ? 'disabled' : invalid ? 'error' : 'normal'"
    >
        <span class="pointer-events-none ps-3 pe-1.5 num" :class="disabled ? 'text-dimmed' : ''" aria-hidden="true">₱</span>
        <input
            :id="id"
            ref="input"
            :name="name"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            :value="text"
            :placeholder="placeholder"
            :disabled="disabled"
            :autofocus="autofocus"
            :aria-invalid="invalid || undefined"
            class="h-full min-w-0 flex-1 bg-transparent pe-3 font-semibold text-highlighted tabular outline-none placeholder:font-normal placeholder:text-dimmed disabled:cursor-not-allowed disabled:text-dimmed"
            :class="size === 'xl' ? 'text-base' : 'text-sm'"
            @input="onInput"
            @focus="onFocus"
            @blur="onBlur"
        />
        <UButton v-if="due !== undefined && !disabled" class="me-1" size="sm" color="primary" variant="link" label="Exact" @click="fillExact" />
    </div>
</template>
