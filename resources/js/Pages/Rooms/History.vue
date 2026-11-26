<!-- Room history (new screen; no mockup frame). All-time timeline of a room: stays (with their
     transactions and receipt), cleanings and manual status changes. Reached from the board and
     Admin -> Rooms. Read-only. -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import Slip from '@/Components/Slip.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

interface TxnLine { label: string; amount: number; status: string }
interface Money { label: string; amount: number }
interface Receipt { header: string; lines: Money[]; payments: Money[]; total: number; by: string }
interface StayEntry {
    kind: 'stay';
    time: string | null;
    stay_id: number;
    guest: string;
    code: string;
    option: string;
    source: string;
    state: string;
    checked_in_at: string | null;
    checked_out_at: string | null;
    room_from: string | null;
    room_to: string | null;
    in_by: string | null;
    out_by: string | null;
    transactions: { lines: TxnLine[]; payments: Money[]; deposits: Money[]; charges: number; balance: number };
    receipt: Receipt;
}
interface CleaningEntry {
    kind: 'cleaning';
    time: string | null;
    status: string;
    trigger: string;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    is_late: boolean;
    result: string | null;
    problem_note: string | null;
    cleaned_by: string | null;
}
interface StatusEntry { kind: 'status'; time: string | null; from: string; to: string; by: string | null }
type Entry = StayEntry | CleaningEntry | StatusEntry;

const props = defineProps<{
    room: { id: number; number: string; type: string; status: string; statusLabel: string };
    page: { entries: Entry[]; total: number };
    offset: number;
    perPage: number;
    filters: { type: string; q: string };
}>();

const { props: shared } = useShared();
const isAdmin = computed(() => ['admin', 'owner'].includes(shared.value.auth?.role ?? ''));
const Layout = computed(() => (isAdmin.value ? AdminLayout : FrontDeskLayout));

const entries = ref<Entry[]>([...props.page.entries]);
const total = ref(props.page.total);
const loadingMore = ref(false);
const filtering = ref(false);
const expanded = ref<Set<number>>(new Set());
const receipt = ref<StayEntry | null>(null);

// Filters (server-side, so they search the whole history, not just the loaded page).
const q = ref(props.filters.q);
const type = ref(props.filters.type || 'all');
const typeItems = [
    { value: 'all', label: 'All' },
    { value: 'stay', label: 'Stays' },
    { value: 'cleaning', label: 'Cleaning' },
    { value: 'status', label: 'Status' },
];

function query(offset: number): Record<string, string | number | undefined> {
    return {
        offset,
        type: type.value === 'all' ? undefined : type.value,
        q: q.value.trim() === '' ? undefined : q.value.trim(),
    };
}

function applyFilters(): void {
    filtering.value = true;
    expanded.value = new Set();
    router.reload({
        only: ['page'],
        data: query(0),
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            entries.value = [...props.page.entries];
            total.value = props.page.total;
        },
        onFinish: () => (filtering.value = false),
    });
}

watchDebounced([q, type], applyFilters, { debounce: 300 });

const statusTone = { available: 'ok', occupied: 'info', uncleaned: 'warn', cleaning: 'info', maintenance: 'bad' } as const;
const roomTone = computed(() => statusTone[props.room.status as keyof typeof statusTone] ?? 'neutral');

/** Entries grouped under a date heading, newest first (order is already set by the server). */
const groups = computed(() => {
    const out: { day: string; items: Entry[] }[] = [];
    for (const e of entries.value) {
        const day = e.time ? formatDateTime(e.time, 'date') : 'Earlier';
        const last = out[out.length - 1];
        if (!last || last.day !== day) {
            out.push({ day, items: [e] });
        } else {
            last.items.push(e);
        }
    }
    return out;
});

const hasMore = computed(() => entries.value.length < total.value);

function toggle(id: number): void {
    const next = new Set(expanded.value);
    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
}

function loadMore(): void {
    loadingMore.value = true;
    router.reload({
        only: ['page'],
        data: query(entries.value.length),
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            entries.value.push(...props.page.entries);
            total.value = props.page.total;
        },
        onFinish: () => (loadingMore.value = false),
    });
}

function cleaningMinutes(e: CleaningEntry): number | null {
    if (!e.started_at || !e.finished_at) {
        return null;
    }
    return Math.max(0, Math.round((new Date(e.finished_at).getTime() - new Date(e.started_at).getTime()) / 60000));
}

const receiptSections = computed(() => {
    const e = receipt.value;
    if (!e) {
        return [];
    }
    return [
        [
            { label: 'Stay', value: e.code },
            { label: 'Room', value: props.room.number },
            { label: 'Guest', value: e.guest },
            { label: 'Out', value: formatDateTime(e.checked_out_at, 'slip') },
        ],
        [...e.receipt.lines.map((l) => ({ label: l.label, value: formatMoney(l.amount, 'slip') })), { label: 'TOTAL', value: formatMoney(e.receipt.total, 'slip'), bold: true }],
        e.receipt.payments.map((p) => ({ label: p.label, value: formatMoney(p.amount, 'slip') })),
    ];
});
</script>

<template>
    <component :is="Layout" :current="isAdmin ? 'rooms' : 'board'" :title="`Room ${room.number} · history`">
        <div class="mx-auto max-w-[760px]">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between gap-4 rounded-card border border-default bg-default px-5 py-4">
                <div>
                    <h1 class="text-xl font-semibold text-highlighted tabular">Room {{ room.number }}</h1>
                    <p class="mt-0.5 text-sm text-muted">{{ room.type }}</p>
                </div>
                <div class="flex items-center gap-4">
                    <StatusLabel :tone="roomTone" :label="room.statusLabel" />
                    <UButton color="neutral" variant="ghost" icon="i-solar-arrow-left-linear" :label="isAdmin ? 'Rooms' : 'Board'" @click="router.visit(route(isAdmin ? 'admin.rooms' : 'board'))" />
                </div>
            </div>

            <!-- Filter + search (server-side: searches the whole history) -->
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <UInput v-model="q" icon="i-solar-magnifer-linear" placeholder="Search guest, code or staff" class="w-full sm:w-80" :loading="filtering" />
                <PeriodSegments v-model="type" :items="typeItems" label="Filter history" />
            </div>

            <p v-if="total === 0" class="rounded-card border border-default bg-default py-16 text-center text-sm text-muted" data-test="no-history">
                {{ q.trim() !== '' || type !== 'all' ? 'Nothing matches that filter.' : 'No history for this room yet.' }}
            </p>

            <!-- Timeline -->
            <div v-for="group in groups" :key="group.day" class="mb-6">
                <p class="mb-3 text-xs font-semibold tracking-wide text-muted uppercase">{{ group.day }}</p>
                <div class="flex flex-col gap-3">
                    <template v-for="(e, i) in group.items" :key="group.day + '-' + i">
                        <!-- Stay -->
                        <article v-if="e.kind === 'stay'" class="rounded-card border border-default bg-default">
                            <button type="button" class="hm-focus flex w-full items-start gap-3 px-4 py-3.5 text-left" @click="toggle(e.stay_id)">
                                <UIcon name="i-solar-bed-bold-duotone" class="mt-0.5 size-5 shrink-0 text-primary" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <span class="font-medium text-highlighted">{{ e.guest }}</span>
                                        <span class="text-sm text-muted">· {{ e.option }} · {{ e.code }}</span>
                                    </div>
                                    <p class="mt-0.5 text-[13px] text-muted">
                                        {{ e.source }} · in {{ formatDateTime(e.checked_in_at, 'auto') }}<template v-if="e.in_by"> by {{ e.in_by }}</template>
                                        <template v-if="e.checked_out_at"> · out {{ formatDateTime(e.checked_out_at, 'auto') }}<template v-if="e.out_by"> by {{ e.out_by }}</template></template>
                                        <template v-else> · still in house</template>
                                    </p>
                                </div>
                                <UIcon :name="expanded.has(e.stay_id) ? 'i-solar-alt-arrow-up-linear' : 'i-solar-alt-arrow-down-linear'" class="mt-0.5 size-4 shrink-0 text-muted" />
                            </button>

                            <div v-if="expanded.has(e.stay_id)" class="border-t border-default px-4 py-3.5">
                                <p class="mb-2 text-xs font-semibold text-muted uppercase">Transactions</p>
                                <div class="flex flex-col gap-1.5">
                                    <div v-for="(l, li) in e.transactions.lines" :key="li" class="flex items-center justify-between gap-3 text-sm" :class="l.status === 'reversed' ? 'text-dimmed line-through' : ''">
                                        <span class="flex min-w-0 items-center gap-2 text-default">
                                            <span class="truncate">{{ l.label }}</span>
                                            <StatusLabel v-if="l.status === 'unpaid'" tone="warn" label="Unpaid" />
                                        </span>
                                        <span class="num shrink-0 text-default">{{ formatMoney(l.amount) }}</span>
                                    </div>
                                    <MoneyRow v-for="(p, pi) in e.transactions.payments" :key="'p' + pi" :label="p.label" :amount="p.amount" tone="muted" />
                                    <template v-for="(d, di) in e.transactions.deposits" :key="'d' + di">
                                        <MoneyRow v-if="d.amount !== 0" :label="d.label + ' held'" :amount="d.amount" tone="muted" />
                                    </template>
                                    <div class="mt-1 border-t border-default pt-1.5">
                                        <MoneyRow :label="e.transactions.balance > 0 ? 'Balance due' : 'Settled'" :amount="e.transactions.balance" :tone="e.transactions.balance > 0 ? 'bad' : 'ok'" />
                                    </div>
                                </div>
                                <div v-if="e.checked_out_at" class="mt-3">
                                    <UButton size="xs" color="neutral" variant="outline" icon="i-solar-bill-list-bold-duotone" label="View receipt" @click="receipt = e" />
                                </div>
                            </div>
                        </article>

                        <!-- Cleaning -->
                        <article v-else-if="e.kind === 'cleaning'" class="flex items-start gap-3 rounded-card border border-default bg-default px-4 py-3">
                            <UIcon name="i-solar-broom-bold-duotone" class="mt-0.5 size-5 shrink-0 text-info" />
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2">
                                    <span class="font-medium text-highlighted">Cleaning</span>
                                    <StatusLabel v-if="e.result === 'Problem'" tone="bad" label="Problem" />
                                    <StatusLabel v-else-if="e.status === 'Done'" tone="ok" label="Done" />
                                    <StatusLabel v-else tone="warn" :label="e.status" />
                                    <span v-if="e.is_late" class="text-xs font-medium text-bad">late</span>
                                </div>
                                <p class="mt-0.5 text-[13px] text-muted">
                                    after {{ e.trigger.toLowerCase() }}<template v-if="e.cleaned_by"> · {{ e.cleaned_by }}</template><template v-if="cleaningMinutes(e) !== null"> · {{ cleaningMinutes(e) }} min</template><template v-if="e.finished_at"> · finished {{ formatDateTime(e.finished_at, 'time') }}</template>
                                </p>
                                <p v-if="e.problem_note" class="mt-0.5 text-[13px] text-bad">“{{ e.problem_note }}”</p>
                            </div>
                        </article>

                        <!-- Manual status change -->
                        <article v-else class="flex items-start gap-3 rounded-card border border-default bg-default px-4 py-3">
                            <UIcon name="i-solar-settings-bold-duotone" class="mt-0.5 size-5 shrink-0 text-muted" />
                            <div class="min-w-0 flex-1">
                                <p class="text-sm text-default"><span class="font-medium text-highlighted">{{ e.from }} → {{ e.to }}</span><template v-if="e.by"> · by {{ e.by }}</template></p>
                                <p class="mt-0.5 text-[13px] text-muted">{{ formatDateTime(e.time, 'time') }}</p>
                            </div>
                        </article>
                    </template>
                </div>
            </div>

            <div v-if="hasMore" class="mt-2 flex justify-center">
                <UButton color="neutral" variant="outline" label="Load more" :loading="loadingMore" @click="loadMore" />
            </div>
        </div>

        <!-- Receipt -->
        <UModal :open="receipt !== null" :title="`Receipt · Room ${room.number}`" :ui="{ content: 'max-w-[380px]' }" @update:open="(v) => { if (!v) receipt = null; }">
            <template #body>
                <Slip v-if="receipt" :header="receipt.receipt.header" title="RECEIPT" :sections="receiptSections" :footer="[`Front desk: ${receipt.receipt.by}`, 'Thank you for staying with us.']" preview />
            </template>
        </UModal>
    </component>
</template>
