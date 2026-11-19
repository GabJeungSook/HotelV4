<!-- Mockup 30 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import KioskFooter from '@/Components/KioskFooter.vue';
import KioskHeader from '@/Components/KioskHeader.vue';
import KioskHoldCard from '@/Components/KioskHoldCard.vue';
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
        <KioskHeader :current="3" />
        <div class="mt-10 flex flex-col items-center text-center">
            <span class="grid size-20 place-items-center rounded-full bg-ok-50 text-ok"><UIcon name="i-solar-check-circle-bold" class="size-12" aria-hidden="true" /></span>
            <h1 class="mt-5 text-[40px] leading-[48px] font-semibold text-highlighted">Room {{ hold.room }} is held for you</h1>
            <p class="mt-2 text-2xl leading-9 text-muted">Show this at the front desk to pay and get your key.</p>
        </div>
        <div class="mt-8 rounded-card border border-default bg-default px-8 py-10">
            <KioskHoldCard :code="hold.code" :amount="hold.amount" :expires-at="hold.held ? hold.expires_at : null" :now="hold.now" />
        </div>
        <p class="mt-6 text-center text-lg leading-[26px] text-muted">If you do not pay in time the room is released and you can start again.</p>
        <KioskFooter>
            <UButton class="h-[72px] justify-center text-2xl" size="xl" block color="neutral" variant="outline" label="Done" data-test="done" @click="done" />
        </KioskFooter>
    </KioskLayout>
</template>
