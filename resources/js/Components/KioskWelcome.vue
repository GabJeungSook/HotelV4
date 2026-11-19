<script setup lang="ts">
import HomiLogo from './HomiLogo.vue';
import PhotoHero from './PhotoHero.vue';

/**
 * The resting screen of the kiosk (27, DESIGN_SYSTEM §5.2): a full-screen lobby photograph under
 * the kiosk scrim, the white logo and a live availability chip at the top, then the welcome,
 * compact house rules and one big "Start booking" anchored low. Tapping anywhere starts. The
 * content rises in once each time the screen mounts after idle; all motion stops under reduced
 * motion.
 */
withDefaults(defineProps<{ hotel: string; rules: string[]; free: number; photos?: { src: string; srcset?: string }[] }>(), {
    photos: () => [
        { src: '/images/photos/lobby.jpg', srcset: '/images/photos/lobby-1200.jpg 1200w, /images/photos/lobby.jpg 2000w' },
        { src: '/images/photos/room-deluxe.jpg', srcset: '' },
    ],
});

defineEmits<{ start: [] }>();
</script>

<template>
    <PhotoHero
        :photos="photos"
        :slides="photos.length > 1"
        scrim="kiosk"
        class="flex min-h-dvh w-full cursor-pointer flex-col"
        role="button"
        tabindex="0"
        aria-label="Tap to start booking"
        @click="$emit('start')"
        @keydown.enter="$emit('start')"
    >
        <div class="flex min-h-dvh flex-col p-14 text-on-primary">
            <div class="hm-up flex items-start justify-between gap-4">
                <HomiLogo variant="white" :width="208" :height="63" />
                <span class="inline-flex items-center gap-3 rounded-full border border-(--hm-on-photo-pill-line) bg-(--hm-on-photo-pill) px-5 py-2.5 text-xl backdrop-blur-sm" data-test="free-rooms">
                    <i class="hm-pulse" aria-hidden="true" />
                    {{ free === 1 ? '1 room available now' : `${free} rooms available now` }}
                </span>
            </div>

            <div class="flex-1" />

            <h1 class="hm-up text-[76px] leading-[1.04] font-semibold tracking-[-0.035em] [animation-delay:0.1s]">
                Welcome to<br />
                {{ hotel }}
            </h1>
            <p class="hm-up mt-5 max-w-[760px] text-[26px] leading-9 text-(--hm-on-photo-body) [animation-delay:0.22s]">
                Book your room in under a minute — choose a room here, then pay at the front desk.
            </p>

            <section v-if="rules.length" class="hm-up mt-9 rounded-card bg-default px-8 py-6 text-default [animation-delay:0.34s]" aria-label="House rules">
                <h2 class="text-sm font-semibold tracking-[0.08em] text-muted uppercase">House rules</h2>
                <ol class="mt-3 grid list-decimal gap-x-10 gap-y-1.5 ps-6 text-xl leading-8 sm:grid-cols-2">
                    <li v-for="rule in rules" :key="rule">{{ rule }}</li>
                </ol>
            </section>

            <button
                type="button"
                class="hm-up hm-focus mt-10 flex h-[88px] w-full items-center justify-center gap-3 rounded-card bg-primary text-[30px] font-semibold text-on-primary transition-colors hover:bg-primary/90 [animation-delay:0.46s]"
                data-test="tap-to-start"
                @click.stop="$emit('start')"
            >
                Start booking
                <UIcon name="i-solar-alt-arrow-right-linear" class="size-8" aria-hidden="true" />
            </button>
            <p class="hm-up mt-4 text-center text-xl text-(--hm-on-photo-soft) [animation-delay:0.46s]">or tap anywhere to begin</p>
        </div>
    </PhotoHero>
</template>
