<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasObrasContractsAndInvariantsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Obras — Contracts And Invariants conformance CLI.
 *
 *   php artisan atlas:aaeos:obras-contracts-and-invariants [--json]
 *
 * Runs the whole-contract audit over a reference Obra bundle and emits the
 * pass|fail evidence document (invariants, persistence boundary, MVP acceptance,
 * completeness rule, level promotion and anti-patterns). Read-only and
 * deterministic; it never mutates Obra state or relaxes a rule.
 *
 * @see docs/engineering-knowledge-base/obras/contracts-and-invariants.md
 */
class AtlasObrasContractsAndInvariantsCommand extends Command
{
    protected $signature = 'atlas:aaeos:obras-contracts-and-invariants {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Obras · audits an Obra bundle against the documented contracts, invariants, MVP acceptance, level-promotion rules and anti-patterns.';

    public function handle(AtlasObrasContractsAndInvariantsService $service): int
    {
        try {
            // Safe default: a conformant reference Obra bundle that demonstrates a
            // green audit. Operators can wire real Obra state later.
            $bundle = [
                'invariants' => array_fill_keys(
                    array_keys(AtlasObrasContractsAndInvariantsService::INVARIANTS),
                    true,
                ),
                'persistence' => array_fill_keys(
                    AtlasObrasContractsAndInvariantsService::PERSISTENCE_MINIMUM,
                    true,
                ),
                'mvp' => array_fill_keys(
                    array_keys(AtlasObrasContractsAndInvariantsService::MVP_CAPABILITIES),
                    true,
                ),
                'completeness' => [
                    'next_step' => 'render final artifact',
                    'has_output' => true,
                    'explicitly_closed' => false,
                ],
                'promotion' => [
                    'from' => 'L0',
                    'to' => 'L1',
                    'evidence' => array_fill_keys(
                        AtlasObrasContractsAndInvariantsService::PROMOTION_REQUIREMENTS['L0->L1'],
                        true,
                    ),
                ],
                'anti_patterns' => [],
            ];

            $result = $service->audit($bundle);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['status'] === AtlasObrasContractsAndInvariantsService::STATUS_PASS
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'obras_contracts_and_invariants_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
