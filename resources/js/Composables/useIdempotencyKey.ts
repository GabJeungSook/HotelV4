import { ref } from 'vue';

/**
 * One UUID per form open (TRD §3.11). Renew it only after a successful save, so a double
 * click or a retry after a lost answer sends the same key and the server returns the first
 * result.
 */
export function newKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    // Fallback for older browsers: RFC 4122 version 4 from getRandomValues.
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function useIdempotencyKey() {
    const key = ref(newKey());

    return { key, renew: () => (key.value = newKey()) };
}
