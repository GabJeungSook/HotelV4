<!-- Mockup 59 — public front page: the HOMI product landing, in a professional multi-section layout.
     Sticky nav with the 2D Homi wordmark, a two-column hero led by a real dashboard screenshot, the
     features, one end-to-end flow, why hotels switch (dark), the system in action (real screens),
     the channels, an about note, a call-to-action and a footer. Light only; scroll-reveal, hero and
     hover animations all stop under reduced motion. -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import { useShared } from '@/Composables/useShared';

const FB = 'https://www.facebook.com/profile.php?id=61578393755918';
const MAIL = 'mailto:homi@gmail.com';
const img = (n: string) => `/images/landing/${n}`;

const { props: shared } = useShared();
const signedIn = computed(() => shared.value.auth !== null);
const homeUrl = computed(() => shared.value.home ?? route('home'));

const features = [
    {
        icon: 'i-solar-widget-5-bold-duotone',
        image: 'homi-03.jpg',
        alt: 'HOMI admin dashboard showing rooms, guests, and sales in one screen',
        title: 'Your whole hotel in one system',
        body: 'Rooms, guests, bookings, front desk, and sales live together — no more jumping between notebooks, chat threads, and three versions of the same spreadsheet.',
        points: ['Real-time room status and availability', 'Guest profiles and stay history', 'Live sales, occupancy, and revenue'],
    },
    {
        icon: 'i-solar-chart-2-bold-duotone',
        image: 'homi-25.jpg',
        alt: 'A front-desk manager generating an instant report in HOMI',
        title: 'Reports in seconds, not hours',
        body: 'The end-of-shift scramble is over. HOMI builds the numbers as the day happens, so a Z-report, a daily sales summary, or occupancy is one tap away — and it always reconciles.',
        points: ['Shift and daily reports built automatically', 'Occupancy, ADR, and RevPAR at a glance', 'Every peso traces back to a real record'],
    },
    {
        icon: 'i-solar-smartphone-bold-duotone',
        image: 'homi-22.jpg',
        alt: 'A housekeeper checking room tasks on the HOMI phone app',
        title: 'Housekeeping on their phones',
        body: 'Room boys see what to clean and in what order — prioritised by checkout time — straight from a phone. The front desk sees a room go ready the moment it is done.',
        points: ['Tasks pushed by checkout schedule', 'Live status: pending, cleaning, ready', 'No radios, no sticky notes, no guessing'],
    },
    {
        icon: 'i-solar-point-on-map-bold-duotone',
        image: 'homi-26.jpg',
        alt: 'A guest using a HOMI self-service check-in kiosk',
        title: 'A lobby kiosk that skips the queue',
        body: 'Guests check in and out themselves at a HOMI kiosk — scan, confirm, and go. The front desk stays free for the moments that actually need a person.',
        points: ['Self check-in and check-out', 'Smoother flow at peak hours', 'Faster room turnover'],
    },
    {
        icon: 'i-solar-bolt-bold-duotone',
        image: 'homi-24.jpg',
        alt: 'A receptionist working with the HOMI dashboard at the front desk',
        title: 'Automation that frees your team',
        body: 'HOMI handles the routine — the counting, the posting, the reconciling — so your people spend their shift on guests and service instead of paperwork.',
        points: ['Smarter day-to-day operations', 'Fewer manual errors', 'Decisions backed by live data'],
    },
];

const benefits = [
    { icon: 'i-solar-users-group-rounded-bold-duotone', title: 'Less manpower', text: 'One system does the work that used to take a stack of logbooks and a full team.' },
    { icon: 'i-solar-bolt-bold-duotone', title: 'More efficiency', text: 'Check-ins, reports, and turnover that used to take hours take seconds.' },
    { icon: 'i-solar-shield-check-bold-duotone', title: 'Fewer errors', text: 'Money is written once and never overwritten, so the books always add up.' },
    { icon: 'i-solar-heart-bold-duotone', title: 'Happier guests', text: 'Shorter waits, smoother service, and no "we have no record of your booking."' },
    { icon: 'i-solar-cloud-bold-duotone', title: 'Cloud-based', text: 'Reach your hotel from anywhere, on any device, at any time.' },
    { icon: 'i-solar-lock-keyhole-bold-duotone', title: 'Secure & reliable', text: 'Enterprise-grade security and an audit trail you can trust.' },
];

const channels = [
    { icon: 'i-solar-smartphone-bold-duotone', title: 'Mobile app', text: 'For staff on the move and guests on their own phones.' },
    { icon: 'i-solar-monitor-bold-duotone', title: 'Web browser', text: 'The full back office and front desk, nothing to install.' },
    { icon: 'i-solar-point-on-map-bold-duotone', title: 'Lobby kiosk', text: 'Self-service check-in and check-out, embedded in the system.' },
];

// The HOMI graphics that document the system in detail, shown as a gallery.
const gallery = [
    { image: 'homi-23.jpg', alt: 'Stop searching, start managing smarter' },
    { image: 'homi-07.jpg', alt: 'Every booking, always recorded' },
    { image: 'homi-21.jpg', alt: 'Smarter reporting, faster decisions' },
    { image: 'homi-05.jpg', alt: 'Manage smarter, grow faster — with POS and kitchen' },
    { image: 'homi-16.jpg', alt: 'Now with kiosk integration — mobile, web, and kiosk' },
    { image: 'homi-11.jpg', alt: 'Hotels made smarter, guests made happier' },
];

const nav = [
    { label: 'Features', href: '#features' },
    { label: 'How it works', href: '#how' },
    { label: 'Why HOMI', href: '#why' },
    { label: 'Contact', href: '#contact' },
];

const scrolled = ref(false);
const activeId = ref('');
const reduced = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
let reveal: IntersectionObserver | null = null;
let spy: IntersectionObserver | null = null;

function onScroll(): void {
    scrolled.value = window.scrollY > 8;
}

// Smooth-scroll in-page anchors, offset for the sticky nav.
function onAnchorClick(e: MouseEvent): void {
    const link = (e.target as HTMLElement)?.closest?.('a[href^="#"]') as HTMLAnchorElement | null;
    if (!link) return;
    const id = link.getAttribute('href')?.slice(1) ?? '';
    const target = id ? document.getElementById(id) : null;
    if (!target) return;
    e.preventDefault();
    const top = id === 'top' ? 0 : target.getBoundingClientRect().top + window.scrollY - 80;
    window.scrollTo({ top: Math.max(0, top), behavior: reduced ? 'auto' : 'smooth' });
    history.replaceState(null, '', `#${id}`);
}

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('click', onAnchorClick);

    if (!reduced) {
        reveal = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('in');
                        reveal?.unobserve(entry.target);
                    }
                }
            },
            { rootMargin: '0px 0px -10% 0px', threshold: 0.12 },
        );
        document.querySelectorAll('[data-reveal]').forEach((el) => reveal?.observe(el));
    } else {
        document.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('in'));
    }

    spy = new IntersectionObserver((entries) => entries.forEach((e) => e.isIntersecting && (activeId.value = e.target.id)), { rootMargin: '-45% 0px -50% 0px', threshold: 0 });
    document.querySelectorAll('section[id]').forEach((el) => spy?.observe(el));
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);
    document.removeEventListener('click', onAnchorClick);
    reveal?.disconnect();
    spy?.disconnect();
});
</script>

<template>
    <Head title="HOMI — the smarter way to run your hotel" />
    <div id="top" class="hm-light min-h-dvh scroll-smooth bg-default font-sans text-default">
        <!-- Sticky nav -->
        <header
            class="sticky top-0 z-50 border-b transition-[background-color,box-shadow,border-color] duration-300"
            :class="scrolled ? 'border-default bg-default/90 shadow-xs backdrop-blur-md' : 'border-transparent bg-default'"
        >
            <div class="mx-auto flex h-[72px] max-w-[1200px] items-center gap-6 px-6 max-[600px]:h-16 max-[600px]:px-4">
                <a href="#top" class="hm-focus shrink-0 rounded" aria-label="HOMI home"><HomiLogo variant="blue" :width="104" :height="32" /></a>
                <nav class="ms-auto hidden items-center gap-0.5 md:flex" aria-label="Main">
                    <a
                        v-for="n in nav"
                        :key="n.href"
                        :href="n.href"
                        class="nav-link hm-focus relative rounded-lg px-3 py-2 text-[14px] font-medium transition-colors"
                        :class="activeId === n.href.slice(1) ? 'text-primary' : 'text-default hover:text-primary'"
                        >{{ n.label }}</a
                    >
                </nav>
                <UButton v-if="signedIn" :as="Link" :href="homeUrl" label="Dashboard" icon="i-solar-widget-5-bold-duotone" size="lg" class="ms-auto rounded-full md:ms-2" data-test="welcome-dashboard" />
                <UButton v-else :as="Link" :href="route('login')" label="Staff sign in" icon="i-solar-user-bold-duotone" size="lg" class="ms-auto rounded-full md:ms-2" data-test="welcome-sign-in" />
            </div>
        </header>

        <!-- Hero -->
        <section class="relative overflow-hidden bg-gradient-to-b from-homi-50 to-default">
            <span aria-hidden="true" class="pointer-events-none absolute -top-28 -right-24 size-[500px] rounded-full bg-primary/10 blur-3xl" />
            <div class="mx-auto grid max-w-[1200px] items-center gap-12 px-6 py-16 max-[600px]:px-4 max-[600px]:py-10 lg:grid-cols-[1.02fr_1fr] lg:py-24">
                <div class="max-w-[560px]">
                    <span class="hm-rise inline-flex items-center gap-2 rounded-full border border-primary-line bg-default px-3.5 py-1.5 text-[12.5px] font-semibold text-primary-ink shadow-xs">
                        <span class="size-1.5 rounded-full bg-primary" aria-hidden="true" />Hotel Solution Software
                    </span>
                    <h1 class="hm-rise mt-5 text-[clamp(2.3rem,5.2vw,3.7rem)] leading-[1.04] font-semibold tracking-[-0.035em] text-highlighted" style="--hm-step: 1">
                        The smarter way to run your <span class="text-primary">hotel.</span>
                    </h1>
                    <p class="hm-rise mt-5 max-w-[480px] text-[1.05rem] leading-[1.6] text-muted" style="--hm-step: 2">
                        Front desk, housekeeping, kiosk, bookings, and reports — one system that means less manpower, more efficiency, and happier guests.
                    </p>
                    <div class="hm-rise mt-7 flex flex-wrap items-center gap-3" style="--hm-step: 3">
                        <UButton href="#how" size="xl" label="See how it works" trailing-icon="i-solar-alt-arrow-right-linear" class="rounded-full px-6" />
                        <UButton :as="Link" :href="route('login')" size="xl" variant="soft" color="neutral" icon="i-solar-user-bold-duotone" label="Staff sign in" class="rounded-full" />
                    </div>
                    <dl class="hm-rise mt-10 flex flex-wrap gap-x-8 gap-y-4" style="--hm-step: 4">
                        <div v-for="c in channels" :key="c.title" class="flex items-center gap-2.5">
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-primary-50"><UIcon :name="c.icon" class="size-[18px] text-primary" aria-hidden="true" /></span>
                            <dt class="text-[13.5px] font-semibold text-highlighted">{{ c.title }}</dt>
                        </div>
                    </dl>
                </div>

                <div class="hm-rise relative" style="--hm-step: 2">
                    <div class="overflow-hidden rounded-[24px] border border-default bg-default shadow-(--hm-shadow-lift)">
                        <img :src="img('homi-02.jpg')" width="1254" height="1254" alt="HOMI — smarter hotel management starts here" class="block w-full" />
                    </div>
                    <div class="hm-floaty absolute -bottom-5 -left-5 flex items-center gap-3 rounded-2xl border border-default bg-default p-3.5 pe-5 shadow-(--hm-shadow-float) max-[600px]:-bottom-3 max-[600px]:-left-2">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-primary text-on-primary"><UIcon name="i-solar-cloud-bold-duotone" class="size-6" aria-hidden="true" /></span>
                        <div>
                            <b class="block text-[14px] leading-tight font-semibold text-highlighted">Cloud-based</b>
                            <span class="text-[12px] text-muted">Reach your hotel from anywhere</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Trust strip -->
        <section class="border-y border-default bg-muted">
            <div class="mx-auto flex max-w-[1200px] flex-wrap items-center justify-center gap-x-10 gap-y-3 px-6 py-5 text-[13.5px] font-medium text-muted max-[600px]:px-4">
                <span class="inline-flex items-center gap-2"><UIcon name="i-solar-point-on-map-bold-duotone" class="size-[18px] text-primary" aria-hidden="true" />Now with kiosk integration</span>
                <span class="hidden h-4 w-px bg-default sm:block" />
                <span class="inline-flex items-center gap-2"><UIcon name="i-solar-buildings-2-bold-duotone" class="size-[18px] text-primary" aria-hidden="true" />Built for hotels, inns &amp; pension houses</span>
                <span class="hidden h-4 w-px bg-default sm:block" />
                <span class="inline-flex items-center gap-2"><UIcon name="i-solar-cloud-bold-duotone" class="size-[18px] text-primary" aria-hidden="true" />Cloud-based, on every device</span>
            </div>
        </section>

        <!-- Problem -->
        <section class="bg-default py-20 max-[600px]:py-14">
            <div data-reveal class="sr mx-auto max-w-[760px] px-6 text-center max-[600px]:px-4">
                <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">The problem</p>
                <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-highlighted">Still running the hotel on paper?</h2>
                <p class="mx-auto mt-5 max-w-[640px] text-[1.05rem] leading-[1.65] text-muted">
                    Logbooks, radios, and a folder full of <span class="rounded bg-elevated px-1.5 py-0.5 font-mono text-[.9em] text-default">FINAL_REPORT_v7.xlsx</span>. A guest walks in and nobody can find the booking. A room sits dirty because the message never got through.
                </p>
                <p class="mx-auto mt-4 max-w-[640px] text-[1.05rem] leading-[1.65] text-muted">HOMI replaces all of it with one connected system — so the answer to “who’s in Room 205?” takes two seconds, not a flip through three notebooks.</p>
            </div>
        </section>

        <!-- Features -->
        <section id="features" class="scroll-mt-24 bg-muted py-20 max-[600px]:py-14">
            <div class="mx-auto max-w-[1200px] px-6 max-[600px]:px-4">
                <div data-reveal class="sr mx-auto mb-14 max-w-[640px] text-center">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">Features</p>
                    <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-highlighted">Everything your front desk needs</h2>
                    <p class="mt-2.5 text-[15px] text-muted">One platform for the whole operation — from the lobby kiosk to the housekeeper’s phone to the owner’s nightly report.</p>
                </div>
                <div class="flex flex-col gap-16 sm:gap-24">
                    <article v-for="(f, i) in features" :key="f.title" data-reveal class="sr grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
                        <div class="overflow-hidden rounded-[22px] border border-default bg-default shadow-(--hm-shadow-lift)" :class="i % 2 === 1 ? 'lg:order-2' : ''">
                            <img :src="img(f.image)" :alt="f.alt" width="1254" height="1254" loading="lazy" class="block w-full" />
                        </div>
                        <div :class="i % 2 === 1 ? 'lg:order-1' : ''">
                            <span class="grid size-12 place-items-center rounded-2xl bg-primary-50"><UIcon :name="f.icon" class="size-7 text-primary" aria-hidden="true" /></span>
                            <h3 class="mt-5 text-[clamp(1.5rem,3vw,2rem)] leading-[1.15] font-semibold tracking-[-0.025em] text-highlighted">{{ f.title }}</h3>
                            <p class="mt-3 text-[1.02rem] leading-[1.6] text-muted">{{ f.body }}</p>
                            <ul class="mt-5 space-y-3">
                                <li v-for="p in f.points" :key="p" class="flex items-start gap-3 text-[15px] text-default">
                                    <UIcon name="i-solar-check-circle-bold-duotone" class="mt-0.5 size-[22px] shrink-0 text-primary" aria-hidden="true" />
                                    <span>{{ p }}</span>
                                </li>
                            </ul>
                        </div>
                    </article>
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section id="how" class="scroll-mt-24 bg-default py-20 max-[600px]:py-14">
            <div class="mx-auto max-w-[1100px] px-6 max-[600px]:px-4">
                <div data-reveal class="sr mx-auto mb-10 max-w-[640px] text-center">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">How it works</p>
                    <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-highlighted">One flow, end to end</h2>
                    <p class="mt-2.5 text-[15px] text-muted">A guest books on the app, the web, or the kiosk. It lands at the front desk, flows to billing, the kitchen, and housekeeping — and closes the loop at checkout.</p>
                </div>
                <div data-reveal class="sr overflow-hidden rounded-[24px] border border-default bg-muted p-3 shadow-(--hm-shadow-lift) sm:p-5">
                    <img :src="img('homi-14.jpg')" width="1920" height="1080" loading="lazy" alt="HOMI flow: guests book via app, web, or kiosk; bookings reach the admin panel, then billing, kitchen and housekeeping." class="block w-full rounded-[14px]" />
                </div>
            </div>
        </section>

        <!-- Why HOMI (dark) -->
        <section id="why" class="scroll-mt-24 bg-homi-950 py-20 text-white max-[600px]:py-14">
            <div class="mx-auto max-w-[1200px] px-6 max-[600px]:px-4">
                <div data-reveal class="sr mx-auto mb-12 max-w-[680px] text-center">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-homi-300 uppercase">Why HOMI</p>
                    <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-white">Why hotels switch to HOMI</h2>
                    <p class="mt-4 text-[15px] leading-[1.65] text-white/70">The old system failed in production. HOMI was rebuilt on three rules: money is written once, every peso points to a shift, and a room has one open stay.</p>
                </div>
                <div class="grid grid-cols-3 gap-5 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1">
                    <div v-for="(b, i) in benefits" :key="b.title" data-reveal class="sr rounded-2xl border border-white/10 bg-white/[0.04] p-6 transition-colors duration-300 hover:bg-white/[0.08]" :style="{ transitionDelay: `${(i % 3) * 70}ms` }">
                        <span class="grid size-11 place-items-center rounded-xl bg-primary/20"><UIcon :name="b.icon" class="size-6 text-homi-300" aria-hidden="true" /></span>
                        <h3 class="mt-4 text-[17px] font-semibold text-white">{{ b.title }}</h3>
                        <p class="mt-1.5 text-[13.5px] leading-[1.55] text-white/70">{{ b.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- The system in detail (gallery) -->
        <section class="bg-muted py-20 max-[600px]:py-14">
            <div class="mx-auto max-w-[1200px] px-6 max-[600px]:px-4">
                <div data-reveal class="sr mx-auto mb-12 max-w-[640px] text-center">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">In detail</p>
                    <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-highlighted">See the system in detail</h2>
                    <p class="mt-2.5 text-[15px] text-muted">A closer look at what HOMI does, straight from the team.</p>
                </div>
                <div class="grid grid-cols-3 gap-6 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1">
                    <figure
                        v-for="(g, i) in gallery"
                        :key="g.image"
                        data-reveal
                        class="sr overflow-hidden rounded-[18px] border border-default bg-default shadow-xs transition-[transform,box-shadow] duration-300 ease-[cubic-bezier(.16,1,.3,1)] hover:-translate-y-1.5 hover:shadow-(--hm-shadow-lift) motion-reduce:transition-none motion-reduce:hover:translate-y-0"
                        :style="{ transitionDelay: `${(i % 3) * 80}ms` }"
                    >
                        <img :src="img(g.image)" :alt="g.alt" width="1254" height="1254" loading="lazy" class="block w-full" />
                    </figure>
                </div>
            </div>
        </section>

        <!-- Channels -->
        <section class="bg-default py-20 max-[600px]:py-14">
            <div class="mx-auto grid max-w-[1200px] items-center gap-12 px-6 max-[600px]:px-4 lg:grid-cols-[0.9fr_1.1fr]">
                <div data-reveal class="sr">
                    <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">Everywhere</p>
                    <h2 class="text-[clamp(1.9rem,4vw,2.6rem)] leading-[1.1] font-semibold tracking-[-0.03em] text-highlighted">Works everywhere your hotel does</h2>
                    <p class="mt-4 text-[15px] leading-[1.65] text-muted">The same system on the front-desk screen, in a staff member’s pocket, and on the lobby kiosk — no installs, always in sync.</p>
                </div>
                <div data-reveal class="sr grid gap-4 sm:grid-cols-3" style="transition-delay: 0.1s">
                    <div v-for="c in channels" :key="c.title" class="rounded-2xl border border-default bg-muted p-5">
                        <span class="grid size-11 place-items-center rounded-xl bg-primary-50"><UIcon :name="c.icon" class="size-6 text-primary" aria-hidden="true" /></span>
                        <h3 class="mt-4 text-[15.5px] font-semibold text-highlighted">{{ c.title }}</h3>
                        <p class="mt-1 text-[13px] leading-[1.5] text-muted">{{ c.text }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- About -->
        <section class="bg-muted py-16 max-[600px]:py-12">
            <div data-reveal class="sr mx-auto max-w-[760px] px-6 text-center max-[600px]:px-4">
                <p class="mb-2 text-[13px] font-semibold tracking-[0.12em] text-primary uppercase">About</p>
                <h2 class="text-[clamp(1.7rem,3.6vw,2.3rem)] leading-[1.15] font-semibold tracking-[-0.03em] text-highlighted">Built by a startup that knows hotels</h2>
                <p class="mx-auto mt-5 max-w-[620px] text-[1.02rem] leading-[1.65] text-muted">
                    HOMI is a tech-driven startup making smart hotel operations accessible and affordable — now powered by kiosk integration for seamless, self-service convenience. We keep building toward one goal: hotels made smarter, guests made happier.
                </p>
            </div>
        </section>

        <!-- CTA -->
        <section id="contact" class="scroll-mt-24 bg-default px-6 py-8 max-[600px]:px-4">
            <div data-reveal class="sr relative mx-auto max-w-[1200px] overflow-hidden rounded-[32px] bg-gradient-to-br from-homi-600 to-homi-800 px-10 py-14 text-white max-[600px]:px-6 max-[600px]:py-10">
                <span aria-hidden="true" class="pointer-events-none absolute -top-20 -right-10 size-80 rounded-full bg-white/10 blur-2xl" />
                <span aria-hidden="true" class="pointer-events-none absolute -bottom-24 -left-10 size-96 rounded-full bg-brand/20 blur-3xl" />
                <div class="relative mx-auto max-w-[720px] text-center">
                    <h2 class="text-[clamp(1.9rem,4.4vw,2.9rem)] leading-[1.08] font-semibold tracking-[-0.03em]">Ready to run your hotel smarter?</h2>
                    <p class="mx-auto mt-4 max-w-[520px] text-[1.05rem] leading-[1.55] text-white/85">Less manpower. More efficiency. Happier guests. Let’s set up HOMI for your hotel.</p>
                    <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                        <UButton :href="FB" target="_blank" rel="noopener" size="xl" class="on-photo rounded-full px-7" icon="i-solar-chat-round-line-bold-duotone" label="Message us on Facebook" />
                        <UButton :href="MAIL" size="xl" variant="outline" class="rounded-full border-white/40 px-6 text-white hover:bg-white/10" icon="i-solar-letter-bold-duotone" label="homi@gmail.com" />
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="bg-homi-950 pt-14 pb-8 text-white/70">
            <div class="mx-auto max-w-[1200px] px-6 max-[600px]:px-4">
                <div class="grid gap-10 pb-10 md:grid-cols-[1.5fr_1fr_1fr]">
                    <div>
                        <HomiLogo variant="white" :width="100" :height="30" />
                        <p class="mt-4 max-w-[340px] text-[13.5px] leading-[1.6] text-white/70">The smarter way to run your hotel — front desk, housekeeping, kiosk, bookings and reports in one connected system.</p>
                    </div>
                    <div>
                        <h3 class="mb-3 text-[13px] font-semibold tracking-[0.1em] text-white uppercase">Explore</h3>
                        <ul class="space-y-2 text-[14px]">
                            <li v-for="n in nav" :key="n.href"><a :href="n.href" class="hm-focus rounded transition-colors hover:text-white">{{ n.label }}</a></li>
                            <li><a :href="route('login')" class="hm-focus rounded transition-colors hover:text-white">Staff sign in</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3 class="mb-3 text-[13px] font-semibold tracking-[0.1em] text-white uppercase">Contact</h3>
                        <ul class="space-y-2.5 text-[14px]">
                            <li><a :href="FB" target="_blank" rel="noopener" class="hm-focus inline-flex items-center gap-2 rounded transition-colors hover:text-white"><UIcon name="i-solar-chat-round-line-bold-duotone" class="size-[18px] text-homi-300" aria-hidden="true" />facebook.com/homi</a></li>
                            <li><a :href="MAIL" class="hm-focus inline-flex items-center gap-2 rounded transition-colors hover:text-white"><UIcon name="i-solar-letter-bold-duotone" class="size-[18px] text-homi-300" aria-hidden="true" />homi@gmail.com</a></li>
                        </ul>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-white/10 pt-6 text-[12.5px] text-white/60">
                    <span>Hotel Solution Software</span>
                    <span class="ms-auto">© 2026 HOMI</span>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
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
