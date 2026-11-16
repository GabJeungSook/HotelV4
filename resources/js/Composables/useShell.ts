import type { DropdownMenuItem } from '@nuxt/ui';
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SidebarBranch } from '@/Components/SidebarNav.vue';
import { useShared } from './useShared';

/**
 * What every desktop layout gives the shell from the shared props (DESIGN_SYSTEM §0, §6.1):
 * the branch switcher card ("Dalisay GenSan · 46 rooms · General Santos City"; the owner's card
 * opens a branch or goes back to all branches), the user card, and where Notifications leads.
 */
export function useShell(overrides: { branch?: () => string | undefined; user?: () => { name: string; role: string } | undefined } = {}) {
    const { props: shared } = useShared();

    const isOwner = computed(() => shared.value.auth?.role === 'owner');

    const branch = computed<SidebarBranch | undefined>(() => {
        const b = shared.value.branchCard ?? null;
        const name = overrides.branch?.() ?? b?.name ?? (isOwner.value ? 'All branches' : undefined);

        if (!name) {
            return undefined;
        }

        const owned = shared.value.ownerBranches ?? [];
        let sub: string | undefined;

        if (b && name === b.name) {
            sub = [`${b.rooms} rooms`, b.place || null].filter(Boolean).join(' · ');
        } else if (name === 'All branches' && owned.length > 0) {
            const rooms = owned.reduce((sum, o) => sum + (o.rooms ?? 0), 0);
            sub = `${owned.length} ${owned.length === 1 ? 'branch' : 'branches'} · ${rooms} rooms`;
        }

        // Regular items (not type:'checkbox', which toggles instead of firing onSelect and keeps
        // the menu open): a click opens/closes the branch and the chip then shows the choice. The
        // current one carries a check.
        const items: DropdownMenuItem[] | undefined =
            isOwner.value && owned.length > 0
                ? [
                      { label: 'All branches', icon: b ? 'i-solar-buildings-2-bold-duotone' : 'i-solar-check-circle-linear', onSelect: () => router.post(route('owner.branches.close')) },
                      ...owned.map((o) => ({ label: o.name, icon: b?.name === o.name ? 'i-solar-check-circle-linear' : 'i-solar-buildings-bold-duotone', onSelect: () => router.post(route('owner.branches.open', o.id)) })),
                  ]
                : undefined;

        return { name, sub, items };
    });

    const user = computed(() => overrides.user?.() ?? (shared.value.auth ? { name: shared.value.auth.user.name, role: shared.value.auth.roleLabel } : undefined));

    /** Attention (49) for those who can act on it, Today for the front desk, else the dashboard. */
    const notifications = computed<string | undefined>(() => {
        const role = shared.value.auth?.role;

        if (!role) {
            return undefined;
        }

        if (['admin', 'supervisor'].includes(role) || (role === 'owner' && shared.value.branchCard)) {
            return route('admin.exceptions');
        }

        if (role === 'frontdesk') {
            return route('today');
        }

        return role === 'owner' ? route('owner.dashboard') : route('dashboard');
    });

    /** The top-bar search goes to the room board, so only roles that have the board get it. */
    const searchRoute = computed(() => (['frontdesk', 'supervisor'].includes(shared.value.auth?.role ?? '') ? route('board') : undefined));

    return { branch, user, notifications, searchRoute };
}
