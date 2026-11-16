<!-- Mockup 59 -->
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import PhotoHero from '@/Components/PhotoHero.vue';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';

/**
 * The public front page (59, mockup v6): a 520 px hero that cross-fades three photos with a drift
 * and a progress bar per photo; the headline comes in word by word, then the line and buttons rise.
 * A white facts strip overlaps the hero. Below: the room types with three amenity tags and their
 * lowest hourly and overnight prices read live from the rate table (cards lift on hover), how a
 * stay works on a line that draws itself once, and Find us beside a lobby photo. No availability
 * and no booking. Light only; all motion stops under reduced motion.
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

/** "Cash or GCash", from the branch's payment methods (card by reference is still "card"). */
const METHOD: Record<string, string> = { cash: 'Cash', gcash: 'GCash', card: 'Card' };
const payWords = computed(() => {
    const list = (props.branch?.methods ?? ['cash']).map((m) => METHOD[m]).filter(Boolean);

    return list.length <= 1 ? (list[0] ?? 'Cash') : `${list.slice(0, -1).join(', ')} or ${list[list.length - 1]}`;
});

/** The lowest hourly price across the room types, for the facts strip. */
const lowest = computed(() => {
    const priced = props.types.filter((t) => t.price !== null) as (RoomTypeCard & { price: { amount: number; hours: number } })[];

    return priced.length ? priced.reduce((a, b) => (b.price.amount < a.price.amount ? b : a)) : null;
});

const facts = computed(() => [
    { icon: 'i-lucide-clock', title: 'Open 24 hours', text: 'Walk in any time' },
    ...(lowest.value ? [{ icon: 'i-lucide-bed', title: `From ${formatMoney(lowest.value.price.amount, 'tile')}`, text: `${hoursLabel(lowest.value.price.hours)}, ${lowest.value.name} room` }] : []),
    { icon: 'i-lucide-banknote', title: payWords.value, text: 'Pay at the front desk' },
    ...(props.branch?.deposit ? [{ icon: 'i-lucide-key', title: `${formatMoney(props.branch.deposit.amount, 'tile')} ${props.branch.deposit.name.toLowerCase()} deposit`, text: 'Returned when you leave' }] : []),
]);

/** Three amenity tags from the type's line ("Double bed, air-conditioning, TV."). */
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
    { icon: 'i-lucide-bed', title: 'Choose your room', text: 'Pick a room type and how many hours, at the kiosk or the desk.' },
    { icon: 'i-lucide-banknote', title: 'Pay at the desk', text: 'Cash or GCash. Your slip carries your stay code.' },
    { icon: 'i-lucide-key', title: 'Extend or check out', text: 'Add hours any time. Return the key and remote to get your deposit back.' },
];

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
    <div class="hm-light min-h-dvh bg-default font-sans text-default">
        <PhotoHero :photos="hero" slides sizes="100vw" class="hm-pausable flex h-[520px] flex-col max-[600px]:h-[600px]" data-welcome-hero>
            <nav class="relative z-[4] flex items-center gap-7 px-10 pt-5 text-(--hm-card) max-[600px]:px-5" aria-label="Welcome">
                <HomiLogo variant="white" :width="84" :height="26" />
                <span class="ms-auto flex gap-[26px] text-[13.5px] font-medium opacity-[0.92] max-[900px]:hidden">
                    <a href="#rooms" class="hm-focus rounded-sm hover:underline">Rooms</a>
                    <a href="#how" class="hm-focus rounded-sm hover:underline">How a stay works</a>
                    <a href="#find-us" class="hm-focus rounded-sm hover:underline">Find us</a>
                </span>
                <UButton
                    v-if="signedIn"
                    :as="Link"
                    :href="homeUrl"
                    label="Dashboard"
                    icon="i-lucide-layout-dashboard"
                    variant="outline"
                    class="on-photo-outline rounded-[9px] max-[900px]:ms-auto"
                    data-test="welcome-dashboard"
                />
                <UButton
                    v-else
                    :as="Link"
                    :href="route('login')"
                    label="Staff sign in"
                    icon="i-lucide-user"
                    variant="outline"
                    class="on-photo-outline rounded-[9px] max-[900px]:ms-auto"
                    data-test="welcome-sign-in"
                />
            </nav>

            <div class="relative z-[4] max-w-[640px] px-10 pt-16 text-(--hm-card) max-[600px]:px-5 max-[600px]:pt-10">
                <span class="on-photo-pill hm-rise inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[12.5px] font-medium">
                    <UIcon name="i-lucide-clock" class="size-3.5" aria-hidden="true" />{{ eyebrow }}
                </span>
                <h1 class="mt-5 mb-4 text-[56px] leading-[1.02] font-semibold tracking-[-0.04em] max-[600px]:text-[38px]" :aria-label="headline">
                    <template v-for="(w, i) in words" :key="`${w}-${i}`"
                        ><span class="hm-word" :style="{ '--hm-step': i }" aria-hidden="true">{{ w }}</span>{{ i < words.length - 1 ? ' ' : '' }}</template
                    >
                </h1>
                <p class="hm-rise mb-[26px] max-w-[470px] text-base leading-[1.55] text-(--hm-on-photo-body)" style="--hm-step: 5">{{ subline }}</p>
                <USelect
                    v-if="branches.length > 1 && branch"
                    :model-value="branch.id"
                    :items="branchItems"
                    aria-label="Branch"
                    class="hm-rise mb-5 w-64"
                    style="--hm-step: 5"
                    data-test="welcome-branch"
                    @update:model-value="pickBranch($event as number)"
                />
                <div class="hm-rise flex flex-wrap gap-2.5" style="--hm-step: 6">
                    <UButton href="#rooms" size="xl" label="See rooms and rates" trailing-icon="i-lucide-chevron-right" class="on-photo h-11 rounded-[10px]" />
                    <UButton href="#how" size="xl" variant="outline" label="How a stay works" class="on-photo-outline h-11 rounded-[10px]" />
                </div>
            </div>

            <div class="hm-prog absolute bottom-[78px] left-10 z-[4] flex gap-2 max-[600px]:left-5" aria-hidden="true">
                <i v-for="p in hero" :key="p.src" class="h-[3px] w-9 rounded-[2px] bg-(--hm-slide-off)" />
            </div>
        </PhotoHero>

        <div
            class="hm-rise relative z-[5] mx-10 -mt-[62px] grid grid-cols-4 rounded-2xl border border-default bg-default shadow-(--hm-shadow-lift) max-[900px]:grid-cols-2 max-[600px]:mx-5"
            style="--hm-step: 4"
            data-test="welcome-facts"
        >
            <div v-for="(f, i) in facts" :key="f.title" class="flex items-center gap-3 px-[22px] py-5" :class="i > 0 ? 'border-l border-default max-[900px]:[&:nth-child(3)]:border-l-0' : ''">
                <UIcon :name="f.icon" class="size-5 shrink-0 text-primary" aria-hidden="true" />
                <div>
                    <b class="block text-sm font-semibold">{{ f.title }}</b>
                    <span class="text-xs text-muted">{{ f.text }}</span>
                </div>
            </div>
        </div>

        <section id="rooms" class="scroll-mt-4 px-10 pt-14 pb-2 max-[600px]:px-5" aria-labelledby="rooms-heading">
            <div class="mb-[22px] flex items-end justify-between gap-5 max-[600px]:flex-col max-[600px]:items-start">
                <div>
                    <h2 id="rooms-heading" class="text-[30px] leading-[1.15] font-semibold tracking-[-0.03em] text-highlighted">{{ heading }}</h2>
                    <p class="mt-1.5 max-w-[460px] text-sm text-muted">Every room has air-conditioning, a private bathroom and fresh linen.</p>
                </div>
                <small v-if="depositLine" class="max-w-[300px] text-right text-[12.5px] text-muted max-[600px]:text-left" data-test="welcome-deposit">{{ depositLine }}</small>
            </div>

            <div v-if="types.length" class="grid grid-cols-3 gap-5 max-[900px]:grid-cols-2 max-[600px]:grid-cols-1">
                <article
                    v-for="(t, i) in types"
                    :key="t.id"
                    class="hm-rise group overflow-hidden rounded-2xl border border-default bg-default transition-[transform,box-shadow] duration-300 ease-[cubic-bezier(.16,1,.3,1)] hover:-translate-y-1 hover:shadow-(--hm-shadow-lift) motion-reduce:transition-none motion-reduce:hover:translate-y-0"
                    :style="{ '--hm-step': i + 1 }"
                    data-test="welcome-type"
                >
                    <div class="h-[190px] overflow-hidden bg-(--hm-photo-fallback)">
                        <img
                            v-if="t.photo"
                            :src="t.photo"
                            alt=""
                            loading="lazy"
                            class="size-full object-cover transition-transform duration-[800ms] ease-[cubic-bezier(.16,1,.3,1)] group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                        />
                    </div>
                    <div class="px-[18px] pt-4 pb-[18px]">
                        <h3 class="text-base font-semibold text-highlighted">{{ t.name }}</h3>
                        <p v-if="t.line" class="mt-1 mb-3 text-[13px] text-muted">{{ t.line }}</p>
                        <div v-if="tags(t.line).length" class="mb-3.5 flex flex-wrap gap-1.5">
                            <span v-for="tag in tags(t.line)" :key="tag" class="rounded-md bg-elevated px-2 py-[3px] text-[11.5px] text-default">{{ tag }}</span>
                        </div>
                        <div class="flex items-baseline justify-between gap-3 border-t border-muted pt-3">
                            <span v-if="t.price">
                                <b class="text-[22px] font-semibold tracking-[-0.02em] tabular">{{ formatMoney(t.price.amount, 'tile') }}</b>
                                <span class="ms-1 text-[12.5px] text-muted">/ {{ hoursLabel(t.price.hours) }}</span>
                            </span>
                            <span v-else-if="t.overnight !== null">
                                <b class="text-[22px] font-semibold tracking-[-0.02em] tabular">{{ formatMoney(t.overnight, 'tile') }}</b>
                                <span class="ms-1 text-[12.5px] text-muted">overnight</span>
                            </span>
                            <span v-if="t.price && t.overnight !== null" class="text-[12.5px] text-muted tabular">Overnight {{ formatMoney(t.overnight, 'tile') }}</span>
                        </div>
                    </div>
                </article>
            </div>
            <p v-else class="text-muted" data-test="welcome-no-types">Rates will be listed here soon. Please ask at the front desk.</p>
        </section>

        <section id="how" class="scroll-mt-4 px-10 pt-14 pb-2 max-[600px]:px-5" aria-labelledby="how-heading">
            <div class="mb-[22px]">
                <h2 id="how-heading" class="text-[30px] leading-[1.15] font-semibold tracking-[-0.03em] text-highlighted">How a stay works</h2>
                <p class="mt-1.5 text-sm text-muted">No booking needed. It takes about two minutes.</p>
            </div>
            <ol class="relative grid grid-cols-3 gap-6 pt-1.5 max-[600px]:grid-cols-1">
                <i class="hm-draw absolute top-[30px] right-6 left-6 h-0.5 bg-primary-line max-[600px]:hidden" aria-hidden="true" />
                <li v-for="s in steps" :key="s.title" class="relative">
                    <span class="mb-4 grid size-12 place-items-center rounded-[14px] border border-default bg-default shadow-xs">
                        <UIcon :name="s.icon" class="size-[22px] text-primary" aria-hidden="true" />
                    </span>
                    <h3 class="mb-1 text-[15px] font-semibold text-highlighted">{{ s.title }}</h3>
                    <p class="max-w-[280px] text-[13.5px] text-muted">{{ s.text }}</p>
                </li>
            </ol>
        </section>

        <section id="find-us" class="scroll-mt-4 px-10 pt-14 pb-2 max-[600px]:px-5" aria-labelledby="find-heading">
            <div class="grid grid-cols-[1.1fr_1fr] items-stretch gap-5 max-[900px]:grid-cols-1">
                <div class="min-h-[240px] overflow-hidden rounded-2xl bg-(--hm-photo-fallback)">
                    <img :src="hero[0]?.src" :srcset="hero[0]?.srcset" sizes="(max-width: 900px) 100vw, 50vw" alt="" loading="lazy" class="size-full object-cover" />
                </div>
                <div class="rounded-2xl border border-default p-6">
                    <h2 id="find-heading" class="mb-2 text-2xl leading-[1.15] font-semibold tracking-[-0.03em] text-highlighted">Find us</h2>
                    <div class="flex gap-3 border-b border-muted py-3">
                        <UIcon name="i-lucide-house" class="mt-0.5 size-[18px] shrink-0 text-muted" aria-hidden="true" />
                        <div>
                            <b class="block text-[13.5px] font-semibold">{{ branch?.name ?? shared.app.name }}</b>
                            <span class="text-[13px] text-muted">{{ branch?.address ?? '' }}</span>
                        </div>
                    </div>
                    <div class="flex gap-3 border-b border-muted py-3">
                        <UIcon name="i-lucide-clock" class="mt-0.5 size-[18px] shrink-0 text-muted" aria-hidden="true" />
                        <div>
                            <b class="block text-[13.5px] font-semibold">Open 24 hours, every day</b>
                            <span class="text-[13px] text-muted">The front desk is always staffed</span>
                        </div>
                    </div>
                    <div class="flex gap-3 py-3">
                        <UIcon name="i-lucide-banknote" class="mt-0.5 size-[18px] shrink-0 text-muted" aria-hidden="true" />
                        <div>
                            <b class="block text-[13.5px] font-semibold">{{ payWords }}</b>
                            <span class="text-[13px] text-muted">Paid at the front desk; no online payment</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <footer class="mt-14 flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-default px-10 py-[22px] text-[12.5px] text-muted max-[600px]:px-5">
            <HomiLogo :width="60" :height="18" />
            <span>{{ eyebrow }}</span>
            <UPopover v-if="credits.length">
                <UButton variant="link" color="neutral" size="xs" label="Photos: Unsplash" class="p-0 text-[12.5px] font-normal text-muted" />
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
