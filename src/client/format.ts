import type { Repeat, RunStatus } from './api';

export const REPEAT_LABELS: Record<Repeat, string> = {
    once: 'Once',
    daily: 'Every day',
    weekly: 'Every week',
    biweekly: 'Every two weeks',
    monthly: 'Every month, same day',
    'first-thursday': 'First Thursday of each month',
};

export const STATUS_LABELS: Record<RunStatus, string> = {
    warning: 'Warning players',
    stopping: 'Stopping server',
    backing_up: 'Backing up',
    wiping: 'Deleting files',
    starting: 'Starting server',
    done: 'Done',
    failed: 'Failed',
    cancelled: 'Cancelled',
    skipped: 'Skipped',
    missed: 'Missed',
};

export const STEPS: RunStatus[] = ['warning', 'stopping', 'backing_up', 'wiping', 'starting'];

/** "3d 4h", "2h 5m", "4m" until the given time, or null once it has passed. */
export function countdown(target: string, now: number = Date.now()): string | null {
    const seconds = Math.floor((new Date(target).getTime() - now) / 1000);
    if (seconds <= 0) return null;

    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);

    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;

    return `${Math.max(minutes, 1)}m`;
}

/** A date in the viewer's own time zone. */
export function when(iso: string | null): string {
    if (!iso) return 'Not scheduled';

    return new Date(iso).toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function browserTimezone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

export function timezones(): string[] {
    try {
        return Intl.supportedValuesOf('timeZone');
    } catch {
        return ['UTC'];
    }
}

/** The next given weekday at a time, as a datetime-local value. 4 is Thursday. */
export function nextWeekday(weekday: number, hour: number, from: Date = new Date()): string {
    const date = new Date(from);
    date.setHours(hour, 0, 0, 0);
    const add = (weekday - date.getDay() + 7) % 7 || (date <= from ? 7 : 0);
    date.setDate(date.getDate() + add);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(hour)}:00`;
}

/** "60, 30, 5" to [60, 30, 5]: whole minutes, largest first, no repeats. */
export function parseMinutes(value: string): number[] {
    const numbers = value
        .split(/[\s,]+/)
        .map((part) => Number.parseInt(part, 10))
        .filter((n) => Number.isFinite(n) && n > 0 && n <= 1440);

    return [...new Set(numbers)].sort((a, b) => b - a).slice(0, 10);
}

export const lines = (value: string): string[] =>
    value
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);

/** A compact date for tables: "Oct 10, 5:49 PM". */
export function short(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}
