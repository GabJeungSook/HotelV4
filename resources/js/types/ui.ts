/** Status tones (DESIGN_SYSTEM §2.3). One mapping for the whole app. */
export type Tone = 'neutral' | 'ok' | 'warn' | 'bad' | 'info' | 'primary' | 'violet' | 'clay';

/** Text-on-fill classes per tone, used by StatusLabel. */
export const toneClasses: Record<Tone, string> = {
    neutral: 'bg-grey-50 text-grey',
    ok: 'bg-ok-50 text-ok',
    warn: 'bg-warn-50 text-warn',
    bad: 'bg-bad-50 text-bad',
    info: 'bg-info-50 text-info',
    primary: 'bg-primary-50 text-primary-ink',
    violet: 'bg-violet-50 text-violet',
    clay: 'bg-clay-50 text-clay',
};

/** Solid fill per tone for chart shapes (shares bars, legend swatches). */
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
    subtitle?: string;
    count?: number;
}

export interface NavGroup {
    heading: string;
    items: NavItem[];
}
