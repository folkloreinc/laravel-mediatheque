import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { App } from './App';
import { createApi } from './api';
import type { Overview, Run } from './types';

const overview: Overview = {
    period: { from: '2026-10-20T12:00:00Z', to: '2026-10-21T12:00:00Z' },
    media: { pending: 0, processing: 1, ready: 41, failed: 2 },
    runs_in_progress: 1,
    stuck_runs: 1,
    runs_ended: { completed: 12, failed: 3, canceled: 0 },
    oldest_queued_at: null,
};

const failedRun: Run = {
    id: 'run_01JAB3QX5Z8Y7W6V5T4S3R2Q1P',
    pipeline: 'video',
    status: 'failed',
    transcoder: 'ffmpeg',
    external_id: null,
    progress: 1,
    error: { code: 'timeout', message: 'The poster took too long.' },
    media: { id: 'med_01JAB3QX5Z8Y7W6V5T4S3R2Q1P', type: 'video', name: 'Episode 1', status: 'ready' },
    renditions: [],
    created_at: '2026-10-21T14:00:00Z',
    started_at: '2026-10-21T14:00:05Z',
    ended_at: '2026-10-21T14:02:06Z',
    duration: 121,
    actions: { retry: true, cancel: false, refresh: false, probe_again: false },
};

const rendition: Run['renditions'][number] = {
    rendition: 'h264',
    operation: 'video.transcode',
    from: null,
    status: 'queued',
    progress: null,
    attempts: 0,
    error: null,
    reason: null,
    queued_at: '2026-10-21T14:00:00Z',
    started_at: null,
    ended_at: null,
    wait: null,
    duration: null,
};

function respond(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('the dashboard', () => {
    let fetcher: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetcher = vi.fn(async (url: string) => {
            if (url.startsWith('/mediatheque/api/overview')) return respond({ data: overview });
            if (url.startsWith('/mediatheque/api/throughput'))
                return respond({ data: { period: overview.period, runs_per_hour: {}, bytes_ingested: 52_000, bytes_produced: 0 } });
            if (url.startsWith('/mediatheque/api/durations')) return respond({ data: [] });
            if (url.endsWith('/retry')) return respond({ data: { ...failedRun, id: 'run_new' } }, 201);
            if (url.startsWith('/mediatheque/api/runs/')) return respond({ data: failedRun });
            return respond({ title: 'Not found' }, 404);
        });
    });

    afterEach(() => {
        cleanup();
        window.history.replaceState(null, '', '/');
    });

    it('shows the overview', async () => {
        render(<App api={createApi({ api: '/mediatheque/api', csrfToken: 'token', canOperate: false }, fetcher as typeof fetch)} canOperate={false} />);

        expect(await screen.findByText('41')).toBeTruthy();
        expect(screen.getByText('1 stuck run')).toBeTruthy();
        expect(screen.getByText('52 kB')).toBeTruthy();
        expect(fetcher).toHaveBeenCalledWith('/mediatheque/api/overview?hours=24', expect.objectContaining({ method: 'GET' }));
    });

    it('retries a run with the page\'s CSRF token', async () => {
        window.history.replaceState(null, '', `/?view=runs&run=${failedRun.id}`);
        render(<App api={createApi({ api: '/mediatheque/api', csrfToken: 'token', canOperate: true }, fetcher as typeof fetch)} canOperate />);

        fireEvent.click(await screen.findByRole('button', { name: 'Retry' }));

        await waitFor(() =>
            expect(fetcher).toHaveBeenCalledWith(
                `/mediatheque/api/runs/${failedRun.id}/retry`,
                expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'token' }) }),
            ),
        );
        await waitFor(() => expect(window.location.search).toContain('run=run_new'));
    });

    it('shows an indeterminate bar while nothing measured a running rendition', async () => {
        const runningRun: Run = {
            ...failedRun,
            status: 'running',
            progress: 0.42,
            error: null,
            ended_at: null,
            renditions: [
                { ...rendition, rendition: 'h264', status: 'running', progress: null },
                { ...rendition, rendition: 'poster', operation: 'video.poster', status: 'completed', progress: 1 },
                { ...rendition, rendition: 'thumbnails', operation: 'video.thumbnails', status: 'skipped', progress: null },
            ],
        };
        fetcher.mockImplementation(async () => respond({ data: runningRun }));
        window.history.replaceState(null, '', `/?view=runs&run=${runningRun.id}`);
        render(<App api={createApi({ api: '/mediatheque/api', csrfToken: 'token', canOperate: false }, fetcher as typeof fetch)} canOperate={false} />);

        const run = await screen.findByRole('progressbar', { name: 'Progress of the run' });
        expect(run.getAttribute('aria-valuenow')).toBe('42');
        expect(run.getAttribute('aria-valuetext')).toBe('42 %');

        const h264 = screen.getByRole('progressbar', { name: 'Progress of h264' });
        expect(h264.getAttribute('aria-valuenow')).toBeNull();
        expect(screen.getByRole('progressbar', { name: 'Progress of poster' }).getAttribute('aria-valuenow')).toBe('100');
        expect(screen.queryByRole('progressbar', { name: 'Progress of thumbnails' })).toBeNull();
    });

    it('hides actions from users who can only view', async () => {
        window.history.replaceState(null, '', `/?view=runs&run=${failedRun.id}`);
        render(<App api={createApi({ api: '/mediatheque/api', csrfToken: 'token', canOperate: false }, fetcher as typeof fetch)} canOperate={false} />);

        expect(await screen.findByText('The poster took too long.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull();
    });
});
