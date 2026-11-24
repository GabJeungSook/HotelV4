<!-- Mockup 48 -->
<script setup lang="ts">
import type { UseFormSubmitOptions } from '@inertiajs/core';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import { formatMoney } from '@/Composables/useMoney';
import type { FlashData } from '@/Composables/useShared';
import AdminLayout from '@/Layouts/AdminLayout.vue';

/**
 * Products (48, DATABASE_SCHEMA §4.13): one list for everything charged besides the room — food
 * and drinks, amenities and damages — on kind tabs. Each has a price, a category, the Z-report
 * column it counts in, and whether stock is counted. A price change is logged with who made it;
 * stock on hand moves only through the stock screen (20).
 */
type Kind = 'food_drink' | 'amenity' | 'damage';

interface RecipeLine {
    ingredient_id: number;
    quantity: number;
}

interface ProductRow {
    id: number;
    name: string;
    kind: Kind;
    category: string;
    report_group: 'foods' | 'drinks' | 'misc';
    outlet: string;
    price_amount: number;
    sellable: boolean;
    tracks_stock: boolean;
    stock_on_hand: number;
    low_stock_at: number;
    is_active: boolean;
    ingredients: RecipeLine[];
}

const props = defineProps<{
    kind: Kind;
    counts: Record<Kind, number>;
    products: ProductRow[];
    categories: string[];
    stock_products: { id: number; name: string }[];
}>();

const kind = ref<Kind>(props.kind);
watch(kind, (k) => router.get(route('admin.products'), k === 'food_drink' ? {} : { kind: k }, { preserveState: true, preserveScroll: true, replace: true }));

const total = computed(() => props.counts.food_drink + props.counts.amenity + props.counts.damage);
const groupLabel = { foods: 'Foods', drinks: 'Drinks', misc: 'Miscellaneous' } as const;
const defaultGroup = (k: Kind): ProductRow['report_group'] => (k === 'food_drink' ? 'drinks' : 'misc');

const formError = ref<string | undefined>(undefined);

function done(close: () => void): UseFormSubmitOptions {
    formError.value = undefined;

    return {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const e = (page.flash as FlashData | undefined)?.error;

            if (e) {
                formError.value = e.message;

                return;
            }

            close();
        },
    };
}

const open = ref(false);
const editing = ref<ProductRow | null>(null);
const isMeal = ref(false);
const form = useForm({
    name: '',
    kind: 'food_drink' as Kind,
    category: '',
    report_group: 'drinks' as ProductRow['report_group'],
    outlet: 'frontdesk',
    price_amount: 0,
    sellable: true,
    tracks_stock: true,
    low_stock_at: 0,
    is_active: true,
    ingredients: [] as { ingredient_id: number | null; quantity: number }[],
});

// Stock items that can be an ingredient of the product being edited (not itself).
const ingredientOptions = computed(() => props.stock_products.filter((p) => p.id !== editing.value?.id).map((p) => ({ label: p.name, value: p.id })));

watch(isMeal, (on) => {
    if (on && form.ingredients.length === 0) {
        form.ingredients.push({ ingredient_id: null, quantity: 1 });
    }
});

function edit(row: ProductRow | null): void {
    editing.value = row;
    formError.value = undefined;
    form.clearErrors();
    form.name = row?.name ?? '';
    form.kind = row?.kind ?? kind.value;
    form.category = row?.category ?? '';
    form.report_group = row?.report_group ?? defaultGroup(kind.value);
    form.outlet = row?.outlet ?? 'frontdesk';
    form.price_amount = row?.price_amount ?? 0;
    form.sellable = row?.sellable ?? true;
    form.tracks_stock = row?.tracks_stock ?? kind.value === 'food_drink';
    form.low_stock_at = row?.low_stock_at ?? 0;
    form.is_active = row?.is_active ?? true;
    form.ingredients = (row?.ingredients ?? []).map((r) => ({ ingredient_id: r.ingredient_id, quantity: r.quantity }));
    isMeal.value = (row?.ingredients.length ?? 0) > 0;
    open.value = true;
}

const recipe = computed(() => form.ingredients.filter((r): r is { ingredient_id: number; quantity: number } => r.ingredient_id !== null));

function save(): void {
    const row = editing.value;
    form.transform((data) => ({
        ...data,
        sellable: isMeal.value ? true : data.sellable,
        tracks_stock: isMeal.value ? false : data.tracks_stock,
        ingredients: isMeal.value ? recipe.value : [],
    })).submit(row ? 'put' : 'post', row ? route('admin.products.update', row.id) : route('admin.products.store'), done(() => (open.value = false)));
}

const priceChanged = computed(() => editing.value !== null && form.price_amount !== editing.value.price_amount);
const valid = computed(() => {
    if (form.name.trim() === '' || form.category.trim() === '') {
        return false;
    }

    if (isMeal.value) {
        const ids = recipe.value.map((r) => r.ingredient_id);

        return form.price_amount > 0 && ids.length >= 1 && new Set(ids).size === ids.length && recipe.value.every((r) => r.quantity >= 1);
    }

    return !form.sellable || form.price_amount > 0;
});
</script>

<template>
    <AdminLayout current="products" :title="`Products · ${total}`">
        <Head title="Products" />
        <PageHeader>
            <UButton icon="i-solar-add-square-linear" label="Add product" @click="edit(null)" />
        </PageHeader>

        <div class="mb-4">
            <PeriodSegments
                v-model="kind"
                label="Kind"
                :items="[
                    { value: 'food_drink', label: `Food & drinks ${counts.food_drink}` },
                    { value: 'amenity', label: `Amenities ${counts.amenity}` },
                    { value: 'damage', label: `Damages ${counts.damage}` },
                ]"
            />
        </div>

        <section class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <EmptyState v-if="products.length === 0" icon="i-solar-box-bold-duotone" title="No products of this kind yet" text="Add the first one." />
            <table v-else class="dtable w-full">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="text-right">Price</th>
                        <th>Tracks stock</th>
                        <th class="text-right">Low at</th>
                        <th>Active</th>
                        <th><span class="sr-only">Edit</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in products" :key="p.id" :data-product="p.name" :class="p.is_active ? '' : 'text-dimmed'">
                        <td class="text-highlighted">{{ p.name }}</td>
                        <td><span class="font-medium text-info">{{ p.category }}</span></td>
                        <td class="num text-right">{{ formatMoney(p.price_amount, 'column') }}</td>
                        <td><span :class="p.tracks_stock ? 'font-medium text-ok' : 'text-muted'">{{ p.tracks_stock ? 'Yes' : 'No' }}</span></td>
                        <td class="num text-right">{{ p.tracks_stock ? p.low_stock_at : '—' }}</td>
                        <td><span :class="p.is_active ? 'font-medium text-ok' : 'font-medium text-bad'">{{ p.is_active ? 'Yes' : 'No' }}</span></td>
                        <td class="text-right"><UButton color="neutral" variant="ghost" size="sm" icon="i-solar-pen-2-linear" label="Edit" @click="edit(p)" /></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <UModal v-model:open="open" :title="editing ? editing.name : 'Add product'" :ui="{ content: 'max-w-[460px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Name" :error="form.errors.name"><UInput v-model="form.name" maxlength="60" class="w-full" autofocus /></UFormField>
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Kind" :error="form.errors.kind">
                            <USelect
                                v-model="form.kind"
                                :items="[
                                    { label: 'Food & drinks', value: 'food_drink' },
                                    { label: 'Amenity', value: 'amenity' },
                                    { label: 'Damage', value: 'damage' },
                                ]"
                                class="w-full"
                                @update:model-value="(k) => !editing && (form.report_group = defaultGroup(k as Kind))"
                            />
                        </UFormField>
                        <UFormField label="Category" :error="form.errors.category">
                            <UInputMenu v-model="form.category" :items="categories" create-item class="w-full" placeholder="Drinks" @create="(c: string) => (form.category = c)" />
                        </UFormField>
                        <UFormField label="Price" :error="form.errors.price_amount" :help="priceChanged ? `Was ${formatMoney(editing!.price_amount)}. The change is logged.` : undefined">
                            <MoneyInput v-model="form.price_amount" />
                        </UFormField>
                        <UFormField label="Z-report column" :error="form.errors.report_group">
                            <USelect v-model="form.report_group" :items="Object.entries(groupLabel).map(([value, label]) => ({ value, label }))" class="w-full" />
                        </UFormField>
                    </div>
                    <USwitch v-model="form.sellable" label="Sold at the counter" description="Turn off for a supply (spoon, plate) that is only used inside a meal, never sold on its own." />
                    <USwitch v-if="form.kind === 'food_drink'" v-model="isMeal" label="Made from ingredients" description="A meal keeps no stock of its own; selling it uses up its ingredients." />

                    <div v-if="isMeal" class="rounded-card border border-default bg-muted p-3">
                        <p class="mb-2 text-xs font-medium text-muted">Used up per one {{ form.name.trim() || 'meal' }}</p>
                        <div class="flex flex-col gap-2">
                            <div v-for="(line, i) in form.ingredients" :key="i" class="flex items-center gap-2">
                                <USelect v-model="line.ingredient_id" :items="ingredientOptions" placeholder="Pick a stock item" class="flex-1" />
                                <UInput v-model.number="line.quantity" type="number" inputmode="numeric" :min="1" class="w-20" aria-label="Quantity" />
                                <UButton icon="i-solar-trash-bin-trash-linear" color="neutral" variant="ghost" :aria-label="`Remove ingredient ${i + 1}`" @click="form.ingredients.splice(i, 1)" />
                            </div>
                        </div>
                        <UButton class="mt-2" size="sm" color="neutral" variant="outline" icon="i-solar-add-circle-linear" label="Add ingredient" @click="form.ingredients.push({ ingredient_id: null, quantity: 1 })" />
                        <p v-if="form.errors.ingredients" class="mt-1 text-xs text-bad">{{ form.errors.ingredients }}</p>
                    </div>

                    <USwitch v-if="!isMeal" v-model="form.tracks_stock" label="Count stock" :description="editing && form.tracks_stock ? `${editing.stock_on_hand} on hand. Stock moves on the Stock screen.` : undefined" />
                    <UFormField v-if="isMeal || form.tracks_stock" :label="isMeal ? 'Low when fewer than this can be made' : 'Low-stock warning at'" :error="form.errors.low_stock_at">
                        <UInput v-model.number="form.low_stock_at" type="number" inputmode="numeric" :min="0" class="w-28" />
                    </UFormField>
                    <USwitch v-model="form.is_active" label="On sale" />
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="open = false" />
                    <UButton :label="editing ? 'Save product' : 'Add product'" :disabled="!valid" :loading="form.processing" @click="save" />
                </div>
            </template>
        </UModal>
    </AdminLayout>
</template>
