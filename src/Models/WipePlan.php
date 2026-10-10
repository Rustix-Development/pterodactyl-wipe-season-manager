<?php

namespace RustixDev\WipeSeasonManager\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pterodactyl\Models\Server;
use RustixDev\WipeSeasonManager\Support\Occurrence;

/**
 * @property int $id
 * @property int $server_id
 * @property string $name
 * @property string $game
 * @property bool $enabled
 * @property CarbonImmutable $anchor_local
 * @property string $timezone
 * @property string $repeat
 * @property int|null $skip_within_hours
 * @property CarbonImmutable|null $skip_until
 * @property CarbonImmutable|null $next_run_at
 * @property list<int> $warn_minutes
 * @property string|null $warn_command
 * @property bool $backup
 * @property list<string> $paths
 * @property string|null $seed_variable
 * @property string $seed_mode
 * @property list<string> $seed_list
 * @property string|null $map_variable
 * @property list<string> $map_list
 * @property int $rotation_index
 * @property string|null $discord_webhook
 * @property string|null $discord_message
 * @property CarbonImmutable|null $last_run_at
 * @property Server $server
 */
class WipePlan extends Model
{
    protected $table = 'ext_wipe_season_manager_plans';

    protected $guarded = ['id'];

    protected $attributes = [
        'warn_minutes' => '[]',
        'paths' => '[]',
        'seed_list' => '[]',
        'map_list' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'backup' => 'boolean',
            'anchor_local' => 'immutable_datetime',
            'skip_until' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'warn_minutes' => 'array',
            'paths' => 'array',
            'seed_list' => 'array',
            'map_list' => 'array',
            'discord_webhook' => 'encrypted',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WipeRun::class, 'plan_id');
    }

    /** The next run after now (or after a skipped run), or null for a finished one-off plan. */
    public function computeNext(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $after = $now ?? CarbonImmutable::now();
        if ($this->skip_until && $this->skip_until->greaterThan($after)) {
            $after = $this->skip_until;
        }

        // A one-off plan that already ran has nothing left.
        if ($this->repeat === 'once' && $this->last_run_at) {
            return null;
        }

        return Occurrence::next($this->anchor_local, $this->timezone, $this->repeat, $after);
    }

    public function refreshNext(?CarbonImmutable $now = null): void
    {
        $this->next_run_at = $this->enabled ? $this->computeNext($now) : null;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        $webhook = $this->discord_webhook;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'game' => $this->game,
            'enabled' => $this->enabled,
            'anchor_local' => $this->anchor_local->format('Y-m-d\TH:i'),
            'timezone' => $this->timezone,
            'repeat' => $this->repeat,
            'skip_within_hours' => $this->skip_within_hours,
            'skipping_next' => $this->skip_until !== null && $this->skip_until->isFuture(),
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'warn_minutes' => $this->warn_minutes,
            'warn_command' => $this->warn_command,
            'backup' => $this->backup,
            'paths' => $this->paths,
            'seed_variable' => $this->seed_variable,
            'seed_mode' => $this->seed_mode,
            'seed_list' => $this->seed_list,
            'map_variable' => $this->map_variable,
            'map_list' => $this->map_list,
            // The webhook URL holds a secret, so only its end is shown.
            'discord_webhook' => $webhook ? '…'.substr($webhook, -6) : null,
            'discord_message' => $this->discord_message,
        ];
    }
}
