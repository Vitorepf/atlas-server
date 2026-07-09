<?php

namespace App\Services\Ai\AutonomousEvolution\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Shared timestamp generation for AutonomousEvolution services.
 *
 * Centralises the near-identical `private function now()` copies scattered
 * across the namespace. Each helper keeps the exact semantics of the original
 * implementations (clock injection, UTC normalisation, atom format) so callers
 * remain deterministic and testable.
 *
 * @unwired-until 2026-07-16
 */
trait NowTrait
{
    /**
     * Current wall-clock time as an ISO-8601 / DATE_ATOM string.
     *
     * @param  (callable(): string)|null  $clock
     */
    private function nowAsString(?callable $clock = null): string
    {
        return $clock !== null
            ? $clock()
            : date(DATE_ATOM);
    }

    /**
     * Current wall-clock time as a UTC DateTimeImmutable.
     *
     * @param  (callable(): DateTimeInterface)|null  $clock
     */
    private function nowAsDateTimeImmutable(?callable $clock = null): DateTimeImmutable
    {
        if ($clock !== null) {
            $value = $clock();

            return $value instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($value)
                : new DateTimeImmutable(is_string($value) ? $value : 'now', new DateTimeZone('UTC'));
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Current wall-clock time as Unix seconds. */
    private function nowAsTimestamp(): int
    {
        return time();
    }
}
