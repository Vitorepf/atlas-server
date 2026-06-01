<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasBlueprintMaturityPhasesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Engineering Blueprint Maturity Phases decider CLI.
 *
 *   php artisan atlas:aaeos:blueprint-maturity-phases [--json]
 *
 * Read-only and deterministic. Evaluates the doc's three contracts — the Seven
 * Items table, the 0..8 phase ladder and the Final DoD criteria — and emits an
 * audit receipt. With safe defaults (the documented baseline, nothing newly
 * proven) it demonstrates the contract: the system is NOT done — it is a strong
 * operational base, the weakest item is the partial Contingency policy, and the
 * phase frontier blocks at Phase 0 because no phase carries verifiable evidence.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/maturity-phases.md
 */
class AtlasBlueprintMaturityPhasesCommand extends Command
{
    protected $signature = 'atlas:aaeos:blueprint-maturity-phases {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Engineering Blueprint maturity phases decider (seven items + 0..8 phase ladder + Final DoD) with an evidence gate.';

    public function handle(AtlasBlueprintMaturityPhasesService $service): int
    {
        try {
            // Safe default report: documented baseline only. No phase or DoD
            // criterion carries evidence, so the system is an operational base.
            $decision = $service->assess([
                'items' => [],
                'phases' => [],
                'dod' => [],
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'blueprint_maturity_phases_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
