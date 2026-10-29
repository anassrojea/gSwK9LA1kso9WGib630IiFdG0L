import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let instance: Echo<'reverb'> | null = null;

/** False when the build has no Reverb key: dashboards then simply stay "not live". */
export function realtimeConfigured(): boolean {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY);
}

/**
 * Lazily connected Echo client for Reverb (private-event.{id} channels).
 * Only dashboards import this; guest invitations never load it.
 * Channel auth goes through axios, which sends Laravel's XSRF cookie.
 */
export function echo(): Echo<'reverb'> {
    if (!instance) {
        (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;
        const tls = (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https';
        instance = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
            forceTLS: tls,
            // one transport: trying the other one too only doubles the failed attempts when Reverb is down
            enabledTransports: tls ? ['wss'] : ['ws'],
            authorizer: (channel: { name: string }) => ({
                authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
                    axios
                        .post<{ auth: string }>('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
                        .then((response) => callback(null, response.data))
                        .catch((error: Error) => callback(error, null));
                },
            }),
        });
    }

    return instance;
}
