<?php

declare(strict_types=1);

namespace Gold\Gateways;

use Gold\Contracts\PaymentGateway;
use Gold\Gateways\Base\AbstractGateway;

/**
 * FROZEN gold fixture (AP-815 D1). Do not "fix" — labeled oracle input.
 *
 * Two hand-verified outbound relationships, both expressed as real `use` imports
 * so they are simultaneously human-obvious AND seen by the extractor:
 *   - extends AbstractGateway   (cross-namespace import -> depends_on edge).
 *   - implements PaymentGateway (cross-namespace import -> depends_on edge).
 */
final class StripeGateway extends AbstractGateway implements PaymentGateway
{
    public function charge(int $amountCents): bool
    {
        return $amountCents > 0;
    }
}
