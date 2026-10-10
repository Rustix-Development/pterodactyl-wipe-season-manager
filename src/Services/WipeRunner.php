<?php

namespace RustixDev\WipeSeasonManager\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use RustixDev\WipeSeasonManager\Models\WipePlan;
use RustixDev\WipeSeasonManager\Models\WipeRun;
use RustixDev\WipeSeasonManager\Support\PathPattern;
use Throwable;

/**
 * Moves a wipe forward one step at a time. Each call does whatever can be done
 * right now and returns; slow steps (the server stopping, the backup finishing)
 * are picked up again on the next scheduler tick.
 *
 * warning -> stopping -> backing_up -> wiping -> starting -> done
 */
class WipeRunner
{
    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly ReadsServerState $state,
        private readonly SendsServerPower $power,
        private readonly SendsServerCommands $commands,
        private readonly InitiatesBackups $backups,
        private readonly ListsDirectories $lister,
        private readonly DeletesFiles $deleter,
        private readonly DiscordNotifier $discord,
    ) {}

    public function advance(WipeRun $run, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        // Keep going while steps finish straight away; stop when one has to wait.
        for ($i = 0; $i < 6 && $run->isActive(); $i++) {
            $before = $run->status;

            try {
                match ($run->status) {
                    'warning' => $this->warn($run, $now),
                    'stopping' => $this->stop($run, $now),
                    'backing_up' => $this->backup($run, $now),
                    'wiping' => $this->wipe($run),
                    'starting' => $this->start($run, $now),
                };
            } catch (Throwable $exception) {
                $this->fail($run, $exception->getMessage() ?: $exception::class);
            }

            $run->save();

            if ($run->status === $before) {
                break;
            }
        }
    }

    private function warn(WipeRun $run, CarbonImmutable $now): void
    {
        if ($now->greaterThanOrEqualTo($run->scheduled_for)) {
            $this->begin($run, $now);

            return;
        }

        $plan = $run->plan;
        $left = (int) ceil($now->diffInSeconds($run->scheduled_for, true) / 60);
        $due = array_values(array_filter(
            $plan?->warn_minutes ?? [],
            fn (int $minutes) => $minutes >= $left && ! in_array($minutes, $run->warnings_sent, true),
        ));

        if ($due === []) {
            return;
        }

        // Only the closest warning is sent if several came due at once.
        $run->warnings_sent = array_values(array_unique([...$run->warnings_sent, ...$due]));
        $command = $this->warningCommand($plan, min($left, min($due)));
        if ($command !== null && $this->isRunning($run->server)) {
            $this->tryCommand($run, $command);
        }
    }

    private function begin(WipeRun $run, CarbonImmutable $now): void
    {
        $run->started_at = $now;
        $run->was_running = $this->isRunning($run->server);
        $run->note($run->was_running ? 'Stopping the server.' : 'The server was already stopped.');

        if ($run->was_running) {
            $plan = $run->plan;
            if ($plan?->warn_command) {
                $this->tryCommand($run, $this->warningCommand($plan, 0));
            }
            $this->power->send($run->server, 'stop');
            $this->enter($run, 'stopping', $now);

            return;
        }

        $this->enter($run, 'backing_up', $now);
    }

    private function stop(WipeRun $run, CarbonImmutable $now): void
    {
        if (! $this->isRunning($run->server, includeStopping: true)) {
            $run->note('The server stopped.');
            $this->enter($run, 'backing_up', $now);

            return;
        }

        $waited = $run->step_started_at?->diffInSeconds($now, true) ?? 0;
        if (! $run->kill_sent && $waited >= $this->settingInt('stop_wait_seconds', 120)) {
            $run->note('The server did not stop in time, so it was killed.');
            $this->power->send($run->server, 'kill');
            $run->kill_sent = true;
            $run->step_started_at = $now;
        } elseif ($run->kill_sent && $waited >= 120) {
            $this->fail($run, 'The server would not stop, even after being killed. Nothing was deleted.');
        }
    }

    private function backup(WipeRun $run, CarbonImmutable $now): void
    {
        if (! $run->plan?->backup) {
            $this->enter($run, 'wiping', $now);

            return;
        }

        if ($run->backup_id === null) {
            $backup = $this->backups->setIsLocked(false)->setIgnoredFiles([])->initiate(
                $run->server,
                sprintf('Before %s (%s)', $run->plan_name, $now->format('Y-m-d H:i').' UTC'),
                true,
            );
            $run->backup_id = $backup->id;
            $run->note('Backup started.');

            return;
        }

        $backup = Backup::query()->withTrashed()->find($run->backup_id);
        if ($backup?->completed_at !== null) {
            if (! $backup->is_successful) {
                $this->fail($run, 'The backup failed, so nothing was deleted.');

                return;
            }

            $run->note('Backup finished.');
            $this->enter($run, 'wiping', $now);

            return;
        }

        $waited = $run->step_started_at?->diffInMinutes($now, true) ?? 0;
        if ($backup === null || $waited >= $this->settingInt('backup_wait_minutes', 30)) {
            $this->fail($run, 'The backup did not finish in time, so nothing was deleted.');
        }
    }

    private function wipe(WipeRun $run): void
    {
        $plan = $run->plan;
        $server = $run->server;
        $deleted = 0;

        // Group patterns by folder so each folder is listed once.
        $byDirectory = [];
        foreach ($plan?->paths ?? [] as $line) {
            $pattern = PathPattern::parse($line);
            if ($pattern !== null) {
                $byDirectory[$pattern->directory][] = $pattern;
            }
        }

        foreach ($byDirectory as $directory => $patterns) {
            try {
                $entries = $this->lister->list($server, $directory);
            } catch (Throwable) {
                $run->note("Skipped {$directory}: the folder does not exist.");

                continue;
            }

            $names = [];
            foreach ($entries as $entry) {
                $name = $entry['name'] ?? null;
                if (! is_string($name)) {
                    continue;
                }
                foreach ($patterns as $pattern) {
                    if ($pattern->matches($name)) {
                        $names[] = $name;

                        break;
                    }
                }
            }

            foreach (array_chunk($names, 50) as $chunk) {
                $this->deleter->delete($server, $directory, $chunk);
                $deleted += count($chunk);
            }

            if ($names !== []) {
                $run->note(sprintf('Deleted %d item%s in %s.', count($names), count($names) === 1 ? '' : 's', $directory));
            }
        }

        $run->files_deleted = $deleted;
        if ($deleted === 0) {
            $run->note('No matching files were found.');
        }

        $this->rotate($run, $plan, $server);
        $this->enter($run, 'starting', CarbonImmutable::now());
    }

    private function rotate(WipeRun $run, ?WipePlan $plan, Server $server): void
    {
        if ($plan === null) {
            return;
        }

        $index = $plan->rotation_index;
        $rotated = false;

        if ($plan->seed_variable && $plan->seed_mode !== 'keep') {
            $seed = match ($plan->seed_mode) {
                'random' => (string) random_int(1, 2147483647),
                'list' => $plan->seed_list === [] ? null : $plan->seed_list[$index % count($plan->seed_list)],
                default => null,
            };
            if ($seed !== null) {
                $this->setVariable($server, $plan->seed_variable, $seed);
                $run->seed = $seed;
                $run->note("Seed set to {$seed}.");
                $rotated = true;
            }
        }

        if ($plan->map_variable && $plan->map_list !== []) {
            $map = $plan->map_list[$index % count($plan->map_list)];
            $this->setVariable($server, $plan->map_variable, $map);
            $run->map = $map;
            $run->note("Map set to {$map}.");
            $rotated = true;
        }

        if ($rotated) {
            $plan->rotation_index = $index + 1;
            $plan->save();
        }
    }

    private function start(WipeRun $run, CarbonImmutable $now): void
    {
        if ($run->was_running) {
            $this->power->send($run->server, 'start');
            $run->note('Starting the server.');
        }

        $run->status = 'done';
        $run->finished_at = $now;
        $run->note('Wipe finished.');

        $plan = $run->plan;
        if ($plan !== null) {
            $plan->last_run_at = $now;
            $plan->refreshNext($now);
            $plan->save();

            if ($plan->discord_webhook) {
                try {
                    $this->discord->announce($plan, $run);
                    $run->note('Posted to Discord.');
                } catch (Throwable $exception) {
                    $run->note('Could not post to Discord: '.$exception->getMessage());
                }
            }
        }
    }

    public function fail(WipeRun $run, string $reason): void
    {
        $reached = $run->status;
        $run->status = 'failed';
        $run->error = mb_substr($reason, 0, 1000);
        $run->finished_at = CarbonImmutable::now();
        $run->note('Failed: '.$reason);

        // Before any file is touched the server is safe to bring back. After that,
        // it stays stopped so someone can look at it first.
        if ($run->was_running && in_array($reached, ['stopping', 'backing_up'], true)) {
            try {
                $this->power->send($run->server, 'start');
                $run->note('Started the server again.');
            } catch (Throwable) {
                $run->note('Could not start the server again.');
            }
        } elseif ($reached === 'wiping' || $reached === 'starting') {
            $run->note('The server was left stopped.');
        }

        $plan = $run->plan;
        if ($plan !== null) {
            $plan->refreshNext();
            $plan->save();
        }
    }

    private function enter(WipeRun $run, string $status, CarbonImmutable $now): void
    {
        $run->status = $status;
        $run->step_started_at = $now;
    }

    /** Core caches the reading for up to 20 seconds, which is well inside a scheduler tick. */
    private function isRunning(Server $server, bool $includeStopping = false): bool
    {
        try {
            $state = $this->state->read($server)->state;
        } catch (Throwable) {
            return false;
        }

        return in_array($state, $includeStopping ? ['running', 'starting', 'stopping'] : ['running', 'starting'], true);
    }

    private function tryCommand(WipeRun $run, ?string $command): void
    {
        if ($command === null) {
            return;
        }

        try {
            $this->commands->send($run->server, $command);
        } catch (Throwable) {
            $run->note('Could not send the in-game warning.');
        }
    }

    private function warningCommand(?WipePlan $plan, int $minutes): ?string
    {
        if (! $plan?->warn_command) {
            return null;
        }

        $time = match (true) {
            $minutes <= 0 => 'now',
            $minutes === 1 => 'in 1 minute',
            $minutes % 60 === 0 => sprintf('in %d hour%s', $minutes / 60, $minutes === 60 ? '' : 's'),
            default => "in {$minutes} minutes",
        };

        return strtr($plan->warn_command, ['{time}' => $time, '{minutes}' => (string) $minutes, '{name}' => $plan->name]);
    }

    private function setVariable(Server $server, string $env, string $value): void
    {
        $variable = EggVariable::query()
            ->where('egg_id', $server->egg_id)
            ->where('env_variable', $env)
            ->where('user_viewable', true)
            ->where('user_editable', true)
            ->first();

        if ($variable === null) {
            throw new \RuntimeException("The startup variable {$env} does not exist or cannot be changed.");
        }

        $validator = Validator::make(['value' => $value], ['value' => $variable->rules]);
        if ($validator->fails()) {
            throw new \RuntimeException("{$env}: ".$validator->errors()->first('value'));
        }

        $server->serverVariables()->updateOrCreate(['variable_id' => $variable->id], ['variable_value' => $value]);
    }

    private function settingInt(string $key, int $default): int
    {
        $value = $this->settings->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
