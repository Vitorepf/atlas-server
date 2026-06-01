<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMetricsAndEvalsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Research Self-Improvement Metrics & Evals decider CLI.
 *
 *   php artisan atlas:aaeos:metrics-and-evals [--json]
 *
 * Read-only, deterministic. Runs the documented "Minimum Eval Suite" against a
 * deliberately failing demo run (an invented source + an uncited critical claim
 * + an auto-applied proposal) so the emitted atlas.research_eval_report.v1 shows
 * the blocking findings and the resulting "fail" status the doc mandates.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/metrics-and-evals.md
 */
class AtlasMetricsAndEvalsCommand extends Command
{
    protected $signature = 'atlas:aaeos:metrics-and-evals
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas research · metrics & evals decider (Minimum Eval Suite -> research_eval_report.v1 pass|warn|fail).';

    public function handle(AtlasMetricsAndEvalsService $service): int
    {
        try {
            // Demonstrate the suite on a run that violates several documented
            // "must" contracts so the report surfaces the blocking findings.
            $report = $service->evaluate([
                'critical_claims' => 10,
                'critical_claims_cited' => 9,        // 1 uncited -> claim_support fail
                'critical_claims_primary_source' => 6, // 60% -> below 80% target (warn)
                'cited_sources' => 12,
                'cited_sources_resolved' => 11,
                'invented_sources' => 1,             // hallucination fail
                'contradictions_found' => 2,
                'contradictions_recorded' => 2,
                'self_improvement_auto_applied' => true, // self_improvement fail
                'docs_health_regressed' => false,
                'architecture_validation_regressed' => false,
            ]);

            $this->line((string) json_encode([
                'ok' => true,
                'report' => $report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'metrics_and_evals_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
