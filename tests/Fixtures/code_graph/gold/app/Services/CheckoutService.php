<?php

declare(strict_types=1);

namespace Gold\Services;

use Gold\Contracts\PaymentGateway;
use Gold\Support\Logger;

/**
 * FROZEN gold fixture (AP-815 D1). Do not "fix" — labeled oracle input.
 *
 * Two hand-verified outbound dependencies, both via real `use` imports:
 *   - constructor-injected PaymentGateway (interface dependency -> depends_on)
 *   - constructor-injected Logger         (collaborator dependency -> depends_on),
 *     also exercised by a real method call $this->logger->write(...).
 */
final class CheckoutService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly Logger $logger,
    ) {
    }

    public function checkout(int $amountCents): bool
    {
        $this->logger->write('charging');

        return $this->gateway->charge($amountCents);
    }
}
