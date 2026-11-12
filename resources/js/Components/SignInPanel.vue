<script setup lang="ts">
import HomiLogo from './HomiLogo.vue';

/**
 * The right half of the sign-in (1), DESIGN_SYSTEM §5.2: a plain white panel, deliberately not
 * a card. Logo top left, the form block centred vertically, the footer line pinned below.
 */
withDefaults(defineProps<{ heading?: string; line?: string }>(), { heading: 'Sign in', line: 'Use your own account. Never share it.' });
</script>

<template>
    <section class="hm-light flex min-h-full flex-col bg-default px-14 py-12 max-[900px]:px-6 max-[900px]:py-8">
        <HomiLogo variant="blue" class="hm-up" />
        <div class="hm-up my-auto w-full max-w-[360px] py-8 [animation-delay:0.12s]">
            <h2 class="font-serif text-[34px] leading-10 font-normal text-highlighted">{{ heading }}</h2>
            <p class="mt-1 mb-6 text-sm text-muted">{{ line }}</p>
            <slot />
        </div>
        <p class="hm-up text-[13px] text-muted [animation-delay:0.26s]"><slot name="footer" /></p>
    </section>
</template>
