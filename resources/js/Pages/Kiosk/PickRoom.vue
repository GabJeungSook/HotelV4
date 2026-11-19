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
 * number of whole days (typed, up to the branch's longest stay). The prices are the server's;
 * nothing is sent back but the type, the kind and the quantity. Idle 60 s → welcome (27).
 */
type Kind = 'hourly' | 'long_stay';

interface KioskType {
    id: number;
    name: string;
    description: string | null;
    room: { id: number; number: string; label: string } | null;
    full: boolean;
    options: { hours: number; amount: number }[];
    long_stay: { max_days: number; per_day: number } | null;
}

const props = defineProps<{ types: KioskType[]; chosen: { room_type_id: number | null; kind: Kind; quantity: number | null }; notice?: string | null }>();

const { flash } = useShared();
useKioskIdle(60, () => router.visit(route('kiosk.index')));

const open = computed(() => props.types.filter((t) => !t.full));
const typeId = ref<number | null>(open.value.find((t) => t.id === props.chosen.room_type_id)?.id ?? open.value[0]?.id ?? null);
const type = computed(() => props.types.find((t) => t.id === typeId.value) ?? null);
const longStay = computed(() => type.value?.long_stay ?? null);

const kind = ref<Kind>('hourly');
const quantity = ref<number | null>(null);
const dayCount = ref(1);

function clampDays(n: number): number {
    const max = longStay.value?.max_days ?? 1;

    return Math.min(Math.max(1, Math.round(n || 1)), max);
}

/** Pick a sensible default when the type changes and the current choice no longer fits. */
function reset(): void {
    const t = type.value;

    if (t === null || t.full) {
        quantity.value = null;

        return;
    }

    if (kind.value === 'hourly' && t.options.some((o) => o.hours === quantity.value)) {
        return;
    }

    if (kind.value === 'long_stay' && t.long_stay && quantity.value !== null && quantity.value >= 1 && quantity.value <= t.long_stay.max_days) {
        return;
    }

    if (t.options.length) {
        kind.value = 'hourly';
        quantity.value = t.options[0].hours;
    } else if (t.long_stay) {
        kind.value = 'long_stay';
        dayCount.value = clampDays(dayCount.value);
        quantity.value = dayCount.value;
    } else {
        quantity.value = null;
    }
}

watch(typeId, reset, { immediate: true });
watch(dayCount, () => {
    dayCount.value = clampDays(dayCount.value);

    if (kind.value === 'long_stay') {
        quantity.value = dayCount.value;
    }
});

// Restore the choice carried back from 29.
if (props.chosen.quantity !== null) {
    const t = type.value;

    if (props.chosen.kind === 'long_stay' && t?.long_stay && props.chosen.quantity >= 1 && props.chosen.quantity <= t.long_stay.max_days) {
        kind.value = 'long_stay';
        dayCount.value = props.chosen.quantity;
        quantity.value = props.chosen.quantity;
    } else if (props.chosen.kind === 'hourly' && t?.options.some((o) => o.hours === props.chosen.quantity)) {
        kind.value = 'hourly';
        quantity.value = props.chosen.quantity;
    }
}

function chooseHours(h: number | null): void {
    if (h !== null) {
        kind.value = 'hourly';
        quantity.value = h;
    }
}

function chooseDays(n = dayCount.value): void {
    dayCount.value = clampDays(n);
    kind.value = 'long_stay';
    quantity.value = dayCount.value;
}

const hourTiles = computed(() => (type.value?.options ?? []).map((o) => ({ value: o.hours, label: `${o.hours} hours`, sub: formatMoney(o.amount, 'tile') })));
const fromPrice = (t: KioskType) => {
    const amounts = [...t.options.map((o) => o.amount), ...(t.long_stay ? [t.long_stay.per_day] : [])];

    return amounts.length ? formatMoney(Math.min(...amounts), 'tile') : null;
};
const selectedAmount = computed(() => {
    if (type.value === null || quantity.value === null) {
        return null;
    }

    return kind.value === 'long_stay' ? (longStay.value?.per_day ?? 0) * quantity.value : (type.value.options.find((o) => o.hours === quantity.value)?.amount ?? null);
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
                @update:model-value="(v) => chooseHours(v as number | null)"
            />

            <div
                v-if="longStay"
                class="mt-4 flex items-center justify-between gap-5 rounded-card border px-7 py-6 transition-colors"
                :class="kind === 'long_stay' ? 'border-select-line bg-select' : 'border-default bg-default'"
                data-test="kiosk-days"
            >
                <div>
                    <div class="text-2xl font-semibold text-highlighted">Stay whole days</div>
                    <div class="mt-0.5 text-lg text-muted">Up to {{ longStay.max_days }} days · {{ formatMoney(longStay.per_day, 'tile') }} per day</div>
                </div>
                <div class="flex items-center gap-3">
                    <UButton class="size-14 justify-center" size="xl" color="neutral" variant="outline" icon="i-solar-minus-circle-linear" :disabled="dayCount <= 1" aria-label="Fewer days" @click="chooseDays(dayCount - 1)" />
                    <UInput
                        :model-value="dayCount"
                        type="number"
                        inputmode="numeric"
                        :min="1"
                        :max="longStay.max_days"
                        class="kiosk w-24"
                        :ui="{ base: 'h-14 text-center text-[28px]' }"
                        aria-label="Number of days"
                        @focus="chooseDays()"
                        @update:model-value="(v) => chooseDays(Number(v))"
                    />
                    <UButton class="size-14 justify-center" size="xl" color="neutral" variant="outline" icon="i-solar-add-circle-linear" :disabled="dayCount >= longStay.max_days" aria-label="More days" @click="chooseDays(dayCount + 1)" />
                </div>
            </div>
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
