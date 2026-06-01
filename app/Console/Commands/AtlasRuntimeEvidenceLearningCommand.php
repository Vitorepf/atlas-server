<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRuntimeEvidenceLearningService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Runtime / Evidence / Learning plane contract.
 *
 * Demonstrates the core invariants: a scope routes to its owning runtime
 * ("divided by scope, not fashion"), an evidence event is admitted only if it is
 * a documented kind, and a Learning output that targets critical behavior is
 * routed to proposal review rather than silently auto-applied.
 *
 * @see docs/engineering-knowledge-base/master-architecture/runtime-evidence-learning.md
 */
final class AtlasRuntimeEvidenceLearningCommand extends Command
{
    protected $signature = 'atlas:aaeos:runtime-evidence-learning {--json : Machine-readable JSON output}';

    protected $description = 'Decide runtime/evidence/learning plane rules: scope-based runtime routing, evidence-kind admissibility, learning-output classification and the critical-change proposal-review gate.';

    public function handle(AtlasRuntimeEvidenceLearningService $service): int
    {
        try {
            $result = [
                'route_ledger' => $service->routeRuntime('ledger'),
                'route_embeddings' => $service->routeRuntime('embeddings'),
                'route_unknown' => $service->routeRuntime('mystery_scope'),
                'evidence_gate_result' => $service->admitEvidence('gate_result'),
                'evidence_unknown' => $service->admitEvidence('vibes'),
                'learning_critical' => $service->classifyLearningOutput('repair_heuristic', true),
                'learning_non_critical' => $service->classifyLearningOutput('memory_signal', false),
                'reentry_critical_unreviewed' => $service->mayReenterRuntime(true, false),
                'action_summary' => $service->summarizeAction('orchestration', 'decision', 'repair_heuristic', true),
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
