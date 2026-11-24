<!-- Mockup 27 · 31 (no rooms) · 31b (offline, in KioskLayout) -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import KioskWelcome from '@/Components/KioskWelcome.vue';
import { usePolling } from '@/Composables/usePolling';
import KioskLayout from '@/Layouts/KioskLayout.vue';
import NoRooms from './NoRooms.vue';

/**
 * The kiosk's resting screen (27): reloads the free-room count every 20 s. With no room free in
 * any type it shows "All rooms are taken" (31) and returns to 27 by itself when one opens up. A
 * failed reload makes the layout show the offline screen (31b).
 */
const props = defineProps<{ hotel: string; rules: string[]; free: number; poll: number }>();

const { refresh } = usePolling(['free', 'rules'], props.poll);

function start(): void {
    router.visit(route('kiosk.start'));
}
</script>

<template>
    <KioskLayout title="Welcome">
        <KioskWelcome v-if="free > 0" :hotel="hotel" :rules="rules" :free="free" @start="start" />
        <NoRooms v-else @check="refresh" />
    </KioskLayout>
</template>
