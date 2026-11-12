<!-- Mockup 1 -->
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PhotoHero from '@/Components/PhotoHero.vue';
import SignInPanel from '@/Components/SignInPanel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useShared } from '@/Composables/useShared';

/**
 * Sign in (1): the split screen of DESIGN_SYSTEM §6.7. The photo and greeting follow the part
 * of the day the server sends; on a computer that signed in before, four counts of the
 * remembered branch sit under the greeting. Counts only: no money, no names.
 */
const props = defineProps<{
    part: 'morning' | 'afternoon' | 'evening';
    now: string;
    photo: { src: string; srcset: string };
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

const greeting = computed(() => ({ morning: 'Good morning.', afternoon: 'Good afternoon.', evening: 'Good evening.' })[props.part]);
const eyebrow = computed(() => {
    const date = formatDateTime(props.now, 'eyebrow');

    return props.facts ? `${props.facts.branch} · ${date}` : date;
});
const shiftLine = computed(() => {
    const s = props.facts?.shift;

    if (!props.facts) {
        return null;
    }

    return s ? `The ${s.period_name} shift has been open since ${formatDateTime(s.opened_at, 'time')}.` : 'No shift is open yet.';
});
const figures = computed(() =>
    props.facts
        ? [
              { n: props.facts.available, label: 'rooms free' },
              { n: props.facts.in_house, label: 'in house' },
              { n: props.facts.due_soon, label: 'due out soon' },
              { n: props.facts.arriving, label: 'arriving today' },
          ]
        : [],
);

function submit(): void {
    form.post(route('login.store'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <div class="hm-light grid min-h-dvh grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] bg-default max-[900px]:grid-cols-1">
        <PhotoHero :photos="[photo]" class="flex min-h-dvh flex-col justify-end max-[900px]:min-h-60">
            <div class="p-12 text-on-primary max-[900px]:p-6">
                <p class="hm-up text-xs font-semibold tracking-[0.08em] text-(--hm-on-photo-soft) uppercase">{{ eyebrow }}</p>
                <h1 class="hm-up mt-2 font-serif text-[52px] leading-[1.05] font-normal [animation-delay:0.12s] max-[900px]:text-[36px]">{{ greeting }}</h1>
                <p v-if="shiftLine" class="hm-up mt-3 text-base text-(--hm-on-photo-body) [animation-delay:0.26s] max-[900px]:hidden">{{ shiftLine }}</p>
                <div
                    v-if="figures.length"
                    class="hm-up mt-6 grid grid-cols-4 border-t border-(--hm-on-photo-line) pt-4 [animation-delay:0.4s] max-[900px]:hidden"
                    data-test="signin-figures"
                >
                    <div v-for="(f, i) in figures" :key="f.label" class="px-4 first:ps-0" :class="i > 0 ? 'border-l border-(--hm-on-photo-divider)' : ''">
                        <b class="block text-[28px] leading-9 font-medium tabular">{{ f.n }}</b>
                        <span class="text-[13px] text-(--hm-on-photo-soft)">{{ f.label }}</span>
                    </div>
                </div>
            </div>
        </PhotoHero>
        <SignInPanel>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <UAlert v-if="flash.error" color="error" variant="subtle" :description="flash.error.message" icon="i-lucide-triangle-alert" />
                <UFormField label="Username" name="username" :error="form.errors.username">
                    <UInput v-model="form.username" size="xl" autocomplete="username" autofocus class="w-full" />
                </UFormField>
                <UFormField label="Password" name="password" :error="form.errors.password">
                    <UInput v-model="form.password" size="xl" :type="show ? 'text' : 'password'" autocomplete="current-password" class="w-full" :ui="{ trailing: 'pe-1' }">
                        <template #trailing>
                            <UButton color="neutral" variant="link" size="sm" :label="show ? 'Hide' : 'Show'" :aria-pressed="show" @click="show = !show" />
                        </template>
                    </UInput>
                </UFormField>
                <UButton type="submit" size="xl" block label="Sign in" :loading="form.processing" class="mt-1.5" />
            </form>
            <template #footer>Forgot your password? Ask your branch admin.</template>
        </SignInPanel>
    </div>
</template>
