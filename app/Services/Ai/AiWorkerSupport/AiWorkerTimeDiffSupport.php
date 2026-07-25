<?php

declare(strict_types=1);

namespace App\Services\Ai\AiWorkerSupport;

/**
 * Pure millisecond duration helpers for AiWorker telemetry (full-pass peel).
 */
final class AiWorkerTimeDiffSupport
{
    public static function epochMs(\DateTimeInterface $value): int
    {
        return ((int) $value->format('U') * 1000) + (int) floor(((int) $value->format('u')) / 1000);
    }

    public static function diffMs(mixed $start, mixed $end): ?int
    {
        if (! $start || ! $end || ! $start instanceof \DateTimeInterface || ! $end instanceof \DateTimeInterface) {
            return null;
        }

        return max(0, self::epochMs($end) - self::epochMs($start));
    }
}
