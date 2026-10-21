/**
 * The shapes of the dashboard's endpoints: the package's JSON resources
 * (tests/fixtures/json/) and the Monitoring read model.
 */

export type RunStatus = 'pending' | 'running' | 'completed' | 'failed' | 'canceled';
export type RenditionStatus = 'queued' | 'running' | 'completed' | 'failed' | 'skipped' | 'canceled';
export type MediaStatus = 'pending' | 'processing' | 'ready' | 'failed';

export interface TranscoderError {
    code: string;
    message: string;
}

export interface Rendition {
    rendition: string;
    operation: string;
    from: string | null;
    status: RenditionStatus;
    progress: number;
    attempts: number;
    error: TranscoderError | null;
    reason: string | null;
    queued_at: string | null;
    started_at: string | null;
    ended_at: string | null;
    wait: number | null;
    duration: number | null;
}

export interface RunActions {
    retry: boolean;
    cancel: boolean;
    refresh: boolean;
    probe_again: boolean;
}

export interface Run {
    id: string;
    pipeline: string;
    status: RunStatus;
    transcoder: string;
    external_id: string | null;
    progress: number;
    error: TranscoderError | null;
    media: { id: string; type: string; name: string | null; status: MediaStatus };
    renditions: Rendition[];
    created_at: string | null;
    started_at: string | null;
    ended_at: string | null;
    duration: number | null;
    /** On a single run only */
    actions?: RunActions;
}

export interface Period {
    from: string;
    to: string;
}

export interface Overview {
    period: Period;
    media: Record<MediaStatus, number>;
    runs_in_progress: number;
    stuck_runs: number;
    runs_ended: Record<'completed' | 'failed' | 'canceled', number>;
    oldest_queued_at: string | null;
}

export interface FailureGroup {
    code: string;
    pipeline: string;
    transcoder: string;
    rendition: string;
    count: number;
    last_message: string;
    last_failed_at: string;
}

export interface Durations {
    pipeline: string;
    rendition: string;
    transcoder: string;
    count: number;
    median: number;
    p95: number;
    wait_median: number | null;
    wait_p95: number | null;
}

export interface Throughput {
    period: Period;
    /** Keyed by the start of the hour (UTC), then by run status */
    runs_per_hour: Record<string, Partial<Record<RunStatus, number>>>;
    bytes_ingested: number;
    bytes_produced: number;
}

export interface Page<T> {
    data: T[];
    next_cursor: string | null;
}

export interface RunFilters {
    status?: RunStatus | '';
    pipeline?: string;
    media?: string;
    error_code?: string;
    stuck?: boolean;
}

/** What the page gives the dashboard, in its `data-config` attribute. */
export interface DashboardConfig {
    api: string;
    csrfToken: string | null;
    canOperate: boolean;
}
