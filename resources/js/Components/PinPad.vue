<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * PIN entry (DESIGN_SYSTEM §5.2). Cells show "•", never the digit. Emits `complete` with the
 * digits when the last cell is filled; the digits are never stored outside this component.
 */
const props = withDefaults(
    defineProps<{
        length?: number;
        error?: string;
        triesLeft?: number;
        locked?: boolean;
        disabled?: boolean;
        showKeys?: boolean;
        size?: 'md' | 'touch';
        /** Listen to the physical keyboard (desktop). Off in the gallery where several pads coexist. */
        keyboard?: boolean;
    }>(),
    { length: 4, error: undefined, triesLeft: undefined, locked: false, disabled: false, showKeys: true, size: 'md', keyboard: true },
);

const emit = defineEmits<{ complete: [pin: string] }>();
const digits = ref('');

const keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', 'back'];

function press(key: string): void {
    if (props.locked || props.disabled) {
        return;
    }

    if (key === 'back') {
        digits.value = digits.value.slice(0, -1);

        return;
    }

    if (!/^\d$/.test(key) || digits.value.length >= props.length) {
        return;
    }

    digits.value += key;

    if (digits.value.length === props.length) {
        const pin = digits.value;
        emit('complete', pin);
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (/^\d$/.test(event.key)) {
        press(event.key);
    } else if (event.key === 'Backspace') {
        press('back');
    }
}

/** A new error clears the digits (no shake). */
watch(
    () => props.error,
    (value) => {
        if (value) {
            digits.value = '';
        }
    },
);

onMounted(() => props.keyboard && window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));

defineExpose({ clear: () => (digits.value = ''), press });
</script>

<template>
    <div class="flex flex-col items-center" :data-state="locked ? 'locked' : error ? 'error' : disabled ? 'disabled' : 'normal'">
        <div class="flex justify-center gap-2.5" role="group" :aria-label="`PIN, ${digits.length} of ${length} digits entered`">
            <span
                v-for="i in length"
                :key="i"
                data-pin-cell
                class="grid h-[52px] w-11 place-items-center rounded-lg border-[1.5px] text-[22px] font-semibold tabular"
                :class="[
                    error ? 'border-bad' : i <= digits.length ? 'border-primary bg-primary-50 text-primary-ink' : 'border-control-dash',
                    locked || disabled ? 'opacity-60' : '',
                ]"
                >{{ i <= digits.length ? '•' : '' }}</span
            >
        </div>
        <p v-if="locked" class="mt-3 max-w-72 text-center text-xs font-semibold text-error" role="alert">PIN entry is locked on this computer. Ask a supervisor to unlock it.</p>
        <p v-else-if="error" class="mt-3 text-center text-xs font-semibold text-error" role="alert">
            {{ error }}<template v-if="triesLeft !== undefined"> · {{ triesLeft }} {{ triesLeft === 1 ? 'try' : 'tries' }} left</template>
        </p>
        <p v-else-if="triesLeft !== undefined" class="mt-3 text-center text-xs text-muted">{{ triesLeft }} {{ triesLeft === 1 ? 'try' : 'tries' }} left</p>
        <div v-if="showKeys" class="mt-4 grid w-full max-w-60 grid-cols-3 gap-2">
            <template v-for="key in keys" :key="key || 'blank'">
                <span v-if="key === ''" aria-hidden="true" />
                <button
                    v-else
                    type="button"
                    class="hm-focus grid cursor-pointer place-items-center rounded-lg bg-grey-50 text-lg font-semibold text-highlighted tabular transition-colors duration-120 ease-out hover:bg-nav-active disabled:cursor-not-allowed disabled:opacity-50"
                    :class="size === 'touch' ? 'h-16' : 'h-14'"
                    :disabled="locked || disabled"
                    :aria-label="key === 'back' ? 'Delete last digit' : key"
                    :data-key="key"
                    @click="press(key)"
                >
                    {{ key === 'back' ? '⌫' : key }}
                </button>
            </template>
        </div>
    </div>
</template>
