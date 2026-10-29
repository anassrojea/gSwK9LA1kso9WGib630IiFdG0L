import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

import type { InvitationConfig } from '@/lib/config-schema';
import { useT } from '@/lib/i18n';
import { serverErrors, validateForEditor, type ConfigErrors } from '@/lib/wizard/validate';

export type SaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'invalid' | 'offline' | 'error';

export const AUTOSAVE_DELAY = 800;
const RETRY_DELAY = 5000;

type Extra = Record<string, unknown>;

/**
 * Autosave for the wizard (plan section 3: no Save button). Every change is
 * debounced, checked with the Zod schema first (inline errors, nothing sent),
 * then the whole config is PATCHed. Saves run one at a time; when offline or
 * on a server hiccup the latest version is retried.
 */
export function useAutosave(url: string, initial: InvitationConfig) {
    const { t } = useT();
    const [config, setConfig] = useState<InvitationConfig>(initial);
    const [state, setState] = useState<SaveState>('idle');
    const [errors, setErrors] = useState<ConfigErrors>({});
    const [savedCount, setSavedCount] = useState(0);

    const current = useRef(initial);
    const pending = useRef<{ config: InvitationConfig; extra: Extra } | null>(null);
    const timer = useRef<number | undefined>(undefined);
    const retry = useRef<number | undefined>(undefined);
    const chain = useRef<Promise<void>>(Promise.resolve());
    const tRef = useRef(t);
    tRef.current = t;

    const send = useCallback(async () => {
        const job = pending.current;
        if (!job) return;
        pending.current = null;

        const check = validateForEditor(job.config, tRef.current);
        if (!check.ok) {
            setErrors(check.errors);
            setState('invalid');
            return;
        }

        setErrors({});
        setState('saving');
        try {
            await axios.patch(url, { config: job.config, ...job.extra }, { headers: { Accept: 'application/json' } });
            if (!pending.current) setState('saved');
            setSavedCount((n) => n + 1);
        } catch (error) {
            const response = axios.isAxiosError(error) ? error.response : undefined;
            if (response?.status === 422) {
                setErrors(serverErrors(response.data?.errors ?? {}, tRef.current));
                setState('invalid');
                return;
            }
            // keep the newest version for the retry, never an older one
            pending.current ??= job;
            setState(!response || !navigator.onLine ? 'offline' : 'error');
            window.clearTimeout(retry.current);
            retry.current = window.setTimeout(() => run(), RETRY_DELAY);
        }
    }, [url]);

    const run = useCallback(() => {
        chain.current = chain.current.then(send, send);
        return chain.current;
    }, [send]);

    /** Apply a change; it is saved AUTOSAVE_DELAY ms after the last one. */
    const update = useCallback(
        (change: (config: InvitationConfig) => InvitationConfig, extra: Extra = {}) => {
            const next = change(current.current);
            current.current = next;
            setConfig(next);
            pending.current = { config: next, extra: { ...(pending.current?.extra ?? {}), ...extra } };
            setState('pending');
            window.clearTimeout(timer.current);
            timer.current = window.setTimeout(() => run(), AUTOSAVE_DELAY);
        },
        [run],
    );

    /** Save now (before leaving the step). Resolves once nothing is waiting. */
    const flush = useCallback(async () => {
        window.clearTimeout(timer.current);
        await run();
    }, [run]);

    useEffect(() => {
        const online = () => pending.current && run();
        const leave = (e: BeforeUnloadEvent) => {
            if (pending.current) {
                e.preventDefault();
            }
        };
        window.addEventListener('online', online);
        window.addEventListener('beforeunload', leave);
        return () => {
            window.removeEventListener('online', online);
            window.removeEventListener('beforeunload', leave);
            window.clearTimeout(timer.current);
            window.clearTimeout(retry.current);
        };
    }, [run]);

    const errorAt = useCallback((pointer: string) => errors[pointer]?.[0], [errors]);

    return { config, update, flush, state, errors, errorAt, savedCount };
}

export type Autosave = ReturnType<typeof useAutosave>;
