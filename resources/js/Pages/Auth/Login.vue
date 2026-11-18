<!-- Mockup 1 — sign in. A branded photo panel on the left (logo, greeting, live shift pill and the
     branch's counts) and a quiet white form on the right led by the 3D Homi mark, matching the front
     page. Each part rises in once, 80 ms apart; all motion stops under reduced motion. Counts only. -->
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useOnline } from '@vueuse/core';
import { computed, ref } from 'vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import PhotoHero from '@/Components/PhotoHero.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useShared } from '@/Composables/useShared';

const props = defineProps<{
    part: 'morning' | 'afternoon' | 'evening';
    now: string;
    photo: { src: string; srcset: string };
    photos?: { src: string; srcset: string }[];
    facts: {
        branch: string;
        available: number;
        in_house: number;
        due_soon: number;
        arriving: number;
        shift: { period_name: string; opened_at: string | null } | null;
    } | null;
}>();

const { flash } = useShared();
const form = useForm({ username: '', password: '' });
const show = ref(false);
const online = useOnline();

const greeting = computed(() => ({ morning: 'Good morning.', afternoon: 'Good afternoon.', evening: 'Good evening.' })[props.part]);
const line = computed(() => {
    const date = formatDateTime(props.now, 'eyebrow');

    return props.facts ? `${props.facts.branch} · ${date}` : date;
});
const shiftPill = computed(() => {
    const s = props.facts?.shift;

    return s ? `${s.period_name} shift open since ${formatDateTime(s.opened_at, 'time')}` : null;
});
const figures = computed(() =>
    props.facts
        ? [
              { n: props.facts.available, label: 'rooms free' },
              { n: props.facts.in_house, label: 'guests in house' },
              { n: props.facts.due_soon, label: 'due out soon' },
              { n: props.facts.arriving, label: 'arriving today' },
          ]
        : [],
);
const year = computed(() => props.now.slice(0, 4));
const slides = computed(() => (props.photos?.length ? props.photos : [props.photo]));

function submit(): void {
    form.post(route('login.store'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <div class="hm-light grid min-h-dvh grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] bg-default font-sans max-[900px]:grid-cols-1">
        <PhotoHero
            :photos="slides"
            :slides="slides.length > 1"
            scrim="signin"
            class="m-3 flex min-h-[calc(100dvh-24px)] flex-col overflow-hidden rounded-[28px] max-[900px]:me-3 max-[900px]:min-h-64"
            data-signin-photo
        >
            <div class="hm-rise flex items-center justify-between gap-3 px-8 pt-7 text-(--hm-card)">
                <HomiLogo variant="white" :width="92" :height="28" />
                <span v-if="shiftPill" class="on-photo-pill inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-[12.5px]" data-shift-pill>
                    <i class="hm-pulse" aria-hidden="true" />{{ shiftPill }}
                </span>
            </div>
            <div class="mt-auto px-8 pb-8 text-(--hm-card)">
                <h2 class="hm-rise mb-3 text-[clamp(2.2rem,4vw,3rem)] leading-[1.03] font-semibold tracking-[-0.035em]" style="--hm-step: 1">{{ greeting }}</h2>
                <p class="hm-rise mb-5 max-w-[400px] text-[15px] text-(--hm-on-photo-body)" style="--hm-step: 2">{{ line }}</p>
                <div
                    v-if="figures.length"
                    class="hm-rise grid grid-cols-4 rounded-2xl bg-default px-5 py-4 text-default shadow-(--hm-shadow-float) max-[900px]:hidden"
                    style="--hm-step: 3"
                    data-test="signin-figures"
                >
                    <div v-for="(f, i) in figures" :key="f.label" :class="i > 0 ? 'border-l border-default ps-4' : ''">
                        <b class="block text-[22px] leading-[1.1] font-semibold tracking-[-0.02em] tabular">{{ f.n }}</b>
                        <span class="text-[11.5px] text-muted">{{ f.label }}</span>
                    </div>
                </div>
            </div>
        </PhotoHero>

        <div class="flex flex-col justify-center px-14 py-10 max-[900px]:px-6 max-[900px]:py-10">
            <div class="mx-auto w-full max-w-[380px]">
                <HomiLogo variant="blue" :width="96" :height="30" class="hm-rise" />
                <h1 class="hm-rise mt-6 text-[28px] leading-[1.15] font-semibold tracking-[-0.025em] text-highlighted" style="--hm-step: 1">Welcome back</h1>
                <p class="hm-rise mt-1.5 text-[13.5px] text-muted" style="--hm-step: 2">Sign in to your branch. Use your own account — never share it.</p>

                <form class="mt-7 flex flex-col" @submit.prevent="submit">
                    <UAlert v-if="flash.error" class="mb-4" color="error" variant="soft" :description="flash.error.message" icon="i-solar-danger-triangle-bold-duotone" />
                    <UFormField label="Username" name="username" :error="form.errors.username" class="hm-rise mb-4" style="--hm-step: 3">
                        <UInput v-model="form.username" size="xl" icon="i-solar-user-bold-duotone" autocomplete="username" autofocus class="w-full" />
                    </UFormField>
                    <UFormField label="Password" name="password" :error="form.errors.password" class="hm-rise mb-4" style="--hm-step: 4">
                        <UInput
                            v-model="form.password"
                            size="xl"
                            icon="i-solar-lock-bold-duotone"
                            :type="show ? 'text' : 'password'"
                            autocomplete="current-password"
                            class="w-full"
                            :ui="{ trailing: 'pe-1.5' }"
                        >
                            <template #trailing>
                                <UButton color="neutral" variant="link" size="sm" :label="show ? 'Hide' : 'Show'" :aria-pressed="show" class="text-muted" @click="show = !show" />
                            </template>
                        </UInput>
                    </UFormField>
                    <div class="hm-rise mt-2" style="--hm-step: 5">
                        <UButton type="submit" size="xl" block :loading="form.processing" class="group rounded-full">
                            Sign in
                            <UIcon v-if="!form.processing" name="i-solar-alt-arrow-right-linear" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true" />
                        </UButton>
                    </div>
                    <p class="hm-rise mt-[18px] text-center text-[12.5px] text-muted" style="--hm-step: 6">Forgot your password? Ask your branch admin.</p>
                </form>

                <div class="hm-rise mt-10 flex flex-wrap items-center justify-between gap-3 border-t border-default pt-5 text-xs text-faint" style="--hm-step: 6">
                    <span>© {{ year }} Homi</span>
                    <span class="inline-flex items-center gap-2" role="status">
                        <i v-if="online" class="hm-pulse" aria-hidden="true" />
                        <UIcon v-else name="i-solar-wi-fi-router-bold-duotone" class="size-3.5 text-bad" aria-hidden="true" />
                        {{ online ? 'Connected' : 'No connection' }}<template v-if="facts"> · {{ facts.branch.replace(/^Hotel\s+/i, '') }}</template>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
