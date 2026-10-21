/** A duration in seconds, as people read it: `850 ms`, `12 s`, `2 min 5 s`, `1 h 4 min`. */
export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || Number.isNaN(seconds)) {
        return '—';
    }
    if (seconds < 1) {
        return `${Math.round(seconds * 1000)} ms`;
    }
    if (seconds < 60) {
        return `${Math.round(seconds)} s`;
    }
    if (seconds < 3600) {
        const minutes = Math.floor(seconds / 60);
        const rest = Math.round(seconds % 60);
        return rest > 0 ? `${minutes} min ${rest} s` : `${minutes} min`;
    }
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.round((seconds % 3600) / 60);
    return minutes > 0 ? `${hours} h ${minutes} min` : `${hours} h`;
}

/** A size in bytes, in decimal units: `52 kB`, `1.4 GB`. */
export function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined) {
        return '—';
    }
    const units = ['B', 'kB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1000 && unit < units.length - 1) {
        value /= 1000;
        unit += 1;
    }
    return `${unit === 0 || value >= 10 ? Math.round(value) : value.toFixed(1)} ${units[unit]}`;
}

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

/** How long ago, or in how long: `3 minutes ago`, `in 2 hours`. */
export function formatRelative(iso: string | null | undefined, now: Date = new Date()): string {
    if (iso === null || iso === undefined) {
        return '—';
    }
    const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000);
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];
    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) {
            return relative.format(Math.round(seconds / size), unit);
        }
    }
    return relative.format(seconds, 'second');
}

/** A date and time in the viewer's time zone. */
export function formatTime(iso: string | null | undefined): string {
    if (iso === null || iso === undefined) {
        return '—';
    }
    return new Date(iso).toLocaleString('en', { dateStyle: 'medium', timeStyle: 'medium' });
}

export function formatPercent(progress: number): string {
    return `${Math.round(progress * 100)} %`;
}
