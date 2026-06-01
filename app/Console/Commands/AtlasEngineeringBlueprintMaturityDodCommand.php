<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasEngineeringBlueprintMaturityDodService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Engineering Blueprint Maturity And DoD decider CLI.
 *
 *   php artisan atlas:aaeos:engineering-blueprint-maturity-dod [--json]
 *
 * Read-only and deterministic. Evaluates both the Final DoD Rule (8-stage
 * new-AI-session pipeline) and the Remaining Product Maturity table (5 areas)
 * and emits an audit receipt. With safe defaults (an empty report — nothing
 * proven yet) it demonstrates the contract: the system is NOT complete and NOT
 * mature, and it names the earliest blocking DoD stage plus the remaining areas.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
 */
class AtlasEngineeringBlueprintMaturityDodCommand extends Command
{
    protected $signature = 'atlas:aaeos:engineering-blueprint-maturity-dod {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Engineering Blueprint maturity + Final DoD decider (complete? / mature?) with an evidence gate.';

    public function handle(AtlasEngineeringBlueprintMaturityDodService $service): int
    {
        try {
            // Safe default report: nothing proven yet — no DoD stage carries
            // evidence and no maturity area is cleared, so the system is
            // incomplete and at most an operational base.
            $decision = $service->assess([
                'stages' => [],
                'areas' => [],
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'engineering_blueprint_maturity_dod_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
