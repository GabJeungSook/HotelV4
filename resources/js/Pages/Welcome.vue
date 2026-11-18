<!-- Mockup 59 — public front page. A professional, multi-section marketing layout: sticky nav with
     the 3D Homi mark, a two-column hero with a floating info card, the rooms as service cards, a
     "why stay" band with live facts, a 3-step process, a dark amenities strip, an FAQ accordion, a
     call-to-action band and a footer. Data (branch, room types, live prices) is unchanged; light
     only; every motion stops under reduced motion. -->
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';

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
    branch: { id: number; name: string; address: string; place: string; deposit: { name: string; amount: number } | null; methods?: string[] } | null;
    types: RoomTypeCard[];
    credits: { file: string; by: string; url: string }[];
    year: number;
}>();

const { props: shared } = useShared();
const signedIn = computed(() => shared.value.auth !== null);
const homeUrl = computed(() => shared.value.home ?? route('home'));

const eyebrow = computed(() => (props.branch ? `${props.branch.place} · Open 24 hours` : 'Open 24 hours'));
const words = computed(() => props.headline.split(/\s+/).filter(Boolean));

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

const METHOD: Record<string, string> = { cash: 'Cash', gcash: 'GCash', card: 'Card' };
const payWords = computed(() => {
    const list = (props.branch?.methods ?? ['cash']).map((m) => METHOD[m]).filter(Boolean);

    return list.length <= 1 ? (list[0] ?? 'Cash') : `${list.slice(0, -1).join(', ')} or ${list[list.length - 1]}`;
});

const lowest = computed(() => {
    const priced = props.types.filter((t) => t.price !== null) as (RoomTypeCard & { price: { amount: number; hours: number } })[];

    return priced.length ? priced.reduce((a, b) => (b.price.amount < a.price.amount ? b : a)) : null;
});

function hoursLabel(h: number): string {
    return h === 1 ? '1 hour' : `${h} hours`;
}

const facts = computed(() => [
    { icon: 'i-solar-clock-circle-bold-duotone', title: 'Open 24 hours', text: 'Walk in any time' },
    ...(lowest.value ? [{ icon: 'i-solar-bed-bold-duotone', title: `From ${formatMoney(lowest.value.price.amount, 'tile')}`, text: `${hoursLabel(lowest.value.price.hours)}, ${lowest.value.name} room` }] : []),
    { icon: 'i-solar-banknote-2-bold-duotone', title: payWords.value, text: 'Pay at the front desk' },
    ...(props.branch?.deposit ? [{ icon: 'i-solar-key-bold-duotone', title: `${formatMoney(props.branch.deposit.amount, 'tile')} ${props.branch.deposit.name.toLowerCase()} deposit`, text: 'Returned when you leave' }] : []),
]);

function tags(line: string | null): string[] {
    return (line ?? '')
        .replace(/\.$/, '')
        .split(/,\s*|\s+and\s+/)
        .map((t) => t.trim())
        .filter(Boolean)
        .slice(0, 3)
        .map((t) => t.charAt(0).toUpperCase() + t.slice(1));
}

const steps = [
    { n: '01', icon: 'i-solar-bed-bold-duotone', title: 'Choose your room', text: 'Pick a room type and how many hours — at the lobby kiosk or the front desk.' },
    { n: '02', icon: 'i-solar-banknote-2-bold-duotone', title: 'Pay at the desk', text: 'Cash or GCash. Your slip carries your stay code — no account, no booking.' },
    { n: '03', icon: 'i-solar-key-bold-duotone', title: 'Extend or check out', text: 'Add hours any time. Return the key and remote to get your deposit back.' },
];

const amenities = computed(() => [
    { icon: 'i-solar-snowflake-bold-duotone', title: 'Air-conditioned', text: 'Every room, always cool.' },
    { icon: 'i-solar-bath-bold-duotone', title: 'Private bathroom', text: 'Hot and cold water.' },
    { icon: 'i-solar-hanger-2-bold-duotone', title: 'Fresh linen', text: 'Changed for every guest.' },
    { icon: 'i-solar-clock-circle-bold-duotone', title: 'Open 24/7', text: 'The desk is always staffed.' },
    { icon: 'i-solar-banknote-2-bold-duotone', title: `${payWords.value} accepted`, text: 'Pay at the front desk.' },
    { icon: 'i-solar-shield-check-bold-duotone', title: 'Private & secure', text: 'Discreet and well-kept.' },
]);

const points = computed(() => [
    'No booking needed — walk in any time.',
    `Pay by ${payWords.value} at the front desk.`,
    'Rooms by the hour or overnight.',
    ...(props.branch?.deposit ? ['Your key deposit is returned at checkout.'] : ['A private bathroom and fresh linen in every room.']),
]);

const faqs = computed(() => [
    { label: 'Do I need a reservation?', content: 'No. Walk in any time — the front desk is open 24 hours. Just pick a room and how long you’ll stay.' },
    { label: 'How do I pay?', content: `${payWords.value} at the front desk. There is no online payment.` },
    ...(props.branch?.deposit && depositLine.value ? [{ label: 'Is there a deposit?', content: depositLine.value }] : []),
    { label: 'Can I extend my stay?', content: 'Yes — add hours any time at the desk. Just let the front desk know before your time is up.' },
    { label: 'What’s in every room?', content: 'Air-conditioning, a private bathroom with hot water, and fresh linen.' },
]);

const logo3d = '/images/landing/homi-19.jpg';

const nav = [
    { label: 'Rooms', href: '#rooms' },
    { label: 'How it works', href: '#how' },
    { label: 'Why us', href: '#why' },
    { label: 'FAQ', href: '#faq' },
    { label: 'Find us', href: '#find-us' },
];

const branchItems = computed(() => props.branches.map((b) => ({ label: b.name, value: b.id })));
function pickBranch(id: number): void {
    router.get(route('welcome'), { branch: id }, { preserveScroll: true, preserveState: false });
}

const aboutPhoto = computed(() => props.types.find((t) => t.photo)?.photo ?? props.hero[0]?.src ?? null);

const scrolled = ref(false);
const reduced = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
let io: IntersectionObserver | null = null;

function onScroll(): void {
    scrolled.value = window.scrollY > 8;
}

// Smooth-scroll in-page anchors (nav, hero, footer); the window is the scroller, so do it in JS.
function onAnchorClick(e: MouseEvent): void {
    const link = (e.target as HTMLElement)?.closest?.('a[href^="#"]') as HTMLAnchorElement | null;
    if (!link) return;
    const id = link.getAttribute('href')?.slice(1) ?? '';
    const target = id ? document.getElementById(id) : null;
    if (!target) return;
    e.preventDefault();
    const top = id === 'top' ? 0 : target.getBoundingClientRect().top + window.scrollY - 84;
    window.scrollTo({ top: Math.max(0, top), behavior: reduced ? 'auto' : 'smooth' });
    history.replaceState(null, '', `#${id}`);
}

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('click', onAnchorClick);

    if (!reduced) {
        io = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('in');
                        io?.unobserve(entry.target);
                    }
                }
            },
            { rootMargin: '0px 0px -10% 0px', threshold: 0.12 },
        );
        document.querySelectorAll('[data-reveal]').forEach((el) => io?.observe(el));
    } else {
        document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('in'));
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);
    document.removeEventListener('click', onAnchorClick);
    io?.disconnect();
});
</script>

<template>
    <Head :title="branch?.name ?? 'Welcome'" />
    <div id="top" class="hm-light min-h-dvh scroll-smooth bg-default font-sans text-default">
        <!-- Sticky nav with the 3D Homi mark -->
        <header
            class="sticky top-0 z-50 border-b transition-[background-color,box-shadow,border-color] duration-300"
            :class="scrolled ? 'border-default bg-default/90 shadow-xs backdrop-blur-md' : 'border-transparent bg-default'"
        >
            <div class="mx-auto flex h-[72px] max-w-[1200px] items-center gap-6 px-6 max-[600px]:h-16 max-[600px]:px-4">
                <a href="#top" class="hm-focus shrink-0 rounded" aria-label="Home">
                    <span class="block h-11 w-[116px] overflow-hidden max-[600px]:h-9 max-[600px]:w-[96px]">
                        <img :src="logo3d" alt="Homi" class="size-full object-cover" style="object-position: center 50%" />
                    </span>
                </a>
                <nav class="ms-auto hidden items-center gap-0.5 md:flex" aria-label="Main">
                    <a v-for="n in nav" :key="n.href" :href="n.href" class="nav-link hm-focus relative rounded-lg px-3 py-2 text-[14px] font-medium text-default transition-colors hover:text-primary">{{ n.label }}</a>
                </nav>
                <UButton
                    v-if="signedIn"
                    :as="Link"
                    :href="homeUrl"
                    label="Dashboard"
                    icon="i-solar-widget-5-bold-duotone"
                    size="lg"
                    class="ms-auto rounded-full md:ms-2"
                    data-test="welcome-dashboard"
                />
                <UButton
                    v-else
                    :as="Link"
                    :href="route('login')"
                    label="Staff sign in"
                    icon="i-solar-user-bold-duotone"
                    size="lg"
                    class="ms-auto rounded-full md:ms-2"
                    data-test="welcome-sign-in"
                />
            </div>
        </header>

        <!-- Hero -->
        <section class="relative overflow-hidden bg-gradient-to-b from-homi-50 to-default">
            <span aria-hidden="true" class="pointer-events-none absolute -top-24 -right-24 size-[460px] rounded-full bg-primary/10 blur-3xl" />
            <div class="mx-auto grid max-w-[1200px] items-center gap-12 px-6 py-16 max-[600px]:px-4 max-[600px]:py-10 lg:grid-cols-[1.05fr_1fr] lg:py-24">
                <div class="max-w-[560px]">
                    <span class="hm-rise inline-flex items-center gap-2 rounded-full border border-primary-line bg-default px-3.5 py-1.5 text-[12.5px] font-semibold text-primary-ink shadow-xs">
                        <span class="size-1.5 rounded-full bg-primary" aria-hidden="true" />{{ eyebrow }}
                    </span>
                    <h1 class="mt-5 text-[clamp(2.3rem,5.2vw,3.7rem)] leading-[1.04] font-semibold tracking-[-0.035em] text-highlighted" :aria-label="headline">
                        <template v-for="(w, i) in words" :key="`${w}-${i}`"
                            ><span class="hm-word" :class="i === words.length - 1 ? 'text-primary' : ''" :style="{ '--hm-step': i }" aria-hidden="true">{{ w }}</span>{{ i < words.length - 1 ? ' ' : '' }}</template
                        >
                    </h1>
                    <p class="hm-rise mt-5 max-w-[480px] text-[1.05rem] leading-[1.6] text-muted" style="--hm-step: 5">{{ subline }}</p>

                    <USelect
                        v-if="branches.length > 1 && branch"
                        :model-value="branch.id"
                        :items="branchItems"
                        aria-label="Branch"
                        size="lg"
                        class="hm-rise mt-6 w-72"
                        style="--hm-step: 5"
                        data-test="welcome-branch"
                        @update:model-value="pickBranch($event as number)"
                    />

                    <div class="hm-rise mt-7 flex flex-wrap items-center gap-3" style="--hm-step: 6">
                        <UButton href="#rooms" size="xl" label="See rooms & rates" trailing-icon="i-solar-alt-arrow-right-linear" class="rounded-full px-6" />
                        <UButton href="#how" size="xl" variant="soft" color="neutral" icon="i-solar-play-circle-bold" label="How a stay works" class="rounded-full" />
                    </div>

                    <dl class="hm-rise mt-10 flex flex-wrap gap-x-8 gap-y-4" style="--hm-step: 7" data-test="welcome-facts">
                        <div v-for="f in facts" :key="f.title" class="flex items-center gap-2.5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-primary-50"><UIcon :name="f.icon" class="size-[18px] text-primary" aria-hidden="true" /></span>
                            <div>
                                <dt class="text-[13px] leading-tight font-semibold text-highlighted">{{ f.title }}</dt>
                                <dd class="text-[11.5px] text-muted">{{ f.text }}</dd>
                            </div>
                        </div>
                    </dl>
                </div>

                <div class="hm-rise relative" style="--hm-step: 3">
                    <div class="relative aspect-[4/3] overflow-hidden rounded-[28px] border border-default shadow-(--hm-shadow-lift)">
                        <img :src="hero[0]?.src" :srcset="hero[0]?.srcset" sizes="(max-width: 1024px) 100vw, 45vw" alt="" class="hm-drift size-full object-cover" />
                        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/25 to-transparent" aria-hidden="true" />
                    </div>
                    <div class="hm-floaty absolute -bottom-5 -left-5 flex items-center gap-3 rounded-2xl border border-default bg-default p-3.5 pe-5 shadow-(--hm-shadow-float) max-[600px]:-left-2 max-[600px]:-bottom-3">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-primary text-on-primary"><UIcon name="i-solar-clock-circle-bold-duotone" class="size-6" aria-hidden="true" /></span>
                        <div>
                            <b class="block text-[14px] leading-tight font-semibold text-highlighted">Open 24 hours</b>
                            <span class="text-[12px] text-muted">Walk in any time — no booking</span>
                        </div>
                    </div>
                    <div v-if="lowest" class="hm-floaty absolute -top-4 -right-3 rounded-2xl border border-default bg-default px-4 py-2.5 shadow-(--hm-shadow-float) max-[900px]:hidden" style="animation-delay: -3s">
                        <span class="text-[11px] font-medium text-muted">From</span>
                        <b class="ms-1 text-[18px] font-semibold tracking-[-0.02em] tabular text-highlighted">{{ formatMoney(lowest.price.amount, 'tile') }}</b>
                        <span class="text-[12px] text-muted"> / {{ hoursLabel(lowest.price.hours) }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Rooms -->
        <section id="rooms" class="scroll-mt-24 bg-default py-20 max-[600px]:py-14">
            <div class="mx-auto max-w-[1200px] px-6 max-[600px]:px-4">
                <div data-reveal class="sr mb-10 flex items-end justify-between gap-6 max-[700px]:flex-col max-[700px]:items-start">
                    <div>
                        <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">Our rooms</p>
                        <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-highlighted">{{ heading }}</h2>
                        <p class="mt-2.5 max-w-[480px] text-[15px] text-muted">Every room has air-conditioning, a private bathroom and fresh linen. Pay by the hour or overnight.</p>
                    </div>
                    <small v-if="depositLine" class="max-w-[280px] rounded-xl bg-muted px-4 py-3 text-right text-[12.5px] text-muted max-[700px]:text-left" data-test="welcome-deposit">{{ depositLine }}</small>
                </div>

                <div v-if="types.length" class="grid grid-cols-3 gap-6 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1">
                    <article
                        v-for="(t, i) in types"
                        :key="t.id"
                        data-reveal
                        class="sr group flex flex-col overflow-hidden rounded-3xl border border-default bg-default shadow-xs transition-[transform,box-shadow] duration-300 ease-[cubic-bezier(.16,1,.3,1)] hover:-translate-y-1.5 hover:shadow-(--hm-shadow-lift) motion-reduce:transition-none motion-reduce:hover:translate-y-0"
                        :style="{ transitionDelay: `${i * 90}ms` }"
                        data-test="welcome-type"
                    >
                        <div class="relative h-[200px] overflow-hidden bg-(--hm-photo-fallback)">
                            <img
                                v-if="t.photo"
                                :src="t.photo"
                                alt=""
                                loading="lazy"
                                class="size-full object-cover transition-transform duration-[800ms] ease-[cubic-bezier(.16,1,.3,1)] group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                            />
                            <span class="absolute top-3.5 left-3.5 grid size-10 place-items-center rounded-xl bg-default/95 shadow-xs backdrop-blur-sm"><UIcon name="i-solar-bed-bold-duotone" class="size-5 text-primary" aria-hidden="true" /></span>
                        </div>
                        <div class="flex flex-1 flex-col px-5 pt-4 pb-5">
                            <h3 class="text-[17px] font-semibold text-highlighted">{{ t.name }}</h3>
                            <p v-if="t.line" class="mt-1 text-[13px] text-muted">{{ t.line }}</p>
                            <div v-if="tags(t.line).length" class="mt-3 flex flex-wrap gap-1.5">
                                <span v-for="tag in tags(t.line)" :key="tag" class="rounded-md bg-primary-50 px-2 py-[3px] text-[11.5px] font-medium text-primary-ink">{{ tag }}</span>
                            </div>
                            <div class="mt-auto flex items-end justify-between gap-3 border-t border-muted pt-4">
                                <div>
                                    <span v-if="t.price" class="block">
                                        <b class="text-[24px] leading-none font-semibold tracking-[-0.02em] tabular text-highlighted">{{ formatMoney(t.price.amount, 'tile') }}</b>
                                        <span class="ms-1 text-[12.5px] text-muted">/ {{ hoursLabel(t.price.hours) }}</span>
                                    </span>
                                    <span v-else-if="t.overnight !== null" class="block">
                                        <b class="text-[24px] leading-none font-semibold tracking-[-0.02em] tabular text-highlighted">{{ formatMoney(t.overnight, 'tile') }}</b>
                                        <span class="ms-1 text-[12.5px] text-muted">overnight</span>
                                    </span>
                                    <span v-if="t.price && t.overnight !== null" class="mt-1 block text-[12.5px] text-muted tabular">Overnight {{ formatMoney(t.overnight, 'tile') }}</span>
                                </div>
                                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary transition-colors duration-200 group-hover:bg-primary group-hover:text-on-primary"><UIcon name="i-solar-alt-arrow-right-linear" class="size-5" aria-hidden="true" /></span>
                            </div>
                        </div>
                    </article>
                </div>
                <p v-else class="rounded-2xl border border-default bg-muted px-5 py-6 text-muted" data-test="welcome-no-types">Rates will be listed here soon. Please ask at the front desk.</p>
            </div>
        </section>

        <!-- Why stay with us -->
        <section id="why" class="scroll-mt-24 bg-homi-50 py-20 max-[600px]:py-14">
            <div class="mx-auto grid max-w-[1200px] items-center gap-12 px-6 max-[600px]:px-4 lg:grid-cols-2">
                <div data-reveal class="sr relative">
                    <div class="aspect-[5/4] overflow-hidden rounded-[28px] border border-default shadow-(--hm-shadow-lift)">
                        <img v-if="aboutPhoto" :src="aboutPhoto" alt="" loading="lazy" class="size-full object-cover" />
                    </div>
                    <div class="absolute right-5 -bottom-6 grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-default bg-default shadow-(--hm-shadow-float) max-[600px]:static max-[600px]:mt-4 max-[600px]:grid-cols-4">
                        <div v-for="f in facts" :key="f.title" class="bg-default px-4 py-3">
                            <UIcon :name="f.icon" class="size-5 text-primary" aria-hidden="true" />
                            <b class="mt-1.5 block text-[14px] leading-tight font-semibold text-highlighted">{{ f.title }}</b>
                            <span class="text-[11px] text-muted">{{ f.text }}</span>
                        </div>
                    </div>
                </div>
                <div data-reveal class="sr" style="transition-delay: 0.1s">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">Why stay with us</p>
                    <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-highlighted">A comfortable room, any hour of the day</h2>
                    <p class="mt-4 text-[15px] leading-[1.65] text-muted">No apps, no accounts, no waiting. Walk in, pick a room, and rest — by the hour or for the night. The front desk is open around the clock.</p>
                    <ul class="mt-6 grid gap-3 sm:grid-cols-2">
                        <li v-for="p in points" :key="p" class="flex items-start gap-2.5 text-[14px] text-default">
                            <UIcon name="i-solar-check-circle-bold-duotone" class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
                            <span>{{ p }}</span>
                        </li>
                    </ul>
                    <div class="mt-8">
                        <UButton href="#rooms" size="xl" label="See rooms & rates" trailing-icon="i-solar-alt-arrow-right-linear" class="rounded-full px-6" />
                    </div>
                </div>
            </div>
        </section>

        <!-- How a stay works -->
        <section id="how" class="scroll-mt-24 bg-default py-20 max-[600px]:py-14">
            <div class="mx-auto max-w-[1100px] px-6 max-[600px]:px-4">
                <div data-reveal class="sr mx-auto mb-12 max-w-[640px] text-center">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">How it works</p>
                    <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-highlighted">A stay in three simple steps</h2>
                    <p class="mt-2.5 text-[15px] text-muted">No booking needed. It takes about two minutes from the door to your room.</p>
                </div>
                <ol class="relative grid grid-cols-3 gap-7 max-[700px]:grid-cols-1">
                    <i class="hm-draw absolute top-[34px] right-[16%] left-[16%] h-0.5 origin-left bg-primary-line max-[700px]:hidden" aria-hidden="true" />
                    <li v-for="(s, i) in steps" :key="s.title" data-reveal class="sr relative text-center" :style="{ transitionDelay: `${i * 110}ms` }">
                        <div class="relative mx-auto mb-5 grid size-[70px] place-items-center rounded-2xl border border-default bg-default shadow-xs">
                            <UIcon :name="s.icon" class="size-8 text-primary" aria-hidden="true" />
                            <span class="absolute -top-2 -right-2 grid size-7 place-items-center rounded-full bg-primary text-[12px] font-semibold text-on-primary tabular">{{ s.n }}</span>
                        </div>
                        <h3 class="mb-1.5 text-[17px] font-semibold text-highlighted">{{ s.title }}</h3>
                        <p class="mx-auto max-w-[300px] text-[13.5px] leading-[1.55] text-muted">{{ s.text }}</p>
                    </li>
                </ol>
            </div>
        </section>

        <!-- Amenities (dark) -->
        <section class="bg-homi-950 py-20 text-white max-[600px]:py-14">
            <div class="mx-auto max-w-[1200px] px-6 max-[600px]:px-4">
                <div data-reveal class="sr mx-auto mb-12 max-w-[640px] text-center">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-homi-300 uppercase">Everything included</p>
                    <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-white">Everything you need for a good night</h2>
                </div>
                <div class="grid grid-cols-3 gap-5 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1">
                    <div v-for="(a, i) in amenities" :key="a.title" data-reveal class="sr flex items-start gap-4 rounded-2xl border border-white/10 bg-white/[0.04] p-5 transition-colors duration-300 hover:bg-white/[0.08]" :style="{ transitionDelay: `${i * 70}ms` }">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-primary/20"><UIcon :name="a.icon" class="size-6 text-homi-300" aria-hidden="true" /></span>
                        <div>
                            <h3 class="text-[15.5px] font-semibold text-white">{{ a.title }}</h3>
                            <p class="mt-0.5 text-[13px] text-white/70">{{ a.text }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" class="scroll-mt-24 bg-default py-20 max-[600px]:py-14">
            <div class="mx-auto grid max-w-[1100px] gap-12 px-6 max-[600px]:px-4 lg:grid-cols-[0.85fr_1.15fr]">
                <div data-reveal class="sr">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">FAQ</p>
                    <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-highlighted">Questions? We’ve got answers</h2>
                    <p class="mt-3 text-[15px] leading-[1.6] text-muted">Everything you need to know before you walk in. Still unsure? Ask the front desk — they’re there all day and night.</p>
                    <UButton :as="Link" :href="route('login')" variant="soft" color="neutral" size="lg" icon="i-solar-user-bold-duotone" label="Staff sign in" class="mt-6 rounded-full" />
                </div>
                <div data-reveal class="sr" style="transition-delay: 0.1s">
                    <UAccordion
                        :items="faqs"
                        :ui="{ item: 'border border-default rounded-2xl mb-3 px-5 bg-default', trigger: 'py-4 text-[15px] font-medium text-highlighted', content: 'pb-4 text-[14px] text-muted leading-[1.6]' }"
                    />
                </div>
            </div>
        </section>

        <!-- CTA band -->
        <section class="px-6 py-6 max-[600px]:px-4">
            <div data-reveal class="sr relative mx-auto max-w-[1200px] overflow-hidden rounded-[32px] bg-gradient-to-br from-homi-600 to-homi-800 px-10 py-14 text-white max-[600px]:px-6 max-[600px]:py-10">
                <span aria-hidden="true" class="pointer-events-none absolute -top-20 -right-10 size-80 rounded-full bg-white/10 blur-2xl" />
                <span aria-hidden="true" class="pointer-events-none absolute -bottom-24 -left-10 size-96 rounded-full bg-brand/20 blur-3xl" />
                <div class="relative flex flex-wrap items-center justify-between gap-8">
                    <div class="max-w-[560px]">
                        <h2 class="text-[clamp(1.9rem,4.4vw,2.8rem)] leading-[1.08] font-semibold tracking-[-0.03em]">Rest well tonight — walk in any time</h2>
                        <p class="mt-3 text-[1.05rem] leading-[1.55] text-white/85">{{ branch?.name ?? 'We' }} {{ branch ? 'is' : 'are' }} open 24 hours. No booking, no account — just a clean, quiet room when you need it.</p>
                    </div>
                    <div class="flex flex-col gap-3">
                        <UButton href="#rooms" size="xl" class="on-photo rounded-full px-7" label="See rooms & rates" trailing-icon="i-solar-alt-arrow-right-linear" />
                        <span v-if="branch" class="inline-flex items-center gap-2 text-[13.5px] text-white/85"><UIcon name="i-solar-map-point-bold-duotone" class="size-[18px]" aria-hidden="true" />{{ branch.address }}</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Find us -->
        <section id="find-us" class="scroll-mt-24 bg-default py-16 max-[600px]:py-12">
            <div class="mx-auto grid max-w-[1200px] items-stretch gap-6 px-6 max-[600px]:px-4 lg:grid-cols-[1.1fr_1fr]">
                <div data-reveal class="sr min-h-[260px] overflow-hidden rounded-[28px] border border-default bg-(--hm-photo-fallback)">
                    <img :src="hero[0]?.src" :srcset="hero[0]?.srcset" sizes="(max-width: 1024px) 100vw, 55vw" alt="" loading="lazy" class="size-full object-cover" />
                </div>
                <div data-reveal class="sr rounded-[28px] border border-default bg-default p-7" style="transition-delay: 0.08s">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">Find us</p>
                    <h2 class="mb-4 text-[clamp(1.6rem,3.2vw,2.1rem)] leading-[1.12] font-semibold tracking-[-0.03em] text-highlighted">Come on over</h2>
                    <div class="flex gap-3.5 border-b border-muted py-3.5">
                        <UIcon name="i-solar-home-2-bold-duotone" class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
                        <div>
                            <b class="block text-[14px] font-semibold text-highlighted">{{ branch?.name ?? shared.app.name }}</b>
                            <span class="text-[13.5px] text-muted">{{ branch?.address ?? '' }}</span>
                        </div>
                    </div>
                    <div class="flex gap-3.5 border-b border-muted py-3.5">
                        <UIcon name="i-solar-clock-circle-bold-duotone" class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
                        <div>
                            <b class="block text-[14px] font-semibold text-highlighted">Open 24 hours, every day</b>
                            <span class="text-[13.5px] text-muted">The front desk is always staffed</span>
                        </div>
                    </div>
                    <div class="flex gap-3.5 py-3.5">
                        <UIcon name="i-solar-banknote-2-bold-duotone" class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
                        <div>
                            <b class="block text-[14px] font-semibold text-highlighted">{{ payWords }}</b>
                            <span class="text-[13.5px] text-muted">Paid at the front desk; no online payment</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-homi-950 pt-14 pb-8 text-white/70">
            <div class="mx-auto max-w-[1200px] px-6 max-[600px]:px-4">
                <div class="grid gap-10 pb-10 md:grid-cols-[1.4fr_1fr_1fr]">
                    <div>
                        <HomiLogo variant="white" :width="96" :height="30" />
                        <p class="mt-4 max-w-[320px] text-[13.5px] leading-[1.6] text-white/70">{{ branch?.name ?? shared.app.name }} — a clean, quiet room whenever you need one, by the hour or overnight. Open 24 hours.</p>
                    </div>
                    <div>
                        <h3 class="mb-3 text-[13px] font-semibold tracking-[0.1em] text-white uppercase">Explore</h3>
                        <ul class="space-y-2 text-[14px]">
                            <li v-for="n in nav" :key="n.href"><a :href="n.href" class="hm-focus rounded transition-colors hover:text-white">{{ n.label }}</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="mb-3 text-[13px] font-semibold tracking-[0.1em] text-white uppercase">Visit</h3>
                        <ul class="space-y-2.5 text-[14px]">
                            <li class="flex gap-2.5"><UIcon name="i-solar-map-point-bold-duotone" class="mt-0.5 size-[18px] shrink-0 text-homi-300" aria-hidden="true" /><span>{{ branch?.address ?? '' }}</span></li>
                            <li class="flex gap-2.5"><UIcon name="i-solar-clock-circle-bold-duotone" class="mt-0.5 size-[18px] shrink-0 text-homi-300" aria-hidden="true" /><span>Open 24 hours, every day</span></li>
                        </ul>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-white/10 pt-6 text-[12.5px] text-white/60">
                    <span>{{ eyebrow }}</span>
                    <UPopover v-if="credits.length">
                        <UButton variant="link" color="neutral" size="xs" label="Photos: Unsplash" class="p-0 text-[12.5px] font-normal text-white/60 hover:text-white" />
                        <template #content>
                            <ul class="max-w-72 space-y-1 p-3 text-[13px]" data-test="welcome-credits">
                                <li v-for="c in credits" :key="c.url">
                                    <a :href="c.url" target="_blank" rel="noopener" class="hm-focus text-primary hover:underline">Photo by {{ c.by }} on Unsplash</a>
                                </li>
                            </ul>
                        </template>
                    </UPopover>
                    <span class="ms-auto">© {{ year }} {{ branch?.name ?? shared.app.name }}</span>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* Scroll-reveal: rise and clear from an already-legible default. */
.sr {
    opacity: 0;
    transform: translateY(26px);
    transition:
        opacity 0.7s cubic-bezier(0.16, 1, 0.3, 1),
        transform 0.7s cubic-bezier(0.16, 1, 0.3, 1);
    will-change: opacity, transform;
}
.sr.in {
    opacity: 1;
    transform: none;
}

/* Nav link underline, drawn on hover. */
.nav-link::after {
    content: '';
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 6px;
    height: 2px;
    border-radius: 2px;
    background: currentColor;
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}
.nav-link:hover::after {
    transform: scaleX(1);
}

/* Floating hero cards drift gently. */
.hm-floaty {
    animation: floaty 6s ease-in-out infinite;
}
@keyframes floaty {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-9px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .sr {
        opacity: 1;
        transform: none;
        transition: none;
    }
    .hm-floaty {
        animation: none;
    }
}
</style>
