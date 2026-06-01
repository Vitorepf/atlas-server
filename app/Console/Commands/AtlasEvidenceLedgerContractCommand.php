<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasEvidenceLedgerContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Evidence Ledger admission contract.
 *
 * Demonstrates the gate on a complete, factual `gate` event (the doc's
 * "Exemplos" shape: command, status, duration, output, trace, receipt), so the
 * verdict is `admit`. Drop a required field, remove every factual anchor, or
 * change the kind to see the documented `reject` / `interpretation` outcomes.
 *
 * @see docs/engineering-knowledge-base/system-graph/evidence-ledger.md
 */
final class AtlasEvidenceLedgerContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:evidence-ledger-contract {--json : Machine-readable JSON output}';

    protected $description = 'Gate a candidate event against the Evidence Ledger contract: admit only complete, factual events; reclassify agent commentary as interpretation.';

    public function handle(AtlasEvidenceLedgerContractService $service): int
    {
        try {
            $event = [
                'kind' => 'gate',
                'command' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'status' => 'ok',
                'duration' => 1.84,
                'output' => 'docs-health status ok',
                'trace' => 'trace_01HCANONICALDEMO',
                'receipt' => 'dec_01HCANONICALDEMO',
            ];

            $result = $service->admit($event);
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
