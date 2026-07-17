<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

/**
 * Shared severity normalization for AAEOS phase blockers.
 *
 * Used by {@see AaeosBlockerSeverityGate} and {@see PhaseAdvanceVerdictClassifier}
 * so high/critical decisive semantics stay in one place.
 */
final class AaeosBlockerSeverity
{
    public const CRITICAL = 'critical';

    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    /**
     * @param  mixed  $blocker
     */
    public static function of(mixed $blocker): string
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

    public static function isDecisive(string $severity): bool
    {
        return $severity === self::HIGH || $severity === self::CRITICAL;
    }
}
