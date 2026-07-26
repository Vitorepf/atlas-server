<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Gate;

/**
 * Narrow contract VerificationGate uses to persist per-command test logs.
 *
 * Kept separate from Persistence\ReceiptStorage so VerificationGate stays
 * unit-testable without a filesystem dependency. Production wires a thin
 * adapter that writes under storage/atlas-dev/receipts/<run_id>/.
 */
interface ReceiptStorageAdapter
{
    public function writeTestLog(string $runId, int $index, string $output): ?string;
}
