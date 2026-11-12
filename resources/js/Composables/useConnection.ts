import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Heartbeat state for the connection bar (50, SYSTEM_DESIGN §4.5). One shared state for the
 * whole page. A failed Inertia visit or heartbeat marks the app offline; while offline the
 * heartbeat is retried every 5 s, and when it answers again the page reloads its props once.
 */
const online = ref(true);
const retryIn = ref(5);
let started = false;
let timer: ReturnType<typeof setInterval> | undefined;
let countdown: ReturnType<typeof setInterval> | undefined;
let interval = 5;

async function ping(): Promise<boolean> {
    try {
        const response = await fetch('/heartbeat', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' });

        return response.ok || response.status === 204;
    } catch {
        return false;
    }
}

function goOffline(): void {
    if (!online.value) {
        return;
    }

    online.value = false;
    retryIn.value = interval;
    countdown = setInterval(() => (retryIn.value = retryIn.value <= 1 ? interval : retryIn.value - 1), 1000);
    timer = setInterval(async () => {
        if (await ping()) {
            goOnline();
        }
    }, interval * 1000);
}

function goOnline(): void {
    if (online.value) {
        return;
    }

    online.value = true;
    clearInterval(timer);
    clearInterval(countdown);
    router.reload();
}

/** Call once from a layout. `seconds` comes from config('hotel.polling.heartbeat'). */
function start(seconds = 5): void {
    if (started || typeof window === 'undefined') {
        return;
    }

    started = true;
    interval = seconds;
    router.on('networkError', () => {
        goOffline();
    });
    window.addEventListener('offline', goOffline);
    window.addEventListener('online', async () => {
        if (await ping()) {
            goOnline();
        }
    });
}

export const connection = { online, retryIn, start, goOffline };

export function useConnection() {
    return connection;
}
