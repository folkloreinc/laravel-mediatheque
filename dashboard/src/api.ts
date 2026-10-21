import { createContext, use } from 'react';

import type {
    DashboardConfig,
    Durations,
    FailureGroup,
    Overview,
    Page,
    Run,
    RunFilters,
    Throughput,
} from './types';

/** A problem+json answer of the dashboard's endpoints. */
export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly reason: string | null = null,
    ) {
        super(message);
    }
}

export type Api = ReturnType<typeof createApi>;

export function createApi(config: DashboardConfig, fetcher: typeof fetch = fetch.bind(window)) {
    async function request<T>(path: string, method: 'GET' | 'POST' = 'GET'): Promise<T> {
        const headers: Record<string, string> = {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        if (method === 'POST' && config.csrfToken !== null) {
            headers['X-CSRF-TOKEN'] = config.csrfToken;
        }

        const response = await fetcher(config.api + path, { method, headers, credentials: 'same-origin' });
        const body: unknown = await response.json().catch(() => null);

        if (!response.ok) {
            const problem = (body ?? {}) as { title?: string; detail?: string; reason?: string };
            throw new ApiError(problem.detail ?? problem.title ?? response.statusText, response.status, problem.reason ?? null);
        }

        return body as T;
    }

    const query = (params: Record<string, string | number | boolean | null | undefined>) => {
        const search = new URLSearchParams();
        for (const [key, value] of Object.entries(params)) {
            if (value !== undefined && value !== null && value !== '' && value !== false) {
                search.set(key, String(value === true ? 1 : value));
            }
        }
        const string = search.toString();
        return string !== '' ? `?${string}` : '';
    };

    return {
        overview: (hours: number) => request<{ data: Overview }>(`/overview${query({ hours })}`).then((r) => r.data),
        runs: (filters: RunFilters, cursor?: string | null) =>
            request<Page<Run>>(`/runs${query({ ...filters, cursor })}`),
        run: (id: string) => request<{ data: Run }>(`/runs/${encodeURIComponent(id)}`).then((r) => r.data),
        failures: (hours: number) => request<{ data: FailureGroup[] }>(`/failures${query({ hours })}`).then((r) => r.data),
        durations: (hours: number) => request<{ data: Durations[] }>(`/durations${query({ hours })}`).then((r) => r.data),
        throughput: (hours: number) => request<{ data: Throughput }>(`/throughput${query({ hours })}`).then((r) => r.data),
        retry: (id: string) => request<{ data: Run }>(`/runs/${encodeURIComponent(id)}/retry`, 'POST').then((r) => r.data),
        cancel: (id: string) => request<{ data: Run }>(`/runs/${encodeURIComponent(id)}/cancel`, 'POST').then((r) => r.data),
        refresh: (id: string) => request<{ data: Run }>(`/runs/${encodeURIComponent(id)}/refresh`, 'POST').then((r) => r.data),
        probeAgain: (media: string) => request<unknown>(`/media/${encodeURIComponent(media)}/probe`, 'POST'),
    };
}

export interface DashboardContextValue {
    api: Api;
    canOperate: boolean;
}

export const DashboardContext = createContext<DashboardContextValue | null>(null);

export function useDashboard(): DashboardContextValue {
    const value = use(DashboardContext);
    if (value === null) {
        throw new Error('useDashboard() is used outside of the dashboard.');
    }
    return value;
}
