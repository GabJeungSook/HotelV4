<script setup lang="ts">
import { computed, ref } from 'vue';

/** A small fixed set of periods on one track; the active one is a white pill (DESIGN_SYSTEM §5.2). */
const props = withDefaults(defineProps<{ items: { value: string; label: string; disabled?: boolean }[]; label?: string }>(), { label: 'Period' });

const model = defineModel<string>({ required: true });
const buttons = ref<HTMLElement[]>([]);
const enabled = computed(() => props.items.map((item, i) => (item.disabled ? -1 : i)).filter((i) => i >= 0));

function move(index: number, step: 1 | -1): void {
    const list = enabled.value;
    const next = list[(list.indexOf(index) + step + list.length) % list.length];
    model.value = props.items[next].value;
    buttons.value[next]?.focus();
}

function onKey(event: KeyboardEvent, index: number): void {
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        event.preventDefault();
        move(index, 1);
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        event.preventDefault();
        move(index, -1);
    }
}
</script>

<template>
    <div role="radiogroup" :aria-label="label" class="inline-flex max-w-full gap-0.5 overflow-x-auto rounded-lg bg-track p-0.5">
        <button
            v-for="(item, i) in items"
            :key="item.value"
            ref="buttons"
            type="button"
            role="radio"
            :aria-checked="model === item.value"
            :disabled="item.disabled"
            :tabindex="model === item.value ? 0 : -1"
            class="hm-focus cursor-pointer rounded-md px-3 py-1 text-[13px] leading-[18px] whitespace-nowrap transition-colors duration-120 ease-out disabled:cursor-not-allowed disabled:opacity-50"
            :class="model === item.value ? 'bg-default font-semibold text-highlighted shadow-(--hm-shadow-segment)' : 'font-medium text-muted hover:text-default'"
            @click="model = item.value"
            @keydown="onKey($event, i)"
        >
            {{ item.label }}
        </button>
    </div>
</template>
