<!-- Mockup 16 -->
<script setup lang="ts">
import { computed, ref } from 'vue';
import OptionTiles from '@/Components/OptionTiles.vue';
import { formatMoney } from '@/Composables/useMoney';

/**
 * Check-out step 1 — items returned (16): the key and the remote, each Yes or No, and other
 * damages from the damage list. Nothing is written yet; each "No" becomes a damage line on save.
 */
defineProps<{
    lost: { key: number; remote: number };
    products: { id: number; name: string; price: number; stock: number | null }[];
}>();

const key = defineModel<boolean | null>('keyReturned', { required: true });
const remote = defineModel<boolean | null>('remoteReturned', { required: true });
const damages = defineModel<Record<string, number>>('damages', { required: true });

const showDamages = ref(Object.keys(damages.value).length > 0);
const yesNo = [
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
];
const keyTile = computed({ get: () => (key.value === null ? null : key.value ? 'yes' : 'no'), set: (v) => (key.value = v === 'yes') });
const remoteTile = computed({ get: () => (remote.value === null ? null : remote.value ? 'yes' : 'no'), set: (v) => (remote.value = v === 'yes') });

function step(id: number, by: 1 | -1): void {
    const next = Math.max(0, (damages.value[String(id)] ?? 0) + by);
    const copy = { ...damages.value };

    if (next === 0) {
        delete copy[String(id)];
    } else {
        copy[String(id)] = next;
    }

    damages.value = copy;
}

</script>

<template>
    <section class="rounded-card border border-default bg-default p-5">
        <table class="dtable w-full">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Returned?</th>
                    <th class="text-right">If not returned</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="inline-flex items-center gap-1.5"><UIcon name="i-solar-key-bold-duotone" class="size-4 text-muted" aria-hidden="true" />Room key</span></td>
                    <td class="w-52"><OptionTiles v-model="keyTile" :options="yesNo" :columns="2" label="Room key returned" /></td>
                    <td class="num text-right">{{ formatMoney(lost.key) }}</td>
                </tr>
                <tr>
                    <td><span class="inline-flex items-center gap-1.5"><UIcon name="i-solar-tv-bold-duotone" class="size-4 text-muted" aria-hidden="true" />TV remote</span></td>
                    <td class="w-52"><OptionTiles v-model="remoteTile" :options="yesNo" :columns="2" label="TV remote returned" /></td>
                    <td class="num text-right">{{ formatMoney(lost.remote) }}</td>
                </tr>
            </tbody>
        </table>
        <div class="mt-4 flex items-center gap-3">
            <UButton color="neutral" variant="outline" icon="i-solar-add-square-linear" label="Add damage charge" @click="showDamages = !showDamages" />
            <span class="text-[13px] text-muted">for broken or missing room items</span>
        </div>
        <table v-if="showDamages" class="dtable mt-3 w-full">
            <tbody>
                <tr v-for="p in products" :key="p.id">
                    <td class="text-highlighted">{{ p.name }}</td>
                    <td class="num text-right">{{ formatMoney(p.price, 'column') }}</td>
                    <td class="w-28 text-right">
                        <div class="inline-flex items-center gap-1">
                            <UButton v-if="damages[String(p.id)]" size="xs" color="neutral" variant="outline" icon="i-solar-minus-circle-linear" :aria-label="`One less ${p.name}`" @click="step(p.id, -1)" />
                            <span v-if="damages[String(p.id)]" class="w-6 text-center tabular">{{ damages[String(p.id)] }}</span>
                            <UButton size="xs" color="neutral" variant="outline" icon="i-solar-add-square-linear" :aria-label="`Add ${p.name}`" @click="step(p.id, 1)" />
                        </div>
                    </td>
                </tr>
                <tr v-if="products.length === 0">
                    <td colspan="3" class="py-4 text-center text-sm text-muted">The branch has no damage items set up.</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
