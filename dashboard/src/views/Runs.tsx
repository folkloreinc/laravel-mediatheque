import { Alert, Button, Select, Switch, TextInput } from '@panneau/ui';
import { useEffect, useState } from 'react';

import { useDashboard } from '../api';
import { Loading, StatusBadge, Table } from '../components';
import styles from '../dashboard.module.css';
import { formatDuration, formatPercent, formatRelative } from '../format';
import { pollingInterval, usePolling } from '../polling';
import type { Page, Run, RunFilters, RunStatus } from '../types';

const STATUSES: { value: RunStatus | ''; label: string }[] = [
    { value: '', label: 'Any status' },
    { value: 'pending', label: 'Pending' },
    { value: 'running', label: 'Running' },
    { value: 'completed', label: 'Completed' },
    { value: 'failed', label: 'Failed' },
    { value: 'canceled', label: 'Canceled' },
];

export function isInProgress(run: Pick<Run, 'status'>): boolean {
    return run.status === 'pending' || run.status === 'running';
}

export function Runs({ filters, onFilters, onOpen }: { filters: RunFilters; onFilters: (filters: RunFilters) => void; onOpen: (run: string) => void }) {
    const { api } = useDashboard();
    const [more, setMore] = useState<Run[]>([]);
    const [cursor, setCursor] = useState<string | null>(null);
    const key = JSON.stringify(filters);
    const first = usePolling(
        () => api.runs(filters),
        (page?: Page<Run>) => pollingInterval(page?.data.some(isInProgress) ?? false),
        key,
    );

    useEffect(() => {
        setMore([]);
        setCursor(null);
    }, [key]);

    const nextCursor = more.length > 0 ? cursor : (first.data?.next_cursor ?? null);
    const loadMore = () => {
        if (nextCursor === null) {
            return;
        }
        void api.runs(filters, nextCursor).then((page) => {
            setMore((runs) => [...runs, ...page.data]);
            setCursor(page.next_cursor);
        });
    };

    const runs = [...(first.data?.data ?? []), ...more.filter((run) => !first.data?.data.some((other) => other.id === run.id))];

    return (
        <>
            <div className={styles.filters}>
                <Select
                    label="Status"
                    options={STATUSES}
                    value={filters.status ?? ''}
                    onChange={(value) => onFilters({ ...filters, status: (value ?? '') as RunStatus | '' })}
                />
                <TextInput label="Pipeline" value={filters.pipeline ?? ''} onChange={(value) => onFilters({ ...filters, pipeline: value })} />
                <TextInput label="Media" placeholder="med_…" value={filters.media ?? ''} onChange={(value) => onFilters({ ...filters, media: value.trim() })} />
                <TextInput label="Error code" placeholder="timeout" value={filters.error_code ?? ''} onChange={(value) => onFilters({ ...filters, error_code: value.trim() })} />
                <Switch isSelected={filters.stuck ?? false} onChange={(stuck) => onFilters({ ...filters, stuck })}>
                    Stuck only
                </Switch>
            </div>

            {first.error !== undefined ? (
                <Alert tone="danger" title="The runs can't be loaded">
                    {first.error.message}
                </Alert>
            ) : first.data === undefined ? (
                <Loading />
            ) : (
                <>
                    <Table
                        items={runs}
                        rowKey={(run) => run.id}
                        empty="No run matches."
                        columns={[
                            {
                                label: 'Run',
                                nowrap: true,
                                render: (run) => (
                                    <button type="button" className={`${styles.link} ${styles.mono}`} onClick={() => onOpen(run.id)}>
                                        {run.id}
                                    </button>
                                ),
                            },
                            { label: 'Pipeline', render: (run) => run.pipeline },
                            {
                                label: 'Media',
                                render: (run) => (
                                    <>
                                        {run.media.name ?? run.media.id} <span className={styles.muted}>{run.media.type}</span>
                                    </>
                                ),
                            },
                            { label: 'Status', nowrap: true, render: (run) => <StatusBadge status={run.status} /> },
                            { label: 'Progress', numeric: true, render: (run) => formatPercent(run.progress) },
                            { label: 'Error', nowrap: true, render: (run) => (run.error !== null ? <span className={styles.mono}>{run.error.code}</span> : null) },
                            { label: 'Transcoder', render: (run) => run.transcoder },
                            { label: 'Created', nowrap: true, render: (run) => formatRelative(run.created_at) },
                            { label: 'Duration', numeric: true, render: (run) => formatDuration(run.duration) },
                        ]}
                    />
                    {nextCursor !== null ? (
                        <div className={styles.section}>
                            <Button variant="secondary" onPress={loadMore}>
                                Load more
                            </Button>
                        </div>
                    ) : null}
                </>
            )}
        </>
    );
}
