<?php

declare(strict_types=1);

namespace Gold\Contracts;

/**
 * FROZEN gold fixture (AP-815 D1). Do not "fix" — this file is a labeled oracle
 * input for CodeGraphGoldEvalTest. Any edit must be mirrored in gold_edges.json.
 *
 * A leaf contract: nothing depends *out* of it; concrete gateways implement it.
 */
interface PaymentGateway
{
    public function charge(int $amountCents): bool;
}
