<?php

namespace RustixDev\WipeSeasonManager\Support;

use Carbon\CarbonImmutable;

/**
 * Works out when a plan next runs. The calendar is read in the plan's own time
 * zone, so "every Thursday at 19:00" stays at 19:00 local time across daylight
 * saving changes. Results are returned in UTC.
 */
final class Occurrence
{
    public const array REPEATS = ['once', 'daily', 'weekly', 'biweekly', 'monthly', 'first-thursday'];

    /** The first run strictly after $after, or null when a one-off plan has already run. */
    public static function next(CarbonImmutable $anchorLocal, string $timezone, string $repeat, CarbonImmutable $after): ?CarbonImmutable
    {
        $anchor = CarbonImmutable::create(
            $anchorLocal->year, $anchorLocal->month, $anchorLocal->day,
            $anchorLocal->hour, $anchorLocal->minute, 0, $timezone,
        );
        $now = $after->setTimezone($timezone);

        if ($anchor->greaterThan($now)) {
            return $anchor->utc();
        }

        $next = match ($repeat) {
            'daily' => self::stepDays($anchor, 1, $now),
            'weekly' => self::stepDays($anchor, 7, $now),
            'biweekly' => self::stepDays($anchor, 14, $now),
            'monthly' => self::monthly($anchor, $now),
            'first-thursday' => self::firstThursday($anchor, $now),
            default => null,
        };

        return $next?->utc();
    }

    private static function stepDays(CarbonImmutable $anchor, int $days, CarbonImmutable $now): CarbonImmutable
    {
        $steps = intdiv((int) $anchor->startOfDay()->diffInDays($now->startOfDay(), true), $days);
        $next = $anchor->addDays($steps * $days);

        while ($next->lessThanOrEqualTo($now)) {
            $next = $next->addDays($days);
        }

        return $next;
    }

    /** Same day of the month as the first run, moved to the month's last day when it is shorter. */
    private static function monthly(CarbonImmutable $anchor, CarbonImmutable $now): CarbonImmutable
    {
        $month = $now->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            $start = $month->addMonthsNoOverflow($i);
            $candidate = $start->setDay(min($anchor->day, $start->daysInMonth))->setTime($anchor->hour, $anchor->minute);

            if ($candidate->greaterThan($now)) {
                return $candidate;
            }
        }

        return $anchor->addMonthsNoOverflow(1);
    }

    /** The first Thursday of each month at the first run's time: Rust's forced wipe. */
    private static function firstThursday(CarbonImmutable $anchor, CarbonImmutable $now): CarbonImmutable
    {
        $month = $now->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            $start = $month->addMonthsNoOverflow($i);
            $candidate = $start->addDays((4 - $start->dayOfWeek + 7) % 7)->setTime($anchor->hour, $anchor->minute);

            if ($candidate->greaterThan($now)) {
                return $candidate;
            }
        }

        return $anchor;
    }
}
