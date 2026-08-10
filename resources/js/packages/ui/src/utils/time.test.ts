import { describe, expect, test } from 'vitest';
import {
    formatHumanReadableDuration,
    formatReportingDuration,
    isDiscardableEmptyEntry,
    shiftDuplicateInterval,
} from './time';

const seconds = 14 * 3600 + 45 * 60 + 6; // 14h 45m 06s

describe('formatHumanReadableDuration', () => {
    test('decimal', () => {
        expect(formatHumanReadableDuration(seconds, 'decimal', 'comma-point')).toBe('14.75 h');
    });

    test('hours-minutes', () => {
        expect(formatHumanReadableDuration(seconds, 'hours-minutes')).toBe('14h 45min');
    });

    test('hours-minutes-colon-separated', () => {
        expect(formatHumanReadableDuration(seconds, 'hours-minutes-colon-separated')).toBe('14:45');
    });

    test('hours-minutes-seconds-colon-separated', () => {
        expect(formatHumanReadableDuration(seconds, 'hours-minutes-seconds-colon-separated')).toBe(
            '14:45:06'
        );
    });

    test.each([
        ['hours-minutes' as const, 0, '0h 00min'],
        ['hours-minutes' as const, 1, '<1min'],
        ['hours-minutes' as const, 30, '<1min'],
        ['hours-minutes' as const, 59, '<1min'],
        ['hours-minutes' as const, 60, '0h 01min'],
        [undefined, 30, '<1min'],
    ])('sub-minute duration (%s, %ss)', (intervalFormat, duration, expected) => {
        expect(formatHumanReadableDuration(duration, intervalFormat)).toBe(expected);
    });
});

describe('isDiscardableEmptyEntry', () => {
    const emptyEntry = {
        description: null,
        project_id: null,
        task_id: null,
        tags: [] as string[],
        start: '2026-01-01T10:00:00Z',
    };

    test('discards an entry with no attributes stopped within the threshold', () => {
        expect(isDiscardableEmptyEntry(emptyEntry, '2026-01-01T10:00:01Z')).toBe(true);
    });

    test('does not discard exactly at the threshold boundary', () => {
        expect(isDiscardableEmptyEntry(emptyEntry, '2026-01-01T10:00:02Z')).toBe(true);
        expect(isDiscardableEmptyEntry(emptyEntry, '2026-01-01T10:00:03Z')).toBe(false);
    });

    test('does not discard an entry with a description even if very short', () => {
        expect(
            isDiscardableEmptyEntry(
                { ...emptyEntry, description: 'quick note' },
                '2026-01-01T10:00:01Z'
            )
        ).toBe(false);
    });

    test('does not discard an entry with a project, task, or tag', () => {
        expect(
            isDiscardableEmptyEntry({ ...emptyEntry, project_id: 'p1' }, '2026-01-01T10:00:01Z')
        ).toBe(false);
        expect(
            isDiscardableEmptyEntry({ ...emptyEntry, task_id: 't1' }, '2026-01-01T10:00:01Z')
        ).toBe(false);
        expect(
            isDiscardableEmptyEntry({ ...emptyEntry, tags: ['tag1'] }, '2026-01-01T10:00:01Z')
        ).toBe(false);
    });

    test('does not discard an otherwise-empty entry with real duration', () => {
        expect(isDiscardableEmptyEntry(emptyEntry, '2026-01-01T10:05:00Z')).toBe(false);
    });
});

describe('shiftDuplicateInterval', () => {
    test('shifts a finished entry to start where the original ended', () => {
        const entry = { start: '2026-01-01T10:00:00Z', end: '2026-01-01T11:00:00Z' };
        const shifted = shiftDuplicateInterval(entry);
        expect(shifted.start).toBe(entry.end);
        expect(shifted.end).not.toBeNull();
    });

    test('preserves the original duration', () => {
        const entry = { start: '2026-01-01T10:00:00Z', end: '2026-01-01T11:30:00Z' };
        const shifted = shiftDuplicateInterval(entry);
        const durationMs = new Date(shifted.end!).getTime() - new Date(shifted.start).getTime();
        expect(durationMs).toBe(90 * 60 * 1000);
    });

    test('copies a running entry unshifted', () => {
        const entry = { start: '2026-01-01T10:00:00Z', end: null };
        const shifted = shiftDuplicateInterval(entry);
        expect(shifted.start).toBe(entry.start);
        expect(shifted.end).toBeNull();
    });
});

describe('formatReportingDuration', () => {
    test('decimal', () => {
        expect(formatReportingDuration(seconds, 'decimal', 'comma-point')).toBe('14.75 h');
    });

    test('hours-minutes', () => {
        expect(formatReportingDuration(seconds, 'hours-minutes')).toBe('14:45:06');
    });

    test('hours-minutes-colon-separated', () => {
        expect(formatReportingDuration(seconds, 'hours-minutes-colon-separated')).toBe('14:45:06');
    });

    test('hours-minutes-seconds-colon-separated', () => {
        expect(formatReportingDuration(seconds, 'hours-minutes-seconds-colon-separated')).toBe(
            '14:45:06'
        );
    });
});
