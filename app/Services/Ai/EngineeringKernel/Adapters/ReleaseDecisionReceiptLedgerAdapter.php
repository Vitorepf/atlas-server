<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\ReceiptLedger;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;

/**
 * First Engineering Kernel adapter proving the ReceiptLedger interface is real, not ornamental:
 * pure delegation to the existing, already-proven AtlasMergeGovernorReleaseDecisionLedger —
 * zero behavior change, zero new validation, zero new hashing. The caller supplies the concrete
 * ledger instance so it (not this adapter) chooses the ledger path.
 */
final class ReleaseDecisionReceiptLedgerAdapter implements ReceiptLedger
{
    public function __construct(private readonly AtlasMergeGovernorReleaseDecisionLedger $ledger) {}

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function append(array $receipt): array
    {
        return $this->ledger->append($receipt);
    }

    /**
     * @return array<string,mixed>
     */
    public function replay(): array
    {
        return $this->ledger->replay();
    }
}
