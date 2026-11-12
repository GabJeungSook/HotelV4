import type { NavGroup } from '@/types/ui';

/**
 * Static navigation per role (DESIGN_SYSTEM §6.1). From Phase 2 on the server builds this array
 * (shared `nav` prop, filtered by role and module) and the layouts read it from the page props.
 */
export const frontDeskNav: NavGroup[] = [
    {
        heading: 'Front desk',
        items: [
            { key: 'today', label: 'Today', route: '/today', icon: 'i-lucide-clock' },
            { key: 'board', label: 'Room board', route: '/board', icon: 'i-lucide-bed' },
            { key: 'reservations', label: 'Reservations', route: '/reservations', icon: 'i-lucide-list' },
            { key: 'pos', label: 'POS', route: '/pos', icon: 'i-lucide-shopping-cart' },
            { key: 'stock', label: 'Stock', route: '/stock', icon: 'i-lucide-package' },
        ],
    },
    { heading: 'Shift', items: [{ key: 'shift', label: 'Shift cash', route: '/shift', icon: 'i-lucide-banknote' }] },
];

export const backOfficeNav: NavGroup[] = [
    { heading: 'Overview', items: [{ key: 'dashboard', label: 'Dashboard', route: '/dashboard', icon: 'i-lucide-chart-column' }] },
    {
        heading: 'Reports',
        items: [
            { key: 'reports', label: 'Reports', route: '/reports', icon: 'i-lucide-list' },
            { key: 'shift-reports', label: 'Shift reports', route: '/reports/shifts', icon: 'i-lucide-banknote' },
            { key: 'z-report', label: 'Z-report', route: '/reports/z', icon: 'i-lucide-list' },
            { key: 'daily', label: 'Daily sales', route: '/reports/daily', icon: 'i-lucide-chart-column' },
            { key: 'occupancy', label: 'Occupancy', route: '/reports/occupancy', icon: 'i-lucide-bed' },
            { key: 'housekeeping', label: 'Housekeeping', route: '/reports/housekeeping', icon: 'i-lucide-brush' },
            { key: 'inventory', label: 'Inventory', route: '/reports/inventory', icon: 'i-lucide-package' },
            { key: 'audit', label: 'Audit trail', route: '/reports/audit', icon: 'i-lucide-lock' },
        ],
    },
    { heading: 'Analytics', items: [{ key: 'stats', label: 'Stats', route: '/stats', icon: 'i-lucide-chart-column' }] },
];

export const adminNav: NavGroup[] = [
    {
        heading: 'Overview',
        items: [
            { key: 'dashboard', label: 'Dashboard', route: '/dashboard', icon: 'i-lucide-chart-column' },
            { key: 'attention', label: 'Attention', route: '/admin/attention', icon: 'i-lucide-triangle-alert' },
        ],
    },
    {
        heading: 'Setup',
        items: [
            { key: 'rooms', label: 'Rooms', route: '/admin/rooms', icon: 'i-lucide-bed' },
            { key: 'rates', label: 'Rates', route: '/admin/rates', icon: 'i-lucide-banknote' },
            { key: 'products', label: 'Products', route: '/admin/products', icon: 'i-lucide-package' },
            { key: 'users', label: 'Users', route: '/admin/users', icon: 'i-lucide-user' },
            { key: 'settings', label: 'Settings', route: '/admin/settings', icon: 'i-lucide-settings' },
        ],
    },
    {
        heading: 'Reports',
        items: [
            { key: 'reports', label: 'Reports', route: '/reports', icon: 'i-lucide-list' },
            { key: 'stats', label: 'Stats', route: '/stats', icon: 'i-lucide-chart-column' },
        ],
    },
];

export const ownerNav: NavGroup[] = [
    {
        heading: 'Overview',
        items: [
            { key: 'dashboard', label: 'Dashboard', route: '/owner', icon: 'i-lucide-chart-column' },
            { key: 'branches', label: 'Branches', route: '/owner/branches', icon: 'i-lucide-list' },
            { key: 'summary', label: 'Owner summary', route: '/owner/summary', icon: 'i-lucide-chart-column' },
        ],
    },
    {
        heading: 'Reports',
        items: [
            { key: 'reports', label: 'Reports', route: '/reports', icon: 'i-lucide-list' },
            { key: 'stats', label: 'Stats', route: '/stats', icon: 'i-lucide-chart-column' },
        ],
    },
    { heading: 'People', items: [{ key: 'owners', label: 'Owner accounts', route: '/owner/accounts', icon: 'i-lucide-user' }] },
];
