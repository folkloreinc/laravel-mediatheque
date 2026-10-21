import { useCallback, useEffect, useRef, useState } from 'react';

export interface Polled<T> {
    data: T | undefined;
    error: Error | undefined;
    loading: boolean;
    reload: () => void;
}

/**
 * Loads now, then again every `interval` milliseconds while the page is
 * visible. `interval` can depend on what was loaded: shorter while runs are
 * in progress. Loads again at once when `key` changes.
 */
export function usePolling<T>(load: () => Promise<T>, interval: number | ((data: T | undefined) => number), key: string): Polled<T> {
    const [data, setData] = useState<T>();
    const [error, setError] = useState<Error>();
    const [loading, setLoading] = useState(true);
    const [tick, setTick] = useState(0);
    const loadRef = useRef(load);
    loadRef.current = load;

    useEffect(() => {
        setLoading(true);
        setData(undefined);
    }, [key]);

    useEffect(() => {
        let active = true;
        loadRef
            .current()
            .then((loaded) => {
                if (active) {
                    setData(loaded);
                    setError(undefined);
                }
            })
            .catch((failure: unknown) => {
                if (active) {
                    setError(failure instanceof Error ? failure : new Error(String(failure)));
                }
            })
            .finally(() => {
                if (active) {
                    setLoading(false);
                }
            });
        return () => {
            active = false;
        };
    }, [key, tick]);

    const delay = typeof interval === 'function' ? interval(data) : interval;
    useEffect(() => {
        const timer = window.setTimeout(() => {
            if (!document.hidden) {
                setTick((value) => value + 1);
            }
        }, delay);
        return () => window.clearTimeout(timer);
    }, [delay, tick, data]);

    const reload = useCallback(() => setTick((value) => value + 1), []);

    return { data, error, loading, reload };
}

/** Every 5 seconds while something is in progress, every 30 otherwise. */
export function pollingInterval(inProgress: boolean): number {
    return inProgress ? 5_000 : 30_000;
}
