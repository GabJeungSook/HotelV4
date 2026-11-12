<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Services\CurrentBranch;
use Illuminate\Support\Facades\Route;

/**
 * The one list of every page in the menus (SYSTEM_DESIGN §4.3, APP_FLOW §2 rule 4). The server
 * keeps the entries the user's role and the branch's modules allow, and only the pages that
 * exist in this build (a route that a later phase adds appears in the menu by itself). A page
 * never decides by itself which links to show.
 *
 * A role's home is the first visible entry (SYSTEM_DESIGN §4.2): the redirect after sign-in
 * and the button on the no-permission page (51).
 */
final class Navigation
{
    private const FRONT = [Role::Frontdesk, Role::Supervisor];

    private const BACK = [Role::Admin, Role::Backoffice, Role::Supervisor, Role::Owner];

    /** Every report in the menu (DESIGN_SYSTEM §6.1): back office; admin and owner reach them from the hub (36). */
    private const REPORTS_FULL = [Role::Backoffice, Role::Supervisor];

    /**
     * Every page in the menus, grouped per layout. `home` marks the role homes in order.
     *
     * @return list<array{layout: string, heading: string, items: list<array{key: string, label: string, route: string, icon: string, roles: list<Role>, module?: string, subtitle?: string}>}>
     */
    private static function pages(): array
    {
        return [
            ['layout' => 'frontdesk', 'heading' => 'Front desk', 'items' => [
                ['key' => 'today', 'label' => 'Today', 'route' => 'today', 'icon' => 'i-lucide-clock', 'roles' => self::FRONT],
                ['key' => 'board', 'label' => 'Room board', 'route' => 'board', 'icon' => 'i-lucide-bed', 'roles' => self::FRONT],
                ['key' => 'reservations', 'label' => 'Reservations', 'route' => 'reservations.index', 'icon' => 'i-lucide-list', 'roles' => self::FRONT, 'module' => 'reservations_enabled'],
                ['key' => 'pos', 'label' => 'POS', 'route' => 'pos.index', 'icon' => 'i-lucide-shopping-cart', 'roles' => self::FRONT, 'module' => 'pos_enabled'],
                ['key' => 'stock', 'label' => 'Stock', 'route' => 'stock.index', 'icon' => 'i-lucide-package', 'roles' => self::FRONT, 'module' => 'pos_enabled'],
            ]],
            ['layout' => 'frontdesk', 'heading' => 'Shift', 'items' => [
                ['key' => 'shift', 'label' => 'Shift cash', 'route' => 'shift.show', 'icon' => 'i-lucide-banknote', 'roles' => self::FRONT],
            ]],
            ['layout' => 'owner', 'heading' => 'Overview', 'items' => [
                ['key' => 'owner', 'label' => 'Dashboard', 'route' => 'owner.dashboard', 'icon' => 'i-lucide-chart-column', 'roles' => [Role::Owner]],
                ['key' => 'branches', 'label' => 'Branches', 'route' => 'owner.branches.index', 'icon' => 'i-lucide-list', 'roles' => [Role::Owner]],
                ['key' => 'summary', 'label' => 'Owner summary', 'route' => 'owner.summary', 'icon' => 'i-lucide-chart-column', 'roles' => [Role::Owner]],
            ]],
            ['layout' => 'backoffice', 'heading' => 'Overview', 'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'i-lucide-chart-column', 'roles' => [Role::Admin, Role::Backoffice, Role::Supervisor]],
                ['key' => 'attention', 'label' => 'Attention', 'route' => 'admin.exceptions', 'icon' => 'i-lucide-triangle-alert', 'roles' => [Role::Admin, Role::Supervisor, Role::Owner]],
            ]],
            ['layout' => 'admin', 'heading' => 'Setup', 'items' => [
                ['key' => 'rooms', 'label' => 'Rooms', 'route' => 'admin.rooms', 'icon' => 'i-lucide-bed', 'roles' => [Role::Admin, Role::Owner]],
                ['key' => 'rates', 'label' => 'Rates', 'route' => 'admin.rates', 'icon' => 'i-lucide-banknote', 'roles' => [Role::Admin, Role::Owner]],
                ['key' => 'products', 'label' => 'Products', 'route' => 'admin.products', 'icon' => 'i-lucide-package', 'roles' => [Role::Admin, Role::Owner]],
                ['key' => 'users', 'label' => 'Users', 'route' => 'admin.users', 'icon' => 'i-lucide-user', 'roles' => [Role::Admin, Role::Owner]],
                ['key' => 'settings', 'label' => 'Settings', 'route' => 'admin.settings', 'icon' => 'i-lucide-settings', 'roles' => [Role::Admin, Role::Owner]],
            ]],
            ['layout' => 'backoffice', 'heading' => 'Reports', 'items' => [
                ['key' => 'reports', 'label' => 'Reports', 'route' => 'reports.index', 'icon' => 'i-lucide-list', 'roles' => self::BACK],
                ['key' => 'shift-reports', 'label' => 'Shift reports', 'route' => 'reports.shifts', 'icon' => 'i-lucide-banknote', 'roles' => self::REPORTS_FULL],
                ['key' => 'z-report', 'label' => 'Z-report', 'route' => 'reports.z.latest', 'icon' => 'i-lucide-list', 'roles' => self::REPORTS_FULL],
                ['key' => 'daily', 'label' => 'Daily sales', 'route' => 'reports.daily', 'icon' => 'i-lucide-chart-column', 'roles' => self::REPORTS_FULL],
                ['key' => 'occupancy', 'label' => 'Occupancy', 'route' => 'reports.occupancy', 'icon' => 'i-lucide-bed', 'roles' => self::REPORTS_FULL],
                ['key' => 'housekeeping-report', 'label' => 'Housekeeping', 'route' => 'reports.housekeeping', 'icon' => 'i-lucide-brush', 'roles' => self::REPORTS_FULL],
                ['key' => 'inventory', 'label' => 'Inventory', 'route' => 'reports.inventory', 'icon' => 'i-lucide-package', 'roles' => self::REPORTS_FULL],
                ['key' => 'audit', 'label' => 'Audit trail', 'route' => 'reports.audit', 'icon' => 'i-lucide-lock', 'roles' => self::REPORTS_FULL],
                ['key' => 'stats', 'label' => 'Stats', 'route' => 'stats', 'icon' => 'i-lucide-chart-column', 'roles' => self::BACK],
            ]],
            ['layout' => 'owner', 'heading' => 'People', 'items' => [
                ['key' => 'owners', 'label' => 'Owner accounts', 'route' => 'owner.accounts', 'icon' => 'i-lucide-user', 'roles' => [Role::Owner]],
            ]],
            ['layout' => 'housekeeping', 'heading' => 'Housekeeping', 'items' => [
                ['key' => 'housekeeping', 'label' => 'Queue', 'route' => 'housekeeping.index', 'icon' => 'i-lucide-brush', 'roles' => [Role::Housekeeper], 'module' => 'housekeeping_enabled'],
            ]],
            ['layout' => 'kiosk', 'heading' => 'Kiosk', 'items' => [
                ['key' => 'kiosk', 'label' => 'Kiosk', 'route' => 'kiosk.index', 'icon' => 'i-lucide-monitor', 'roles' => [Role::Kiosk], 'module' => 'kiosk_enabled'],
            ]],
        ];
    }

    /**
     * The menu for this user: groups of `{ key, label, route, icon, subtitle }`, already filtered.
     * Front desk roles see the front desk menu only; a supervisor also gets the dashboard and
     * reports groups (APP_FLOW §1.4).
     *
     * @return list<array{heading: string, items: list<array{key: string, label: string, route: string, icon: string, subtitle: string|null}>}>
     */
    public static function for(User $user, ?Branch $branch): array
    {
        $groups = [];

        foreach (self::pages() as $group) {
            $items = [];

            foreach ($group['items'] as $item) {
                if (! self::visible($item, $user, $branch)) {
                    continue;
                }

                $items[] = [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'route' => route($item['route'], absolute: false),
                    'icon' => $item['icon'],
                    'subtitle' => $item['subtitle'] ?? null,
                ];
            }

            if ($items !== []) {
                $groups[] = ['heading' => $group['heading'], 'items' => $items];
            }
        }

        return $groups;
    }

    /** The role home: the first visible page, or the front desk's open-shift page (APP_FLOW §1.1). */
    public static function home(User $user, ?Branch $branch, bool $isShiftMember = false): string
    {
        if ($user->role === Role::Frontdesk && ! $isShiftMember) {
            return route('shift.open', absolute: false);
        }

        foreach (self::pages() as $group) {
            foreach ($group['items'] as $item) {
                if (self::visible($item, $user, $branch)) {
                    return route($item['route'], absolute: false);
                }
            }
        }

        return route('login', absolute: false);
    }

    /** The button on the no-permission page (51): "Back to Today". */
    public static function homeLabel(User $user): string
    {
        foreach (self::pages() as $group) {
            foreach ($group['items'] as $item) {
                if (self::visible($item, $user, app(CurrentBranch::class)->branch())) {
                    return 'Back to '.($item['key'] === 'today' ? 'Today' : mb_strtolower($item['label']));
                }
            }
        }

        return 'Back to sign in';
    }

    /** @param  array{key: string, label: string, route: string, icon: string, roles: list<Role>, module?: string}  $item */
    private static function visible(array $item, User $user, ?Branch $branch): bool
    {
        if (! in_array($user->role, $item['roles'], true) || ! Route::has($item['route'])) {
            return false;
        }

        $module = $item['module'] ?? null;

        return $module === null || $branch === null || (bool) $branch->getAttribute($module);
    }
}
