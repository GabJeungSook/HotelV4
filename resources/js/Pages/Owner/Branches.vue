<!-- Mockup 55 -->
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useShared } from '@/Composables/useShared';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';
import type { Tone } from '@/types/ui';

/**
 * Owner — branches (55): each branch with its rooms, staff, the shift open right now and today's
 * sales so far (from the summary table). "Open" enters the branch with admin rights and lands on
 * its dashboard (61); a branch is deactivated, never deleted. After "Create branch" (56) the new
 * admin's temporary password is shown here once.
 */
interface BranchRow {
    id: number;
    name: string;
    address: string;
    code_prefix: string;
    status: 'setting_up' | 'active' | 'inactive';
    status_label: string;
    rooms: number;
    staff: number;
    shift: { period_name: string; status: 'open' | 'closing'; opened_at: string | null } | null;
    business_date: string;
    today_net_sales: number | null;
}

interface Created {
    kind: 'branch' | 'owner' | 'reset';
    branch?: string;
    name: string;
    username: string;
    password: string;
}

const props = defineProps<{ branches: BranchRow[]; opened_id: number | null }>();

const { page } = useShared();
const confirm = useConfirmDialog();
const created = computed(() => (page.flash as { created?: Created } | undefined)?.created ?? null);
const opened = computed(() => props.branches.find((b) => b.id === props.opened_id) ?? null);
const statusTone: Record<BranchRow['status'], Tone> = { active: 'ok', setting_up: 'neutral', inactive: 'neutral' };

function shiftChip(b: BranchRow): { tone: Tone; label: string } {
    if (b.status === 'setting_up') {
        return { tone: 'neutral', label: '—' };
    }

    if (!b.shift) {
        return { tone: 'warn', label: 'No open shift' };
    }

    const at = b.shift.opened_at ? formatDateTime(b.shift.opened_at, 'time') : '';

    return b.shift.status === 'closing' ? { tone: 'warn', label: `${b.shift.period_name} · closing` } : { tone: 'ok', label: `${b.shift.period_name} · ${at}` };
}

function open(b: BranchRow): void {
    router.post(route('owner.branches.open', { branch: b.id }));
}

async function setStatus(b: BranchRow, status: 'active' | 'inactive'): Promise<void> {
    if (status === 'inactive') {
        const ok = await confirm({
            title: `Deactivate ${b.name}?`,
            description: 'Its staff can no longer work in it. Nothing is deleted: its history stays readable, and you can set it back to active.',
            confirmLabel: 'Deactivate',
            cancelLabel: 'Keep active',
            confirmColor: 'error',
        });

        if (!ok) {
            return;
        }
    }

    router.patch(route('owner.branches.update', { branch: b.id }), { status }, { preserveScroll: true });
}

function menu(b: BranchRow) {
    if (b.status === 'active') {
        return [[{ label: 'Deactivate', icon: 'i-solar-forbidden-circle-bold-duotone', color: 'error' as const, onSelect: () => setStatus(b, 'inactive') }]];
    }

    if (b.status === 'inactive') {
        return [[{ label: 'Set back to active', icon: 'i-solar-restart-linear', onSelect: () => setStatus(b, 'active') }]];
    }

    return [];
}
</script>

<template>
    <OwnerLayout current="branches" :title="`Branches · ${branches.length}`">
        <Head title="Branches" />
        <PageHeader>
            <UButton icon="i-solar-add-square-linear" label="Add branch" :to="route('owner.branches.create')" />
        </PageHeader>

        <UAlert
            v-if="created && created.kind === 'branch'"
            color="success"
            variant="subtle"
            icon="i-solar-check-circle-bold-duotone"
            class="mb-4"
            :title="`${created.branch} is added`"
            data-test="branch-created"
        >
            <template #description>
                Give {{ created.name }} this temporary password for <b>{{ created.username }}</b>:
                <span class="font-mono font-semibold text-highlighted select-all">{{ created.password }}</span>. It is shown only once. They sign in with it and set their PIN.
            </template>
        </UAlert>

        <div v-if="opened" class="mb-4 flex flex-wrap items-center gap-3 rounded-card border border-default bg-default px-4 py-3">
            <span class="text-sm">You are working in <b class="text-highlighted">{{ opened.name }}</b>.</span>
            <UButton class="ms-auto" color="neutral" variant="outline" size="sm" label="Back to all branches" @click="router.post(route('owner.branches.close'))" />
        </div>

        <div class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <EmptyState v-if="branches.length === 0" title="No branches yet" text="Add the first branch to start." action-label="Add branch" @action="router.visit(route('owner.branches.create'))" />
            <table v-else class="dtable">
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th class="num">Rooms</th>
                        <th class="num">Staff</th>
                        <th>Shift now</th>
                        <th class="num">Today</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in branches" :key="b.id" :data-branch="b.code_prefix">
                        <td class="!whitespace-normal">
                            <b class="text-highlighted">{{ b.name }}</b>
                            <div class="text-xs text-muted">{{ b.address }}</div>
                        </td>
                        <td class="num">{{ b.rooms }}</td>
                        <td class="num">{{ b.staff }}</td>
                        <td><StatusLabel :tone="shiftChip(b).tone" :label="shiftChip(b).label" /></td>
                        <td class="num">{{ b.today_net_sales === null ? '—' : formatMoney(b.today_net_sales, 'column') }}</td>
                        <td><StatusLabel :tone="statusTone[b.status]" :label="b.status_label" /></td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <UButton color="neutral" variant="outline" size="sm" :label="b.id === opened_id ? 'Opened' : 'Open'" :disabled="b.id === opened_id" @click="open(b)" />
                                <UDropdownMenu v-if="menu(b).length" :items="menu(b)" :content="{ align: 'end' }">
                                    <UButton color="neutral" variant="ghost" size="sm" icon="i-solar-menu-dots-linear" :aria-label="`More for ${b.name}`" />
                                </UDropdownMenu>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-muted">
            "Open" switches the whole app to that branch; the chip in the top bar shows which one you are in. Today's sales are for each branch's current business day.
        </p>
    </OwnerLayout>
</template>
