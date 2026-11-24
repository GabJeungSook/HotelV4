/** Status tones (DESIGN_SYSTEM §2.3). One mapping for the whole app. */
export type Tone = 'neutral' | 'ok' | 'warn' | 'bad' | 'info' | 'primary' | 'violet' | 'clay';

/** Text-on-fill-with-edge classes per tone, used by StatusLabel (v6 pill with a 1 px border). */
export const toneClasses: Record<Tone, string> = {
    neutral: 'bg-grey-50 text-grey border-grey-line',
    ok: 'bg-ok-50 text-ok border-ok-line',
    warn: 'bg-warn-50 text-warn border-warn-line',
    bad: 'bg-bad-50 text-bad border-bad-line',
    info: 'bg-info-50 text-info border-info-line',
    primary: 'bg-primary-50 text-primary-ink border-primary-line',
    violet: 'bg-violet-50 text-violet border-violet-line',
    clay: 'bg-clay-50 text-clay border-clay-line',
};

/**
 * A role shown as plain colored text, not a pill (DESIGN_SYSTEM §5.2): the account level at a
 * glance. Superadmin reads in brand blue, admin in strong ink, everyone else quietly. One map
 * app-wide; `roleText[key] ?? 'text-default'` for an unknown key.
 */
export const roleText: Record<string, string> = {
    owner: 'text-primary font-semibold',
    admin: 'text-highlighted font-semibold',
    supervisor: 'text-highlighted font-medium',
    backoffice: 'text-default font-medium',
    frontdesk: 'text-default',
    housekeeper: 'text-muted',
    kiosk: 'text-muted',
};

/** Soft block fill + edge per tone: calendar and timeline blocks (ShiftCalendar, StaysTimeline). */
export const toneBlock: Record<Tone, string> = {
    neutral: 'bg-grey-50 border-grey-line',
    ok: 'bg-ok-50 border-ok-line',
    warn: 'bg-warn-50 border-warn-line',
    bad: 'bg-bad-50 border-bad-line',
    info: 'bg-info-50 border-info-line',
    primary: 'bg-primary-50 border-primary-line',
    violet: 'bg-violet-50 border-violet-line',
    clay: 'bg-clay-50 border-clay-line',
};

/** Tone text colour for a status shown as a coloured dot + text, not a pill (StatusLabel v7). */
export const toneTextClass: Record<Tone, string> = {
    neutral: 'text-muted',
    ok: 'text-ok',
    warn: 'text-warn',
    bad: 'text-bad',
    info: 'text-info',
    primary: 'text-primary',
    violet: 'text-violet',
    clay: 'text-clay',
};

/** Solid fill per tone for chart shapes (shares bars, legend swatches) and the status dot. */
export const toneFill: Record<Tone, string> = {
    neutral: 'var(--hm-grey)',
    ok: 'var(--hm-ok)',
    warn: 'var(--hm-warn)',
    bad: 'var(--hm-bad)',
    info: 'var(--hm-info)',
    primary: 'var(--hm-primary)',
    violet: 'var(--hm-violet)',
    clay: 'var(--hm-clay)',
};

/** Icon + tone per notification / "needs attention" kind (NotificationQuery, AttentionList). */
export const notificationMeta: Record<string, { icon: string; tone: Tone }> = {
    overdue_stay: { icon: 'i-solar-clock-circle-bold-duotone', tone: 'bad' },
    due_out_soon: { icon: 'i-solar-alarm-bold-duotone', tone: 'warn' },
    kiosk_hold: { icon: 'i-solar-smartphone-2-bold-duotone', tone: 'info' },
    unpaid_balance: { icon: 'i-solar-wallet-money-bold-duotone', tone: 'warn' },
    stock: { icon: 'i-solar-box-bold-duotone', tone: 'warn' },
    no_open_shift: { icon: 'i-solar-lock-keyhole-bold-duotone', tone: 'bad' },
    shift_open_too_long: { icon: 'i-solar-clock-circle-bold-duotone', tone: 'warn' },
    cleaning_stuck: { icon: 'i-solar-broom-bold-duotone', tone: 'warn' },
    day_not_closed: { icon: 'i-solar-calendar-bold-duotone', tone: 'warn' },
    short_shift: { icon: 'i-solar-banknote-2-bold-duotone', tone: 'warn' },
    no_pin: { icon: 'i-solar-key-bold-duotone', tone: 'warn' },
    invariant_failed: { icon: 'i-solar-danger-triangle-bold-duotone', tone: 'bad' },
};

export const notificationFallback: { icon: string; tone: Tone } = { icon: 'i-solar-bell-bold-duotone', tone: 'neutral' };

/** Room board display keys (DESIGN_SYSTEM §2.3), sent by the server's BoardQuery. */
export type RoomDisplayStatus = 'vacant' | 'occupied' | 'due_out' | 'overdue' | 'held' | 'to_clean' | 'cleaning' | 'blocked';

export const roomStatus: Record<RoomDisplayStatus, { label: string; tone: Tone }> = {
    vacant: { label: 'Vacant', tone: 'ok' },
    occupied: { label: 'Occupied', tone: 'info' },
    due_out: { label: 'Due out', tone: 'warn' },
    overdue: { label: 'Overdue', tone: 'bad' },
    held: { label: 'Held', tone: 'primary' },
    to_clean: { label: 'To clean', tone: 'clay' },
    cleaning: { label: 'Cleaning', tone: 'violet' },
    blocked: { label: 'Blocked', tone: 'neutral' },
};

/** Sidebar navigation (DESIGN_SYSTEM §5.2 SidebarNav). Built on the server from Phase 2 on. */
export interface NavItem {
    key: string;
    label: string;
    route: string;
    icon: string;
    subtitle?: string | null;
    count?: number;
    /** Child links of a folding parent (Reports → Report hub, Shift reports, …). */
    children?: NavItem[];
}

export interface NavGroup {
    heading: string;
    items: NavItem[];
}
