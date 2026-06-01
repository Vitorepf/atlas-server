<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasLearningProposalsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Learning Proposals decision contract.
 *
 * Demonstrates the doc's invariants: a signal with no evidence never becomes
 * canon, a weak signal is held for more evidence, an admitted proposal carries
 * {justification, risk, suggested_action}, and a critical (policy/routing/gate/
 * eval_gate/heuristic) proposal can never auto-apply — it is always routed to
 * human review.
 *
 * @see docs/engineering-knowledge-base/system-graph/learning-proposals.md
 */
final class AtlasLearningProposalsCommand extends Command
{
    protected $signature = 'atlas:aaeos:learning-proposals {--json : Machine-readable JSON output}';

    protected $description = 'Decide learning-proposal rules: evidence-gated admission, weak-signal hold, justification/risk/action output, and the critical-change no-auto-apply review gate.';

    public function handle(AtlasLearningProposalsService $service): int
    {
        try {
            $strongCritical = $service->evaluate([
                'kind' => 'routing',
                'summary' => 'route summarisation default to challenger',
                'evidence_refs' => ['ev-1', 'ev-2', 'ev-3'],
                'sample_size' => 40,
                'effect_size' => 0.7,
            ]);

            $noEvidence = $service->evaluate([
                'kind' => 'policy',
                'summary' => 'loosen retry policy',
                'evidence_refs' => [],
                'sample_size' => 50,
                'effect_size' => 0.9,
            ]);

            $weak = $service->evaluate([
                'kind' => 'memory',
                'summary' => 'maybe a memory hint',
                'evidence_refs' => ['ev-1'],
                'sample_size' => 1,
                'effect_size' => 0.1,
            ]);

            $providerExample = $service->evaluateProviderComparison([
                'challenger' => 'model-b',
                'incumbent' => 'model-a',
                'task_class' => 'code_review',
                'win_rate' => 0.72,
                'sample_size' => 50,
                'evidence_refs' => ['bench-run-1', 'bench-run-2'],
            ]);

            $result = [
                'schema_version' => AtlasLearningProposalsService::SCHEMA_VERSION,
                'weak_signal_floor' => AtlasLearningProposalsService::WEAK_SIGNAL_FLOOR,
                'critical_kinds' => AtlasLearningProposalsService::CRITICAL_KINDS,
                'strong_critical_proposal' => $strongCritical,
                'no_evidence_rejected' => $noEvidence,
                'weak_signal_held' => $weak,
                'provider_comparison_example' => $providerExample,
                'stages_routing' => $service->classifyStages('routing'),
                'stages_memory' => $service->classifyStages('memory'),
                'ranked' => $service->rank([$weak, $strongCritical, $noEvidence]),
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
