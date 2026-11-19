<!-- Mockup 31b -->
<script setup lang="ts">
import EmptyState from '@/Components/EmptyState.vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import StatusLabel from '@/Components/StatusLabel.vue';

/**
 * The kiosk is offline (31b): shown by KioskLayout in place of any kiosk screen while the
 * heartbeat fails. It takes no input; when the server answers again the page reloads and the
 * kiosk is back on the welcome screen (APP_FLOW §6 case 44).
 */
</script>

<template>
    <HomiLogo variant="blue" :width="124" :height="38" />
    <div class="flex flex-1 flex-col items-center justify-center" role="alert" data-test="kiosk-offline">
        <EmptyState size="kiosk" tone="bad" icon="i-solar-wi-fi-router-bold-duotone" title="The kiosk is offline" text="Please check in at the front desk. We are sorry for the trouble." />
        <StatusLabel class="mt-6" size="lg" tone="warn" label="Trying to reconnect…" />
    </div>
</template>
