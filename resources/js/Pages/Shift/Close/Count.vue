<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import { formatMoney } from '@/Composables/useMoney';

/**
 * Close step 2 — blind count (23). How the drawer is counted is a branch setting
 * (denomination_count_enabled): by denomination (type how many of each bill and the coins total;
 * the pieces are saved as a draft so a reload keeps them, and the server adds them up), or by a
 * single counted total (faster — the front desk counts the cash and types the one number). The sum
 * shown here is for the person counting; the server works the counted total out itself. Nothing
 * about the expected cash is on this page.
 */
export interface CountForm {
    by_denomination: boolean;
    bills: number[];
    pieces: Record<string, number> | null;
    counted_by: string;
    witnesses: { id: number; name: string }[];
    default_witness: number | null;
}

const props = defineProps<{ form: CountForm }>();

const pieces = reactive<Record<string, number>>(Object.fromEntries([...props.form.bills.map((b) => [String(b), props.form.pieces?.[String(b)] ?? 0]), ['coins', props.form.pieces?.coins ?? 0]]));
const total = ref(0);
const witness = ref<number | null>(props.form.default_witness);
const draft = ref<'idle' | 'saving' | 'saved' | 'failed'>(props.form.pieces ? 'saved' : 'idle');
const submit = useForm({ step: 'count', pieces: {} as Record<string, number>, counted: null as number | null, witness_id: null as number | null });
const notYet = useForm({ step: 'not_yet' });

const witnessItems = computed(() => [{ label: 'No witness', value: null as number | null }, ...props.form.witnesses.map((w) => ({ label: w.name, value: w.id as number | null }))]);
// Display only: the server works the total out again.
const counted = computed(() => props.form.bills.reduce((sum, bill) => sum + bill * (pieces[String(bill)] || 0), 0) + (pieces.coins || 0));
const ready = computed(() => props.form.by_denomination || total.value > 0);
const countHint = computed(() =>
    props.form.by_denomination
        ? 'Count what is in the drawer and type the pieces. You will see the expected amount after you confirm.'
        : 'Count all the cash in the drawer and type the total. You will see the expected amount after you confirm.',
);

function xsrf(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

const saveDraft = useDebounceFn(async () => {
    draft.value = 'saving';

    try {
        const response = await fetch(route('shift.close.draft'), {
            method: 'PUT',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ pieces: { ...pieces } }),
        });
        draft.value = response.ok ? 'saved' : 'failed';
    } catch {
        draft.value = 'failed';
    }
}, 1000);

function setPieces(bill: number, value: string | number): void {
    const n = typeof value === 'number' ? value : parseInt(String(value).replace(/\D/g, ''), 10);
    pieces[String(bill)] = Number.isFinite(n) && n > 0 ? Math.min(n, 99999) : 0;
    saveDraft();
}

function setCoins(value: number): void {
    pieces.coins = value;
    saveDraft();
}

function confirmCount(): void {
    submit.witness_id = witness.value;

    if (props.form.by_denomination) {
        submit.pieces = { ...pieces };
        submit.counted = null;
    } else {
        submit.pieces = {};
        submit.counted = total.value;
    }

    submit.post(route('shift.close.store'), { preserveScroll: true });
}

const draftText = computed(() => ({ idle: 'The pieces are saved as you type.', saving: 'Saving the pieces…', saved: 'Pieces saved. A reload keeps them.', failed: 'Could not save the pieces. Keep typing; it retries.' })[draft.value]);
</script>

<template>
    <form class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1" @submit.prevent="ready && confirmCount()">
        <section v-if="form.by_denomination" class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <table class="dtable w-full">
                <thead>
                    <tr>
                        <th>Bill / coin</th>
                        <th class="text-right">Pieces</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="bill in form.bills" :key="bill" :data-bill="bill">
                        <td class="align-middle">{{ formatMoney(bill, 'tile') }}</td>
                        <td class="text-right">
                            <UInput
                                :model-value="pieces[String(bill)] || ''"
                                inputmode="numeric"
                                placeholder="0"
                                class="ml-auto w-24"
                                :ui="{ base: 'text-right tabular' }"
                                :aria-label="`Pieces of ${formatMoney(bill, 'tile')}`"
                                @update:model-value="setPieces(bill, $event)"
                            />
                        </td>
                        <td class="r align-middle">{{ formatMoney(bill * (pieces[String(bill)] || 0), 'column') }}</td>
                    </tr>
                    <tr data-bill="coins">
                        <td class="align-middle">Coins (total)</td>
                        <td class="text-right text-muted">—</td>
                        <td class="text-right"><MoneyInput :model-value="pieces.coins" class="ml-auto w-32" name="coins" @update:model-value="setCoins" /></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td class="font-semibold text-highlighted">Counted</td>
                        <td />
                        <td class="num text-right font-semibold text-highlighted" data-counted>{{ formatMoney(counted) }}</td>
                    </tr>
                </tfoot>
            </table>
        </section>
        <section v-else class="rounded-card border border-default bg-default p-5">
            <UFormField label="Counted cash total" name="counted">
                <MoneyInput v-model="total" class="w-full" name="counted" autofocus />
            </UFormField>
            <p class="mt-3 text-[13px] text-muted">Count all the cash in the drawer and type the total here.</p>
        </section>
        <div class="flex flex-col gap-4">
            <UAlert color="neutral" variant="subtle" icon="i-solar-banknote-2-bold-duotone" :description="countHint" />
            <section class="rounded-card border border-default bg-default p-5">
                <MoneyRow label="Counted by" :text="form.counted_by" />
                <UFormField label="Witness" name="witness_id" class="mt-2">
                    <USelect v-model="witness" :items="witnessItems" class="w-full" />
                </UFormField>
                <p v-if="form.by_denomination" class="mt-3 text-xs text-muted" data-draft-state :data-state="draft">{{ draftText }}</p>
            </section>
            <div>
                <UButton size="xl" block label="Confirm count" :disabled="!ready" :loading="submit.processing" data-confirm-count type="submit" />
                <p class="mt-2 text-center text-[13px] text-muted">You cannot change the count after confirming.</p>
                <UButton class="mt-2" block color="neutral" variant="ghost" label="Not yet — reopen the shift" :loading="notYet.processing" @click="notYet.post(route('shift.close.store'))" />
            </div>
        </div>
    </form>
</template>
