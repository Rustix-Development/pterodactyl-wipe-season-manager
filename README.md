# Wipe & Season Manager

Automatic, scheduled wipes for Rust and other games. Each wipe warns players in game, backs the server up, deletes the files you choose, can change the seed or map, starts the server again and posts to Discord. Every wipe is kept in a history with a step-by-step log.

## Features

- **Wipe plans per server** under a new **Wipes** tab. A server can have several, for example a weekly map wipe and the monthly force wipe.
- **Calendar**: once, daily, weekly, every two weeks, monthly on the same day, or the first Thursday of each month. Times follow the plan's own time zone, so 19:00 stays 19:00 when the clocks change.
- **Skip rule**: a plan can step aside when another wipe on the same server is within a set number of hours, such as skipping the weekly wipe in force wipe week. Owners can also skip just the next wipe, or cancel one while it is still warning players.
- **Warnings in game**: console commands at the minutes you choose (60, 30, 5, 1...), with `{time}` filled in.
- **Backup first**: nothing is deleted unless the backup succeeds. It uses the server's own backup slots and replaces the oldest unlocked backup when they are full.
- **File cleanup** by pattern, for example `server/rust/player.blueprints.*`. Wildcards are allowed only in the last part, and paths outside the server or that match everything in its root are refused.
- **Seed and map rotation**: a random seed every wipe or the next one from a list, and the next map URL from a list, written to the server's startup variables.
- **Discord**: a message to a webhook after each wipe with the server, plan, seed and the next wipe as a Discord timestamp. The webhook is stored encrypted and never shown in full again.
- **Starting points** for Rust (map wipe and force wipe), ARK, Minecraft, 7 Days to Die, Palworld and Valheim. Everything can be edited. The Rust paths match the official egg; check the others against the egg you use.
- **Admin overview** under **Admin → Wipes**: every server's upcoming wipes and the latest results.
- **Subuser permission**: owners can always manage wipes; subusers need **Wipe & Season Manager → manage**.

## How a wipe runs

The panel's scheduler checks every minute. When a wipe is due:

1. Warnings are sent at the times you set.
2. The server is stopped. If it has not stopped after the time set in the settings, it is killed.
3. A backup is taken, if the plan has one. If it fails or takes too long, the wipe stops here, the server is started again and nothing is deleted.
4. The matching files are deleted and the seed or map is changed.
5. The server is started again (only if it was running before) and the Discord message is sent.

If something goes wrong after files have been deleted, the server is left stopped so you can check it. If the panel was down when a wipe was due and it is more than an hour late, it is recorded as missed instead of running at an unexpected time.

## Settings

Open **Admin → Extensions → Wipe & Season Manager → Settings**.

| Setting | Default | What it does |
|---|---|---|
| Seconds to wait for a server to stop | 120 | After this the server is killed. |
| Minutes to wait for the backup | 30 | After this the wipe is cancelled and nothing is deleted. |
| Plans per server | 5 | |
| Allow Discord posts | On | Turn off to hide the Discord fields and stop all posts. |

## Requirements

- Pterodactyl Panel 2.0 (2.0-develop or later)
- PHP 8.3+
- The panel's scheduler running every minute (the `schedule:run` cron entry from the panel install guide).
- Backup slots on servers that use "Back up first".

## Install

1. Download `wipe-season-manager-<version>.pteroext` from the [latest release](https://github.com/Rustix-Development/pterodactyl-wipe-season-manager/releases/latest).
2. In the panel, open **Admin → Extensions**, upload the file and enable it.
3. Reload the panel.

From a terminal instead:

```sh
php artisan p:extension:install /path/to/wipe-season-manager-<version>.pteroext --enable
```

## API

All server routes need the `ext.wipe-season-manager.manage` permission.

| Method | Path |
|---|---|
| GET, POST | `/api/client/servers/{server}/extensions/wipe-season-manager/plans` |
| PUT, DELETE | `/api/client/servers/{server}/extensions/wipe-season-manager/plans/{plan}` |
| POST | `/api/client/servers/{server}/extensions/wipe-season-manager/plans/{plan}/run` |
| POST | `/api/client/servers/{server}/extensions/wipe-season-manager/plans/{plan}/skip` (`{"skip": true}` or `false`) |
| POST | `/api/client/servers/{server}/extensions/wipe-season-manager/runs/{run}/cancel` |
| GET | `/api/admin/extensions/wipe-season-manager/overview` (admins) |

## Build from source

```sh
npm install
npm run typecheck
npm test
npm run build
php artisan p:extension:doctor /path/to/wipe-season-manager
php artisan p:extension:pack /path/to/wipe-season-manager
```

## Uninstall

Remove it under **Admin → Extensions**, or from a terminal:

```sh
php artisan p:extension:remove wipe-season-manager
```

Removing the extension stops all wipes. Its plans and history stay in the database tables `ext_wipe_season_manager_plans` and `ext_wipe_season_manager_runs`, and backups it made stay on the servers.

## License

MIT. See [LICENSE](LICENSE).

This is a community extension and is not affiliated with or endorsed by Pterodactyl.
