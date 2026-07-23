<?php

namespace App\Services\Ai\DualCore;

/**
 * Canonical enums and defaults for `atlas.dual_core.route_decision.v1`
 * (`atlas-dual-core-engineering-system.md:247-258`). Single source of truth
 * shared by service, model, tests and any future integration adapter.
 */
final class DualCoreRouteDecisionCanon
{
    public const SCHEMA_VERSION = 'atlas.dual_core.route_decision.v1';

    public const ROUTE_DEV = 'dev';

    public const ROUTE_FORGE = 'forge';

    public const ROUTE_DEV_TO_FORGE = 'dev_to_forge';

    /** Third elite executor (zero human in eng loop). Same bar L0–L5. */
    public const ROUTE_AUTONOMOS = 'autonomos';

    /** @var array<int,string> */
    public const ROUTES = [
        self::ROUTE_DEV,
        self::ROUTE_FORGE,
        self::ROUTE_DEV_TO_FORGE,
        self::ROUTE_AUTONOMOS,
    ];

    public const AMBIGUITY_LOW = 'low';

    public const AMBIGUITY_MEDIUM = 'medium';

    public const AMBIGUITY_HIGH = 'high';

    /** @var array<int,string> */
    public const AMBIGUITY_LEVELS = [
        self::AMBIGUITY_LOW,
        self::AMBIGUITY_MEDIUM,
        self::AMBIGUITY_HIGH,
    ];

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    /** @var array<int,string> */
    public const RISK_LEVELS = [
        self::RISK_LOW,
        self::RISK_MEDIUM,
        self::RISK_HIGH,
        self::RISK_CRITICAL,
    ];

    public const DURATION_MINUTES = 'minutes';

    public const DURATION_HOURS = 'hours';

    public const DURATION_DAYS = 'days';

    public const DURATION_WEEKS = 'weeks';

    public const DURATION_MONTHS = 'months';

    /** @var array<int,string> */
    public const EXPECTED_DURATIONS = [
        self::DURATION_MINUTES,
        self::DURATION_HOURS,
        self::DURATION_DAYS,
        self::DURATION_WEEKS,
        self::DURATION_MONTHS,
    ];

    /**
     * Default evidence_required list per route. Aligned with
     * `atlas-dual-core-engineering-system.md:355-359` shared evidence
     * contract — dev path produces plan/receipt/verification; forge path
     * adds sdd/work_packets/evidence_pack; dev_to_forge path carries the
     * escalation_packet contract slot.
     *
     * @return array<int,string>
     */
    public static function defaultEvidenceRequired(string $route): array
    {
        return match ($route) {
            self::ROUTE_DEV => ['plan', 'receipt', 'verification'],
            self::ROUTE_FORGE => ['sdd', 'plan', 'work_packets', 'receipt', 'verification', 'evidence_pack'],
            self::ROUTE_DEV_TO_FORGE => ['plan', 'receipt', 'escalation_packet'],
            self::ROUTE_AUTONOMOS => ['task_contract', 'receipt', 'verification', 'scoped_commit'],
            default => ['plan', 'receipt', 'verification'],
        };
    }

    public static function defaultSddRequired(string $route): bool
    {
        return ! in_array($route, [self::ROUTE_DEV, self::ROUTE_AUTONOMOS], true);
    }
}

