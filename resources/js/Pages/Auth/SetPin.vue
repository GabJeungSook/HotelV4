<!-- Mockup 3 (PIN pad pattern; "Set your PIN" has no frame of its own, APP_FLOW §8.2) -->
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PinPad from '@/Components/PinPad.vue';
import SignInPanel from '@/Components/SignInPanel.vue';
import { useShared } from '@/Composables/useShared';

/** Set your 4-digit PIN at first sign-in: the PIN pad of screen 3, typed twice. */
defineProps<{ length: number }>();

const { flash } = useShared();
const form = useForm({ pin: '', pin_confirmation: '' });
const step = ref<1 | 2>(1);
const attempt = ref(0);

function entered(pin: string): void {
    if (step.value === 1) {
        form.pin = pin;
        step.value = 2;
        attempt.value++;

        return;
    }

    form.pin_confirmation = pin;
    form.put(route('pin.update'), {
        onError: () => restart(),
        onSuccess: (page) => {
            if ((page.flash as { error?: unknown } | undefined)?.error) {
                restart();
            }
        },
    });
}

function restart(): void {
    form.reset();
    step.value = 1;
    attempt.value++;
}
</script>

<template>
    <Head title="Set your PIN" />
    <div class="hm-light grid min-h-dvh place-items-center bg-muted p-4">
        <div class="w-full max-w-[460px] overflow-hidden rounded-card border border-default">
            <SignInPanel heading="Set your PIN" line="You will use it to switch user on a shared computer and to approve. Never share it.">
                <UAlert v-if="flash.error" color="error" variant="subtle" class="mb-4" :description="flash.error.message" icon="i-lucide-triangle-alert" />
                <p class="mb-4 text-center text-sm font-medium text-highlighted">{{ step === 1 ? `Type a ${length}-digit PIN` : 'Type it again' }}</p>
                <PinPad :key="attempt" :length="length" :error="form.errors.pin" :disabled="form.processing" @complete="entered" />
                <template #footer>
                    <UButton color="neutral" variant="link" label="Sign out" @click="router.post(route('logout'))" />
                </template>
            </SignInPanel>
        </div>
    </div>
</template>
