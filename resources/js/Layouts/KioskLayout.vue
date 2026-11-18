<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import { useConnection } from '@/Composables/useConnection';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { useShared } from '@/Composables/useShared';
import ServerErrorDialog from '@/Components/ServerErrorDialog.vue';
import Offline from '@/Pages/Kiosk/Offline.vue';

/**
 * Lobby kiosk, 1080 × 1920 portrait (DESIGN_SYSTEM §6.8): no chrome, white page, 32 px padding,
 * light only. Every touch target is 56 px or taller; primary buttons 72 px. `bare` drops the
 * padding for the full-screen photo of the welcome screen (27).
 *
 * While the heartbeat fails the kiosk shows the offline screen (31b) instead of the page and
 * takes no input, because a hold it cannot save would double-book a room (APP_FLOW §6 case 44).
 *
 * Staff exit: there is no visible sign-out (a guest must not reach it). A press-and-hold of ~2 s
 * on the top-right corner — a quick tap does nothing — asks to confirm, then signs the device out.
 */
withDefaults(defineProps<{ title?: string; bare?: boolean }>(), { title: 'Kiosk', bare: false });

const { online } = useConnection();
const { props: shared } = useShared();
const confirm = useConfirmDialog();

onMounted(() => useConnection().start(shared.value.app?.heartbeat ?? 5));

const HOLD_MS = 2000;
let holdTimer: ReturnType<typeof setTimeout> | undefined;

function holdStart(): void {
    holdTimer = setTimeout(askSignOut, HOLD_MS);
}

function holdCancel(): void {
    if (holdTimer !== undefined) {
        clearTimeout(holdTimer);
        holdTimer = undefined;
    }
}

async function askSignOut(): Promise<void> {
    holdCancel();

    const ok = await confirm({
        title: 'Sign out this kiosk?',
        description: 'The screen returns to sign-in. A staff member signs in again to set the kiosk back up.',
        confirmLabel: 'Sign out',
        cancelLabel: 'Cancel',
        confirmColor: 'error',
    });

    if (ok) {
        router.post(route('logout'));
    }
}
</script>

<template>
    <Head :title="title" />
    <ServerErrorDialog />
    <div class="hm-light flex min-h-dvh justify-center bg-default text-lg leading-7 text-default select-none">
        <main class="flex min-h-dvh w-full max-w-[1080px] flex-col" :class="bare && online ? '' : 'p-8'">
            <Offline v-if="!online" />
            <slot v-else />
        </main>

        <!-- Hidden staff exit: press and hold the top-right corner for ~2 s, then confirm. Invisible
             and no cursor, so a guest never finds it; a quick tap does nothing. -->
        <button
            type="button"
            class="fixed top-0 right-0 z-50 size-16 cursor-default bg-transparent"
            aria-label="Staff: press and hold to sign the kiosk out"
            @pointerdown="holdStart"
            @pointerup="holdCancel"
            @pointerleave="holdCancel"
            @pointercancel="holdCancel"
            @contextmenu.prevent
        />
    </div>
</template>
