<!-- Mockup 1 -->
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useOnline } from '@vueuse/core';
import { computed, ref } from 'vue';
import HomiLogo from '@/Components/HomiLogo.vue';
import PhotoHero from '@/Components/PhotoHero.vue';
import SignInPanel from '@/Components/SignInPanel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useShared } from '@/Composables/useShared';

/**
 * Sign in (1, mockup v6): a framed photo panel that cross-fades three photos with a slow drift; on
 * it the white logo, a live "AM shift open since 7:58 AM" pill, the greeting for the part of the
 * day and, on a computer that signed in before, a white card with four counts of that branch.
 * On the right a quiet form. The greeting, the card and each field rise in once, 80 ms apart; all
 * motion stops under reduced motion. Counts only: no money, no names.
 */
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
    <div class="hm-light grid min-h-dvh grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] bg-default font-sans max-[900px]:grid-cols-1">
        <PhotoHero
            :photos="slides"
            :slides="slides.length > 1"
            scrim="signin"
            class="my-3 ms-3 flex min-h-[calc(100dvh-24px)] flex-col rounded-2xl max-[900px]:me-3 max-[900px]:min-h-72"
            data-signin-photo
        >
            <div class="hm-rise flex items-center justify-between gap-3 px-7 pt-[26px] text-[12.5px] text-(--hm-card)">
                <HomiLogo variant="white" :width="78" :height="24" />
                <span v-if="shiftPill" class="on-photo-pill inline-flex items-center gap-2 rounded-full px-3 py-1.5" data-shift-pill>
                    <i class="hm-pulse" aria-hidden="true" />{{ shiftPill }}
                </span>
            </div>
            <div class="mt-auto px-7 pb-7 text-(--hm-card)">
                <h2 class="hm-rise mb-3.5 text-[46px] leading-[1.04] font-semibold tracking-[-0.035em] max-[900px]:text-[34px]" style="--hm-step: 1">{{ greeting }}</h2>
                <p class="hm-rise mb-[18px] max-w-[380px] text-sm text-(--hm-on-photo-body)" style="--hm-step: 2">{{ line }}</p>
                <div
                    v-if="figures.length"
                    class="hm-rise grid grid-cols-4 rounded-[14px] bg-default px-[18px] py-4 text-default shadow-(--hm-shadow-float) max-[900px]:hidden"
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
        <SignInPanel>
            <form class="flex flex-col" @submit.prevent="submit">
                <UAlert v-if="flash.error" class="mb-4" color="error" variant="soft" :description="flash.error.message" icon="i-lucide-triangle-alert" />
                <UFormField label="Username" name="username" :error="form.errors.username" class="hm-rise mb-4" style="--hm-step: 3">
                    <UInput v-model="form.username" size="xl" icon="i-lucide-user" autocomplete="username" autofocus class="w-full" />
                </UFormField>
                <UFormField label="Password" name="password" :error="form.errors.password" class="hm-rise mb-4" style="--hm-step: 4">
                    <UInput
                        v-model="form.password"
                        size="xl"
                        icon="i-lucide-lock"
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
                    <UButton type="submit" size="xl" block :loading="form.processing" class="group">
                        Sign in
                        <UIcon v-if="!form.processing" name="i-lucide-chevron-right" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true" />
                    </UButton>
                </div>
                <p class="hm-rise mt-[18px] text-center text-[12.5px] text-muted" style="--hm-step: 6">Forgot your password? Ask your branch admin.</p>
            </form>
            <template #footer>
                <span>© {{ year }} Homi</span>
                <span class="inline-flex items-center gap-2" role="status">
                    <i v-if="online" class="hm-pulse" aria-hidden="true" />
                    <UIcon v-else name="i-lucide-wifi-off" class="size-3.5 text-bad" aria-hidden="true" />
                    {{ online ? 'Connected' : 'No connection' }}<template v-if="facts"> · this computer is set to {{ facts.branch.replace(/^Hotel\s+/i, '') }}</template>
                </span>
            </template>
        </SignInPanel>
    </div>
</template>
