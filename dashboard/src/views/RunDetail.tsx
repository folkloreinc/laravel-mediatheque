import { Alert, Button, Card, Heading, Inline, Stack, Text, useToast } from '@panneau/ui';
import { useState } from 'react';

import { ApiError, useDashboard } from '../api';
import { Loading, StatusBadge, Table } from '../components';
import styles from '../dashboard.module.css';
import { formatDuration, formatPercent, formatTime } from '../format';
import { usePolling } from '../polling';
import type { Run } from '../types';
import { isInProgress } from './Runs';

type Action = 'retry' | 'cancel' | 'refresh' | 'probe_again';

const LABELS: Record<Action, string> = {
    retry: 'Retry',
    cancel: 'Cancel',
    refresh: 'Refresh',
    probe_again: 'Probe again',
};

export function RunDetail({ id, onBack, onOpen }: { id: string; onBack: () => void; onOpen: (run: string) => void }) {
    const { api, canOperate } = useDashboard();
    const toasts = useToast();
    const [busy, setBusy] = useState<Action | null>(null);
    const run = usePolling(() => api.run(id), (data?: Run) => (data !== undefined && isInProgress(data) ? 3_000 : 30_000), id);

    const act = (action: Action, data: Run) => {
        setBusy(action);
        const request =
            action === 'retry'
                ? api.retry(data.id)
                : action === 'cancel'
                  ? api.cancel(data.id)
                  : action === 'refresh'
                    ? api.refresh(data.id)
                    : api.probeAgain(data.media.id);

        request
            .then((result) => {
                if (action === 'retry' && typeof result === 'object' && result !== null && 'id' in result) {
                    toasts.show({ title: 'Retried', description: `New run ${String(result.id)}`, tone: 'success' });
                    onOpen(String(result.id));
                } else {
                    toasts.show({ title: action === 'probe_again' ? 'Probe queued' : `${LABELS[action]}: done`, tone: 'success' });
                    run.reload();
                }
            })
            .catch((error: unknown) => {
                toasts.show({ title: `${LABELS[action]} failed`, description: error instanceof ApiError || error instanceof Error ? error.message : String(error), tone: 'danger' });
            })
            .finally(() => setBusy(null));
    };

    return (
        <>
            <div className={styles.header}>
                <button type="button" className={styles.link} onClick={onBack}>
                    ← All runs
                </button>
            </div>
            {run.error !== undefined ? (
                <Alert tone="danger" title="The run can't be loaded">
                    {run.error.message}
                </Alert>
            ) : run.data === undefined ? (
                <Loading />
            ) : (
                <RunCard run={run.data} canOperate={canOperate} busy={busy} onAct={(action) => act(action, run.data as Run)} />
            )}
        </>
    );
}

function RunCard({ run, canOperate, busy, onAct }: { run: Run; canOperate: boolean; busy: Action | null; onAct: (action: Action) => void }) {
    const actions = (Object.keys(LABELS) as Action[]).filter((action) => run.actions?.[action] === true);

    return (
        <Stack gap={4}>
            <Card
                title={
                    <Inline gap={3} align="center">
                        <span className={styles.mono}>{run.id}</span>
                        <StatusBadge status={run.status} />
                    </Inline>
                }
                headingLevel={2}
                actions={
                    canOperate && actions.length > 0 ? (
                        <Inline gap={2}>
                            {actions.map((action) => (
                                <Button
                                    key={action}
                                    size="sm"
                                    variant={action === 'cancel' ? 'danger' : 'secondary'}
                                    isDisabled={busy !== null}
                                    onPress={() => onAct(action)}
                                >
                                    {LABELS[action]}
                                </Button>
                            ))}
                        </Inline>
                    ) : null
                }
            >
                <dl className={styles.stats}>
                    <Field label="Pipeline" value={run.pipeline} />
                    <Field label="Transcoder" value={run.external_id !== null ? `${run.transcoder} (${run.external_id})` : run.transcoder} />
                    <Field label="Media" value={`${run.media.name ?? '—'} · ${run.media.id} · ${run.media.type} · ${run.media.status}`} />
                    <Field label="Progress" value={formatPercent(run.progress)} />
                    <Field label="Created" value={formatTime(run.created_at)} />
                    <Field label="Started" value={formatTime(run.started_at)} />
                    <Field label="Ended" value={formatTime(run.ended_at)} />
                    <Field label="Duration" value={formatDuration(run.duration)} />
                </dl>
                {run.error !== null ? (
                    <Alert tone="danger" title={run.error.code}>
                        {run.error.message}
                    </Alert>
                ) : null}
            </Card>

            <section className={styles.section} aria-labelledby="renditions-heading">
                <Heading level={3} size={4} id="renditions-heading">
                    Renditions
                </Heading>
                <Table
                    items={run.renditions}
                    rowKey={(rendition) => rendition.rendition}
                    empty="This run has no rendition."
                    columns={[
                        { label: 'Rendition', render: (rendition) => rendition.rendition },
                        {
                            label: 'Operation',
                            render: (rendition) => (
                                <>
                                    <span className={styles.mono}>{rendition.operation}</span>
                                    {rendition.from !== null ? <span className={styles.muted}> from {rendition.from}</span> : null}
                                </>
                            ),
                        },
                        { label: 'Status', nowrap: true, render: (rendition) => <StatusBadge status={rendition.status} /> },
                        { label: 'Progress', numeric: true, render: (rendition) => formatPercent(rendition.progress) },
                        { label: 'Attempts', numeric: true, render: (rendition) => rendition.attempts },
                        { label: 'Wait', numeric: true, render: (rendition) => formatDuration(rendition.wait) },
                        { label: 'Duration', numeric: true, render: (rendition) => formatDuration(rendition.duration) },
                        {
                            label: 'Error or reason',
                            render: (rendition) =>
                                rendition.error !== null ? (
                                    <span className={styles.message}>
                                        <strong>{rendition.error.code}</strong> {rendition.error.message}
                                    </span>
                                ) : (
                                    <span className={`${styles.message} ${styles.muted}`}>{rendition.reason}</span>
                                ),
                        },
                    ]}
                />
            </section>
        </Stack>
    );
}

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div className={styles.stat}>
            <Text as="span" size="sm" muted>
                {label}
            </Text>
            <Text as="span" size="sm">
                {value}
            </Text>
        </div>
    );
}
