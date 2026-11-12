import { onBeforeUnmount, onMounted } from 'vue';

/**
 * The kiosk's idle reset (DESIGN_SYSTEM §5.3, APP_FLOW §6 case 46): `onIdle` runs after `seconds`
 * without a touch, a key or a pointer move. Any input restarts the count. Nothing on the kiosk is
 * saved before "Hold my room", so walking away leaves nothing behind.
 */
export function useKioskIdle(seconds: number, onIdle: () => void) {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const events = ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'input'] as const;

    function reset(): void {
        clearTimeout(timer);
        timer = setTimeout(onIdle, seconds * 1000);
    }

    onMounted(() => {
        events.forEach((e) => window.addEventListener(e, reset, { passive: true }));
        reset();
    });

    onBeforeUnmount(() => {
        clearTimeout(timer);
        events.forEach((e) => window.removeEventListener(e, reset));
    });

    return { reset };
}
