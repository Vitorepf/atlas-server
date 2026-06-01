<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDomainPipelinesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI OS - Domain Pipelines decider CLI.
 *
 *   php artisan atlas:aaeos:domain-pipelines [--json]
 *
 * Read-only and deterministic. With safe defaults it demonstrates the contract:
 * the canonical 15-stage pipeline in doc order, a mature+operational plan that
 * skips `policy`/`gates`/`evidence` being rejected, and a finance market order
 * being blocked by the review-only autonomy limit.
 *
 * @see docs/engineering-knowledge-base/operating-system/domain-pipelines.md
 */
class AtlasDomainPipelinesCommand extends Command
{
    protected $signature = 'atlas:aaeos:domain-pipelines {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI OS domain pipelines: canonical pipeline shape, mandatory-stage invariant and per-domain autonomy limits.';

    public function handle(AtlasDomainPipelinesService $service): int
    {
        try {
            // Safe default: a mature operational plan that lists only the cheap
            // stages and skips policy/gates/evidence — must be rejected.
            $thinPlan = $service->validatePipeline([
                'stages' => ['input', 'domain', 'intent', 'decide', 'output'],
                'mature' => true,
                'operational' => true,
            ]);

            // Finance review-only limit: a market order is blocked.
            $financeOrder = $service->evaluateAction([
                'domain' => 'finance',
                'capability' => 'market_order',
            ]);

            $decision = [
                'canonical_pipeline' => $service->canonicalPipeline(),
                'thin_mature_operational_plan' => $thinPlan,
                'finance_market_order' => $financeOrder,
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'domain_pipelines_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
