<?php

declare(strict_types=1);

namespace Gold\Services;

use Gold\Gateways\StripeGateway;

/**
 * FROZEN gold fixture (AP-815 D1). Do not "fix" — labeled oracle input.
 *
 * One hand-verified outbound dependency, exercised via BOTH a `use` import AND a
 * `::class` constant reference to the same target — the legacy resolver dedups
 * these into a single depends_on edge (RefundService -> StripeGateway).
 */
final class RefundService
{
    public function gatewayClass(): string
    {
        return StripeGateway::class;
    }
}
