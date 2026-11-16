<!-- Mockup 49 -->
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import ApprovalPin, { type Approver } from '@/Components/ApprovalPin.vue';
import EmptyState from '@/Components/EmptyState.vue';
import MoneyInput from '@/Components/MoneyInput.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Needs attention (49): every row of AttentionList for the branch, each with the button that
 * opens the normal screen for that task. "Force close" asks for a count (the count table of 23)
 * and a PIN (14); "Reset task" asks to confirm. Nothing is fixed automatically and there is no
 * bulk fix.
 */
interface Row {
    kind: string;
    severity: 'high' | 'normal';
    label: string;
    title: string;
    detail: string;
    since: string | null;
    since_text: string;
    route: string;
    action: { label: string; type: 'link' | 'force_close' | 'reset'; id: number | null };
}

const props = defineProps<{ rows: Row[]; at: string; approvers: Approver[]; bills: number[] }>();

const confirm = useConfirmDialog();
const page = usePage();

/* ---- reset ---- */
async function reset(row: Row): Promise<void> {
    const ok = await confirm({
        title: 'Reset this cleaning?',
        description: `${row.detail}. The task is set aside and the room goes back to the queue as "to clean". Who reset it is recorded.`,
        confirmLabel: 'Reset task',
        cancelLabel: 'Keep it',
    });

    if (ok && row.action.id !== null) {
        router.post(route('housekeeping.reset', row.action.id), {}, { preserveScroll: true });
    }
}

/* ---- force close: count, then PIN ---- */
const closing = ref<Row | null>(null);
const pieces = reactive<Record<string, number>>({});
const pinOpen = ref(false);
const pinError = ref<string | undefined>(undefined);
const attempt = ref(0);
const processing = ref(false);

function startForceClose(row: Row): void {
    closing.value = row;
    for (const b of props.bills) pieces[String(b)] = 0;
    pieces.coins = 0;
}

const counted = computed(() => props.bills.reduce((sum, b) => sum + b * (pieces[String(b)] || 0), 0) + (pieces.coins || 0));

function setPieces(bill: number, value: string | number): void {
    const n = typeof value === 'number' ? value : parseInt(String(value).replace(/\D/g, ''), 10);
    pieces[String(bill)] = Number.isFinite(n) && n > 0 ? Math.min(n, 99999) : 0;
}

function approve(payload: { approver_id: number; pin: string }): void {
    if (!closing.value?.action.id) return;

    processing.value = true;
    router.post(
        route('shift.force-close', closing.value.action.id),
        { pieces: { ...pieces }, ...payload },
        {
            preserveScroll: true,
            onSuccess: () => {
                pinOpen.value = false;
                closing.value = null;
            },
            onError: (errors) => {
                pinError.value = errors.pin ?? errors.approver_id ?? Object.values(errors)[0];
                attempt.value++;
            },
            onFinish: () => (processing.value = false),
        },
    );
}

const pinLocked = computed(() => Boolean((page.props as { pinLocked?: boolean }).pinLocked));
const tone = (row: Row) => (row.severity === 'high' ? 'bad' : 'warn');
</script>

<template>
    <Head title="Needs attention" />
    <BackOfficeLayout current="attention" :title="`Needs attention · ${rows.length}`">
        <PageHeader>
            <template #status><StatusLabel :label="formatDateTime(at, 'dayTime')" /></template>
        </PageHeader>

        <EmptyState v-if="rows.length === 0" icon="i-solar-check-circle-linear" title="All good." text="Nothing needs attention right now." />

        <section v-else class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <table class="dtable" data-attention>
                <thead>
                    <tr>
                        <th scope="col">What</th>
                        <th scope="col">Details</th>
                        <th scope="col">Since</th>
                        <th scope="col"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in rows" :key="`${row.kind}-${i}`" :data-kind="row.kind">
                        <td><StatusLabel :tone="tone(row)" :label="row.label" /></td>
                        <td class="whitespace-normal">
                            <b class="block font-semibold text-highlighted">{{ row.title }}</b>
                            <small class="text-xs text-muted">{{ row.detail }}</small>
                        </td>
                        <td class="tabular">{{ row.since_text }}</td>
                        <td class="r">
                            <UButton v-if="row.action.type === 'force_close'" color="neutral" variant="outline" size="sm" :label="row.action.label" @click="startForceClose(row)" />
                            <UButton v-else-if="row.action.type === 'reset'" color="neutral" variant="outline" size="sm" :label="row.action.label" @click="reset(row)" />
                            <UButton v-else color="neutral" variant="outline" size="sm" :label="row.action.label" :as="Link" :href="row.route" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <p class="mt-2 text-xs text-muted">Nothing here is fixed automatically. Each button opens the normal screen for that task and records who acted.</p>

        <USlideover :open="closing !== null" title="Force close · count the drawer" :description="closing?.detail" @update:open="(v: boolean) => !v && (closing = null)">
            <template #body>
                <p class="text-sm text-muted">Count the cash in the drawer as it is now. The shift is closed with this count and marked as force-closed.</p>
                <table class="dtable mt-3">
                    <thead>
                        <tr>
                            <th scope="col">Bill</th>
                            <th scope="col" class="r">Pieces</th>
                            <th scope="col" class="r">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in bills" :key="b">
                            <td>{{ formatMoney(b, 'whole') }}</td>
                            <td class="r">
                                <UInput :model-value="String(pieces[String(b)] || '')" inputmode="numeric" class="w-24" :aria-label="`${formatMoney(b, 'whole')} pieces`" @update:model-value="(v: string | number) => setPieces(b, v)" />
                            </td>
                            <td class="r">{{ formatMoney(b * (pieces[String(b)] || 0), 'column') }}</td>
                        </tr>
                        <tr>
                            <td>Coins</td>
                            <td class="r" colspan="2"><MoneyInput v-model="pieces.coins" aria-label="Coins total" /></td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-3"><MoneyRow label="Counted" :amount="counted" tone="primary" /></div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="outline" label="Not now" @click="closing = null" />
                    <UButton label="Next: approve" @click="pinOpen = true" />
                </div>
            </template>
        </USlideover>

        <ApprovalPin
            v-model:open="pinOpen"
            :summary="`Force close · ${closing?.title ?? ''} · counted ${formatMoney(counted)}`"
            :approvers="approvers"
            :error="pinError"
            :locked="pinLocked"
            :processing="processing"
            :attempt="attempt"
            @approve="approve"
        />
    </BackOfficeLayout>
</template>
