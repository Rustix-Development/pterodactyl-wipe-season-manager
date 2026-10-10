<?php

namespace RustixDev\WipeSeasonManager\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;

/**
 * One wipe, from its first warning to the server starting again.
 *
 * @property int $id
 * @property int $server_id
 * @property int|null $plan_id
 * @property string $plan_name
 * @property string $trigger
 * @property string $status
 * @property CarbonImmutable $scheduled_for
 * @property list<int> $warnings_sent
 * @property bool $was_running
 * @property CarbonImmutable|null $step_started_at
 * @property bool $kill_sent
 * @property int|null $backup_id
 * @property int $files_deleted
 * @property string|null $seed
 * @property string|null $map
 * @property list<array{at: string, message: string}> $log
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property WipePlan|null $plan
 * @property Server $server
 */
class WipeRun extends Model
{
    public const array ACTIVE = ['warning', 'stopping', 'backing_up', 'wiping', 'starting'];

    protected $table = 'ext_wipe_season_manager_runs';

    protected $guarded = ['id'];

    protected $attributes = [
        'warnings_sent' => '[]',
        'log' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'step_started_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'warnings_sent' => 'array',
            'log' => 'array',
            'was_running' => 'boolean',
            'kill_sent' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WipePlan::class, 'plan_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function note(string $message): void
    {
        $log = $this->log;
        $log[] = ['at' => CarbonImmutable::now()->toIso8601String(), 'message' => $message];
        $this->log = array_slice($log, -100);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->plan_id,
            'plan_name' => $this->plan_name,
            'trigger' => $this->trigger,
            'status' => $this->status,
            'scheduled_for' => $this->scheduled_for->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'files_deleted' => $this->files_deleted,
            'seed' => $this->seed,
            'map' => $this->map,
            'error' => $this->error,
            'log' => $this->log,
        ];
    }
}
