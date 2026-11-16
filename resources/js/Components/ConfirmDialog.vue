<script setup lang="ts">
import { createReusableTemplate } from '@vueuse/core';
import { computed, ref } from 'vue';

/**
 * Confirm a destructive or sensitive action (DESIGN_SYSTEM §5.2). Opened through
 * useConfirmDialog(); emits `close` with false (cancel, Esc) or { reasonId, note }.
 * The safe button is on the left as a text button and takes focus first.
 * `inline` draws the same panel without the modal (component gallery).
 */
export interface ConfirmReason {
    id: number;
    name: string;
    needs_note: boolean;
    needs_manager: boolean;
}

export interface ConfirmResult {
    reasonId: number | null;
    note: string | null;
    needsApproval: boolean;
}

const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        confirmLabel: string;
        cancelLabel: string;
        confirmColor?: 'primary' | 'error';
        reasons?: ConfirmReason[];
        needsApproval?: boolean;
        loggedAs?: string;
        inline?: boolean;
    }>(),
    { description: undefined, confirmColor: 'primary', reasons: undefined, needsApproval: false, loggedAs: undefined, inline: false },
);

const emit = defineEmits<{ close: [result: ConfirmResult | false] }>();

// The reusable-template definitions make this a multi-root component, so the overlay's
// `open` and `after:leave` are passed to UModal explicitly.
defineOptions({ inheritAttrs: false });

const [DefineBody, Body] = createReusableTemplate();
const [DefineFooter, Footer] = createReusableTemplate();

const reasonId = ref<number | undefined>(undefined);
const note = ref('');
const touched = ref(false);

const picked = computed(() => props.reasons?.find((r) => r.id === reasonId.value));
const noteRequired = computed(() => picked.value?.needs_note === true);
const approval = computed(() => props.needsApproval || picked.value?.needs_manager === true);
const reasonItems = computed(() => (props.reasons ?? []).map((r) => ({ label: r.name, value: r.id })));

const reasonError = computed(() => (touched.value && props.reasons?.length && !picked.value ? 'Choose a reason.' : undefined));
const noteError = computed(() => (touched.value && noteRequired.value && note.value.trim() === '' ? 'Write a short note.' : undefined));

/** Description with **bold** amounts, split into safe text parts (no v-html). */
const descriptionParts = computed(() =>
    (props.description ?? '')
        .split(/(\*\*[^*]+\*\*)/)
        .filter(Boolean)
        .map((part) => ({ bold: part.startsWith('**'), text: part.replace(/\*\*/g, '') })),
);

function cancel(): void {
    emit('close', false);
}

function confirm(): void {
    touched.value = true;

    if (reasonError.value || noteError.value) {
        return;
    }

    emit('close', { reasonId: picked.value?.id ?? null, note: note.value.trim() || null, needsApproval: approval.value });
}
</script>

<template>
    <DefineBody>
        <div class="flex flex-col gap-3.5">
            <p v-if="description" class="text-sm text-default">
                <template v-for="(part, i) in descriptionParts" :key="i"
                    ><b v-if="part.bold">{{ part.text }}</b><template v-else>{{ part.text }}</template></template
                >
            </p>
            <UFormField v-if="reasons?.length" label="Reason" name="reason" :error="reasonError">
                <USelect v-model="reasonId" :items="reasonItems" value-key="value" placeholder="Choose a reason" class="w-full" data-field="reason" />
            </UFormField>
            <UFormField v-if="noteRequired" label="Note" name="note" :error="noteError">
                <UTextarea v-model="note" :rows="3" class="w-full" data-field="note" />
            </UFormField>
        </div>
    </DefineBody>

    <DefineFooter>
        <div class="flex w-full flex-col gap-2">
            <div class="flex justify-end gap-2">
                <UButton color="neutral" variant="ghost" :label="cancelLabel" data-action="cancel" @click="cancel" />
                <UButton :color="confirmColor" variant="solid" :label="confirmLabel" :icon="approval ? 'i-solar-lock-bold-duotone' : undefined" data-action="confirm" @click="confirm" />
            </div>
            <p v-if="loggedAs" class="text-right text-xs text-muted">Recorded as {{ loggedAs }}</p>
        </div>
    </DefineFooter>

    <div v-if="inline" v-bind="$attrs" role="dialog" :aria-label="title" class="rounded-xl border border-default bg-default p-5" data-confirm-dialog>
        <h3 class="mb-1 text-[18px] leading-[26px] font-semibold" :class="confirmColor === 'error' ? 'text-error' : 'text-highlighted'">{{ title }}</h3>
        <Body />
        <div class="mt-4"><Footer /></div>
    </div>
    <UModal v-else v-bind="$attrs" :title="title" :close="false" data-confirm-dialog @update:open="(open: boolean) => !open && cancel()">
        <template #title>
            <span :class="confirmColor === 'error' ? 'text-error' : 'text-highlighted'">{{ title }}</span>
        </template>
        <template #body><Body /></template>
        <template #footer><Footer /></template>
    </UModal>
</template>
