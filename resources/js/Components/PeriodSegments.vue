<script setup lang="ts">
import { computed, ref } from 'vue';

/** A small fixed set on one track (periods, List/Calendar, floors); the active one is a white pill (DESIGN_SYSTEM §5.2, mockup v6 `.segp`). */
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
    <div role="radiogroup" :aria-label="label" class="inline-flex max-w-full shrink-0 gap-0.5 overflow-x-auto rounded-[9px] border border-default bg-track p-0.5">
        <button
            v-for="(item, i) in items"
            :key="item.value"
            ref="buttons"
            type="button"
            role="radio"
            :aria-checked="model === item.value"
            :disabled="item.disabled"
            :tabindex="model === item.value ? 0 : -1"
            class="hm-focus cursor-pointer rounded-[7px] px-[11px] py-1 text-xs leading-[18px] font-medium whitespace-nowrap transition-colors duration-120 ease-out disabled:cursor-not-allowed disabled:opacity-50"
            :class="model === item.value ? 'bg-default text-highlighted shadow-(--hm-shadow-segment)' : 'text-muted hover:text-default'"
            @click="model = item.value"
            @keydown="onKey($event, i)"
        >
            {{ item.label }}
        </button>
    </div>
</template>
