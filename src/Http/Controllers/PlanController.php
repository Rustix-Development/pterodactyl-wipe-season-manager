<?php

namespace RustixDev\WipeSeasonManager\Http\Controllers;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use RustixDev\WipeSeasonManager\Models\WipePlan;
use RustixDev\WipeSeasonManager\Models\WipeRun;
use RustixDev\WipeSeasonManager\Services\DiscordNotifier;
use RustixDev\WipeSeasonManager\Services\WipeScheduler;
use RustixDev\WipeSeasonManager\Support\Occurrence;
use RustixDev\WipeSeasonManager\Support\PathPattern;

class PlanController
{
    public function __construct(private readonly ExtensionSettings $settings) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);

        $runs = WipeRun::query()->where('server_id', $server->id)->latest('id')->limit(25)->get();

        return new JsonResponse([
            'plans' => WipePlan::query()->where('server_id', $server->id)->orderBy('id')->get()->map->toPayload(),
            'runs' => $runs->map->toPayload(),
            'max_plans' => $this->maxPlans(),
        ]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        abort_if(WipePlan::query()->where('server_id', $server->id)->count() >= $this->maxPlans(), 422, 'This server already has the most wipe plans allowed.');

        $plan = new WipePlan(['server_id' => $server->id]);
        $this->fill($plan, $request);

        return new JsonResponse(['data' => $plan->toPayload()], 201);
    }

    public function update(Request $request, Server $server, int $plan): JsonResponse
    {
        $this->authorize($request, $server);
        $model = $this->find($server, $plan);
        $this->fill($model, $request);

        return new JsonResponse(['data' => $model->toPayload()]);
    }

    public function destroy(Request $request, Server $server, int $plan): Response
    {
        $this->authorize($request, $server);
        $model = $this->find($server, $plan);
        abort_if($model->runs()->whereIn('status', WipeRun::ACTIVE)->exists(), 409, 'This plan is wiping right now. Cancel the wipe first.');
        $model->delete();

        return response()->noContent();
    }

    public function run(Request $request, Server $server, int $plan, WipeScheduler $scheduler): JsonResponse
    {
        $this->authorize($request, $server);
        $model = $this->find($server, $plan);
        abort_if(WipeRun::query()->where('server_id', $server->id)->whereIn('status', WipeRun::ACTIVE)->exists(), 409, 'A wipe is already running on this server.');

        $model->setRelation('server', $server);
        $run = $scheduler->runNow($model, CarbonImmutable::now());

        return new JsonResponse(['data' => $run->fresh()?->toPayload()], 202);
    }

    /** Skip the next scheduled wipe, or undo that. */
    public function skip(Request $request, Server $server, int $plan): JsonResponse
    {
        $this->authorize($request, $server);
        $model = $this->find($server, $plan);
        $skip = $request->boolean('skip', true);

        if ($skip && $model->next_run_at) {
            $model->skip_until = $model->next_run_at;
        } elseif (! $skip) {
            $model->skip_until = null;
        }
        $model->refreshNext();
        $model->save();

        return new JsonResponse(['data' => $model->toPayload()]);
    }

    /** Stop a wipe that is still warning players. Once the server is stopping it has to finish. */
    public function cancel(Request $request, Server $server, int $run): JsonResponse
    {
        $this->authorize($request, $server);
        $model = WipeRun::query()->where('server_id', $server->id)->findOrFail($run);
        abort_unless($model->status === 'warning', 409, 'This wipe has already started and can no longer be cancelled.');

        $model->status = 'cancelled';
        $model->finished_at = CarbonImmutable::now();
        $model->note('Cancelled by '.$request->user()->username.'.');
        $model->save();

        if ($plan = $model->plan) {
            $plan->skip_until = $model->scheduled_for;
            $plan->refreshNext();
            $plan->save();
        }

        return new JsonResponse(['data' => $model->toPayload()]);
    }

    private function fill(WipePlan $plan, Request $request): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'game' => ['required', 'string', 'max:32', 'alpha_dash'],
            'enabled' => ['boolean'],
            'anchor_local' => ['required', 'date_format:Y-m-d\TH:i'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'repeat' => ['required', Rule::in(Occurrence::REPEATS)],
            'skip_within_hours' => ['nullable', 'integer', 'min:1', 'max:336'],
            'warn_minutes' => ['present', 'array', 'max:10'],
            'warn_minutes.*' => ['integer', 'min:1', 'max:1440', 'distinct'],
            'warn_command' => ['nullable', 'string', 'max:255'],
            'backup' => ['boolean'],
            'paths' => ['present', 'array', 'max:50'],
            'paths.*' => ['string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || PathPattern::parse($value) === null) {
                    $fail("\"{$value}\" is not a safe path. Use a path inside the server, wildcards only in the last part, and no \"..\".");
                }
            }],
            'seed_variable' => ['nullable', 'string', 'max:191', 'regex:/^[A-Z0-9_]+$/'],
            'seed_mode' => ['required', Rule::in(['keep', 'random', 'list'])],
            'seed_list' => ['present', 'array', 'max:100'],
            'seed_list.*' => ['string', 'max:191'],
            'map_variable' => ['nullable', 'string', 'max:191', 'regex:/^[A-Z0-9_]+$/'],
            'map_list' => ['present', 'array', 'max:100'],
            'map_list.*' => ['string', 'max:2048'],
            // Null keeps the saved webhook, an empty string removes it.
            'discord_webhook' => ['nullable', 'string', 'max:255'],
            'discord_message' => ['nullable', 'string', 'max:1000'],
        ], [], ['anchor_local' => 'first wipe', 'paths.*' => 'path']);

        abort_if($data['seed_mode'] === 'list' && $data['seed_list'] === [], 422, 'Add at least one seed to rotate through.');

        $webhook = $data['discord_webhook'] ?? null;
        if ($webhook !== null && $webhook !== '' && ! preg_match(DiscordNotifier::WEBHOOK_PATTERN, $webhook)) {
            abort(422, 'The Discord webhook must be a discord.com/api/webhooks/... link.');
        }
        if (! $this->discordAllowed()) {
            $webhook = '';
        }
        unset($data['discord_webhook']);

        $scheduleChanged = ! $plan->exists
            || $plan->anchor_local?->format('Y-m-d\TH:i') !== $data['anchor_local']
            || $plan->timezone !== $data['timezone']
            || $plan->repeat !== $data['repeat'];

        $plan->fill([
            ...$data,
            'enabled' => $data['enabled'] ?? true,
            'backup' => $data['backup'] ?? true,
            'warn_minutes' => array_values(array_map('intval', $data['warn_minutes'])),
            'paths' => array_values(array_filter(array_map('trim', $data['paths']))),
            'seed_list' => array_values(array_filter(array_map('trim', $data['seed_list']))),
            'map_list' => array_values(array_filter(array_map('trim', $data['map_list']))),
            'anchor_local' => CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['anchor_local'], 'UTC'),
        ]);

        if ($webhook === '') {
            $plan->discord_webhook = null;
        } elseif ($webhook !== null) {
            $plan->discord_webhook = $webhook;
        }

        if ($scheduleChanged) {
            $plan->skip_until = null;
            $plan->last_run_at = null;
        }
        $plan->refreshNext();
        $plan->save();
    }

    private function find(Server $server, int $id): WipePlan
    {
        return WipePlan::query()->where('server_id', $server->id)->findOrFail($id);
    }

    private function authorize(Request $request, Server $server): void
    {
        abort_unless($request->user()->can('ext.wipe-season-manager.manage', $server), 403);
    }

    private function maxPlans(): int
    {
        $value = $this->settings->get('max_plans', 5);

        return is_numeric($value) ? max(1, (int) $value) : 5;
    }

    private function discordAllowed(): bool
    {
        return (bool) $this->settings->get('allow_discord', true);
    }
}
