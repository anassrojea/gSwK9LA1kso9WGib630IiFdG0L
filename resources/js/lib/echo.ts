import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let instance: Echo<'reverb'> | null = null;

/**
 * Lazily connected Echo client for Reverb (private-event.{id} channels).
 * Only dashboards import this; guest invitations never load it.
 */
export function echo(): Echo<'reverb'> {
    if (!instance) {
        (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;
        instance = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
    }

    return instance;
}
