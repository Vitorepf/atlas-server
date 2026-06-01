<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

final class AaeosBlockerSeverityGate
{
    private const SEVERITY_HIGH = 'high';

    private const SEVERITY_MEDIUM = 'medium';

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
        $highCount = 0;
        $mediumCount = 0;

        foreach ($blockers as $blocker) {
            $severity = $this->normalizeSeverity($blocker);

            if ($severity === self::SEVERITY_HIGH) {
                $highCount++;

                continue;
            }

            if ($severity === self::SEVERITY_MEDIUM) {
                $mediumCount++;
            }
        }

        return [
            'signal' => $this->resolveSignal($highCount, $mediumCount),
            'high_count' => $highCount,
            'medium_count' => $mediumCount,
        ];
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

    private function resolveSignal(int $highCount, int $mediumCount): string
    {
        if ($highCount > 0) {
            return self::SIGNAL_BLOCKED;
        }

        if ($mediumCount > 0) {
            return self::SIGNAL_WARNING;
        }

        return self::SIGNAL_CLEAR;
    }
}
