import { Badge, Card, Spinner, Text, type Tone } from '@panneau/ui';
import type { ReactNode } from 'react';
import { ProgressBar } from 'react-aria-components';

import styles from './dashboard.module.css';
import { formatPercent } from './format';

const TONES: Record<string, Tone> = {
    completed: 'success',
    ready: 'success',
    failed: 'danger',
    running: 'info',
    processing: 'info',
    canceled: 'warning',
};

/** A run, rendition or media status. */
export function StatusBadge({ status }: { status: string }) {
    return <Badge tone={TONES[status] ?? 'neutral'}>{status}</Badge>;
}

/**
 * A run's or a rendition's progress. While it runs and nothing measured it
 * (a transcoder that reports no progress, ffmpeg without the input's
 * duration), the bar is indeterminate; otherwise an unknown progress is a
 * dash.
 */
export function Progress({ value, running, label }: { value: number | null; running: boolean; label: string }) {
    if (value === null && !running) {
        return <span className={styles.muted}>{formatPercent(null)}</span>;
    }
    return (
        <ProgressBar
            aria-label={label}
            value={value !== null ? value * 100 : undefined}
            isIndeterminate={value === null}
            valueLabel={value !== null ? formatPercent(value) : undefined}
            className={styles.progress}
        >
            {({ percentage, isIndeterminate }) => (
                <>
                    <span className={styles.progressTrack}>
                        <span
                            className={isIndeterminate ? `${styles.progressFill} ${styles.indeterminate}` : styles.progressFill}
                            style={isIndeterminate ? undefined : { inlineSize: `${percentage ?? 0}%` }}
                        />
                    </span>
                    <span className={styles.progressValue}>{formatPercent(value)}</span>
                </>
            )}
        </ProgressBar>
    );
}

export function Stat({ label, value, tone, hint }: { label: string; value: ReactNode; tone?: Tone; hint?: ReactNode }) {
    return (
        <Card>
            <div className={styles.stat}>
                <Text size="sm" muted>
                    {label}
                </Text>
                <Text as="span" className={styles.statValue} tone={tone}>
                    {value}
                </Text>
                {hint !== undefined ? (
                    <Text size="sm" muted>
                        {hint}
                    </Text>
                ) : null}
            </div>
        </Card>
    );
}

export interface Column<T> {
    label: string;
    numeric?: boolean;
    /** Short values that read better on one line: ids, statuses, times */
    nowrap?: boolean;
    render: (item: T) => ReactNode;
}

function cellClass<T>(column: Column<T>): string | undefined {
    const names = [column.numeric ? styles.numeric : null, column.nowrap ? styles.nowrap : null].filter((name) => name !== null);
    return names.length > 0 ? names.join(' ') : undefined;
}

export function Table<T>({ items, columns, rowKey, empty }: { items: T[]; columns: Column<T>[]; rowKey: (item: T) => string; empty: string }) {
    if (items.length === 0) {
        return <div className={`${styles.tableWrap} ${styles.empty}`}>{empty}</div>;
    }
    return (
        <div className={styles.tableWrap}>
            <table className={styles.table}>
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th key={column.label} scope="col" className={cellClass(column)}>
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {items.map((item) => (
                        <tr key={rowKey(item)}>
                            {columns.map((column) => (
                                <td key={column.label} className={cellClass(column)}>
                                    {column.render(item)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export function Loading() {
    return (
        <div className={styles.empty}>
            <Spinner label="Loading" />
        </div>
    );
}
