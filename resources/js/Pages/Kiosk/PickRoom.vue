<!-- Mockup 28 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import KioskFooter from '@/Components/KioskFooter.vue';
import KioskHeader from '@/Components/KioskHeader.vue';
import KioskTypeCard from '@/Components/KioskTypeCard.vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import { useKioskIdle } from '@/Composables/useKioskIdle';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import KioskLayout from '@/Layouts/KioskLayout.vue';

/**
 * Room and stay (28): one room per type, picked on the server by the rotation rule, or "Full".
 * The guest picks a type and either a number of hours or, when the branch allows long stay, a
 * number of whole days. The prices are the server's; nothing is sent back but the type, the kind
 * and the quantity. After a "room just taken" at 29 the kiosk comes back here with a message, and
 * the rotation offers the next room. Idle 60 s → welcome (27).
 */
type Kind = 'hourly' | 'long_stay';

interface KioskType {
    id: number;
    name: string;
    description: string | null;
    room: { id: number; number: string; label: string } | null;
    full: boolean;
    options: { hours: number; amount: number }[];
    day_options: { days: number; amount: number }[];
}

const props = defineProps<{ types: KioskType[]; chosen: { room_type_id: number | null; kind: Kind; quantity: number | null }; notice?: string | null }>();

const { flash } = useShared();
useKioskIdle(60, () => router.visit(route('kiosk.index')));

const open = computed(() => props.types.filter((t) => !t.full));
const typeId = ref<number | null>(open.value.find((t) => t.id === props.chosen.room_type_id)?.id ?? open.value[0]?.id ?? null);
const type = computed(() => props.types.find((t) => t.id === typeId.value) ?? null);

const kind = ref<Kind>('hourly');
const quantity = ref<number | null>(null);

/** The quantities a kind offers for the current type. */
const quantitiesFor = (t: KioskType | null, k: Kind): number[] => (t === null ? [] : k === 'long_stay' ? t.day_options.map((o) => o.days) : t.options.map((o) => o.hours));

/** Pick a sensible default (the previous choice if it still fits, else the first hourly, else the first day). */
function reset(): void {
    const t = type.value;

    if (t === null || t.full) {
        quantity.value = null;

        return;
    }

    if (quantitiesFor(t, kind.value).includes(quantity.value ?? -1)) {
        return;
    }

    if (t.options.length) {
        kind.value = 'hourly';
        quantity.value = t.options[0].hours;
    } else if (t.day_options.length) {
        kind.value = 'long_stay';
        quantity.value = t.day_options[0].days;
    } else {
        quantity.value = null;
    }
}

watch(typeId, reset, { immediate: true });

// Restore the choice carried back from 29.
if (props.chosen.quantity !== null && quantitiesFor(type.value, props.chosen.kind).includes(props.chosen.quantity)) {
    kind.value = props.chosen.kind;
    quantity.value = props.chosen.quantity;
}

function choose(k: Kind, q: number | null): void {
    if (q !== null) {
        kind.value = k;
        quantity.value = q;
    }
}

const hourTiles = computed(() => (type.value?.options ?? []).map((o) => ({ value: o.hours, label: `${o.hours} hours`, sub: formatMoney(o.amount, 'tile') })));
const dayTiles = computed(() => (type.value?.day_options ?? []).map((o) => ({ value: o.days, label: o.days === 1 ? '1 day' : `${o.days} days`, sub: formatMoney(o.amount, 'tile') })));
const fromPrice = (t: KioskType) => {
    const amounts = [...t.options.map((o) => o.amount), ...t.day_options.map((o) => o.amount)];

    return amounts.length ? formatMoney(Math.min(...amounts), 'tile') : null;
};
const selectedAmount = computed(() => {
    const t = type.value;

    if (t === null || quantity.value === null) {
        return null;
    }

    return (kind.value === 'long_stay' ? t.day_options.find((o) => o.days === quantity.value) : t.options.find((o) => o.hours === quantity.value))?.amount ?? null;
});
const stayLabel = computed(() => (quantity.value === null ? '' : kind.value === 'long_stay' ? (quantity.value === 1 ? '1 day' : `${quantity.value} days`) : `${quantity.value} hours`));
const ready = computed(() => type.value !== null && !type.value.full && quantity.value !== null);

function next(): void {
    if (ready.value) {
        router.visit(route('kiosk.details', { room_type: typeId.value, kind: kind.value, quantity: quantity.value }));
    }
}
</script>

<template>
    <KioskLayout title="Choose a room">
        <KioskHeader :current="1" />
        <UAlert v-if="flash.error" class="mt-6 text-xl" color="warning" variant="subtle" icon="i-solar-info-circle-bold-duotone" :description="flash.error.message" data-test="kiosk-taken" />
        <UAlert v-else-if="notice" class="mt-6 text-xl" color="warning" variant="subtle" icon="i-solar-info-circle-bold-duotone" :description="notice" data-test="kiosk-taken" />

        <h1 class="mt-8 text-[40px] leading-[48px] font-semibold text-highlighted">Choose your room</h1>
        <p class="mt-2 text-2xl leading-9 text-muted">One room is ready in each type. Pick one, then choose how long you'll stay.</p>
        <div class="mt-6 flex flex-col gap-4">
            <KioskTypeCard
                v-for="t in types"
                :key="t.id"
                :name="t.name"
                :description="t.description"
                :room="t.room?.label"
                :from-price="fromPrice(t)"
                :full="t.full"
                :selected="t.id === typeId"
                @select="typeId = t.id"
            />
        </div>

        <template v-if="type && !type.full">
            <h2 class="mt-10 text-[32px] leading-10 font-semibold text-highlighted">How long will you stay?</h2>
            <OptionTiles
                v-if="hourTiles.length"
                class="mt-4"
                :model-value="kind === 'hourly' ? quantity : null"
                :options="hourTiles"
                :columns="2"
                size="kiosk"
                label="Hours"
                @update:model-value="(v) => choose('hourly', v as number | null)"
            />
            <template v-if="dayTiles.length">
                <h3 class="mt-7 text-2xl leading-9 font-medium text-muted">Staying a while? Pay by the day</h3>
                <OptionTiles
                    class="mt-4"
                    :model-value="kind === 'long_stay' ? quantity : null"
                    :options="dayTiles"
                    :columns="2"
                    size="kiosk"
                    label="Days"
                    @update:model-value="(v) => choose('long_stay', v as number | null)"
                />
            </template>
        </template>

        <KioskFooter>
            <template v-if="ready && type && selectedAmount !== null" #summary>
                <div class="flex items-center justify-between gap-4 rounded-card border border-default bg-muted px-7 py-5" data-test="kiosk-pick-summary">
                    <span class="text-2xl text-muted">{{ type.name }} · {{ stayLabel }} · Room {{ type.room?.number }}</span>
                    <b class="text-[28px] font-semibold text-highlighted tabular">{{ formatMoney(selectedAmount) }}</b>
                </div>
            </template>
            <template #back>
                <UButton class="h-[72px] justify-center text-2xl" size="xl" block color="neutral" variant="outline" label="Back" @click="router.visit(route('kiosk.index'))" />
            </template>
            <UButton class="h-[72px] justify-center text-2xl" size="xl" block label="Continue" trailing-icon="i-solar-alt-arrow-right-linear" :disabled="!ready" @click="next" />
        </KioskFooter>
    </KioskLayout>
</template>
