import { Heading, Inline, PanneauRoot, Select, Tab, TabList, TabPanel, Tabs, Text } from '@panneau/ui';
import { useEffect, useMemo, useState } from 'react';

import { type Api, DashboardContext } from './api';
import styles from './dashboard.module.css';
import type { RunFilters } from './types';
import { Failures } from './views/Failures';
import { Overview } from './views/Overview';
import { RunDetail } from './views/RunDetail';
import { Runs } from './views/Runs';

type View = 'overview' | 'runs' | 'failures';

const VIEWS: View[] = ['overview', 'runs', 'failures'];

const PERIODS = [
    { value: 1, label: 'Last hour' },
    { value: 24, label: 'Last 24 hours' },
    { value: 24 * 7, label: 'Last 7 days' },
    { value: 24 * 30, label: 'Last 30 days' },
];

/** The dashboard's state, kept in the URL's query, so a view can be shared and reloaded. */
interface State {
    view: View;
    hours: number;
    run: string | null;
    filters: RunFilters;
}

function readState(search: string): State {
    const query = new URLSearchParams(search);
    const view = query.get('view') as View | null;
    const hours = Number(query.get('hours'));

    return {
        view: view !== null && VIEWS.includes(view) ? view : 'overview',
        hours: PERIODS.some((period) => period.value === hours) ? hours : 24,
        run: query.get('run'),
        filters: {
            status: (query.get('status') ?? '') as RunFilters['status'],
            pipeline: query.get('pipeline') ?? '',
            media: query.get('media') ?? '',
            error_code: query.get('error_code') ?? '',
            stuck: query.get('stuck') === '1',
        },
    };
}

function writeState(state: State): string {
    const query = new URLSearchParams();
    if (state.view !== 'overview') query.set('view', state.view);
    if (state.hours !== 24) query.set('hours', String(state.hours));
    if (state.run !== null) query.set('run', state.run);
    for (const [key, value] of Object.entries(state.filters)) {
        if (value !== '' && value !== false && value !== undefined) query.set(key, value === true ? '1' : String(value));
    }
    const search = query.toString();
    return search !== '' ? `?${search}` : window.location.pathname;
}

export function App({ api, canOperate }: { api: Api; canOperate: boolean }) {
    const [state, setState] = useState<State>(() => readState(window.location.search));
    const context = useMemo(() => ({ api, canOperate }), [api, canOperate]);

    useEffect(() => {
        const onPopState = () => setState(readState(window.location.search));
        window.addEventListener('popstate', onPopState);
        return () => window.removeEventListener('popstate', onPopState);
    }, []);

    const update = (next: Partial<State>) => {
        const merged = { ...state, ...next };
        window.history.pushState(null, '', writeState(merged));
        setState(merged);
    };

    return (
        <PanneauRoot colorScheme="auto" locale="en">
            <DashboardContext value={context}>
                <div className={styles.page}>
                    <header className={styles.header}>
                        <Inline justify="between" align="end" wrap gap={4}>
                            <div>
                                <Heading level={1} size={2}>
                                    Mediatheque
                                </Heading>
                                <Text muted>Probes, pipeline runs and renditions</Text>
                            </div>
                            <Select
                                label="Period"
                                options={PERIODS}
                                value={state.hours}
                                onChange={(value) => update({ hours: typeof value === 'number' ? value : 24 })}
                            />
                        </Inline>
                    </header>

                    <Tabs selectedKey={state.view} onSelectionChange={(key) => update({ view: key as View, run: null })}>
                        <TabList aria-label="Views">
                            <Tab id="overview">Overview</Tab>
                            <Tab id="runs">Runs</Tab>
                            <Tab id="failures">Failures</Tab>
                        </TabList>
                        <TabPanel id="overview">
                            <Overview hours={state.hours} onShowStuck={() => update({ view: 'runs', filters: { stuck: true } })} />
                        </TabPanel>
                        <TabPanel id="runs">
                            {state.run !== null ? (
                                <RunDetail id={state.run} onBack={() => update({ run: null })} onOpen={(run) => update({ run })} />
                            ) : (
                                <Runs filters={state.filters} onFilters={(filters) => update({ filters })} onOpen={(run) => update({ run })} />
                            )}
                        </TabPanel>
                        <TabPanel id="failures">
                            <Failures
                                hours={state.hours}
                                onShowRuns={(group) => update({ view: 'runs', run: null, filters: { status: 'failed', pipeline: group.pipeline, error_code: group.code } })}
                            />
                        </TabPanel>
                    </Tabs>
                </div>
            </DashboardContext>
        </PanneauRoot>
    );
}
