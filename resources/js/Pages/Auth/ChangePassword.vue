<!-- Mockup 1 (sign-in card pattern; "Change temporary password" has no frame of its own, APP_FLOW §8.2) -->
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import SignInPanel from '@/Components/SignInPanel.vue';
import { useShared } from '@/Composables/useShared';

/** Replace a temporary password at first sign-in: the sign-in card of screen 1, typed twice. */
defineProps<{ min: number }>();

const { flash } = useShared();
const form = useForm({ password: '', password_confirmation: '' });

function submit(): void {
    form.put(route('password.update'), { onFinish: () => form.reset() });
}
</script>

<template>
    <Head title="Change your password" />
    <div class="hm-light grid min-h-dvh place-items-center bg-muted p-4">
        <div class="w-full max-w-[460px] overflow-hidden rounded-card border border-default">
            <SignInPanel heading="Change your password" line="You signed in with a temporary password. Choose your own before you go on.">
                <UAlert v-if="flash.error" color="error" variant="subtle" class="mb-4" :description="flash.error.message" icon="i-solar-danger-triangle-bold-duotone" />
                <form class="flex flex-col gap-4" @submit.prevent="submit">
                    <UFormField label="New password" :hint="`At least ${min} characters`" :error="form.errors.password">
                        <UInput v-model="form.password" type="password" autocomplete="new-password" class="w-full" autofocus />
                    </UFormField>
                    <UFormField label="Type it again" :error="form.errors.password_confirmation">
                        <UInput v-model="form.password_confirmation" type="password" autocomplete="new-password" class="w-full" />
                    </UFormField>
                    <UButton type="submit" block label="Save password" :loading="form.processing" :disabled="form.password.length < min || form.password_confirmation === ''" />
                </form>
                <template #footer>
                    <UButton color="neutral" variant="link" label="Sign out" @click="router.post(route('logout'))" />
                </template>
            </SignInPanel>
        </div>
    </div>
</template>
