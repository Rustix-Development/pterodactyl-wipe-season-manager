<?php

namespace RustixDev\WipeSeasonManager\Services;

use Carbon\CarbonImmutable;
use RustixDev\WipeSeasonManager\Models\WipePlan;
use RustixDev\WipeSeasonManager\Models\WipeRun;

/**
 * Runs every minute: opens a run when a plan's first warning is due, and moves
 * every open run forward.
 */
class WipeScheduler
{
    /** A wipe more than this late (the panel was down) is recorded as missed, not run at a random time. */
    public const int MISSED_AFTER_MINUTES = 60;

    public function __construct(private readonly WipeRunner $runner) {}

    public function tick(?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $this->openDueRuns($now);

        WipeRun::query()
            ->whereIn('status', WipeRun::ACTIVE)
            ->with(['plan', 'server'])
            ->orderBy('scheduled_for')
            ->get()
            ->each(fn (WipeRun $run) => $this->runner->advance($run, $now));
    }

    /** Start a run now, without warnings. */
    public function runNow(WipePlan $plan, CarbonImmutable $now): WipeRun
    {
        $run = $this->open($plan, $now, 'manual');
        $run->setRelation('plan', $plan);
        $run->setRelation('server', $plan->server);
        $this->runner->advance($run, $now);

        return $run;
    }

    private function openDueRuns(CarbonImmutable $now): void
    {
        $plans = WipePlan::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            // Look a day ahead; each plan's own warning window is checked below.
            ->where('next_run_at', '<=', $now->addDay())
            ->with('server')
            ->get();

        foreach ($plans as $plan) {
            $warnAhead = max([0, ...$plan->warn_minutes]);
            if ($plan->next_run_at->subMinutes($warnAhead)->greaterThan($now)) {
                continue;
            }

            if (WipeRun::query()->where('server_id', $plan->server_id)->whereIn('status', WipeRun::ACTIVE)->exists()) {
                continue;
            }

            if ($plan->next_run_at->addMinutes(self::MISSED_AFTER_MINUTES)->lessThan($now)) {
                $this->close($plan, $now, 'missed', 'The panel was not running when this wipe was due, so it was skipped.');

                continue;
            }

            if ($this->yieldsToAnotherPlan($plan)) {
                $this->close($plan, $now, 'skipped', 'Skipped because another wipe on this server is close to it.');

                continue;
            }

            $this->open($plan, $plan->next_run_at, 'schedule');
        }
    }

    /** A plan with "skip when another wipe is within X hours" steps aside for the other plan. */
    private function yieldsToAnotherPlan(WipePlan $plan): bool
    {
        if (! $plan->skip_within_hours) {
            return false;
        }

        return WipePlan::query()
            ->where('server_id', $plan->server_id)
            ->where('id', '!=', $plan->id)
            ->where('enabled', true)
            ->whereBetween('next_run_at', [
                $plan->next_run_at->subHours($plan->skip_within_hours),
                $plan->next_run_at->addHours($plan->skip_within_hours),
            ])
            ->exists();
    }

    private function open(WipePlan $plan, CarbonImmutable $scheduledFor, string $trigger): WipeRun
    {
        $run = new WipeRun([
            'server_id' => $plan->server_id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'trigger' => $trigger,
            'status' => 'warning',
            'scheduled_for' => $scheduledFor,
        ]);
        $run->note($trigger === 'manual' ? 'Started by hand.' : 'Wipe scheduled; sending warnings.');
        $run->save();

        return $run;
    }

    private function close(WipePlan $plan, CarbonImmutable $now, string $status, string $reason): void
    {
        $run = new WipeRun([
            'server_id' => $plan->server_id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'trigger' => 'schedule',
            'status' => $status,
            'scheduled_for' => $plan->next_run_at,
            'finished_at' => $now,
            'error' => $reason,
        ]);
        $run->note($reason);
        $run->save();

        // Move past this occurrence.
        $plan->skip_until = $plan->next_run_at;
        $plan->refreshNext($now);
        $plan->save();
    }
}
