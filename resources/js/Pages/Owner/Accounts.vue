<!-- Mockup: none (owner accounts, APP_FLOW §8.2: table and form of screen 47) -->
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import PersonPill from '@/Components/PersonPill.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { useShared } from '@/Composables/useShared';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';
import { roleText } from '@/types/ui';

/**
 * Owner accounts: everyone with the owner role, above the branches. Add, edit, deactivate (never
 * your own account) and reset a password. A new account or a reset gets a temporary password
 * that is shown here once.
 */
interface Account {
    id: number;
    name: string;
    username: string;
    is_active: boolean;
    is_me: boolean;
    last_seen_at: string | null;
}

interface Created {
    kind: 'branch' | 'owner' | 'reset';
    name: string;
    username: string;
    password: string;
}

defineProps<{ accounts: Account[] }>();

const { page, flash } = useShared();
const created = computed(() => (page.flash as { created?: Created } | undefined)?.created ?? null);

const sheet = ref(false);
const editing = ref<Account | null>(null);
const form = useForm({ name: '', username: '', is_active: true, reset_password: false });

function add(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    sheet.value = true;
}

function edit(a: Account): void {
    editing.value = a;
    form.clearErrors();
    form.name = a.name;
    form.username = a.username;
    form.is_active = a.is_active;
    form.reset_password = false;
    sheet.value = true;
}

function save(): void {
    const done = { preserveScroll: true, onSuccess: () => (sheet.value = false) };

    if (editing.value) {
        form.patch(route('owner.accounts.update', { user: editing.value.id }), done);
    } else {
        form.transform((d) => ({ name: d.name, username: d.username })).post(route('owner.accounts.store'), done);
    }
}
</script>

<template>
    <OwnerLayout current="owners" :title="`Superadmin accounts · ${accounts.length}`">
        <Head title="Superadmin accounts" />
        <PageHeader>
            <UButton icon="i-solar-add-square-linear" label="Add superadmin" @click="add" />
        </PageHeader>

        <UAlert v-if="created" color="success" variant="subtle" icon="i-solar-check-circle-bold-duotone" class="mb-4" :title="created.kind === 'reset' ? 'Password reset' : `${created.name} is added`" data-test="owner-created">
            <template #description>
                Temporary password for <b>{{ created.username }}</b>:
                <span class="font-mono font-semibold text-highlighted select-all">{{ created.password }}</span>. It is shown only once.
            </template>
        </UAlert>
        <UAlert v-if="flash.error" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" class="mb-4" :description="flash.error.message" />

        <div class="overflow-x-auto rounded-card border border-default bg-default p-4">
            <table class="dtable">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Last seen</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in accounts" :key="a.id">
                        <td>
                            <PersonPill :name="a.name" />
                            <span v-if="a.is_me" class="ms-2 text-xs text-muted">(you)</span>
                        </td>
                        <td>{{ a.username }}</td>
                        <td><span class="text-[12.5px]" :class="roleText.owner">Superadmin</span></td>
                        <td>{{ a.last_seen_at ? formatDateTime(a.last_seen_at, 'dayTime') : '—' }}</td>
                        <td><StatusLabel :tone="a.is_active ? 'ok' : 'neutral'" :label="a.is_active ? 'Active' : 'Deactivated'" /></td>
                        <td class="text-right"><UButton color="neutral" variant="outline" size="sm" icon="i-solar-pen-2-linear" label="Edit" @click="edit(a)" /></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <USlideover v-model:open="sheet" :title="editing ? `Edit ${editing.name}` : 'Add superadmin'" :ui="{ content: 'max-w-[420px]' }">
            <template #body>
                <form class="flex flex-col gap-3" @submit.prevent="save">
                    <UFormField label="Full name" name="name" :error="form.errors.name">
                        <UInput v-model="form.name" class="w-full" autofocus autocomplete="off" />
                    </UFormField>
                    <UFormField label="Username" name="username" :error="form.errors.username">
                        <UInput v-model="form.username" class="w-full" autocomplete="off" />
                    </UFormField>
                    <template v-if="editing">
                        <USwitch v-model="form.is_active" label="Active" :disabled="editing.is_me" :description="editing.is_me ? 'You cannot deactivate your own account.' : 'A deactivated superadmin cannot sign in. Nothing is deleted.'" />
                        <USwitch v-model="form.reset_password" label="Reset password" description="A new temporary password is shown once after saving." />
                    </template>
                    <p v-else class="text-xs text-muted">A temporary password is shown once after saving.</p>
                    <UButton type="submit" block class="mt-2" :label="editing ? 'Save' : 'Add superadmin'" :loading="form.processing" />
                </form>
            </template>
        </USlideover>
    </OwnerLayout>
</template>
