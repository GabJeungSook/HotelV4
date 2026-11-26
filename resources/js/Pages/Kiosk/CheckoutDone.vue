<!-- Kiosk checkout done: the request is in; the guest settles at the front desk. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import HomiLogo from '@/Components/HomiLogo.vue';
import { useKioskIdle } from '@/Composables/useKioskIdle';
import KioskLayout from '@/Layouts/KioskLayout.vue';

// Return to the welcome screen on its own after a short while.
useKioskIdle(20, () => router.visit(route('kiosk.index')));

function home(): void {
    router.visit(route('kiosk.index'));
}
</script>

<template>
    <KioskLayout title="Checkout requested">
        <div class="flex min-h-[70dvh] flex-col items-center justify-center text-center">
            <HomiLogo variant="blue" :width="124" :height="38" class="mb-12" />

            <span class="grid size-24 place-items-center rounded-full bg-ok-50 text-ok" aria-hidden="true">
                <UIcon name="i-solar-check-circle-bold-duotone" class="size-14" />
            </span>

            <h1 class="mt-8 text-[44px] leading-tight font-semibold tracking-[-0.02em] text-highlighted">You're all set</h1>
            <p class="mt-4 max-w-[640px] text-[26px] leading-9 text-muted">
                We've let the front desk know you're checking out. Please go to the desk to settle any balance and collect your deposit.
            </p>

            <UButton class="mt-12 h-[72px] w-full max-w-[420px] justify-center text-2xl" size="xl" block label="Done" @click="home" />
        </div>
    </KioskLayout>
</template>
