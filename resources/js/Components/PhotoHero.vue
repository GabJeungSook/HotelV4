<script setup lang="ts">
/**
 * The photo layer of sign-in (1), welcome (59) and kiosk welcome (27), DESIGN_SYSTEM §5.2.
 * Back to front: the fallback colour block, the photo (cover, decorative), the flat scrim, the
 * content. The photo drifts slowly; under reduced motion it stays still.
 */
withDefaults(
    defineProps<{
        photos: { src: string; srcset?: string }[];
        scrim?: 'default' | 'kiosk';
        drift?: boolean;
    }>(),
    { scrim: 'default', drift: true },
);
</script>

<template>
    <div class="hm-light relative isolate overflow-hidden bg-(--hm-photo-fallback)">
        <div class="absolute inset-0 -z-10 overflow-hidden" aria-hidden="true">
            <img
                v-for="(photo, i) in photos"
                :key="photo.src"
                :src="photo.src"
                :srcset="photo.srcset"
                sizes="(max-width: 900px) 100vw, 57vw"
                alt=""
                :fetchpriority="i === 0 ? 'high' : 'low'"
                :loading="i === 0 ? 'eager' : 'lazy'"
                class="absolute inset-0 size-full object-cover"
                :class="drift ? 'hm-drift' : ''"
            />
            <div class="absolute inset-0" :class="scrim === 'kiosk' ? 'bg-(--hm-scrim-kiosk)' : 'bg-(--hm-scrim)'" />
        </div>
        <slot />
    </div>
</template>
