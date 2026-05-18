<?php

namespace App\Services\Ai\AutomationDomain;

use RuntimeException;

/**
 * Domain exception raised when an Automation Runtime invariant is violated:
 * unknown run kind, missing payload fields, illegal plan_type, etc.
 */
class AutomationDomainException extends RuntimeException
{
    public static function invalidRunKind(string $given): self
    {
        return new self("invalid run_kind [{$given}] — expected one of: ".implode(',', AutomationDomainCanon::RUN_KINDS));
    }

    public static function invalidPlanType(string $given): self
    {
        return new self("invalid plan_type [{$given}] — expected one of: ".implode(',', AutomationDomainCanon::PLAN_TYPES));
    }

    public static function invalidDecisionKind(string $given): self
    {
        return new self("invalid decision_kind [{$given}] — expected one of: ".implode(',', AutomationDomainCanon::DECISION_KINDS));
    }

    public static function invalidEvolutionEventKind(string $given): self
    {
        return new self("invalid event_kind [{$given}] — expected one of: ".implode(',', AutomationDomainCanon::EVOLUTION_EVENT_KINDS));
    }

    public static function missingField(string $field): self
    {
        return new self("automation payload missing required field [{$field}]");
    }
}
