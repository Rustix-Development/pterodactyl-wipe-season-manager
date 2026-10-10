import type { PlanInput } from './api';
import { browserTimezone, nextWeekday } from './format';

export interface Preset {
    id: string;
    label: string;
    build(): PlanInput;
}

const RUST_MAP_FILES = [
    'server/rust/*.map',
    'server/rust/*.sav',
    'server/rust/*.sav.*',
    'server/rust/player.states.*',
    'server/rust/player.deaths.*',
    'server/rust/sv.files.*',
];

const base = (): PlanInput => ({
    name: 'Wipe',
    game: 'custom',
    enabled: true,
    anchor_local: nextWeekday(4, 19),
    timezone: browserTimezone(),
    repeat: 'weekly',
    skip_within_hours: null,
    warn_minutes: [60, 30, 15, 5, 1],
    warn_command: 'say Server wipe {time}!',
    backup: true,
    paths: [],
    seed_variable: null,
    seed_mode: 'keep',
    seed_list: [],
    map_variable: null,
    map_list: [],
    discord_webhook: null,
    discord_message: null,
});

/**
 * Starting points per game. Rust's are based on the official egg (identity "rust");
 * the others follow each game's default save folders and should be checked against the egg in use.
 */
export const PRESETS: Preset[] = [
    {
        id: 'rust-map',
        label: 'Rust: weekly map wipe',
        build: () => ({
            ...base(),
            name: 'Map wipe',
            game: 'rust-map',
            warn_command: 'say <color=#ff5555>Map wipe {time}!</color>',
            paths: RUST_MAP_FILES,
            seed_variable: 'WORLD_SEED',
            seed_mode: 'random',
            map_variable: 'MAP_URL',
            // Steps aside when the monthly force wipe is close.
            skip_within_hours: 72,
        }),
    },
    {
        id: 'rust-full',
        label: 'Rust: monthly force wipe (map and blueprints)',
        build: () => ({
            ...base(),
            name: 'Force wipe',
            game: 'rust-full',
            repeat: 'first-thursday',
            timezone: 'Europe/London',
            anchor_local: nextWeekday(4, 19),
            warn_command: 'say <color=#ff5555>Force wipe {time}!</color>',
            paths: [...RUST_MAP_FILES, 'server/rust/player.blueprints.*'],
            seed_variable: 'WORLD_SEED',
            seed_mode: 'random',
            map_variable: 'MAP_URL',
        }),
    },
    {
        id: 'ark',
        label: 'ARK: Survival Evolved',
        build: () => ({
            ...base(),
            name: 'Map wipe',
            game: 'ark',
            repeat: 'monthly',
            warn_command: 'broadcast Server wipe {time}!',
            paths: [
                'ShooterGame/Saved/SavedArks/*.ark',
                'ShooterGame/Saved/SavedArks/*.arkprofile',
                'ShooterGame/Saved/SavedArks/*.arktribe',
                'ShooterGame/Saved/SavedArks/*.profilebak',
                'ShooterGame/Saved/SavedArks/*.tribebak',
            ],
        }),
    },
    {
        id: 'minecraft',
        label: 'Minecraft: season reset',
        build: () => ({
            ...base(),
            name: 'Season reset',
            game: 'minecraft',
            repeat: 'monthly',
            warn_command: 'say Season reset {time}!',
            paths: ['world', 'world_nether', 'world_the_end'],
        }),
    },
    {
        id: '7dtd',
        label: '7 Days to Die',
        build: () => ({
            ...base(),
            name: 'World reset',
            game: '7dtd',
            repeat: 'monthly',
            warn_command: 'say "Server wipe {time}!"',
            paths: ['.local/share/7DaysToDie/Saves/*', '.local/share/7DaysToDie/GeneratedWorlds/*'],
        }),
    },
    {
        id: 'palworld',
        label: 'Palworld',
        build: () => ({
            ...base(),
            name: 'World reset',
            game: 'palworld',
            repeat: 'monthly',
            warn_command: null,
            paths: ['Pal/Saved/SaveGames/0/*'],
        }),
    },
    {
        id: 'valheim',
        label: 'Valheim',
        build: () => ({
            ...base(),
            name: 'World reset',
            game: 'valheim',
            repeat: 'monthly',
            warn_command: null,
            paths: ['.config/unity3d/IronGate/Valheim/worlds_local/*'],
        }),
    },
    { id: 'custom', label: 'Custom', build: () => ({ ...base(), warn_command: null }) },
];
