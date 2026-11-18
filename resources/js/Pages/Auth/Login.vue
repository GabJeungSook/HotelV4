<!-- Mockup 1 — sign in. A single centered card on a soft brand-blue field: the Homi mark, a greeting
     for the part of the day, and a quiet form. Each part rises in once, 80 ms apart; all motion stops
     under reduced motion. -->
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { useOnline } from '@vueuse/core';
import { computed, ref } from 'vue';
import HomiLogo from '@/Components/HomiLogo.vue';
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
const year = computed(() => props.now.slice(0, 4));
const place = computed(() => props.facts?.branch.replace(/^Hotel\s+/i, '') ?? null);

function submit(): void {
    form.post(route('login.store'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <div class="hm-light relative grid min-h-dvh place-items-center overflow-hidden bg-gradient-to-br from-homi-50 via-default to-homi-50 p-4 font-sans">
        <span class="pointer-events-none absolute -top-32 -right-24 size-[480px] rounded-full bg-primary/10 blur-3xl" aria-hidden="true" />
        <span class="pointer-events-none absolute -bottom-40 -left-24 size-[520px] rounded-full bg-brand/10 blur-3xl" aria-hidden="true" />

        <div class="relative w-full max-w-[408px]">
            <div class="hm-rise rounded-[28px] border border-default bg-default p-8 shadow-(--hm-shadow-lift) sm:p-10">
                <div class="mb-6 flex justify-center">
                    <HomiLogo variant="blue" :width="112" :height="34" />
                </div>
                <h1 class="text-center text-[26px] leading-[1.2] font-semibold tracking-[-0.025em] text-highlighted">{{ greeting }}</h1>
                <p class="mt-1.5 text-center text-[13.5px] text-muted">Sign in to your branch to continue.</p>

                <form class="mt-7 flex flex-col" @submit.prevent="submit">
                    <UAlert v-if="flash.error" class="mb-4" color="error" variant="soft" :description="flash.error.message" icon="i-solar-danger-triangle-bold-duotone" />
                    <UFormField label="Username" name="username" :error="form.errors.username" class="hm-rise mb-4" style="--hm-step: 1">
                        <UInput v-model="form.username" size="xl" icon="i-solar-user-bold-duotone" autocomplete="username" autofocus class="w-full" />
                    </UFormField>
                    <UFormField label="Password" name="password" :error="form.errors.password" class="hm-rise mb-4" style="--hm-step: 2">
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
                    <div class="hm-rise mt-2" style="--hm-step: 3">
                        <UButton type="submit" size="xl" block :loading="form.processing" class="group rounded-full">
                            Sign in
                            <UIcon v-if="!form.processing" name="i-solar-alt-arrow-right-linear" class="size-4 transition-transform duration-200 group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden="true" />
                        </UButton>
                    </div>
                    <p class="hm-rise mt-[18px] text-center text-[12.5px] text-muted" style="--hm-step: 4">Forgot your password? Ask your branch admin.</p>
                </form>
            </div>

            <div class="hm-rise mt-5 flex items-center justify-center gap-2 text-xs text-faint" style="--hm-step: 5" role="status">
                <span>© {{ year }} Homi</span>
                <span aria-hidden="true">·</span>
                <span class="inline-flex items-center gap-1.5">
                    <i v-if="online" class="hm-pulse" aria-hidden="true" />
                    <UIcon v-else name="i-solar-wi-fi-router-bold-duotone" class="size-3.5 text-bad" aria-hidden="true" />
                    {{ online ? 'Connected' : 'No connection' }}<template v-if="place"> · {{ place }}</template>
                </span>
            </div>
        </div>
    </div>
</template>
