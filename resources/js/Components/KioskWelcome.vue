<script setup lang="ts">
import HomiLogo from './HomiLogo.vue';

/**
 * The resting screen of the kiosk (27, DESIGN_SYSTEM §5.2): a bright, light screen in the system's
 * blue scheme — the blue Homi mark and a live availability pill at the top, a framed lobby photo,
 * the welcome, compact house rules and one big "Start booking". Tapping anywhere starts. The
 * content rises in once each time the screen mounts after idle; all motion stops under reduced
 * motion.
 */
withDefaults(defineProps<{ hotel: string; rules: string[]; free: number; checkout?: boolean; photos?: { src: string; srcset?: string }[] }>(), {
    checkout: false,
    photos: () => [{ src: '/images/photos/lobby.jpg', srcset: '/images/photos/lobby-1200.jpg 1200w, /images/photos/lobby.jpg 2000w' }],
});

defineEmits<{ start: []; checkout: [] }>();
</script>

<template>
    <div
        class="relative flex min-h-dvh cursor-pointer flex-col"
        role="button"
        tabindex="0"
        aria-label="Tap to start booking"
        @click="$emit('start')"
        @keydown.enter="$emit('start')"
    >
        <!-- Soft brand-blue glow so the white screen still reads as part of the system. -->
        <div class="pointer-events-none absolute inset-x-0 top-0 -z-10 h-[620px] bg-gradient-to-b from-primary-50 to-transparent" aria-hidden="true" />

        <div class="hm-up flex items-center justify-between gap-4">
            <HomiLogo variant="blue" :width="188" :height="57" />
            <span class="inline-flex items-center gap-3 rounded-full bg-primary-50 px-6 py-3 text-xl font-medium text-primary ring-1 ring-primary/15" data-test="free-rooms">
                <i class="hm-pulse" aria-hidden="true" />
                {{ free === 1 ? '1 room available now' : `${free} rooms available now` }}
            </span>
        </div>

        <figure class="hm-up mt-8 min-h-[300px] flex-1 overflow-hidden rounded-[28px] ring-1 ring-black/5 [animation-delay:0.1s]">
            <img :src="photos[0].src" :srcset="photos[0].srcset" sizes="(max-width: 1080px) 100vw, 1080px" alt="" class="size-full object-cover" />
        </figure>

        <h1 class="hm-up mt-9 text-[68px] leading-[1.05] font-semibold tracking-[-0.03em] text-highlighted [animation-delay:0.2s]">
            Welcome to<br />
            <span class="text-primary">{{ hotel }}</span>
        </h1>
        <p class="hm-up mt-4 max-w-[760px] text-[26px] leading-9 text-muted [animation-delay:0.3s]">
            Book your room in under a minute — choose a room here, then pay at the front desk.
        </p>

        <section v-if="rules.length" class="hm-up mt-8 rounded-card border border-default bg-elevated px-8 py-6 [animation-delay:0.4s]" aria-label="House rules">
            <h2 class="text-sm font-semibold tracking-[0.08em] text-muted uppercase">House rules</h2>
            <ol class="mt-3 grid list-decimal gap-x-10 gap-y-1.5 ps-6 text-xl leading-8 text-default sm:grid-cols-2">
                <li v-for="rule in rules" :key="rule">{{ rule }}</li>
            </ol>
        </section>

        <button
            type="button"
            class="hm-up hm-focus mt-8 flex h-[88px] w-full items-center justify-center gap-3 rounded-card bg-primary text-[30px] font-semibold text-on-primary shadow-[0_10px_30px_-10px_var(--hm-primary-shadow,rgba(0,158,245,0.5))] transition-colors hover:bg-primary/90 [animation-delay:0.5s]"
            data-test="tap-to-start"
            @click.stop="$emit('start')"
        >
            Start booking
            <UIcon name="i-solar-alt-arrow-right-linear" class="size-8" aria-hidden="true" />
        </button>
        <button
            v-if="checkout"
            type="button"
            class="hm-up hm-focus mt-4 flex h-[72px] w-full items-center justify-center gap-3 rounded-card border border-default bg-default text-[24px] font-medium text-default transition-colors hover:bg-elevated [animation-delay:0.55s]"
            data-test="kiosk-checkout"
            @click.stop="$emit('checkout')"
        >
            <UIcon name="i-solar-logout-3-linear" class="size-7" aria-hidden="true" />
            Already staying? Check out
        </button>
        <p class="hm-up mt-4 text-center text-xl text-dimmed [animation-delay:0.6s]">or tap anywhere to begin</p>
    </div>
</template>
