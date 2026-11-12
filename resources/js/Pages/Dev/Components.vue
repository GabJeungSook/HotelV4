<!-- Mockup: Building blocks, and the cards and charts of 60–63 -->
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import KioskLayout from '@/Layouts/KioskLayout.vue';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';
import PhoneLayout from '@/Layouts/PhoneLayout.vue';
import BuildingBlocks from './Partials/BuildingBlocks.vue';
import Dashboards from './Partials/Dashboards.vue';
import NuxtUi from './Partials/NuxtUi.vue';
import TypeAndFormats from './Partials/TypeAndFormats.vue';

/** Component gallery at /dev/components (local only, ROADMAP phase 1). */
const props = defineProps<{ layout: string; layouts: string[] }>();

const user = { name: 'Jessa Mae Dela Cruz', role: 'Front desk' };
const state = ref<'data' | 'loading' | 'empty'>('data');
const stateItems = [
    { value: 'data', label: 'With data' },
    { value: 'loading', label: 'Loading' },
    { value: 'empty', label: 'Empty' },
];

const shell = computed(
    () =>
        ({
            backoffice: { is: BackOfficeLayout, props: { current: 'dashboard', title: 'Component gallery', branch: 'Dalisay GenSan', user } },
            frontdesk: {
                is: FrontDeskLayout,
                props: { current: 'today', title: 'Component gallery', branch: 'Dalisay GenSan', shift: { label: 'AM shift · Wed 11 Nov', tone: 'ok', closable: true }, user, canSwitch: true },
            },
            admin: { is: AdminLayout, props: { current: 'dashboard', title: 'Component gallery', branch: 'Dalisay GenSan', user } },
            owner: { is: OwnerLayout, props: { current: 'dashboard', title: 'Component gallery', user } },
            kiosk: { is: KioskLayout, props: {} },
            phone: { is: PhoneLayout, props: { title: 'Component gallery', subtitle: 'Liza Gonzaga · 3 rooms waiting', tone: 'primary' } },
        })[props.layout] ?? { is: BackOfficeLayout, props: {} },
);

const sections = [
    { id: 'blocks', label: 'Building blocks' },
    { id: 'nuxt', label: 'Nuxt UI' },
    { id: 'dash', label: 'Dashboards 60–63' },
    { id: 'type', label: 'Type and formats' },
];
</script>

<template>
    <Head title="Component gallery" />
    <component :is="shell.is" v-bind="shell.props">
        <div class="flex flex-col gap-6" :class="layout === 'kiosk' ? 'text-sm leading-5' : ''">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-card border border-default bg-default px-4 py-3">
                <span class="text-sm font-medium text-highlighted">Layout</span>
                <nav aria-label="Layouts" class="flex flex-wrap gap-1">
                    <Link
                        v-for="name in layouts"
                        :key="name"
                        :href="`/dev/components?layout=${name}`"
                        class="hm-focus rounded-md px-2.5 py-1 text-sm"
                        :class="name === layout ? 'bg-nav-active font-medium text-highlighted' : 'text-default hover:bg-elevated'"
                        :aria-current="name === layout ? 'page' : undefined"
                        >{{ name }}</Link
                    >
                </nav>
                <span class="text-sm font-medium text-highlighted lg:ms-auto">Charts and cards</span>
                <PeriodSegments v-model="state" :items="stateItems" label="Chart state" />
            </div>
            <nav aria-label="Gallery sections" class="flex flex-wrap gap-4 text-sm">
                <a v-for="s in sections" :key="s.id" :href="`#${s.id}`" class="hm-focus font-medium text-primary">{{ s.label }}</a>
            </nav>
            <section id="blocks" aria-labelledby="blocks-h">
                <h2 id="blocks-h" class="mb-3 text-[18px] leading-[26px] font-semibold text-highlighted">Building blocks</h2>
                <BuildingBlocks :kiosk="layout === 'kiosk'" :phone="layout === 'phone'" :state="state" />
            </section>
            <section id="nuxt" aria-labelledby="nuxt-h">
                <h2 id="nuxt-h" class="mb-3 text-[18px] leading-[26px] font-semibold text-highlighted">Nuxt UI components we use</h2>
                <NuxtUi />
            </section>
            <section id="dash" aria-labelledby="dash-h">
                <h2 id="dash-h" class="mb-3 text-[18px] leading-[26px] font-semibold text-highlighted">Dashboards, Today and Stats (60–63)</h2>
                <Dashboards :state="state" />
            </section>
            <section id="type" aria-labelledby="type-h">
                <h2 id="type-h" class="mb-3 text-[18px] leading-[26px] font-semibold text-highlighted">Type, peso check and formats</h2>
                <TypeAndFormats />
            </section>
        </div>
    </component>
</template>
