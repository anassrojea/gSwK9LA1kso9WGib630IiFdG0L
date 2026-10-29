import { useEffect, useRef, useState } from 'react';

import type { GuestRow, Stats } from '@/lib/dashboard/guests';

export type LiveState = 'connecting' | 'live' | 'offline';

export interface GuestBroadcast {
    household_id: string;
    guest: GuestRow;
    stats: Stats;
}

const EVENTS = ['.rsvp.submitted', '.household.opened', '.guest.checked_in'] as const;
export type BroadcastName = (typeof EVENTS)[number];

interface PusherConnection {
    state: string;
    bind(event: string, callback: (change: { current: string; previous: string }) => void): void;
    unbind(event: string, callback?: unknown): void;
}

/**
 * Live dashboard updates over Reverb (private-event.{id}). Reverb is a nice
 * extra, never a requirement: without a key, without a server, or when the
 * channel is refused, the hook reports "offline" and stops trying, so the
 * console stays clean and the host can refresh by hand. After a dropped
 * connection comes back, onResync lets the page fetch what it missed.
 */
export function useEventChannel(eventId: string, onMessage: (name: BroadcastName, payload: GuestBroadcast) => void, onResync: () => void): LiveState {
    const [state, setState] = useState<LiveState>('connecting');
    const handlers = useRef({ onMessage, onResync });
    handlers.current = { onMessage, onResync };

    useEffect(() => {
        let cancelled = false;
        let cleanup: () => void = () => {};

        (async () => {
            const { echo, realtimeConfigured } = await import('@/lib/echo');
            if (cancelled) return;
            if (!realtimeConfigured()) {
                setState('offline');
                return;
            }

            try {
                const client = echo();
                const connector = client.connector as unknown as { pusher: { connection: PusherConnection; connect(): void; disconnect(): void } };
                const pusher = connector.pusher;
                const connection = pusher.connection;
                let wasLive = false;

                const onChange = ({ current }: { current: string }) => {
                    if (current === 'connected') {
                        setState('live');
                        if (wasLive) handlers.current.onResync();
                        wasLive = true;
                    } else if (current === 'unavailable' || current === 'failed') {
                        // no server: stop retrying (each attempt is a console error) until the next page load
                        setState('offline');
                        pusher.disconnect();
                    } else if (current === 'connecting') {
                        setState((s) => (s === 'offline' ? s : 'connecting'));
                    }
                };
                connection.bind('state_change', onChange);
                if (connection.state === 'connected') onChange({ current: 'connected' });
                if (connection.state === 'disconnected') pusher.connect();

                const channel = client.private(`event.${eventId}`);
                for (const name of EVENTS) {
                    channel.listen(name, (payload: GuestBroadcast) => handlers.current.onMessage(name, payload));
                }
                channel.error(() => setState('offline'));

                cleanup = () => {
                    connection.unbind('state_change', onChange);
                    client.leave(`event.${eventId}`);
                };
            } catch {
                setState('offline');
            }
        })();

        return () => {
            cancelled = true;
            cleanup();
        };
    }, [eventId]);

    return state;
}
