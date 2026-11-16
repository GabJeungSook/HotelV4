<!-- Mockup 44 -->
<script setup lang="ts">
import type { UseFormSubmitOptions } from '@inertiajs/core';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import type { FlashData } from '@/Composables/useShared';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import type { Tone } from '@/types/ui';

/**
 * Rooms (44, APP_FLOW §5.2): add and edit rooms (number unique in the branch, letters allowed),
 * with floors and room types on their own tabs. Status changes here only between Available and
 * Maintenance, and only for a free room; an occupied or uncleaned room keeps its status.
 */
type Status = 'available' | 'occupied' | 'uncleaned' | 'cleaning' | 'maintenance';
type Tab = 'rooms' | 'floors' | 'types';

interface RoomRow {
    id: number;
    number: string;
    floor_id: number;
    floor: string;
    room_type_id: number;
    type: string;
    area: string;
    status: Status;
    is_kiosk_enabled: boolean;
    is_active: boolean;
    held: boolean;
    status_editable: boolean;
}

interface FloorRow {
    id: number;
    number: number;
    label: string;
    rooms: number;
}

interface TypeRow {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    rooms: number;
}

const props = defineProps<{
    tab: Tab;
    branch_status: string;
    rooms: RoomRow[];
    floors: FloorRow[];
    types: TypeRow[];
    areas: string[];
}>();

const tab = ref<Tab>(props.tab);
watch(tab, (t) => router.get(route('admin.rooms'), t === 'rooms' ? {} : { tab: t }, { preserveState: true, preserveScroll: true, replace: true }));

const statusTone: Record<Status, Tone> = { available: 'ok', occupied: 'info', uncleaned: 'warn', cleaning: 'warn', maintenance: 'neutral' };
const statusLabel: Record<Status, string> = { available: 'Available', occupied: 'Occupied', uncleaned: 'To clean', cleaning: 'Cleaning', maintenance: 'Maintenance' };

const title = computed(() => (tab.value === 'rooms' ? `Rooms · ${props.rooms.length}` : tab.value === 'floors' ? `Floors · ${props.floors.length}` : `Room types · ${props.types.length}`));
const floorItems = computed(() => props.floors.map((f) => ({ label: f.label, value: f.id })));
const typeItems = computed(() => props.types.map((t) => ({ label: t.is_active ? t.name : `${t.name} (off)`, value: t.id })));

const formError = ref<string | undefined>(undefined);

/** Visit options for a dialog's form: a domain refusal comes back as flash.error and stays inline. */
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

// Room ---------------------------------------------------------------------------------------
const roomOpen = ref(false);
const editingRoom = ref<RoomRow | null>(null);
const roomForm = useForm({
    number: '',
    floor_id: null as number | null,
    room_type_id: null as number | null,
    area: 'Main',
    is_kiosk_enabled: true,
    is_active: true,
    status: null as Status | null,
});

function openRoom(row: RoomRow | null): void {
    editingRoom.value = row;
    formError.value = undefined;
    roomForm.clearErrors();
    roomForm.number = row?.number ?? '';
    roomForm.floor_id = row?.floor_id ?? props.floors[0]?.id ?? null;
    roomForm.room_type_id = row?.room_type_id ?? props.types.find((t) => t.is_active)?.id ?? null;
    roomForm.area = row?.area ?? 'Main';
    roomForm.is_kiosk_enabled = row?.is_kiosk_enabled ?? true;
    roomForm.is_active = row?.is_active ?? true;
    roomForm.status = row?.status ?? null;
    roomOpen.value = true;
}

function saveRoom(): void {
    const row = editingRoom.value;
    roomForm.transform((d) => ({ ...d, status: row && row.status_editable ? d.status : null }));
    roomForm.submit(row ? 'put' : 'post', row ? route('admin.rooms.update', row.id) : route('admin.rooms.store'), done(() => (roomOpen.value = false)));
}

const lockedStatus = computed(() => {
    const row = editingRoom.value;

    if (!row || row.status_editable) {
        return null;
    }

    if (row.held) {
        return 'Held for a kiosk guest. The status can change after the hold is confirmed or released.';
    }

    return row.status === 'occupied'
        ? 'A guest is in this room. Its status changes at check-out, transfer or cancel.'
        : 'Waiting for housekeeping. Its status changes when the cleaning is finished.';
});

// Floor --------------------------------------------------------------------------------------
const floorOpen = ref(false);
const editingFloor = ref<FloorRow | null>(null);
const floorForm = useForm({ number: 1, label: '' });

function openFloor(row: FloorRow | null): void {
    editingFloor.value = row;
    formError.value = undefined;
    floorForm.clearErrors();
    const next = Math.max(0, ...props.floors.map((f) => f.number)) + 1;
    floorForm.number = row?.number ?? next;
    floorForm.label = row?.label ?? `${next}F`;
    floorOpen.value = true;
}

function saveFloor(): void {
    const row = editingFloor.value;
    floorForm.submit(row ? 'put' : 'post', row ? route('admin.floors.update', row.id) : route('admin.floors.store'), done(() => (floorOpen.value = false)));
}

// Room type ----------------------------------------------------------------------------------
const typeOpen = ref(false);
const editingType = ref<TypeRow | null>(null);
const typeForm = useForm({ name: '', description: '', is_active: true });

function openType(row: TypeRow | null): void {
    editingType.value = row;
    formError.value = undefined;
    typeForm.clearErrors();
    typeForm.name = row?.name ?? '';
    typeForm.description = row?.description ?? '';
    typeForm.is_active = row?.is_active ?? true;
    typeOpen.value = true;
}

function saveType(): void {
    const row = editingType.value;
    typeForm.submit(row ? 'put' : 'post', row ? route('admin.room-types.update', row.id) : route('admin.room-types.store'), done(() => (typeOpen.value = false)));
}

function add(): void {
    if (tab.value === 'rooms') {
        openRoom(null);
    } else if (tab.value === 'floors') {
        openFloor(null);
    } else {
        openType(null);
    }
}

const addLabel = computed(() => (tab.value === 'rooms' ? 'Add room' : tab.value === 'floors' ? 'Add floor' : 'Add room type'));
const canAddRoom = computed(() => props.floors.length > 0 && props.types.some((t) => t.is_active));
</script>

<template>
    <AdminLayout current="rooms" :title="title">
        <Head title="Rooms" />
        <PageHeader>
            <UButton icon="i-lucide-plus" :label="addLabel" :disabled="tab === 'rooms' && !canAddRoom" @click="add" />
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-3">
            <PeriodSegments
                v-model="tab"
                label="Show"
                :items="[
                    { value: 'rooms', label: `Rooms ${rooms.length}` },
                    { value: 'floors', label: `Floors ${floors.length}` },
                    { value: 'types', label: `Room types ${types.length}` },
                ]"
            />
        </div>

        <UAlert
            v-if="branch_status === 'setting_up'"
            class="mb-4"
            color="info"
            variant="subtle"
            icon="i-lucide-info"
            description="This branch is being set up. It becomes active when its first room is saved."
        />

        <!-- Rooms -->
        <template v-if="tab === 'rooms'">
            <section class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
                <EmptyState
                    v-if="rooms.length === 0"
                    icon="i-lucide-bed"
                    title="No rooms yet"
                    :text="canAddRoom ? 'Add the first room of this branch.' : 'Add a floor and a room type first, then the rooms.'"
                />
                <table v-else class="dtable w-full">
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Floor</th>
                            <th>Type</th>
                            <th>Area</th>
                            <th>Status</th>
                            <th>On kiosk</th>
                            <th><span class="sr-only">Edit</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in rooms" :key="r.id" :data-room="r.number" :class="r.is_active ? '' : 'text-dimmed'">
                            <td class="num font-semibold text-highlighted">{{ r.number }}</td>
                            <td>{{ r.floor }}</td>
                            <td>{{ r.type }}</td>
                            <td>{{ r.area }}</td>
                            <td>
                                <StatusLabel :tone="statusTone[r.status]" :label="statusLabel[r.status]" />
                                <StatusLabel v-if="!r.is_active" class="ms-1" tone="neutral" label="Off" />
                            </td>
                            <td>{{ r.is_kiosk_enabled ? 'Yes' : '—' }}</td>
                            <td class="text-right"><UButton color="neutral" variant="ghost" size="sm" label="Edit" @click="openRoom(r)" /></td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <UAlert
                class="mt-4"
                color="info"
                variant="subtle"
                icon="i-lucide-lock"
                description="Status can be changed here only between Available and Maintenance. A room with a guest in it cannot be set to Available."
            />
        </template>

        <!-- Floors -->
        <section v-else-if="tab === 'floors'" class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
            <EmptyState v-if="floors.length === 0" icon="i-lucide-list" title="No floors yet" text="Add the floors of this branch, then its rooms." />
            <table v-else class="dtable w-full">
                <thead>
                    <tr>
                        <th>Floor</th>
                        <th>Label</th>
                        <th class="text-right">Rooms</th>
                        <th><span class="sr-only">Edit</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="f in floors" :key="f.id">
                        <td class="num">{{ f.number }}</td>
                        <td class="text-highlighted">{{ f.label }}</td>
                        <td class="num text-right">{{ f.rooms }}</td>
                        <td class="text-right"><UButton color="neutral" variant="ghost" size="sm" label="Edit" @click="openFloor(f)" /></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Room types -->
        <section v-else class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
            <EmptyState v-if="types.length === 0" icon="i-lucide-list" title="No room types yet" text="Add the room types this branch sells, then set their rates." />
            <table v-else class="dtable w-full">
                <thead>
                    <tr>
                        <th>Room type</th>
                        <th>Shown on the kiosk</th>
                        <th class="text-right">Rooms</th>
                        <th>Active</th>
                        <th><span class="sr-only">Edit</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in types" :key="t.id">
                        <td class="text-highlighted">{{ t.name }}</td>
                        <td class="!whitespace-normal text-muted">{{ t.description || '—' }}</td>
                        <td class="num text-right">{{ t.rooms }}</td>
                        <td>{{ t.is_active ? 'Yes' : 'No' }}</td>
                        <td class="text-right"><UButton color="neutral" variant="ghost" size="sm" label="Edit" @click="openType(t)" /></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Room dialog -->
        <UModal v-model:open="roomOpen" :title="editingRoom ? `Room ${editingRoom.number}` : 'Add room'" :ui="{ content: 'max-w-[460px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="formError" data-form-error />
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Room number" name="number" :error="roomForm.errors.number" help="Letters allowed, such as 3A">
                            <UInput v-model="roomForm.number" class="w-full" autofocus maxlength="8" />
                        </UFormField>
                        <UFormField label="Area" name="area" :error="roomForm.errors.area">
                            <UInput v-model="roomForm.area" class="w-full" maxlength="20" placeholder="Main" />
                        </UFormField>
                        <UFormField label="Floor" name="floor_id" :error="roomForm.errors.floor_id">
                            <USelect v-model="roomForm.floor_id" :items="floorItems" class="w-full" />
                        </UFormField>
                        <UFormField label="Room type" name="room_type_id" :error="roomForm.errors.room_type_id">
                            <USelect v-model="roomForm.room_type_id" :items="typeItems" class="w-full" />
                        </UFormField>
                    </div>
                    <UFormField v-if="editingRoom" label="Status" name="status" :error="roomForm.errors.status">
                        <USelect
                            v-if="editingRoom.status_editable"
                            v-model="roomForm.status"
                            :items="[
                                { label: 'Available', value: 'available' },
                                { label: 'Maintenance', value: 'maintenance' },
                            ]"
                            class="w-full"
                        />
                        <div v-else class="flex items-center gap-2 text-[13px] text-muted">
                            <StatusLabel :tone="statusTone[editingRoom.status]" :label="statusLabel[editingRoom.status]" />
                            <span>{{ lockedStatus }}</span>
                        </div>
                    </UFormField>
                    <USwitch v-model="roomForm.is_kiosk_enabled" label="The kiosk may offer this room" />
                    <USwitch v-model="roomForm.is_active" label="In use" description="A room switched off is left off the board, the kiosk and check-in." />
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="roomOpen = false" />
                    <UButton :label="editingRoom ? 'Save room' : 'Add room'" :loading="roomForm.processing" :disabled="!roomForm.number.trim() || !roomForm.floor_id || !roomForm.room_type_id" @click="saveRoom" />
                </div>
            </template>
        </UModal>

        <!-- Floor dialog -->
        <UModal v-model:open="floorOpen" :title="editingFloor ? `Floor ${editingFloor.label}` : 'Add floor'" :ui="{ content: 'max-w-[380px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="formError" data-form-error />
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Floor number" name="number" :error="floorForm.errors.number">
                            <UInput v-model.number="floorForm.number" type="number" inputmode="numeric" class="w-full" />
                        </UFormField>
                        <UFormField label="Label" name="label" :error="floorForm.errors.label">
                            <UInput v-model="floorForm.label" class="w-full" maxlength="20" placeholder="1F" />
                        </UFormField>
                    </div>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="floorOpen = false" />
                    <UButton :label="editingFloor ? 'Save floor' : 'Add floor'" :loading="floorForm.processing" :disabled="!floorForm.label.trim()" @click="saveFloor" />
                </div>
            </template>
        </UModal>

        <!-- Room type dialog -->
        <UModal v-model:open="typeOpen" :title="editingType ? editingType.name : 'Add room type'" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-lucide-triangle-alert" :description="formError" data-form-error />
                    <UFormField label="Name" name="name" :error="typeForm.errors.name">
                        <UInput v-model="typeForm.name" class="w-full" maxlength="40" placeholder="Deluxe" />
                    </UFormField>
                    <UFormField label="Description on the kiosk" name="description" :error="typeForm.errors.description">
                        <UInput v-model="typeForm.description" class="w-full" maxlength="120" placeholder="Queen bed, aircon, hot shower" />
                    </UFormField>
                    <USwitch v-model="typeForm.is_active" label="Sold at this branch" />
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="typeOpen = false" />
                    <UButton :label="editingType ? 'Save room type' : 'Add room type'" :loading="typeForm.processing" :disabled="!typeForm.name.trim()" @click="saveType" />
                </div>
            </template>
        </UModal>
    </AdminLayout>
</template>
