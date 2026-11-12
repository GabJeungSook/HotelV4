<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { useConnection } from '@/Composables/useConnection';
import { useShared } from '@/Composables/useShared';
import Offline from '@/Pages/Kiosk/Offline.vue';

/**
 * Lobby kiosk, 1080 × 1920 portrait (DESIGN_SYSTEM §6.8): no chrome, white page, 32 px padding,
 * light only. Every touch target is 56 px or taller; primary buttons 72 px. `bare` drops the
 * padding for the full-screen photo of the welcome screen (27).
 *
 * While the heartbeat fails the kiosk shows the offline screen (31b) instead of the page and
 * takes no input, because a hold it cannot save would double-book a room (APP_FLOW §6 case 44).
 */
withDefaults(defineProps<{ title?: string; bare?: boolean }>(), { title: 'Kiosk', bare: false });

const { online } = useConnection();
const { props: shared } = useShared();

onMounted(() => useConnection().start(shared.value.app?.heartbeat ?? 5));
</script>

<template>
    <Head :title="title" />
    <div class="hm-light flex min-h-dvh justify-center bg-default text-lg leading-7 text-default select-none">
        <main class="flex min-h-dvh w-full max-w-[1080px] flex-col" :class="bare && online ? '' : 'p-8'">
            <Offline v-if="!online" />
            <slot v-else />
        </main>
    </div>
</template>
