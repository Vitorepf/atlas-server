<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use RuntimeException;

/**
 * Raised by {@see AtlasMaestroProviderBidReceiptLedger} on any attempt to mutate or delete a past
 * entry.
 */
final class LedgerImmutableViolation extends RuntimeException
{
}
