<?php

namespace App\Services\Ai\DualCore;

use InvalidArgumentException;

class DualCoreRouteDecisionException extends InvalidArgumentException
{
    public static function invalidRoute(string $route): self
    {
        $allowed = implode(', ', DualCoreRouteDecisionCanon::ROUTES);

        return new self("Invalid dual_core route [{$route}]; allowed: [{$allowed}].");
    }

    public static function invalidAmbiguityLevel(string $level): self
    {
        $allowed = implode(', ', DualCoreRouteDecisionCanon::AMBIGUITY_LEVELS);

        return new self("Invalid ambiguity_level [{$level}]; allowed: [{$allowed}].");
    }

    public static function invalidRiskLevel(string $level): self
    {
        $allowed = implode(', ', DualCoreRouteDecisionCanon::RISK_LEVELS);

        return new self("Invalid risk_level [{$level}]; allowed: [{$allowed}].");
    }

    public static function invalidExpectedDuration(string $duration): self
    {
        $allowed = implode(', ', DualCoreRouteDecisionCanon::EXPECTED_DURATIONS);

        return new self("Invalid expected_duration [{$duration}]; allowed: [{$allowed}].");
    }

    public static function emptyIntentSummary(): self
    {
        return new self('intent_summary cannot be empty.');
    }

    public static function emptyReason(): self
    {
        return new self('reason cannot be empty.');
    }
}
