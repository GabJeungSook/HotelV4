<script setup lang="ts">
import { useConnection } from '@/Composables/useConnection';

/** Connection lost (50, DESIGN_SYSTEM §5.2): no props, reads useConnection(). Mounted once per layout. */
const { online, retryIn } = useConnection();
</script>

<template>
    <div
        v-if="!online"
        data-shell
        role="alert"
        class="sticky top-0 z-50 flex items-center gap-2 bg-bad px-4 py-2 text-[13px] leading-5 font-semibold text-on-primary"
    >
        <UIcon name="i-lucide-wifi-off" class="size-4 shrink-0" aria-hidden="true" />
        <span>No connection to the server. Do not take payments until this bar disappears. Trying again in {{ retryIn }} s…</span>
    </div>
</template>
