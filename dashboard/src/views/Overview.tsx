import { Alert, Card, Heading, chartColors } from '@panneau/ui';

import { useDashboard } from '../api';
import { Loading, Stat, Table } from '../components';
import styles from '../dashboard.module.css';
import { formatBytes, formatDuration, formatRelative } from '../format';
import { pollingInterval, usePolling } from '../polling';
import type { Durations, Overview as OverviewData, Throughput } from '../types';

export function Overview({ hours, onShowStuck }: { hours: number; onShowStuck: () => void }) {
    const { api } = useDashboard();
    const overview = usePolling(
        () => api.overview(hours),
        (data?: OverviewData) => pollingInterval((data?.runs_in_progress ?? 0) > 0),
        `overview-${hours}`,
    );
    const throughput = usePolling(() => api.throughput(hours), 30_000, `throughput-${hours}`);
    const durations = usePolling(() => api.durations(hours), 60_000, `durations-${hours}`);

    if (overview.error !== undefined) {
        return <Alert tone="danger" title="The overview can't be loaded">{overview.error.message}</Alert>;
    }
    if (overview.data === undefined) {
        return <Loading />;
    }

    const data = overview.data;
    const period = `in the last ${hours < 48 ? `${hours} h` : `${Math.round(hours / 24)} days`}`;

    return (
        <>
            {data.stuck_runs > 0 ? (
                <Alert
                    tone="warning"
                    title={`${data.stuck_runs} stuck ${data.stuck_runs === 1 ? 'run' : 'runs'}`}
                    actions={
                        <button type="button" className={styles.link} onClick={onShowStuck}>
                            Show them
                        </button>
                    }
                >
                    Runs that haven't ended long after they started. A transcoder may have lost them: refresh or cancel them.
                </Alert>
            ) : null}

            <section className={styles.section} aria-labelledby="media-heading">
                <Heading level={2} size={4} id="media-heading">
                    Media
                </Heading>
                <div className={styles.stats}>
                    <Stat label="Ready" value={data.media.ready} />
                    <Stat label="Processing" value={data.media.processing} />
                    <Stat label="Pending" value={data.media.pending} />
                    <Stat label="Failed" value={data.media.failed} tone={data.media.failed > 0 ? 'danger' : undefined} />
                </div>
            </section>

            <section className={styles.section} aria-labelledby="runs-heading">
                <Heading level={2} size={4} id="runs-heading">
                    Runs
                </Heading>
                <div className={styles.stats}>
                    <Stat label="In progress" value={data.runs_in_progress} hint={`Oldest queued: ${formatRelative(data.oldest_queued_at)}`} />
                    <Stat label="Stuck" value={data.stuck_runs} tone={data.stuck_runs > 0 ? 'warning' : undefined} />
                    <Stat label={`Completed ${period}`} value={data.runs_ended.completed} />
                    <Stat label={`Failed ${period}`} value={data.runs_ended.failed} tone={data.runs_ended.failed > 0 ? 'danger' : undefined} />
                    <Stat label={`Canceled ${period}`} value={data.runs_ended.canceled} />
                </div>
            </section>

            {throughput.data !== undefined ? (
                <section className={styles.section} aria-labelledby="storage-heading">
                    <Heading level={2} size={4} id="storage-heading">
                        Storage
                    </Heading>
                    <div className={styles.stats}>
                        <Stat label={`Ingested ${period}`} value={formatBytes(throughput.data.bytes_ingested)} />
                        <Stat label={`Produced ${period}`} value={formatBytes(throughput.data.bytes_produced)} />
                    </div>
                </section>
            ) : null}

            {throughput.data !== undefined ? (
                <section className={styles.section}>
                    <Card title="Runs ended per hour" headingLevel={2}>
                        <RunsPerHour throughput={throughput.data} />
                    </Card>
                </section>
            ) : null}

            <section className={styles.section} aria-labelledby="durations-heading">
                <Heading level={2} size={4} id="durations-heading">
                    Durations {period}
                </Heading>
                {durations.data !== undefined ? <DurationsTable durations={durations.data} /> : <Loading />}
            </section>
        </>
    );
}

const SERIES = [
    { status: 'completed', label: 'Completed', color: chartColors.categorical(0) },
    { status: 'failed', label: 'Failed', color: chartColors.categorical(1) },
    { status: 'canceled', label: 'Canceled', color: chartColors.categorical(2) },
] as const;

/** Stacked bars, one per hour of the period. */
function RunsPerHour({ throughput }: { throughput: Throughput }) {
    const from = new Date(throughput.period.from);
    from.setUTCMinutes(0, 0, 0);
    const to = new Date(throughput.period.to);
    const hours: { start: string; counts: Record<string, number> }[] = [];
    for (let time = from.getTime(); time <= to.getTime(); time += 3_600_000) {
        const start = new Date(time).toISOString().replace('.000Z', 'Z');
        hours.push({ start, counts: (throughput.runs_per_hour[start] ?? {}) as Record<string, number> });
    }
    const max = Math.max(1, ...hours.map((hour) => SERIES.reduce((sum, series) => sum + (hour.counts[series.status] ?? 0), 0)));
    const width = 100 / Math.max(1, hours.length);
    const total = hours.reduce((sum, hour) => sum + SERIES.reduce((count, series) => count + (hour.counts[series.status] ?? 0), 0), 0);

    return (
        <>
            <svg className={styles.bars} viewBox="0 0 100 100" preserveAspectRatio="none" role="img" aria-label={`${total} runs ended in the period`}>
                {hours.map((hour, index) => {
                    let y = 100;
                    return SERIES.map((series) => {
                        const count = hour.counts[series.status] ?? 0;
                        const height = (count / max) * 100;
                        y -= height;
                        return count > 0 ? (
                            <rect key={`${hour.start}-${series.status}`} x={index * width + width * 0.1} y={y} width={width * 0.8} height={height} fill={series.color}>
                                <title>{`${new Date(hour.start).toLocaleString('en', { dateStyle: 'short', timeStyle: 'short' })}: ${count} ${series.label.toLowerCase()}`}</title>
                            </rect>
                        ) : null;
                    });
                })}
            </svg>
            <div className={styles.legend}>
                {SERIES.map((series) => (
                    <span key={series.status}>
                        <span className={styles.swatch} style={{ background: series.color }} />
                        {series.label}
                    </span>
                ))}
            </div>
        </>
    );
}

function DurationsTable({ durations }: { durations: Durations[] }) {
    return (
        <Table
            items={durations}
            rowKey={(item) => `${item.pipeline}-${item.rendition}-${item.transcoder}`}
            empty="No rendition completed in the period."
            columns={[
                { label: 'Pipeline', render: (item) => item.pipeline },
                { label: 'Rendition', render: (item) => item.rendition },
                { label: 'Transcoder', render: (item) => item.transcoder },
                { label: 'Count', numeric: true, render: (item) => item.count },
                { label: 'Median', numeric: true, render: (item) => formatDuration(item.median) },
                { label: 'p95', numeric: true, render: (item) => formatDuration(item.p95) },
                { label: 'Wait (median)', numeric: true, render: (item) => formatDuration(item.wait_median) },
                { label: 'Wait (p95)', numeric: true, render: (item) => formatDuration(item.wait_p95) },
            ]}
        />
    );
}
