import { afterEach, expect, test } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import { plansQuery, type Plan, type PlansResponse } from './api';
import { countdown, lines, nextWeekday, parseMinutes } from './format';
import { PRESETS } from './presets';
import WipesScreen from './screens/WipesScreen';

let host: ReturnType<typeof createExtensionTestHost> | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
});

test('warning minutes are cleaned up and sorted', () => {
    expect(parseMinutes('5, 60 30,30 abc 0 2000')).toEqual([60, 30, 5]);
    expect(lines(' a \n\n b ')).toEqual(['a', 'b']);
});

test('countdown and next weekday', () => {
    const now = Date.parse('2026-10-10T12:00:00Z');
    expect(countdown('2026-10-12T15:30:00Z', now)).toBe('2d 3h');
    expect(countdown('2026-10-10T12:00:30Z', now)).toBe('1m');
    expect(countdown('2026-10-10T11:00:00Z', now)).toBeNull();
    // Saturday 10 Oct 2026 -> Thursday 15 Oct.
    expect(nextWeekday(4, 19, new Date(2026, 9, 10, 12))).toBe('2026-10-15T19:00');
    // On a Thursday after 19:00 the next one is a week later.
    expect(nextWeekday(4, 19, new Date(2026, 9, 15, 20))).toBe('2026-10-22T19:00');
});

test('every preset has safe-looking paths and a name', () => {
    for (const preset of PRESETS) {
        const plan = preset.build();
        expect(plan.name.length).toBeGreaterThan(0);
        for (const path of plan.paths) {
            expect(path.startsWith('/')).toBe(false);
            expect(path.includes('..')).toBe(false);
        }
    }
    expect(PRESETS.find((p) => p.id === 'rust-full')!.build().paths).toContain('server/rust/player.blueprints.*');
});

const plan: Plan = {
    ...PRESETS[0]!.build(),
    id: 1,
    discord_webhook: null,
    skipping_next: false,
    next_run_at: '2099-01-01T19:00:00Z',
    last_run_at: null,
};

test('the screen lists plans, the running wipe and the history', async () => {
    const server = createTestServer({ uuid: 'aaaaaaaa-0000-4000-8000-000000000001' });
    host = createExtensionTestHost({ extensionId: 'wipe-season-manager', prefix: 'rxws', server });
    const data: PlansResponse = {
        max_plans: 5,
        plans: [plan],
        runs: [
            {
                id: 2,
                plan_id: 1,
                plan_name: 'Map wipe',
                trigger: 'schedule',
                status: 'backing_up',
                scheduled_for: '2026-10-10T19:00:00Z',
                started_at: '2026-10-10T19:00:00Z',
                finished_at: null,
                files_deleted: 0,
                seed: null,
                map: null,
                error: null,
                log: [],
            },
            {
                id: 1,
                plan_id: 1,
                plan_name: 'Map wipe',
                trigger: 'manual',
                status: 'failed',
                scheduled_for: '2026-10-03T19:00:00Z',
                started_at: '2026-10-03T19:00:00Z',
                finished_at: '2026-10-03T19:02:00Z',
                files_deleted: 0,
                seed: null,
                map: null,
                error: 'The backup failed, so nothing was deleted.',
                log: [],
            },
        ],
    };
    host.queryClient.setQueryData(plansQuery(server.attributes.uuid).queryKey, data);
    render(<WipesScreen />, { wrapper: host.Wrapper });

    expect(await screen.findByText('Every week · ' + plan.timezone)).toBeTruthy();
    expect(screen.getByText('Map wipe: wiping now')).toBeTruthy();
    expect(document.querySelector('[aria-current="step"]')?.textContent).toBe('Backing up');
    expect(screen.getByText('The backup failed, so nothing was deleted.')).toBeTruthy();
    // No new manual wipe while one is running.
    expect((screen.getByText('Wipe now').closest('button') as HTMLButtonElement).disabled).toBe(true);
});
