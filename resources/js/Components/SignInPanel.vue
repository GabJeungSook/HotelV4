<script setup lang="ts">
import HomiLogo from './HomiLogo.vue';

/**
 * The right half of the sign-in (1, mockup v6 `.sg6-f`) and of the password and PIN pages: a plain
 * white panel, deliberately not a card. Logo top left, the form block centred vertically (at most
 * 360 px), the footer row pinned below. Each part rises in once, 80 ms apart; nothing moves under
 * reduced motion.
 */
withDefaults(defineProps<{ heading?: string; line?: string }>(), { heading: 'Sign in to Homi', line: 'Use your own account. Never share it with anyone.' });
</script>

<template>
    <section class="hm-light flex min-h-full flex-col bg-default px-14 pt-[34px] pb-[26px] max-[900px]:px-6 max-[900px]:py-8">
        <HomiLogo variant="blue" :width="86" :height="26" class="hm-rise" />
        <div class="my-auto w-full max-w-[360px] py-8">
            <h1 class="hm-rise text-[28px] leading-[1.15] font-semibold tracking-[-0.025em] text-highlighted" style="--hm-step: 1">{{ heading }}</h1>
            <p class="hm-rise mt-1.5 mb-[26px] text-[13.5px] text-muted" style="--hm-step: 2">{{ line }}</p>
            <slot />
        </div>
        <div class="hm-rise flex flex-wrap items-center justify-between gap-3 text-xs text-faint" style="--hm-step: 6"><slot name="footer" /></div>
    </section>
</template>
