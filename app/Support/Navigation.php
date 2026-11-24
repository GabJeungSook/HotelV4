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

    /** Pages of one branch: for an owner, shown only once a branch is opened. */
    private const OPENED_BRANCH_ONLY = ['dashboard', 'attention', 'rooms', 'rates', 'products', 'admin-halls', 'users', 'settings'];

    private const BACK = [Role::Admin, Role::Backoffice, Role::Supervisor, Role::Owner];

    /** Every report in the menu (DESIGN_SYSTEM §6.1): back office; admin and owner reach them from the hub (36). */
    private const REPORTS_FULL = [Role::Backoffice, Role::Supervisor];

    /** Reports every back-office role finds under the folding Reports parent (mockup v6). */
    private const REPORTS_SHORT = [Role::Admin, Role::Backoffice, Role::Supervisor, Role::Owner];

    /**
     * Every page in the menus, grouped per layout. `home` marks the role homes in order.
     *
     * Items with `parent` fold under one parent link in the sidebar (mockup v6: Reports → Report hub,
     * Shift reports, …).
     *
     * @return list<array{layout: string, heading: string, items: list<array{key: string, label: string, route: string, icon: string, roles: list<Role>, module?: string, subtitle?: string, parent?: string}>}>
     */
    private static function pages(): array
    {
        return [
            ['layout' => 'frontdesk', 'heading' => 'Front desk', 'items' => [
                ['key' => 'today', 'label' => 'Today', 'route' => 'today', 'icon' => 'i-solar-clock-circle-bold-duotone', 'roles' => self::FRONT, 'subtitle' => 'What needs doing in the next half hour'],
                ['key' => 'board', 'label' => 'Room board', 'route' => 'board', 'icon' => 'i-solar-bed-bold-duotone', 'roles' => self::FRONT],
                ['key' => 'reservations', 'label' => 'Reservations', 'route' => 'reservations.index', 'icon' => 'i-solar-calendar-bold-duotone', 'roles' => self::FRONT, 'module' => 'reservations_enabled', 'subtitle' => 'Bookings and their arrival times'],
                ['key' => 'pos', 'label' => 'Point of sale', 'route' => 'pos.index', 'icon' => 'i-solar-cart-large-bold-duotone', 'roles' => self::FRONT, 'module' => 'pos_enabled', 'subtitle' => 'Sell to a room or for cash'],
                ['key' => 'stock', 'label' => 'Stock', 'route' => 'stock.index', 'icon' => 'i-solar-box-bold-duotone', 'roles' => self::FRONT, 'module' => 'pos_enabled', 'subtitle' => 'Counts, deliveries and adjustments'],
                ['key' => 'halls', 'label' => 'Function halls', 'route' => 'halls.index', 'icon' => 'i-solar-confetti-bold-duotone', 'roles' => self::FRONT, 'module' => 'event_hall_enabled', 'subtitle' => 'Book an event hall and take the deposit'],
            ]],
            ['layout' => 'frontdesk', 'heading' => 'Shift', 'items' => [
                ['key' => 'shift', 'label' => 'Shift cash', 'route' => 'shift.show', 'icon' => 'i-solar-banknote-2-bold-duotone', 'roles' => self::FRONT],
            ]],
            ['layout' => 'owner', 'heading' => 'Overview', 'items' => [
                ['key' => 'owner', 'label' => 'Dashboard', 'route' => 'owner.dashboard', 'icon' => 'i-solar-chart-2-bold-duotone', 'roles' => [Role::Owner]],
                ['key' => 'branches', 'label' => 'Branches', 'route' => 'owner.branches.index', 'icon' => 'i-solar-buildings-2-bold-duotone', 'roles' => [Role::Owner]],
            ]],
            ['layout' => 'backoffice', 'heading' => 'Overview', 'items' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'i-solar-chart-2-bold-duotone', 'roles' => [Role::Admin, Role::Backoffice, Role::Supervisor, Role::Owner]],
                ['key' => 'attention', 'label' => 'Attention', 'route' => 'admin.exceptions', 'icon' => 'i-solar-danger-triangle-bold-duotone', 'roles' => [Role::Admin, Role::Supervisor, Role::Owner]],
            ]],
            ['layout' => 'admin', 'heading' => 'Setup', 'items' => [
                ['key' => 'rooms', 'label' => 'Rooms', 'route' => 'admin.rooms', 'icon' => 'i-solar-bed-bold-duotone', 'roles' => [Role::Admin, Role::Owner]],
                ['key' => 'rates', 'label' => 'Rates', 'route' => 'admin.rates', 'icon' => 'i-solar-banknote-2-bold-duotone', 'roles' => [Role::Admin, Role::Owner]],
                ['key' => 'products', 'label' => 'Products', 'route' => 'admin.products', 'icon' => 'i-solar-box-bold-duotone', 'roles' => [Role::Admin, Role::Owner]],
                ['key' => 'admin-halls', 'label' => 'Event halls', 'route' => 'admin.halls', 'icon' => 'i-solar-confetti-bold-duotone', 'roles' => [Role::Admin, Role::Owner], 'module' => 'event_hall_enabled'],
                ['key' => 'users', 'label' => 'Users', 'route' => 'admin.users', 'icon' => 'i-solar-user-bold-duotone', 'roles' => [Role::Admin, Role::Owner]],
                ['key' => 'settings', 'label' => 'Settings', 'route' => 'admin.settings', 'icon' => 'i-solar-settings-bold-duotone', 'roles' => [Role::Admin, Role::Owner]],
            ]],
            ['layout' => 'backoffice', 'heading' => 'Reports', 'items' => [
                ['key' => 'reports', 'label' => 'Report hub', 'route' => 'reports.index', 'icon' => 'i-solar-list-bold-duotone', 'roles' => self::BACK, 'parent' => 'reports-menu'],
                ['key' => 'summary', 'label' => 'Branch comparison', 'route' => 'owner.summary', 'icon' => 'i-solar-buildings-2-bold-duotone', 'roles' => [Role::Owner], 'parent' => 'reports-menu', 'subtitle' => 'Closed-books sales, cash and occupancy, branch by branch'],
                ['key' => 'shift-reports', 'label' => 'Shift reports', 'route' => 'reports.shifts', 'icon' => 'i-solar-banknote-2-bold-duotone', 'roles' => self::REPORTS_SHORT, 'parent' => 'reports-menu', 'subtitle' => 'Closed shifts are read from their frozen totals'],
                ['key' => 'z-report', 'label' => 'Z-report', 'route' => 'reports.z.latest', 'icon' => 'i-solar-list-bold-duotone', 'roles' => self::REPORTS_FULL, 'parent' => 'reports-menu'],
                ['key' => 'daily', 'label' => 'Daily sales', 'route' => 'reports.daily', 'icon' => 'i-solar-chart-2-bold-duotone', 'roles' => self::REPORTS_SHORT, 'parent' => 'reports-menu'],
                ['key' => 'occupancy', 'label' => 'Occupancy', 'route' => 'reports.occupancy', 'icon' => 'i-solar-bed-bold-duotone', 'roles' => self::REPORTS_FULL, 'parent' => 'reports-menu'],
                ['key' => 'housekeeping-report', 'label' => 'Housekeeping', 'route' => 'reports.housekeeping', 'icon' => 'i-solar-broom-bold-duotone', 'roles' => self::REPORTS_FULL, 'parent' => 'reports-menu'],
                ['key' => 'inventory', 'label' => 'Inventory', 'route' => 'reports.inventory', 'icon' => 'i-solar-box-bold-duotone', 'roles' => self::REPORTS_FULL, 'parent' => 'reports-menu'],
                ['key' => 'audit', 'label' => 'Audit trail', 'route' => 'reports.audit', 'icon' => 'i-solar-lock-bold-duotone', 'roles' => self::REPORTS_FULL, 'parent' => 'reports-menu'],
                ['key' => 'stats', 'label' => 'Stats', 'route' => 'stats', 'icon' => 'i-solar-graph-up-bold-duotone', 'roles' => [Role::Admin, Role::Owner], 'subtitle' => 'Totals and trends for any period'],
            ]],
            ['layout' => 'backoffice', 'heading' => 'Analytics', 'items' => [
                ['key' => 'stats', 'label' => 'Stats', 'route' => 'stats', 'icon' => 'i-solar-graph-up-bold-duotone', 'roles' => self::REPORTS_FULL, 'subtitle' => 'Totals and trends for any period'],
            ]],
            ['layout' => 'owner', 'heading' => 'People', 'items' => [
                ['key' => 'owners', 'label' => 'Superadmin accounts', 'route' => 'owner.accounts', 'icon' => 'i-solar-user-bold-duotone', 'roles' => [Role::Owner]],
            ]],
            ['layout' => 'housekeeping', 'heading' => 'Housekeeping', 'items' => [
                ['key' => 'housekeeping', 'label' => 'Queue', 'route' => 'housekeeping.index', 'icon' => 'i-solar-broom-bold-duotone', 'roles' => [Role::Housekeeper], 'module' => 'housekeeping_enabled'],
            ]],
            ['layout' => 'kiosk', 'heading' => 'Kiosk', 'items' => [
                ['key' => 'kiosk', 'label' => 'Kiosk', 'route' => 'kiosk.index', 'icon' => 'i-solar-monitor-bold-duotone', 'roles' => [Role::Kiosk], 'module' => 'kiosk_enabled'],
            ]],
        ];
    }

    /**
     * The menu for this user: groups of `{ key, label, route, icon, subtitle }`, already filtered.
     * Front desk roles see the front desk menu only; a supervisor also gets the dashboard and
     * reports groups (APP_FLOW §1.4).
     *
     * Items that share a `parent` become the `children` of one folding parent link, placed where the
     * first of them was.
     *
     * @return list<array{heading: string, items: list<array<string, mixed>>}>
     */
    public static function for(User $user, ?Branch $branch): array
    {
        $groups = [];

        foreach (self::pages() as $group) {
            $items = [];
            $parents = [];

            foreach ($group['items'] as $item) {
                if (! self::visible($item, $user, $branch)) {
                    continue;
                }

                $entry = [
                    'key' => $item['key'],
                    'label' => $user->role === Role::Owner && $item['key'] === 'dashboard' ? 'Branch dashboard' : $item['label'],
                    'route' => route($item['route'], absolute: false),
                    'icon' => $item['icon'],
                    'subtitle' => $item['subtitle'] ?? null,
                ];
                $parent = $item['parent'] ?? null;

                if ($parent === null) {
                    $items[] = $entry;

                    continue;
                }

                if (! isset($parents[$parent])) {
                    $parents[$parent] = count($items);
                    $items[] = ['key' => $parent, 'label' => 'Reports', 'route' => $entry['route'], 'icon' => 'i-solar-list-bold-duotone', 'subtitle' => null, 'children' => []];
                }

                $items[$parents[$parent]]['children'][] = $entry;
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

        // An owner gets a branch's own pages (61, 44–49) only after opening it (DESIGN_SYSTEM §6.1).
        if ($user->role === Role::Owner && $branch === null && in_array($item['key'], self::OPENED_BRANCH_ONLY, true)) {
            return false;
        }

        $module = $item['module'] ?? null;

        return $module === null || $branch === null || (bool) $branch->getAttribute($module);
    }
}
