import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { NavGroup } from '@/types/ui';

/** The shared props of every page (SYSTEM_DESIGN §4.3), typed once. */
export interface SharedShift {
    id: number;
    period_name: string;
    business_date: string;
    status: 'open' | 'closing';
    opened_at: string | null;
    defined_end: string;
    minutes_past_end: number;
    overdue: boolean;
    stale: boolean;
    closing_by: string | null;
    members: { id: number; name: string }[];
}

export interface NotificationItem {
    kind: string;
    severity: 'high' | 'normal';
    title: string;
    detail: string;
    since: string | null;
    since_text: string;
    href: string;
}

export interface NotificationFeed {
    count: number; // total open items (panel header)
    unread: number; // items new since the bell was last opened (the badge)
    items: NotificationItem[];
    href: string;
}

export interface SharedProps {
    app: { name: string; heartbeat: number };
    auth: { user: { id: number; name: string; username: string }; role: string; roleLabel: string } | null;
    branch: { id: number; name: string; status: string; modules: Record<string, boolean> } | null;
    branchCard?: { name: string; rooms: number; place: string } | null;
    shift: SharedShift | null;
    notifications: NotificationFeed | null;
    nav: NavGroup[];
    home: string | null;
    pinLocked: boolean;
    ownerBranches: { id: number; name: string; rooms?: number }[];
    errors: Record<string, string>;
    [key: string]: unknown;
}

/** A domain refusal sent back by DomainErrorResponse as `flash.error`. */
export interface FlashError {
    code: string;
    message: string;
    tries_left?: number | null;
    room?: string | null;
    taken_by?: string | null;
    type?: string | null;
    next_room_id?: number | null;
    next_room?: string | null;
    request_id?: string | null;
}

export interface FlashData {
    toast?: { type: 'success' | 'error' | 'info'; title?: string; message: string };
    error?: FlashError;
}

export function useShared() {
    const page = usePage<SharedProps>();

    return {
        page,
        props: computed(() => page.props),
        user: computed(() => page.props.auth?.user ?? null),
        shift: computed(() => page.props.shift),
        flash: computed(() => (page.flash ?? {}) as FlashData),
    };
}

/** "Jessa Mae Dela Cruz" → "Jessa Mae D.", "Rhea Bautista" → "Rhea B." */
export function shortName(name: string): string {
    const parts = name.trim().split(/\s+/);

    if (parts.length > 2) {
        return `${parts[0]} ${parts[1]} ${parts[2][0]}.`;
    }

    return parts.length === 2 ? `${parts[0]} ${parts[1][0]}.` : (parts[0] ?? '');
}

/** "Jessa Mae Dela Cruz" → "Jessa Mae", "Rhea Bautista" → "Rhea" (quiet second lines). */
export function firstName(name: string): string {
    const parts = name.trim().split(/\s+/);

    return parts.length > 2 ? `${parts[0]} ${parts[1]}` : (parts[0] ?? '');
}
