<!-- Mockup 33 -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CleanTimer from '@/Components/CleanTimer.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useShared } from '@/Composables/useShared';
import PhoneLayout from '@/Layouts/PhoneLayout.vue';

/**
 * Cleaning in progress (33, APP_FLOW §4.17 steps 4–6): a timer against the target, the checklist,
 * "Room is ready" (room → Available, sellable again) and "Report a problem in this room" (room →
 * Maintenance with a note). The housekeeper cannot start another room until this one is finished.
 */
const props = defineProps<{
    task: { id: number; room: string; type: string | null; started_at: string | null; due_at: string };
    target_minutes: number;
    checklist: string[];
}>();

const { flash } = useShared();
const ticked = ref<Record<number, boolean>>({});
const reporting = ref(false);
const form = useForm({ result: 'ready' as 'ready' | 'problem', problem_note: '' });
const error = computed(() => flash.value.error?.message);

function finish(result: 'ready' | 'problem'): void {
    form.result = result;
    form.transform((data) => ({ result: data.result, ...(data.result === 'problem' ? { problem_note: data.problem_note } : {}) })).post(route('housekeeping.finish', props.task.id), {
        onSuccess: () => (reporting.value = false),
    });
}
</script>

<template>
    <PhoneLayout :title="`Cleaning Room ${task.room}`" :subtitle="task.started_at ? `Started ${formatDateTime(task.started_at, 'time')}` : undefined" tone="violet" current="queue">
        <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="error" data-finish-error />

        <div class="px-1">
            <CleanTimer v-if="task.started_at" :started-at="task.started_at" :target-minutes="target_minutes" />
        </div>

        <section class="mt-2 rounded-[10px] border border-default bg-default p-3">
            <b class="text-highlighted">Before you finish</b>
            <div class="mt-2 flex flex-col">
                <UCheckbox v-for="(item, i) in checklist" :key="item" v-model="ticked[i]" :label="item" size="xl" class="min-h-12 items-center" />
            </div>
        </section>

        <UButton
            class="mt-2 h-14 justify-center text-base"
            size="xl"
            color="success"
            block
            label="Room is ready"
            :loading="form.processing && form.result === 'ready'"
            :disabled="form.processing"
            @click="finish('ready')"
        />
        <UButton class="h-12 justify-center" size="xl" color="neutral" variant="ghost" block label="Report a problem in this room" @click="reporting = true" />

        <UModal v-model:open="reporting" title="Report a problem" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <p class="mb-3 text-sm text-muted">Room {{ task.room }} goes to Maintenance instead of Available. An admin returns it when it is fixed.</p>
                <UFormField label="What is wrong" name="problem_note" :error="form.errors.problem_note">
                    <UTextarea v-model="form.problem_note" :rows="3" class="w-full" size="xl" placeholder="Aircon leaking onto the bed" autofocus />
                </UFormField>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton size="xl" color="neutral" variant="ghost" label="Cancel" @click="reporting = false" />
                    <UButton
                        size="xl"
                        color="error"
                        label="Send to maintenance"
                        :disabled="form.problem_note.trim() === '' || form.processing"
                        :loading="form.processing && form.result === 'problem'"
                        @click="finish('problem')"
                    />
                </div>
            </template>
        </UModal>
    </PhoneLayout>
</template>
