<script setup lang="ts" generic="T extends string | number">
import { computed, ref } from 'vue';

/**
 * Single choice from a small fixed set that carries a second line (DESIGN_SYSTEM §5.2).
 * Radio-group semantics: arrow keys move and select, Space selects; unavailable tiles are skipped.
 */
export interface OptionTile<V> {
    value: V;
    label: string;
    sub?: string;
    disabled?: boolean;
    reason?: string;
}

const props = withDefaults(
    defineProps<{
        options: OptionTile<T>[];
        label?: string;
        columns?: 'auto' | number;
        size?: 'md' | 'kiosk';
        addLabel?: string;
    }>(),
    { label: undefined, columns: 'auto', size: 'md', addLabel: undefined },
);

const model = defineModel<T | null>({ default: null });
const emit = defineEmits<{ add: [] }>();
const tiles = ref<HTMLElement[]>([]);

const enabled = computed(() => props.options.map((o, i) => (o.disabled ? -1 : i)).filter((i) => i >= 0));

/** The tile that takes Tab focus: the selected one, else the first enabled one. */
const tabIndexOf = computed(() => {
    const selected = props.options.findIndex((o) => o.value === model.value && !o.disabled);

    return selected >= 0 ? selected : (enabled.value[0] ?? -1);
});

const gridStyle = computed(() =>
    props.columns === 'auto' ? { gridTemplateColumns: `repeat(auto-fit, minmax(${props.size === 'kiosk' ? '200px' : '96px'}, 1fr))` } : { gridTemplateColumns: `repeat(${props.columns}, minmax(0, 1fr))` },
);

function select(option: OptionTile<T>): void {
    if (!option.disabled) {
        model.value = option.value;
    }
}

function move(from: number, step: 1 | -1): void {
    const list = enabled.value;

    if (list.length === 0) {
        return;
    }

    const pos = list.indexOf(from);
    const next = list[(pos + step + list.length) % list.length];
    select(props.options[next]);
    tiles.value[next]?.focus();
}

function onKey(event: KeyboardEvent, index: number): void {
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        event.preventDefault();
        move(index, 1);
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        event.preventDefault();
        move(index, -1);
    } else if (event.key === ' ' || event.key === 'Enter') {
        event.preventDefault();
        select(props.options[index]);
    }
}
</script>

<template>
    <div role="radiogroup" :aria-label="label" class="grid gap-2" :style="gridStyle">
        <button
            v-for="(option, i) in options"
            :key="String(option.value)"
            ref="tiles"
            type="button"
            role="radio"
            :aria-checked="model === option.value"
            :aria-disabled="option.disabled || undefined"
            :disabled="option.disabled"
            :tabindex="i === tabIndexOf ? 0 : -1"
            class="hm-focus flex flex-col items-center justify-center rounded-md border text-center transition-colors duration-120 ease-out"
            :class="[
                size === 'kiosk' ? 'min-h-24 px-4 py-3' : 'min-h-9 px-2.5 py-1.5',
                option.disabled
                    ? 'cursor-not-allowed border-accented bg-default opacity-45'
                    : model === option.value
                      ? 'border-select-line bg-select text-primary-ink'
                      : 'cursor-pointer border-accented bg-default text-default hover:bg-elevated',
            ]"
            :data-state="option.disabled ? 'unavailable' : model === option.value ? 'selected' : 'normal'"
            @click="select(option)"
            @keydown="onKey($event, i)"
        >
            <span :class="[size === 'kiosk' ? 'text-2xl' : 'text-sm', model === option.value && !option.disabled ? 'font-semibold' : 'font-medium']">{{ option.label }}</span>
            <span
                v-if="option.sub || (option.disabled && option.reason)"
                class="tabular font-medium"
                :class="[size === 'kiosk' ? 'text-[22px]' : 'text-xs', model === option.value && !option.disabled ? 'text-primary-ink' : 'text-muted']"
                >{{ option.disabled && option.reason ? option.reason : option.sub }}</span
            >
        </button>
        <button
            v-if="addLabel"
            type="button"
            class="hm-focus flex min-h-9 cursor-pointer items-center justify-center rounded-md border border-dashed border-accented px-2.5 py-1.5 text-sm font-medium text-muted hover:bg-elevated"
            @click="emit('add')"
        >
            + {{ addLabel }}
        </button>
    </div>
</template>
