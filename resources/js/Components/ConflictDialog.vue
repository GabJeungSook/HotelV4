<script setup lang="ts">
/**
 * Room just taken (53, DESIGN_SYSTEM §5.2): not dismissible by an overlay click. The primary
 * action keeps the guest's details and reopens check-in with the next free room of the type.
 */
withDefaults(
    defineProps<{
        title: string;
        message: string;
        primaryLabel?: string;
        secondaryLabel?: string;
    }>(),
    { primaryLabel: undefined, secondaryLabel: 'Back to board' },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ primary: []; secondary: [] }>();
</script>

<template>
    <UModal v-model:open="open" :title="title" :dismissible="false" :close="false" :ui="{ content: 'max-w-md' }">
        <template #body>
            <p class="text-sm leading-6 text-default">{{ message }}</p>
        </template>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <UButton color="neutral" variant="outline" :label="secondaryLabel" @click="emit('secondary')" />
                <UButton v-if="primaryLabel" :label="primaryLabel" @click="emit('primary')" />
            </div>
        </template>
    </UModal>
</template>
