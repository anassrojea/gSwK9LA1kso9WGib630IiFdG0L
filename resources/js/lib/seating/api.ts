import axios from 'axios';

/** JSON call to the app (session cookie + XSRF header through axios). */
export async function sendJson<T>(method: 'get' | 'post' | 'put' | 'patch' | 'delete', url: string, body?: unknown): Promise<T> {
    const response = await axios.request<T>({ method, url, data: body, headers: { Accept: 'application/json' } });
    return response.data;
}

/** The first validation message of a failed call, else the given fallback. */
export function errorMessage(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        if (first) return first;
        if (data?.message && error.response && error.response.status < 500) return data.message;
    }
    return fallback;
}

export function isOffline(error: unknown): boolean {
    return axios.isAxiosError(error) && !error.response;
}

export function statusOf(error: unknown): number | null {
    return axios.isAxiosError(error) ? (error.response?.status ?? null) : null;
}
