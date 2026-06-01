<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingRepairContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Programming Repair Contract gate CLI.
 *
 *   php artisan atlas:aaeos:programming-repair-contract
 *     [--envelope-id=] [--receipt-id=] [--failure-domain=]
 *     [--strategy=] [--evidence-kind=] [--json]
 *
 * Read-only, deterministic. With request options it evaluates one proposed
 * repair request against the doc's "Target Use" contract (identity preserved,
 * failure classified, evidence present, human-review domains, heavy-repair
 * block) and returns whether it is `ready_to_plan`. With no request options it
 * emits the contract manifest.
 *
 * @see docs/engineering-knowledge-base/domains/programming-repair-contract.md
 */
class AtlasProgrammingRepairContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-repair-contract
        {--envelope-id= : originating operation envelope id}
        {--receipt-id= : originating decision receipt id}
        {--failure-domain= : closed FailureDomain value (e.g. gate.failed, security.finding)}
        {--strategy= : proposed repair strategy (e.g. rerun_harness)}
        {--evidence-kind= : one accepted evidence kind to attach (e.g. test_output)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Programming · repair contract gate (admissibility before AtlasRepairOrchestrator::plan) + manifest.';

    public function handle(AtlasProgrammingRepairContractService $service): int
    {
        try {
            $envelopeId = $this->option('envelope-id');
            $receiptId = $this->option('receipt-id');
            $failureDomain = $this->option('failure-domain');
            $strategy = $this->option('strategy');
            $evidenceKind = $this->option('evidence-kind');

            $hasRequest = $this->present($envelopeId)
                || $this->present($receiptId)
                || $this->present($failureDomain)
                || $this->present($strategy)
                || $this->present($evidenceKind);

            if ($hasRequest) {
                $evidenceRefs = $this->present($evidenceKind)
                    ? [['kind' => (string) $evidenceKind, 'ref' => 'cli-supplied']]
                    : [];

                $payload = [
                    'ok' => true,
                    'verdict' => $service->evaluate([
                        'envelope_id' => $this->present($envelopeId) ? (string) $envelopeId : null,
                        'receipt_id' => $this->present($receiptId) ? (string) $receiptId : null,
                        'failure_domain' => $this->present($failureDomain) ? (string) $failureDomain : null,
                        'strategy' => $this->present($strategy) ? (string) $strategy : null,
                        'evidence_refs' => $evidenceRefs,
                    ]),
                ];
            } else {
                $payload = [
                    'ok' => true,
                    'manifest' => $service->contractManifest(),
                ];
            }

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function present(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
