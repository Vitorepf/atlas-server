<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;

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

        $severity = AiValueNormalizer::trimmedStringOrNull($blocker['severity'] ?? null);
        if ($severity === null) {
            return '';
        }

        return strtolower($severity);
    }

    public static function isDecisive(string $severity): bool
    {
        return $severity === self::HIGH || $severity === self::CRITICAL;
    }

    /**
     * @param  mixed  $blocker
     */
    public static function hasOwner(mixed $blocker): bool
    {
        if (! is_array($blocker)) {
            return false;
        }

        $owner = AiValueNormalizer::trimmedStringOrNull($blocker['owner'] ?? null);

        return $owner !== null;
    }
}
