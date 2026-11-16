/**
 * Initials and a stable soft colour for a person's avatar (DESIGN_SYSTEM §0, PersonPill).
 * The colour is picked from the name, so the same person has the same colour on every screen.
 */
export const AVATAR_COLOURS = 7;

export function initials(name: string): string {
    const words = name.replace(/\./g, '').trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return '?';
    }

    return words
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase();
}

/** 1–7, from the first letter and the length of the name (as in the mockup). */
export function avatarColour(name: string): number {
    const n = name.trim();

    return n === '' ? 1 : ((n.charCodeAt(0) + n.length) % AVATAR_COLOURS) + 1;
}
