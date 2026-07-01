<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

final class AaeosBlockerSeverityGate
{
    private const SEVERITY_CRITICAL = 'critical';

    private const SEVERITY_HIGH = 'high';

    private const SEVERITY_MEDIUM = 'medium';

    private const SEVERITY_LOW = 'low';

    private const SIGNAL_BLOCKED = 'blocked';

    private const SIGNAL_WARNING = 'warning';

    private const SIGNAL_CLEAR = 'clear';

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
            $severity = $this->normalizeSeverity($blocker);

            match ($severity) {
                self::SEVERITY_CRITICAL => $criticalCount++,
                self::SEVERITY_HIGH => $highCount++,
                self::SEVERITY_MEDIUM => $mediumCount++,
                self::SEVERITY_LOW => $lowCount++,
                default => $unknownCount++,
            };

            if (in_array($severity, [self::SEVERITY_CRITICAL, self::SEVERITY_HIGH], true)
                && ! $this->hasOwner($blocker)) {
                $ownerlessBlockers[] = $blocker;
            }
        }

        return [
            'signal' => $this->resolveSignal($criticalCount, $highCount, $mediumCount),
            'critical_count' => $criticalCount,
            'high_count' => $highCount,
            'medium_count' => $mediumCount,
            'low_count' => $lowCount,
            'unknown_count' => $unknownCount,
            'ownerless_blockers' => $ownerlessBlockers,
        ];
    }

    /**
     * @param  mixed  $blocker
     */
    private function hasOwner($blocker): bool
    {
        if (! is_array($blocker)) {
            return false;
        }

        $owner = $blocker['owner'] ?? null;

        return is_string($owner) && trim($owner) !== '';
    }

    /**
     * @param  mixed  $blocker
     */
    private function normalizeSeverity($blocker): string
    {
        if (! is_array($blocker)) {
            return '';
        }

        $severity = $blocker['severity'] ?? null;

        if (! is_string($severity)) {
            return '';
        }

        return strtolower(trim($severity));
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
