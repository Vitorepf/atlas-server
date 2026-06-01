<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDualCoreEngineeringSystemService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dual-Core Engineering System · routing matrix decider CLI.
 *
 *   php artisan atlas:aaeos:dual-core-engineering-system [--json]
 *
 * Read-only and deterministic. Applies the doc's "Fluxo" matrix to a sample
 * demand and emits the auditable `atlas.dual_core.route_decision.v1` payload —
 * the chosen core (dev | forge | dev_to_forge), the reason, the active Forge
 * signals and the evidence the route owes. With safe defaults (a small, clear
 * task) it demonstrates the fast-path `dev` route the doc mandates.
 *
 * @see docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
 */
class AtlasDualCoreEngineeringSystemCommand extends Command
{
    protected $signature = 'atlas:aaeos:dual-core-engineering-system {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas kernel · dual-core routing decider (dev|forge|dev_to_forge) for one demand.';

    public function handle(AtlasDualCoreEngineeringSystemService $service): int
    {
        try {
            // Safe default demand: a small, clear, single-module bug fix — the
            // canonical fast-path case that must route to `dev`.
            $decision = $service->decideRoute([
                'intent_summary' => 'Fix a small local bug with a test',
                'ambiguity_level' => AtlasDualCoreEngineeringSystemService::AMBIGUITY_LOW,
                'risk_level' => AtlasDualCoreEngineeringSystemService::RISK_LOW,
                'expected_duration' => AtlasDualCoreEngineeringSystemService::DURATION_MINUTES,
                'modules_touched_estimate' => 1,
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'dual_core_routing_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
