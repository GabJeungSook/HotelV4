<script setup lang="ts">
import HomiLogo from './HomiLogo.vue';
import PhotoHero from './PhotoHero.vue';

/**
 * The resting screen of the kiosk (27, DESIGN_SYSTEM §5.2): the lobby photograph full screen
 * under the kiosk scrim with a slow drift, the white logo, the serif welcome, one sentence, the
 * house rules in a white panel, and one white "Tap to start" with the free-room line under it.
 * Tapping anywhere starts. The fade-up runs again each time the screen is mounted after idle.
 */
withDefaults(defineProps<{ hotel: string; rules: string[]; free: number; photo?: { src: string; srcset?: string } }>(), {
    photo: () => ({ src: '/images/photos/lobby.jpg', srcset: '/images/photos/lobby-1200.jpg 1200w, /images/photos/lobby.jpg 2000w' }),
});

defineEmits<{ start: [] }>();
</script>

<template>
    <PhotoHero :photos="[photo]" scrim="kiosk" class="flex min-h-dvh w-full cursor-pointer flex-col" role="button" tabindex="0" aria-label="Tap to start" @click="$emit('start')" @keydown.enter="$emit('start')">
        <div class="flex min-h-dvh flex-col p-16 text-on-primary">
            <HomiLogo variant="white" :width="300" :height="91" class="hm-up" />
            <h1 class="hm-up mt-16 font-serif text-[80px] leading-[84px] font-normal [animation-delay:0.12s]">
                Welcome to<br />
                {{ hotel }}
            </h1>
            <p class="hm-up mt-6 text-[26px] leading-9 text-(--hm-on-photo-body) [animation-delay:0.26s]">Choose your room here, then pay at the front desk.</p>
            <section v-if="rules.length" class="hm-up mt-12 rounded-card bg-default px-8 py-7 text-default [animation-delay:0.4s]" aria-label="House rules">
                <h2 class="text-[22px] leading-[30px] font-semibold text-highlighted">House rules</h2>
                <ol class="mt-3 list-decimal ps-7 text-xl leading-[30px]">
                    <li v-for="rule in rules" :key="rule">{{ rule }}</li>
                </ol>
            </section>
            <div class="flex-1" />
            <UButton
                class="hm-up h-[72px] justify-center bg-default text-[28px] text-highlighted hover:bg-default/90 [animation-delay:0.54s]"
                size="xl"
                block
                label="Tap to start"
                data-test="tap-to-start"
                @click.stop="$emit('start')"
            />
            <p class="hm-up mt-4 text-center text-xl leading-7 text-(--hm-on-photo-soft) [animation-delay:0.54s]" data-test="free-rooms">
                {{ free === 1 ? '1 room available now' : `${free} rooms available now` }}
            </p>
        </div>
    </PhotoHero>
</template>
