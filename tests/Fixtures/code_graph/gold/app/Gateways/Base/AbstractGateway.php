<?php

declare(strict_types=1);

namespace Gold\Gateways\Base;

/**
 * FROZEN gold fixture (AP-815 D1). Do not "fix" — labeled oracle input.
 *
 * A leaf base class in its OWN namespace (Gold\Gateways\Base) so a subclass must
 * `use` it to extend it — making the inheritance edge both human-obvious and
 * visible to the legacy extractor. Sink of one depends_on edge
 * (StripeGateway -> AbstractGateway).
 */
abstract class AbstractGateway
{
    abstract public function charge(int $amountCents): bool;

    protected function currency(): string
    {
        return 'USD';
    }
}
