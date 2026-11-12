<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

/**
 * PIN entry locked (APP_FLOW §8.2): the sign-in card of screen 1 for a supervisor's or admin's
 * own username and password. Posts to /pin/unlock (UnlockPin); no approvals row is written.
 */
const emit = defineEmits<{ unlocked: [] }>();
const form = useForm({ username: '', password: '' });

function submit(): void {
    form.post(route('pin.unlock'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            form.reset();
            emit('unlocked');
        },
    });
}
</script>

<template>
    <form class="mt-4 flex flex-col gap-3 rounded-lg border border-default p-4" @submit.prevent="submit">
        <p class="text-sm font-semibold text-highlighted">Supervisor or admin: unlock this computer</p>
        <UFormField label="Username" name="username" :error="form.errors.username">
            <UInput v-model="form.username" autocomplete="off" class="w-full" />
        </UFormField>
        <UFormField label="Password" name="password" :error="form.errors.password">
            <UInput v-model="form.password" type="password" autocomplete="off" class="w-full" />
        </UFormField>
        <UButton type="submit" block label="Unlock PIN entry" :loading="form.processing" />
    </form>
</template>
