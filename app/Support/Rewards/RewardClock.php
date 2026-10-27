<?php

namespace App\Support\Rewards;

use Carbon\CarbonImmutable;

/** Reward schedules and audit timestamps are UTC, independent of the legacy app timezone. */
final class RewardClock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function parse(string $date): CarbonImmutable
    {
        // Explicit offsets remain authoritative; naive operator inputs mean UTC.
        return CarbonImmutable::parse($date, 'UTC')->utc();
    }
}
