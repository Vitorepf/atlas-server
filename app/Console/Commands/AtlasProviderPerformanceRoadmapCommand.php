<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProviderPerformanceRoadmapService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Provider Performance Evolution Roadmap decider.
 *
 * Demonstrates the doc's core contracts on safe defaults: the three selection
 * modes (manual override is an audited, non-default path that forces a receipt),
 * the AP-99 7-signal evidence completeness gate, Provider Launch Intake
 * classification (always kept behind Atlas), Dynamic Compute Market routing
 * (high-risk -> premium), and the Anti-Fragility test.
 *
 * @see docs/engineering-knowledge-base/evolution/provider-performance-roadmap.md
 */
final class AtlasProviderPerformanceRoadmapCommand extends Command
{
    protected $signature = 'atlas:aaeos:provider-performance-roadmap {--json : Machine-readable JSON output}';

    protected $description = 'Decide Provider Performance Roadmap rules: selection modes, AP-99 evidence completeness, provider launch intake, dynamic compute routing and the anti-fragility test.';

    public function handle(AtlasProviderPerformanceRoadmapService $service): int
    {
        try {
            $partialEvidence = [
                'task_domain_flow' => 'programming.forge',
                'provider_model' => 'example-model',
                'latency_and_cost' => ['latency_ms' => 1200, 'cost_usd' => 0.04],
                'gate_outcomes' => 'pass',
                // repair_rate, human_acceptance, final_quality_score intentionally missing
            ];

            $result = [
                'modes' => [
                    AtlasProviderPerformanceRoadmapService::MODE_AUTO_BEST_ALLOWED => $service->resolveMode(
                        AtlasProviderPerformanceRoadmapService::MODE_AUTO_BEST_ALLOWED
                    ),
                    AtlasProviderPerformanceRoadmapService::MODE_AUTO_BEST_AVAILABLE => $service->resolveMode(
                        AtlasProviderPerformanceRoadmapService::MODE_AUTO_BEST_AVAILABLE
                    ),
                    AtlasProviderPerformanceRoadmapService::MODE_MANUAL_OVERRIDE => $service->resolveMode(
                        AtlasProviderPerformanceRoadmapService::MODE_MANUAL_OVERRIDE
                    ),
                ],
                'ap99_partial_evidence' => $service->validateAp99Evidence($partialEvidence),
                'intake_skill' => $service->classifyProviderLaunch('skill'),
                'intake_irrelevant' => $service->classifyProviderLaunch('irrelevant'),
                'route_architecture' => $service->routeCompute('architecture'),
                'route_low_risk' => $service->routeCompute('formatting'),
                'anti_fragility_absorbed' => $service->antiFragilityTest(true, false),
                'anti_fragility_gap' => $service->antiFragilityTest(true, true),
            ];
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
