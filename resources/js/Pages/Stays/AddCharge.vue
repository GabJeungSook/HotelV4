<!-- Mockup 10 -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import TotalRow from '@/Components/TotalRow.vue';
import { newKey } from '@/Composables/useIdempotencyKey';
import { formatMoney } from '@/Composables/useMoney';

/**
 * Add charge (10): three tabs from the one product list (48), out-of-stock items cannot be added.
 * "Add to folio" leaves the lines unpaid; "Add and take payment now" hands the cart to Take
 * payment (11), which saves the charge and the payment in one transaction.
 */
export interface Product {
    id: number;
    name: string;
    kind: 'food_drink' | 'amenity' | 'damage';
    price: number;
    stock: number | null;
}

const props = defineProps<{ stayId: number; products: Product[] }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ payNow: [charge: { items: Record<string, number>; total: number; key: string }] }>();

const tab = ref<Product['kind']>('food_drink');
const search = ref('');
const qty = ref<Record<number, number>>({});
const error = ref<string | undefined>(undefined);
const form = useForm({ items: {} as Record<string, number>, pay_now: false, idempotency_key: newKey() });

watch(open, (value) => {
    if (value) {
        qty.value = {};
        search.value = '';
        error.value = undefined;
        form.idempotency_key = newKey();
    }
});

const tabs = [
    { value: 'food_drink', label: 'Food & drinks' },
    { value: 'amenity', label: 'Amenities' },
    { value: 'damage', label: 'Damages' },
];
const shown = computed(() => props.products.filter((p) => p.kind === tab.value && p.name.toLowerCase().includes(search.value.trim().toLowerCase())));
const picked = computed(() => props.products.filter((p) => (qty.value[p.id] ?? 0) > 0));
const pieces = computed(() => picked.value.reduce((n, p) => n + (qty.value[p.id] ?? 0), 0));
const total = computed(() => picked.value.reduce((sum, p) => sum + p.price * (qty.value[p.id] ?? 0), 0));
const items = computed(() => Object.fromEntries(picked.value.map((p) => [String(p.id), qty.value[p.id] ?? 0])));

function out(p: Product): boolean {
    return p.stock !== null && p.stock <= 0;
}

function change(p: Product, step: 1 | -1): void {
    const next = Math.max(0, (qty.value[p.id] ?? 0) + step);
    qty.value = { ...qty.value, [p.id]: p.stock === null ? next : Math.min(next, p.stock) };
}

function addToFolio(): void {
    form.transform(() => ({ items: items.value, pay_now: false, idempotency_key: form.idempotency_key })).post(route('stays.charge', props.stayId), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as { error?: { message: string } } | undefined)?.error;
            error.value = e?.message;

            if (!e) {
                open.value = false;
            }
        },
    });
}

function payNow(): void {
    emit('payNow', { items: items.value, total: total.value, key: form.idempotency_key });
    open.value = false;
}
</script>

<template>
    <USlideover v-model:open="open" title="Add charge" :ui="{ content: 'max-w-[420px]' }">
        <template #body>
            <UAlert v-if="error" class="mb-3" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="error" />
            <div role="tablist" class="flex gap-1 rounded-md bg-grey-50 p-1">
                <button
                    v-for="t in tabs"
                    :key="t.value"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.value"
                    class="hm-focus flex-1 cursor-pointer rounded px-2 py-1.5 text-sm"
                    :class="tab === t.value ? 'bg-default font-medium text-highlighted shadow-xs' : 'text-muted'"
                    @click="tab = t.value as Product['kind']"
                >
                    {{ t.label }}
                </button>
            </div>
            <UInput v-model="search" icon="i-lucide-search" placeholder="Search item" class="mt-3 w-full" />
            <table class="dtable mt-3 w-full">
                <tbody>
                    <tr v-for="p in shown" :key="p.id">
                        <td>
                            <span :class="out(p) ? 'text-muted' : 'text-highlighted'">{{ p.name }}</span>
                            <span v-if="out(p)" class="block text-xs font-semibold text-bad">Out of stock</span>
                            <span v-else-if="p.stock !== null" class="block text-xs text-muted">{{ p.stock }} in stock</span>
                        </td>
                        <td class="num text-right" :class="out(p) ? 'text-muted' : ''">{{ formatMoney(p.price, 'column') }}</td>
                        <td class="w-28 text-right">
                            <div class="inline-flex items-center gap-1">
                                <UButton v-if="(qty[p.id] ?? 0) > 0" size="xs" color="neutral" variant="outline" icon="i-lucide-minus" :aria-label="`One less ${p.name}`" @click="change(p, -1)" />
                                <span v-if="(qty[p.id] ?? 0) > 0" class="w-6 text-center tabular">{{ qty[p.id] }}</span>
                                <UButton size="xs" color="neutral" variant="outline" icon="i-lucide-plus" :disabled="out(p) || (p.stock !== null && (qty[p.id] ?? 0) >= p.stock)" :aria-label="`Add ${p.name}`" @click="change(p, 1)" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="shown.length === 0">
                        <td colspan="3" class="py-6 text-center text-sm text-muted">No item matches.</td>
                    </tr>
                </tbody>
            </table>
        </template>
        <template #footer>
            <div class="flex w-full flex-col gap-2">
                <TotalRow :label="`${pieces} ${pieces === 1 ? 'item' : 'items'}`" :amount="total" />
                <UButton block label="Add to folio" :disabled="pieces === 0" :loading="form.processing" @click="addToFolio" />
                <UButton block color="neutral" variant="outline" label="Add and take payment now" :disabled="pieces === 0 || form.processing" @click="payNow" />
            </div>
        </template>
    </USlideover>
</template>
