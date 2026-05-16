<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use App\Services\Ai\Programming\AtlasDev\Pipeline\RoutingDecision;

/**
 * Maps the core's `routing_decision` enum to the short surface-level
 * `status` string that App and API responses share. The status is the
 * single thing a thin client should need to switch on; everything else is
 * detail (`routing_decision`, `blockers`, `suggested_flow`).
 *
 * Pure helper — surface-agnostic by intent (App and API both use it).
 */
final class SurfaceStatusMapper
{
    public const STATUS_READY = 'ready';

    public const STATUS_READ_ONLY = 'read_only';

    public const STATUS_FORGE_PREVIEW = 'forge_preview';

    public const STATUS_DELEGATED = 'delegated';

    public const STATUS_BLOCKED = 'blocked';

    public function planOnlyStatus(PlanOnlyResult $result): string
    {
        return match ($result->routing->kind) {
            RoutingDecision::ATLAS_DEV_FAST_PATH => self::STATUS_READY,
            RoutingDecision::READ_ONLY_ANSWER => self::STATUS_READ_ONLY,
            RoutingDecision::FORGE_PROMOTION_PREVIEW => self::STATUS_FORGE_PREVIEW,
            RoutingDecision::DELEGATE_TO_OTHER_FLOW => self::STATUS_DELEGATED,
            default => self::STATUS_BLOCKED,
        };
    }
}
