<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiTelemetryEvidencePerformanceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the consolidated telemetry / evidence / performance
 * contract.
 *
 * Demonstrates the core invariant on a trace projected from the Evidence Ledger
 * without a provider/model identity: it is NOT a provider execution, so its cost
 * is estimated / provider_not_applicable / not_applicable and it is excluded
 * from the missing-cost-rates report even with a stale summary.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
 */
final class AtlasAiTelemetryEvidencePerformanceCommand extends Command
{
    protected $signature = 'atlas:aaeos:telemetry-evidence-performance {--json : Machine-readable JSON output}';

    protected $description = 'Decide telemetry/evidence/performance contract rules: ledger-projection cost classification, missing-cost-rate inclusion, aggregator_version comparability, low-sample health floor and notification-receipt authority.';

    public function handle(AtlasAiTelemetryEvidencePerformanceService $service): int
    {
        try {
            $projectionTrace = [
                'schema_version' => AtlasAiTelemetryEvidencePerformanceService::PROJECTION_SCHEMA_VERSION,
                'projection_id' => AtlasAiTelemetryEvidencePerformanceService::PROJECTION_ID,
                'provider' => null,
                'model' => null,
                'has_active_rate' => false,
                'has_stale_summary' => true,
            ];

            $result = [
                'cost_classification' => $service->classifyTraceCost($projectionTrace),
                'missing_cost_report' => $service->includeInMissingCostReport($projectionTrace),
                'aggregator_gate' => $service->gateAggregatorComparability(['v3', 'v4']),
                'health_low_sample' => $service->resolveHealthStatus(
                    AtlasAiTelemetryEvidencePerformanceService::STATUS_CRITICAL,
                    2
                ),
                'receipt_policy_patch' => $service->receiptAuthorizes('policy_patch'),
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
