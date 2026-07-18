<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

final class AaeosBlockerSeverityGate
{
    public const SIGNAL_BLOCKED = 'blocked';

    public const SIGNAL_WARNING = 'warning';

    public const SIGNAL_CLEAR = 'clear';
    public const FIELD_CRITICAL_COUNT = 'critical_count';
    public const FIELD_HIGH_COUNT = 'high_count';
    public const FIELD_UNKNOWN_COUNT = 'unknown_count';
    public const FIELD_SIGNAL = 'signal';
    public const FIELD_LOW_COUNT = 'low_count';
    public const FIELD_MEDIUM_COUNT = 'medium_count';
    public const FIELD_OWNERLESS_BLOCKERS = 'ownerless_blockers';

    /** @var list<string> */
    public const SIGNALS = [
        self::SIGNAL_BLOCKED,
        self::SIGNAL_WARNING,
        self::SIGNAL_CLEAR,
    ];

    /**
     * Pure reduction over blockers[] of schema atlas.aaeos.phase.v1
     * (array{id, severity, owner}). Any high severity forces 'blocked'
     * and overrides medium; otherwise any medium forces 'warning';
     * otherwise (empty or low-only) the gate is 'clear'. Severity is
     * compared case-insensitively after trimming; missing or blank
     * severity is ignored and never tallied.
     *
     * @param  array<int|string, mixed>  $blockers
     * @return array{signal: string, high_count: int, medium_count: int}
     */
    public function assess(array $blockers): array
    {
        $criticalCount = 0;
        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;
        $unknownCount = 0;
        $ownerlessBlockers = [];

        foreach ($blockers as $blocker) {
            $severity = AaeosBlockerSeverity::of($blocker);

            match ($severity) {
                AaeosBlockerSeverity::CRITICAL => $criticalCount++,
                AaeosBlockerSeverity::HIGH => $highCount++,
                AaeosBlockerSeverity::MEDIUM => $mediumCount++,
                AaeosBlockerSeverity::LOW => $lowCount++,
                default => $unknownCount++,
            };

            if (AaeosBlockerSeverity::isDecisive($severity)
                && ! AaeosBlockerSeverity::hasOwner($blocker)) {
                $ownerlessBlockers[] = $blocker;
            }
        }

        return [
            self::FIELD_SIGNAL => $this->resolveSignal($criticalCount, $highCount, $mediumCount),
            self::FIELD_CRITICAL_COUNT => $criticalCount,
            self::FIELD_HIGH_COUNT => $highCount,
            self::FIELD_MEDIUM_COUNT => $mediumCount,
            self::FIELD_LOW_COUNT => $lowCount,
            self::FIELD_UNKNOWN_COUNT => $unknownCount,
            self::FIELD_OWNERLESS_BLOCKERS => $ownerlessBlockers,
        ];
    }

    private function resolveSignal(int $criticalCount, int $highCount, int $mediumCount): string
    {
        if ($criticalCount > 0 || $highCount > 0) {
            return self::SIGNAL_BLOCKED;
        }

        if ($mediumCount > 0) {
            return self::SIGNAL_WARNING;
        }

        return self::SIGNAL_CLEAR;
    }
}
