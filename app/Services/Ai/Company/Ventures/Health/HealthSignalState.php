<?php

namespace App\Services\Ai\Company\Ventures\Health;

/**
 * K4 — a single health signal's state. `unknown` is a FIRST-CLASS state,
 * distinct from `red`: both are fail-closed (neither permits success), but
 * they mean different things — red = measured-bad, unknown = not-yet-known.
 */
enum HealthSignalState: string
{
    case GREEN = 'green';
    case RED = 'red';
    case UNKNOWN = 'unknown';

    /** Higher = worse. Used for monotonic-downgrade composition. */
    public function severity(): int
    {
        return match ($this) {
            self::GREEN => 0,
            self::UNKNOWN => 1,
            self::RED => 2,
        };
    }

    public function permitsSuccess(): bool
    {
        return $this === self::GREEN;
    }
}
