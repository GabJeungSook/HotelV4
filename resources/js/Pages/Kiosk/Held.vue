<!-- Mockup 30 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import KioskFooter from '@/Components/KioskFooter.vue';
import KioskHoldCard from '@/Components/KioskHoldCard.vue';
import StepBar from '@/Components/StepBar.vue';
import { useKioskIdle } from '@/Composables/useKioskIdle';
import KioskLayout from '@/Layouts/KioskLayout.vue';

/**
 * Room held (30): the code, its QR, the amount to pay at the desk, and the countdown, which is
 * the server's hold_expires_at. The guest shows the code or the QR at the front desk, where the
 * hold is in the holds sheet (5). Back to the welcome screen (27) on Done or after 30 s.
 */
defineProps<{ hold: { code: string; room: string | null; state: string; amount: number; expires_at: string | null; now: string; held: boolean } }>();

function done(): void {
    router.visit(route('kiosk.index'));
}

useKioskIdle(30, done);
</script>

<template>
    <KioskLayout title="Room held">
        <StepBar :steps="['Room', 'Your name', 'Pay at desk']" :current="3" size="kiosk" />
        <div class="mt-12 text-center">
            <h1 class="text-[40px] leading-[48px] font-semibold text-highlighted">Room {{ hold.room }} is held for you</h1>
        </div>
        <KioskHoldCard class="mt-4" :code="hold.code" :amount="hold.amount" :expires-at="hold.held ? hold.expires_at : null" :now="hold.now" />
        <p class="mt-6 text-center text-lg leading-[26px] text-muted">If you do not pay in time the room is released and you can start again.</p>
        <KioskFooter>
            <UButton class="h-[72px] justify-center text-2xl" size="xl" block color="neutral" variant="outline" label="Done" data-test="done" @click="done" />
        </KioskFooter>
    </KioskLayout>
</template>
