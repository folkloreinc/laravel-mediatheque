import { describe, expect, it } from 'vitest';

import { formatBytes, formatDuration, formatPercent, formatRelative } from './format';

describe('formatDuration', () => {
    it('reads as people say it', () => {
        expect(formatDuration(0.85)).toBe('850 ms');
        expect(formatDuration(12.4)).toBe('12 s');
        expect(formatDuration(125)).toBe('2 min 5 s');
        expect(formatDuration(120)).toBe('2 min');
        expect(formatDuration(3840)).toBe('1 h 4 min');
        expect(formatDuration(null)).toBe('—');
    });
});

describe('formatBytes', () => {
    it('uses decimal units', () => {
        expect(formatBytes(512)).toBe('512 B');
        expect(formatBytes(52_000)).toBe('52 kB');
        expect(formatBytes(1_400_000_000)).toBe('1.4 GB');
        expect(formatBytes(null)).toBe('—');
    });
});

describe('formatRelative', () => {
    it('says how long ago', () => {
        const now = new Date('2026-10-21T12:00:00Z');
        expect(formatRelative('2026-10-21T11:57:00Z', now)).toBe('3 minutes ago');
        expect(formatRelative('2026-10-21T10:00:00Z', now)).toBe('2 hours ago');
        expect(formatRelative('2026-10-21T11:59:50Z', now)).toBe('10 seconds ago');
        expect(formatRelative(null, now)).toBe('—');
    });
});

describe('formatPercent', () => {
    it('rounds', () => {
        expect(formatPercent(0.666)).toBe('67 %');
        expect(formatPercent(0)).toBe('0 %');
        expect(formatPercent(null)).toBe('—');
    });
});
