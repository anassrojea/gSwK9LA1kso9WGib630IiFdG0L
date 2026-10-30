import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';

import { pollDelay, type Generation } from '@/lib/ai';
import { useT } from '@/lib/i18n';

/**
 * Start an AI job (POST → 202 with the ai_generations row) and poll
 * GET /ai/generations/{id} until it is done or failed (phase 7B).
 */
export function useAiGeneration<R>() {
    const { t } = useT();
    const [generation, setGeneration] = useState<Generation<R> | null>(null);
    const [error, setError] = useState<{ message: string; reason?: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const alive = useRef(true);

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);

    const fail = (err: unknown) => {
        const response = axios.isAxiosError(err) ? err.response : undefined;
        const data = response?.data as { message?: string; reason?: string; errors?: Record<string, string[]> } | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined;
        const message =
            response?.status === 401 || response?.status === 419
                ? t('ai.sign_in')
                : response?.status === 429 && !data?.reason
                  ? t('ai.errors.rate')
                  : first || data?.message || t('ai.errors.unavailable');
        setError({ message, reason: data?.reason });
    };

    const start = useCallback(
        async (url: string, body: Record<string, unknown>) => {
            setError(null);
            setGeneration(null);
            setBusy(true);
            try {
                let { data } = await axios.post<Generation<R>>(url, body, { headers: { Accept: 'application/json' } });
                for (let attempt = 0; alive.current && (data.status === 'queued' || data.status === 'running'); attempt++) {
                    await new Promise((resolve) => setTimeout(resolve, pollDelay(attempt)));
                    ({ data } = await axios.get<Generation<R>>(data.poll, { headers: { Accept: 'application/json' } }));
                }
                if (!alive.current) return;
                setGeneration(data);
                if (data.status === 'failed') setError({ message: data.message || t('ai.errors.unavailable'), reason: data.error ?? undefined });
            } catch (err) {
                if (alive.current) fail(err);
            } finally {
                if (alive.current) setBusy(false);
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );

    const reset = () => {
        setGeneration(null);
        setError(null);
    };

    return { generation, result: generation?.status === 'done' ? generation.result : null, error, busy, start, reset };
}
