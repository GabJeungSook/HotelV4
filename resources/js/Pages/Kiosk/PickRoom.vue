<!-- Mockup 28 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import KioskFooter from '@/Components/KioskFooter.vue';
import KioskTypeCard from '@/Components/KioskTypeCard.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import StepBar from '@/Components/StepBar.vue';
import { useKioskIdle } from '@/Composables/useKioskIdle';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import KioskLayout from '@/Layouts/KioskLayout.vue';

/**
 * Room and hours (28): one room per type, picked on the server by the rotation rule, or "Full".
 * The guest picks a type and the hours; the prices are the server's. Nothing is sent back but
 * the type and the hours. After a "room just taken" at 29 the kiosk comes back here with a
 * message, and the rotation offers the next room. Idle 60 s → welcome (27).
 */
interface KioskType {
    id: number;
    name: string;
    description: string | null;
    room: { id: number; number: string; label: string } | null;
    full: boolean;
    options: { hours: number; amount: number }[];
}

const props = defineProps<{ types: KioskType[]; chosen: { room_type_id: number | null; hours: number | null }; notice?: string | null }>();

const { flash } = useShared();
useKioskIdle(60, () => router.visit(route('kiosk.index')));

const open = computed(() => props.types.filter((t) => !t.full));
const typeId = ref<number | null>(open.value.find((t) => t.id === props.chosen.room_type_id)?.id ?? open.value[0]?.id ?? null);
const type = computed(() => props.types.find((t) => t.id === typeId.value) ?? null);
const hours = ref<number | null>(type.value?.options.find((o) => o.hours === props.chosen.hours)?.hours ?? type.value?.options[0]?.hours ?? null);

watch(typeId, () => {
    if (!type.value?.options.some((o) => o.hours === hours.value)) {
        hours.value = type.value?.options[0]?.hours ?? null;
    }
});

const tiles = computed(() => (type.value?.options ?? []).map((o) => ({ value: o.hours, label: `${o.hours} hours`, sub: formatMoney(o.amount, 'tile') })));
const ready = computed(() => type.value !== null && !type.value.full && hours.value !== null);

function next(): void {
    if (ready.value) {
        router.visit(route('kiosk.details', { room_type: typeId.value, hours: hours.value }));
    }
}
</script>

<template>
    <KioskLayout title="Choose a room">
        <StepBar :steps="['Room', 'Your name', 'Pay at desk']" :current="1" size="kiosk" />
        <UAlert v-if="flash.error" class="mt-6 text-xl" color="warning" variant="subtle" icon="i-lucide-info" :description="flash.error.message" data-test="kiosk-taken" />
        <UAlert v-else-if="notice" class="mt-6 text-xl" color="warning" variant="subtle" icon="i-lucide-info" :description="notice" data-test="kiosk-taken" />

        <h1 class="mt-8 text-[40px] leading-[48px] font-semibold text-highlighted">Choose a room type</h1>
        <div class="mt-6 flex flex-col gap-4">
            <KioskTypeCard
                v-for="t in types"
                :key="t.id"
                :name="t.name"
                :description="t.description"
                :room="t.room?.label"
                :full="t.full"
                :selected="t.id === typeId"
                @select="typeId = t.id"
            />
        </div>

        <template v-if="type && !type.full">
            <h2 class="mt-10 text-[32px] leading-10 font-semibold text-highlighted">How long will you stay?</h2>
            <OptionTiles v-model="hours" class="mt-4" :options="tiles" :columns="2" size="kiosk" label="Hours" />
        </template>

        <KioskFooter>
            <template #back>
                <UButton class="h-[72px] justify-center text-2xl" size="xl" block color="neutral" variant="outline" label="Back" @click="router.visit(route('kiosk.index'))" />
            </template>
            <UButton class="h-[72px] justify-center text-2xl" size="xl" block label="Next" :disabled="!ready" @click="next" />
        </KioskFooter>
    </KioskLayout>
</template>
