<?php

namespace RustixDev\WipeSeasonManager\Services;

use Illuminate\Support\Facades\Http;
use RustixDev\WipeSeasonManager\Models\WipePlan;
use RustixDev\WipeSeasonManager\Models\WipeRun;

class DiscordNotifier
{
    public const string DEFAULT_MESSAGE = '**{server}** has wiped ({plan}). Next wipe: {next}.';

    /** Discord webhook URLs only, so the panel cannot be used to send requests elsewhere. */
    public const string WEBHOOK_PATTERN = '#^https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$#';

    public function announce(WipePlan $plan, WipeRun $run): void
    {
        $next = $plan->next_run_at
            ? sprintf('<t:%1$d:F> (<t:%1$d:R>)', $plan->next_run_at->getTimestamp())
            : 'not scheduled';

        $content = strtr($plan->discord_message ?: self::DEFAULT_MESSAGE, [
            '{server}' => $plan->server->name,
            '{plan}' => $plan->name,
            '{seed}' => $run->seed ?? 'unchanged',
            '{map}' => $run->map ?? 'unchanged',
            '{next}' => $next,
        ]);

        Http::timeout(5)
            ->post((string) $plan->discord_webhook, [
                'content' => mb_substr($content, 0, 2000),
                'allowed_mentions' => ['parse' => ['everyone', 'roles']],
            ])
            ->throw();
    }
}
