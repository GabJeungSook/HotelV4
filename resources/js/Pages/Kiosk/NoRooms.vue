<!-- Mockup 31 -->
<script setup lang="ts">
import EmptyState from '@/Components/EmptyState.vue';

/**
 * No rooms (31): shown on /kiosk while no room is free in any type. The welcome page checks again
 * every 20 s and shows the welcome screen (27) as soon as a room opens up; "Check again" asks now.
 */
defineEmits<{ check: [] }>();
</script>

<template>
    <div class="flex flex-1 flex-col items-center justify-center" data-test="kiosk-no-rooms">
        <EmptyState
            size="kiosk"
            icon="i-lucide-bed"
            title="All rooms are taken right now"
            text="Rooms open up as guests leave and cleaning finishes. Please ask the front desk for the next available room."
            action-label="Check again"
            @action="$emit('check')"
        />
    </div>
</template>
