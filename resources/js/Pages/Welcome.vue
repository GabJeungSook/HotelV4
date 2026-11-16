<!-- Mockup 59 -->
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useIntervalFn, usePreferredReducedMotion } from '@vueuse/core';
import { computed, ref } from 'vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import PhotoHero from '@/Components/PhotoHero.vue';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';

/**
 * The public front page (59, DESIGN_SYSTEM §6.7): the hero with three cross-fading photos,
 * the room types with their lowest hourly and overnight prices read live from the rate table,
 * how a stay works, and the footer with the photo credits. No availability and no booking.
 * Light only.
 */
interface RoomTypeCard {
    id: number;
    name: string;
    line: string | null;
    photo: string | null;
    price: { amount: number; hours: number } | null;
    overnight: number | null;
}

const props = defineProps<{
    headline: string;
    subline: string;
    hero: { src: string; srcset: string }[];
    branches: { id: number; name: string }[];
    branch: { id: number; name: string; address: string; place: string; deposit: { name: string; amount: number } | null } | null;
    types: RoomTypeCard[];
    credits: { file: string; by: string; url: string }[];
    year: number;
}>();

const { props: shared } = useShared();
const signedIn = computed(() => shared.value.auth !== null);
const homeUrl = computed(() => shared.value.home ?? route('home'));

const eyebrow = computed(() => (props.branch ? `${props.branch.place} · Open 24 hours` : 'Open 24 hours'));

const WORDS = ['No', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten'];
const heading = computed(() => {
    const n = props.types.length;
    const word = WORDS[n];

    return word ? `${word} ${n === 1 ? 'way' : 'ways'} to stay` : 'Ways to stay';
});

const depositLine = computed(() => {
    const d = props.branch?.deposit;

    return d ? `A ${formatMoney(d.amount, 'tile')} ${d.name.toLowerCase()} deposit is added at check-in and returned when you leave.` : null;
});

const steps = [
    { n: '01', title: 'Choose', text: 'Pick a room type and how many hours, at the kiosk or the desk.' },
    { n: '02', title: 'Pay at the desk', text: 'Cash or GCash. Your slip carries your stay code.' },
    { n: '03', title: 'Extend or leave', text: 'Add hours any time. Return the key and remote to get your deposit back.' },
];

// The slide indicators follow the photo that is showing; under reduced motion the first stays.
const slide = ref(0);
const motion = usePreferredReducedMotion();
useIntervalFn(() => {
    if (motion.value !== 'reduce') {
        slide.value = (slide.value + 1) % props.hero.length;
    }
}, 7000);

const branchItems = computed(() => props.branches.map((b) => ({ label: b.name, value: b.id })));
function pickBranch(id: number): void {
    router.get(route('welcome'), { branch: id }, { preserveScroll: true, preserveState: false });
}

function hoursLabel(h: number): string {
    return h === 1 ? '1 hour' : `${h} hours`;
}
</script>

<template>
    <Head :title="branch?.name ?? 'Welcome'" />
    <div class="hm-light min-h-dvh bg-default text-default">
        <PhotoHero :photos="hero" slides sizes="100vw" class="flex h-[min(100svh,760px)] min-h-[560px] flex-col">
            <nav class="flex items-center gap-6 px-12 pt-6 text-on-primary max-[600px]:px-6" aria-label="Welcome">
                <HomiLogo variant="white" :width="120" :height="36" />
                <span class="ms-auto flex gap-6 text-[15px] leading-5 opacity-90 max-[900px]:hidden">
                    <a href="#rooms" class="hm-focus rounded-sm hover:underline">Rooms</a>
                    <a href="#how" class="hm-focus rounded-sm hover:underline">How a stay works</a>
                    <a href="#find-us" class="hm-focus rounded-sm hover:underline">Find us</a>
                </span>
                <UButton
                    v-if="signedIn"
                    :as="Link"
                    :href="homeUrl"
                    label="Dashboard"
                    variant="outline"
                    class="on-photo-outline max-[900px]:ms-auto"
                    data-test="welcome-dashboard"
                />
                <UButton v-else :as="Link" :href="route('login')" label="Staff sign in" variant="outline" class="on-photo-outline max-[900px]:ms-auto" data-test="welcome-sign-in" />
            </nav>

            <div class="max-w-[640px] px-12 pt-[12vh] text-on-primary max-[600px]:px-6">
                <p class="hm-up text-xs leading-4 font-medium tracking-[0.12em] text-(--hm-on-photo-soft) uppercase">{{ eyebrow }}</p>
                <h1 class="hm-up mt-3 font-serif text-[60px] leading-[62px] font-normal tracking-[-0.01em] [animation-delay:0.12s] max-[600px]:text-[40px] max-[600px]:leading-[44px]">
                    {{ headline }}
                </h1>
                <p class="hm-up mt-4 max-w-[520px] text-[17px] leading-[26px] text-(--hm-on-photo-body) [animation-delay:0.26s]">{{ subline }}</p>
                <USelect
                    v-if="branches.length > 1 && branch"
                    :model-value="branch.id"
                    :items="branchItems"
                    aria-label="Branch"
                    class="hm-up mt-5 w-64 [animation-delay:0.26s]"
                    data-test="welcome-branch"
                    @update:model-value="pickBranch($event as number)"
                />
                <div class="hm-up mt-6 flex flex-wrap gap-3 [animation-delay:0.4s]">
                    <UButton href="#rooms" size="lg" label="See rooms and rates" class="on-photo" />
                    <UButton href="#how" size="lg" variant="outline" label="How a stay works" class="on-photo-outline" />
                </div>
            </div>

            <div class="mt-auto flex gap-1.5 px-12 pb-6 max-[600px]:px-6" aria-hidden="true">
                <i v-for="(p, i) in hero" :key="p.src" class="h-0.5 w-[18px]" :class="i === slide ? 'bg-(--hm-card)' : 'bg-(--hm-slide-off)'" />
            </div>
        </PhotoHero>

        <section id="rooms" class="mx-auto max-w-[1100px] scroll-mt-4 px-6 py-16" aria-labelledby="rooms-heading">
            <div class="flex items-end justify-between gap-6 max-[600px]:flex-col max-[600px]:items-start">
                <div>
                    <p class="text-xs leading-4 font-medium tracking-[0.12em] text-muted uppercase">Rooms</p>
                    <h2 id="rooms-heading" class="mt-1 font-serif text-[32px] leading-[38px] font-normal text-highlighted">{{ heading }}</h2>
                </div>
                <p v-if="depositLine" class="max-w-[320px] text-right text-sm text-muted max-[600px]:text-left" data-test="welcome-deposit">{{ depositLine }}</p>
            </div>

            <div v-if="types.length" class="mt-8 grid grid-cols-3 gap-6 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1">
                <article
                    v-for="(t, i) in types"
                    :key="t.id"
                    class="hm-up overflow-hidden rounded-[10px] border border-default bg-default"
                    :style="{ animationDelay: `${0.54 + i * 0.12}s` }"
                    data-test="welcome-type"
                >
                    <img v-if="t.photo" :src="t.photo" alt="" loading="lazy" class="aspect-[4/3] w-full object-cover" />
                    <div v-else class="aspect-[4/3] w-full bg-(--hm-photo-fallback)" aria-hidden="true" />
                    <div class="px-[18px] py-4">
                        <h3 class="text-[17px] leading-6 font-semibold text-highlighted">{{ t.name }}</h3>
                        <p v-if="t.line" class="mt-0.5 text-sm text-muted">{{ t.line }}</p>
                        <div class="mt-3 flex items-baseline justify-between gap-3">
                            <span v-if="t.price">
                                <span class="text-[22px] leading-7 font-medium text-highlighted tabular">{{ formatMoney(t.price.amount, 'tile') }}</span>
                                <span class="ms-1 text-[13px] text-muted">{{ hoursLabel(t.price.hours) }}</span>
                            </span>
                            <span v-else-if="t.overnight !== null">
                                <span class="text-[22px] leading-7 font-medium text-highlighted tabular">{{ formatMoney(t.overnight, 'tile') }}</span>
                                <span class="ms-1 text-[13px] text-muted">overnight</span>
                            </span>
                            <span v-if="t.price && t.overnight !== null" class="text-[13px] text-muted tabular">Overnight {{ formatMoney(t.overnight, 'tile') }}</span>
                        </div>
                    </div>
                </article>
            </div>
            <p v-else class="mt-8 text-muted" data-test="welcome-no-types">Rates will be listed here soon. Please ask at the front desk.</p>
        </section>

        <section id="how" class="scroll-mt-4 border-y border-default bg-muted" aria-labelledby="how-heading">
            <div class="mx-auto max-w-[1100px] px-6 py-16">
                <p id="how-heading" class="text-xs leading-4 font-medium tracking-[0.12em] text-muted uppercase">How a stay works</p>
                <ol class="mt-6 grid grid-cols-3 gap-8 max-[600px]:grid-cols-1">
                    <li v-for="s in steps" :key="s.n">
                        <span class="font-serif text-[28px] leading-8 text-muted">{{ s.n }}</span>
                        <h3 class="mt-1 text-[17px] leading-6 font-semibold text-highlighted">{{ s.title }}</h3>
                        <p class="mt-0.5 text-[15px] leading-[22px] text-muted">{{ s.text }}</p>
                    </li>
                </ol>
            </div>
        </section>

        <footer id="find-us" class="mx-auto flex max-w-[1100px] flex-wrap items-center gap-x-6 gap-y-2 p-6 text-[13px] leading-[18px] text-muted">
            <HomiLogo :width="56" :height="17" />
            <span>{{ branch ? `${branch.address} · Open 24 hours` : eyebrow }}</span>
            <UPopover v-if="credits.length">
                <UButton variant="link" color="neutral" size="xs" label="Photos: Unsplash" class="p-0 text-[13px] text-muted" />
                <template #content>
                    <ul class="max-w-72 space-y-1 p-3 text-[13px]" data-test="welcome-credits">
                        <li v-for="c in credits" :key="c.url">
                            <a :href="c.url" target="_blank" rel="noopener" class="hm-focus text-primary hover:underline">Photo by {{ c.by }} on Unsplash</a>
                        </li>
                    </ul>
                </template>
            </UPopover>
            <span class="ms-auto">© {{ year }} {{ branch?.name ?? shared.app.name }}</span>
        </footer>
    </div>
</template>
