<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import PinPad from './PinPad.vue';
import PinUnlock from './PinUnlock.vue';

/**
 * Supervisor approval (14, DESIGN_SYSTEM §5.2): a summary line, the approver, the PIN pad,
 * "Cancel" and "Approve" (disabled until four digits). Nothing is saved until the server
 * accepts the PIN; the parent posts the action with { approver_id, pin }.
 */
export interface Approver {
    id: number;
    name: string;
    role: string;
}

const props = withDefaults(
    defineProps<{
        summary: string;
        approvers: Approver[];
        error?: string;
        locked?: boolean;
        processing?: boolean;
        /** Bumped by the parent after a refused try, so the pad clears. */
        attempt?: number;
    }>(),
    { error: undefined, locked: false, processing: false, attempt: 0 },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ approve: [payload: { approver_id: number; pin: string }] }>();

const approverId = ref<number | undefined>(props.approvers[0]?.id);
const pin = ref('');
// Bumped on every opening, so the pad never keeps digits from the last time.
const opens = ref(0);
const items = computed(() => props.approvers.map((a) => ({ label: `${a.name} (${a.role})`, value: a.id })));

watch(
    () => props.attempt,
    () => (pin.value = ''),
);
watch(open, (value) => {
    if (value) {
        pin.value = '';
        opens.value++;
    }
});

function approve(): void {
    if (approverId.value !== undefined && pin.value.length === 4) {
        emit('approve', { approver_id: approverId.value, pin: pin.value });
    }
}
</script>

<template>
    <UModal v-model:open="open" :ui="{ content: 'max-w-[360px]' }">
        <template #header>
            <div class="flex items-center gap-2">
                <UIcon name="i-solar-lock-bold-duotone" class="size-[18px] text-muted" aria-hidden="true" />
                <h2 class="text-base font-semibold text-highlighted">Supervisor approval</h2>
            </div>
        </template>
        <template #body>
            <p class="mb-3 text-[13px] leading-5 text-muted">{{ summary }}</p>
            <template v-if="!locked">
                <UFormField label="Approver" name="approver_id" class="mb-4">
                    <USelect v-model="approverId" :items="items" class="w-full" placeholder="Choose who approves" />
                </UFormField>
                <p v-if="approvers.length === 0" class="mb-3 text-sm text-error">No supervisor or admin is set up to approve.</p>
            </template>
            <PinPad :key="`${opens}-${attempt}`" :locked="locked" :error="error" :disabled="processing" @complete="pin = $event" />
            <PinUnlock v-if="locked" />
        </template>
        <template #footer>
            <div class="flex w-full justify-end gap-2">
                <UButton color="neutral" variant="ghost" label="Cancel" @click="open = false" />
                <UButton label="Approve" :disabled="locked || pin.length !== 4 || approverId === undefined" :loading="processing" @click="approve" />
            </div>
        </template>
    </UModal>
</template>
