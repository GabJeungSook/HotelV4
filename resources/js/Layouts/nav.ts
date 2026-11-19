import type { NavGroup } from '@/types/ui';

/**
 * Static navigation per role (DESIGN_SYSTEM §6.1). From Phase 2 on the server builds this array
 * (shared `nav` prop, filtered by role and module) and the layouts read it from the page props.
 */
export const frontDeskNav: NavGroup[] = [
    {
        heading: 'Front desk',
        items: [
            { key: 'today', label: 'Today', route: '/today', icon: 'i-solar-clock-circle-bold-duotone' },
            { key: 'board', label: 'Room board', route: '/board', icon: 'i-solar-bed-bold-duotone' },
            { key: 'reservations', label: 'Reservations', route: '/reservations', icon: 'i-solar-calendar-bold-duotone' },
            { key: 'pos', label: 'Point of sale', route: '/pos', icon: 'i-solar-cart-large-bold-duotone' },
            { key: 'stock', label: 'Stock', route: '/stock', icon: 'i-solar-box-bold-duotone' },
        ],
    },
    { heading: 'Shift', items: [{ key: 'shift', label: 'Shift cash', route: '/shift', icon: 'i-solar-banknote-2-bold-duotone' }] },
];

export const backOfficeNav: NavGroup[] = [
    { heading: 'Overview', items: [{ key: 'dashboard', label: 'Dashboard', route: '/dashboard', icon: 'i-solar-chart-2-bold-duotone' }] },
    {
        heading: 'Reports',
        items: [
            {
                key: 'reports-menu',
                label: 'Reports',
                route: '/reports',
                icon: 'i-solar-list-bold-duotone',
                children: [
                    { key: 'reports', label: 'Report hub', route: '/reports', icon: 'i-solar-list-bold-duotone' },
                    { key: 'shift-reports', label: 'Shift reports', route: '/reports/shifts', icon: 'i-solar-banknote-2-bold-duotone' },
                    { key: 'z-report', label: 'Z-report', route: '/reports/z', icon: 'i-solar-list-bold-duotone' },
                    { key: 'daily', label: 'Daily sales', route: '/reports/daily', icon: 'i-solar-chart-2-bold-duotone' },
                    { key: 'occupancy', label: 'Occupancy', route: '/reports/occupancy', icon: 'i-solar-bed-bold-duotone' },
                    { key: 'housekeeping-report', label: 'Housekeeping', route: '/reports/housekeeping', icon: 'i-solar-broom-bold-duotone' },
                    { key: 'inventory', label: 'Inventory', route: '/reports/inventory', icon: 'i-solar-box-bold-duotone' },
                    { key: 'audit', label: 'Audit trail', route: '/reports/audit', icon: 'i-solar-lock-bold-duotone' },
                ],
            },
        ],
    },
    { heading: 'Analytics', items: [{ key: 'stats', label: 'Stats', route: '/stats', icon: 'i-solar-graph-up-bold-duotone' }] },
];

export const adminNav: NavGroup[] = [
    {
        heading: 'Overview',
        items: [
            { key: 'dashboard', label: 'Dashboard', route: '/dashboard', icon: 'i-solar-chart-2-bold-duotone' },
            { key: 'attention', label: 'Attention', route: '/admin/attention', icon: 'i-solar-danger-triangle-bold-duotone' },
        ],
    },
    {
        heading: 'Setup',
        items: [
            { key: 'rooms', label: 'Rooms', route: '/admin/rooms', icon: 'i-solar-bed-bold-duotone' },
            { key: 'rates', label: 'Rates', route: '/admin/rates', icon: 'i-solar-banknote-2-bold-duotone' },
            { key: 'products', label: 'Products', route: '/admin/products', icon: 'i-solar-box-bold-duotone' },
            { key: 'users', label: 'Users', route: '/admin/users', icon: 'i-solar-user-bold-duotone' },
            { key: 'settings', label: 'Settings', route: '/admin/settings', icon: 'i-solar-settings-bold-duotone' },
        ],
    },
    {
        heading: 'Reports',
        items: [
            {
                key: 'reports-menu',
                label: 'Reports',
                route: '/reports',
                icon: 'i-solar-list-bold-duotone',
                children: [
                    { key: 'reports', label: 'Report hub', route: '/reports', icon: 'i-solar-list-bold-duotone' },
                    { key: 'shift-reports', label: 'Shift reports', route: '/reports/shifts', icon: 'i-solar-banknote-2-bold-duotone' },
                    { key: 'daily', label: 'Daily sales', route: '/reports/daily', icon: 'i-solar-chart-2-bold-duotone' },
                ],
            },
            { key: 'stats', label: 'Stats', route: '/stats', icon: 'i-solar-graph-up-bold-duotone' },
        ],
    },
];

export const ownerNav: NavGroup[] = [
    {
        heading: 'Overview',
        items: [
            { key: 'dashboard', label: 'Dashboard', route: '/owner', icon: 'i-solar-chart-2-bold-duotone' },
            { key: 'branches', label: 'Branches', route: '/owner/branches', icon: 'i-solar-buildings-2-bold-duotone' },
            { key: 'summary', label: 'Branch comparison', route: '/owner/summary', icon: 'i-solar-buildings-2-bold-duotone' },
        ],
    },
    {
        heading: 'Reports',
        items: [
            {
                key: 'reports-menu',
                label: 'Reports',
                route: '/reports',
                icon: 'i-solar-list-bold-duotone',
                children: [
                    { key: 'reports', label: 'Report hub', route: '/reports', icon: 'i-solar-list-bold-duotone' },
                    { key: 'shift-reports', label: 'Shift reports', route: '/reports/shifts', icon: 'i-solar-banknote-2-bold-duotone' },
                    { key: 'daily', label: 'Daily sales', route: '/reports/daily', icon: 'i-solar-chart-2-bold-duotone' },
                ],
            },
            { key: 'stats', label: 'Stats', route: '/stats', icon: 'i-solar-graph-up-bold-duotone' },
        ],
    },
    { heading: 'People', items: [{ key: 'owners', label: 'Superadmin accounts', route: '/owner/accounts', icon: 'i-solar-user-bold-duotone' }] },
];
