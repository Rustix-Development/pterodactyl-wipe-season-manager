<?php

namespace RustixDev\WipeSeasonManager\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RustixDev\WipeSeasonManager\Models\WipePlan;
use RustixDev\WipeSeasonManager\Models\WipeRun;

/** Every server's upcoming and recent wipes, for the admin overview. */
class AdminController
{
    public function index(): JsonResponse
    {
        $upcoming = WipePlan::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->orderBy('next_run_at')
            ->with('server:id,uuid,uuidShort,name')
            ->limit(100)
            ->get()
            ->map(fn (WipePlan $plan) => [
                'server' => $plan->server?->only(['id', 'name', 'uuidShort']),
                'plan' => $plan->name,
                'repeat' => $plan->repeat,
                'next_run_at' => $plan->next_run_at?->toIso8601String(),
                'skipping_next' => $plan->skip_until !== null && $plan->skip_until->isFuture(),
            ]);

        $recent = WipeRun::query()
            ->latest('id')
            ->with('server:id,uuid,uuidShort,name')
            ->limit(50)
            ->get()
            ->map(fn (WipeRun $run) => [
                ...$run->toPayload(),
                'server' => $run->server?->only(['id', 'name', 'uuidShort']),
            ]);

        return new JsonResponse(['upcoming' => $upcoming, 'recent' => $recent]);
    }
}
