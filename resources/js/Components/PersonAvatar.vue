<script setup lang="ts">
import { computed } from 'vue';
import { avatarColour, initials } from '@/Composables/usePerson';

/** A round initials avatar in one of seven soft colours picked from the name (DESIGN_SYSTEM §0). */
const props = withDefaults(defineProps<{ name: string; size?: number; ring?: boolean }>(), { size: 24, ring: false });

const c = computed(() => avatarColour(props.name));
const style = computed(() => ({
    width: `${props.size}px`,
    height: `${props.size}px`,
    fontSize: `${Math.max(9, Math.round(props.size * 0.38 * 10) / 10)}px`,
    background: `var(--hm-av-${c.value})`,
    color: `var(--hm-av-${c.value}-ink)`,
}));
</script>

<template>
    <i
        class="inline-grid shrink-0 place-items-center rounded-full leading-none font-semibold tracking-[0.02em] not-italic select-none"
        :class="ring ? 'shadow-[0_0_0_2px_var(--hm-card)]' : ''"
        :style="style"
        :title="name"
        aria-hidden="true"
        data-avatar
        >{{ initials(name) }}</i
    >
</template>
