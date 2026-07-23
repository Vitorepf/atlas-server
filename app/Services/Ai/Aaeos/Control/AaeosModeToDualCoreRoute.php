<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;

final class AaeosModeToDualCoreRoute
{
    public static function map(string $mode): string
    {
        return match ($mode) {
            AaeosExecutorMode::DEV => DualCoreRouteDecisionCanon::ROUTE_DEV,
            AaeosExecutorMode::FORGE => DualCoreRouteDecisionCanon::ROUTE_FORGE,
            AaeosExecutorMode::AUTONOMOS => DualCoreRouteDecisionCanon::ROUTE_AUTONOMOS,
            default => DualCoreRouteDecisionCanon::ROUTE_DEV,
        };
    }
}
