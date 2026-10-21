import { Alert } from '@panneau/ui';

import { useDashboard } from '../api';
import { Loading, Table } from '../components';
import styles from '../dashboard.module.css';
import { formatRelative } from '../format';
import { usePolling } from '../polling';
import type { FailureGroup } from '../types';

export function Failures({ hours, onShowRuns }: { hours: number; onShowRuns: (group: FailureGroup) => void }) {
    const { api } = useDashboard();
    const failures = usePolling(() => api.failures(hours), 30_000, `failures-${hours}`);

    if (failures.error !== undefined) {
        return (
            <Alert tone="danger" title="The failures can't be loaded">
                {failures.error.message}
            </Alert>
        );
    }
    if (failures.data === undefined) {
        return <Loading />;
    }

    return (
        <Table
            items={failures.data}
            rowKey={(group) => `${group.code}-${group.pipeline}-${group.transcoder}-${group.rendition}`}
            empty="No rendition failed in the period."
            columns={[
                { label: 'Count', numeric: true, render: (group) => group.count },
                { label: 'Code', nowrap: true, render: (group) => <span className={styles.mono}>{group.code}</span> },
                { label: 'Pipeline', render: (group) => group.pipeline },
                { label: 'Rendition', render: (group) => group.rendition },
                { label: 'Transcoder', render: (group) => group.transcoder },
                { label: 'Last message', render: (group) => <span className={styles.message}>{group.last_message}</span> },
                { label: 'Last', nowrap: true, render: (group) => formatRelative(group.last_failed_at) },
                {
                    label: 'Runs',
                    render: (group) => (
                        <button type="button" className={styles.link} onClick={() => onShowRuns(group)}>
                            Show runs
                        </button>
                    ),
                },
            ]}
        />
    );
}
