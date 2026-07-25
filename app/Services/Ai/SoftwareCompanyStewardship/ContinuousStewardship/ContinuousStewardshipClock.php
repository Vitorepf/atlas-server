<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Shared byte-identical helper(s) de-duplicated across this family (time, now).
 */
trait ContinuousStewardshipClock
{
    private function time(string $value): ?int
    {
        if (trim($value) === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }
}
