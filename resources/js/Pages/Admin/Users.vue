<!-- Mockup 47 -->
<script setup lang="ts">
import type { UseFormSubmitOptions } from '@inertiajs/core';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import PersonPill from '@/Components/PersonPill.vue';
import { useConfirmDialog } from '@/Composables/useConfirmDialog';
import { type FlashData, useShared } from '@/Composables/useShared';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { roleText } from '@/types/ui';

/**
 * Users (47, APP_FLOW §8.2): one account per person of this branch, with a role, plus kiosk
 * devices. Owner accounts sit above the branches and are not listed. The admin resets a PIN
 * (the user sets a new one at the next sign-in) or a password (a temporary one is shown once),
 * assigns floors to housekeepers, and deactivates staff who leave; nobody is deleted.
 */
interface UserRow {
    id: number;
    name: string;
    username: string;
    role: string;
    role_label: string;
    pin: boolean | null;
    floor_ids: number[];
    floors: string[];
    is_active: boolean;
}

const props = defineProps<{
    users: UserRow[];
    roles: { value: string; label: string }[];
    floors: { value: number; label: string }[];
}>();

const { user: me } = useShared();
const page = usePage();
const confirm = useConfirmDialog();
const formError = ref<string | undefined>(undefined);

function done(close: () => void): UseFormSubmitOptions {
    formError.value = undefined;

    return {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (p) => {
            const e = (p.flash as FlashData | undefined)?.error;

            if (e) {
                formError.value = e.message;

                return;
            }

            close();
        },
    };
}

// The temporary password, flashed once after adding a user or resetting a password.
const secret = ref<{ title: string; username: string; password: string } | null>(null);
const secretOpen = computed({ get: () => secret.value !== null, set: (v: boolean) => !v && (secret.value = null) });
watch(
    () => (page.flash as { secret?: { title: string; username: string; password: string } } | undefined)?.secret,
    (s) => {
        if (s) {
            secret.value = s;
        }
    },
    { immediate: true },
);

const open = ref(false);
const editing = ref<UserRow | null>(null);
const form = useForm({ name: '', username: '', role: 'frontdesk', floor_ids: [] as number[] });

function edit(row: UserRow | null): void {
    editing.value = row;
    formError.value = undefined;
    form.clearErrors();
    form.name = row?.name ?? '';
    form.username = row?.username ?? '';
    form.role = row?.role ?? 'frontdesk';
    form.floor_ids = [...(row?.floor_ids ?? [])];
    open.value = true;
}

function save(): void {
    const row = editing.value;
    form.transform((d) => ({ ...d, floor_ids: d.role === 'housekeeper' ? d.floor_ids : [] }));
    form.submit(row ? 'put' : 'post', row ? route('admin.users.update', row.id) : route('admin.users.store'), done(() => (open.value = false)));
}

async function resetPin(row: UserRow): Promise<void> {
    const ok = await confirm({
        title: `Reset ${row.name}'s PIN?`,
        description: 'Their PIN stops working now. They set a new 4-digit PIN at their next sign-in.',
        confirmLabel: 'Reset PIN',
        cancelLabel: 'Keep it',
    });

    if (ok) {
        router.post(route('admin.users.pin-reset', row.id), {}, { preserveScroll: true, onSuccess: () => (open.value = false) });
    }
}

async function resetPassword(row: UserRow): Promise<void> {
    const ok = await confirm({
        title: `Reset ${row.name}'s password?`,
        description: 'Their password stops working now. A new temporary password is shown once, for you to give them.',
        confirmLabel: 'Reset password',
        cancelLabel: 'Keep it',
    });

    if (ok) {
        router.post(route('admin.users.password-reset', row.id), {}, { preserveScroll: true, onSuccess: () => (open.value = false) });
    }
}

async function setActive(row: UserRow, active: boolean): Promise<void> {
    const ok = await confirm({
        title: active ? `Reactivate ${row.name}?` : `Deactivate ${row.name}?`,
        description: active ? 'They can sign in again with their username and password.' : 'They can no longer sign in. Their past work keeps their name; nothing is deleted.',
        confirmLabel: active ? 'Reactivate' : 'Deactivate',
        cancelLabel: 'Cancel',
        confirmColor: active ? 'primary' : 'error',
    });

    if (ok) {
        router.put(route('admin.users.active', row.id), { active }, { preserveScroll: true, onSuccess: () => (open.value = false) });
    }
}

const activeCount = computed(() => props.users.filter((u) => u.is_active).length);
const isMe = computed(() => editing.value !== null && editing.value.id === me.value?.id);
</script>

<template>
    <AdminLayout current="users" :title="`Users · ${activeCount}`">
        <Head title="Users" />
        <PageHeader>
            <UButton icon="i-solar-add-square-linear" label="Add user" @click="edit(null)" />
        </PageHeader>

        <section class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <table class="dtable w-full">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>PIN</th>
                        <th>Floors</th>
                        <th>Status</th>
                        <th><span class="sr-only">Edit</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id" :data-user="u.username">
                        <td><PersonPill :name="u.name" /></td>
                        <td>{{ u.username }}</td>
                        <td><span class="text-[12.5px]" :class="roleText[u.role] ?? 'text-default'">{{ u.role_label }}</span></td>
                        <td :class="u.pin === false ? 'text-bad' : ''">{{ u.pin === null ? '—' : u.pin ? 'Set' : 'Not set' }}</td>
                        <td>{{ u.floors.length ? u.floors.join(', ') : '—' }}</td>
                        <td><StatusLabel :tone="u.is_active ? 'ok' : 'neutral'" :label="u.is_active ? 'Active' : 'Deactivated'" /></td>
                        <td class="text-right"><UButton color="neutral" variant="ghost" size="sm" icon="i-solar-pen-2-linear" label="Edit" @click="edit(u)" /></td>
                    </tr>
                </tbody>
            </table>
        </section>
        <p class="mt-3 text-[13px] text-muted">Front desk, supervisors and admins set their own 4-digit PIN at first sign-in. You can reset a PIN, never see it.</p>

        <UModal v-model:open="open" :title="editing ? editing.name : 'Add user'" :ui="{ content: 'max-w-[460px]' }">
            <template #body>
                <div class="flex flex-col gap-4">
                    <UAlert v-if="formError" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" :description="formError" data-form-error />
                    <UFormField label="Name" :error="form.errors.name"><UInput v-model="form.name" maxlength="80" class="w-full" autofocus /></UFormField>
                    <div class="grid grid-cols-2 gap-3">
                        <UFormField label="Username" :error="form.errors.username"><UInput v-model="form.username" maxlength="40" class="w-full" placeholder="first.last" /></UFormField>
                        <UFormField label="Role" :error="form.errors.role">
                            <USelect v-model="form.role" :items="roles" :disabled="isMe" class="w-full" />
                        </UFormField>
                    </div>
                    <UFormField v-if="form.role === 'housekeeper'" label="Floors" :error="form.errors.floor_ids" help="The floors whose rooms this housekeeper cleans.">
                        <USelectMenu v-model="form.floor_ids" :items="floors" value-key="value" multiple placeholder="Pick floors" class="w-full" />
                    </UFormField>
                    <UAlert v-if="!editing" color="info" variant="subtle" icon="i-solar-info-circle-bold-duotone" description="A temporary password is shown once after saving. Give it to the person; they set their PIN at first sign-in." />

                    <div v-if="editing" class="flex flex-wrap gap-2 border-t border-default pt-4">
                        <UButton v-if="editing.pin !== null" color="neutral" variant="outline" size="sm" icon="i-solar-lock-bold-duotone" label="Reset PIN" @click="resetPin(editing)" />
                        <UButton color="neutral" variant="outline" size="sm" icon="i-solar-lock-bold-duotone" label="Reset password" @click="resetPassword(editing)" />
                        <UButton
                            v-if="!isMe"
                            :color="editing.is_active ? 'error' : 'primary'"
                            variant="outline"
                            size="sm"
                            :label="editing.is_active ? 'Deactivate' : 'Reactivate'"
                            @click="setActive(editing, !editing.is_active)"
                        />
                    </div>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end gap-2">
                    <UButton color="neutral" variant="ghost" label="Cancel" @click="open = false" />
                    <UButton :label="editing ? 'Save user' : 'Add user'" :disabled="!form.name.trim() || !form.username.trim()" :loading="form.processing" @click="save" />
                </div>
            </template>
        </UModal>

        <UModal v-model:open="secretOpen" :title="secret?.title ?? ''" :dismissible="false" :ui="{ content: 'max-w-[400px]' }">
            <template #body>
                <div v-if="secret" class="flex flex-col gap-3">
                    <p class="text-[13px] text-muted">This password is shown only now. Give it to the person; they sign in with it and choose their own password.</p>
                    <dl class="rounded-md bg-elevated px-4 py-3">
                        <div class="flex justify-between py-1 text-[13px]">
                            <dt class="text-muted">Username</dt>
                            <dd class="font-semibold text-highlighted">{{ secret.username }}</dd>
                        </div>
                        <div class="flex justify-between py-1 text-[13px]">
                            <dt class="text-muted">Temporary password</dt>
                            <dd class="num text-base font-semibold tracking-wide text-highlighted" data-temporary-password>{{ secret.password }}</dd>
                        </div>
                    </dl>
                </div>
            </template>
            <template #footer>
                <div class="flex w-full justify-end">
                    <UButton label="Done" @click="secret = null" />
                </div>
            </template>
        </UModal>
    </AdminLayout>
</template>
