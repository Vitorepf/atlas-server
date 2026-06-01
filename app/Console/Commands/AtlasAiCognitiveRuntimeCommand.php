<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiCognitiveRuntimeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cognitive Runtime (law-level) decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-ai-cognitive-runtime [--json]
 *
 * Exercises the four law-level decision surfaces of the parent doc over safe
 * reference inputs: the ten Non-Negotiable Invariants, the 72h long-session
 * readiness gate, the Retrieval Quality DoD and the read-only Cognitive Audit
 * Loop net value. Read-only and deterministic; it never runs a provider, writes
 * evidence, promotes memory or relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
 */
class AtlasAiCognitiveRuntimeCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-ai-cognitive-runtime {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI Cognitive Runtime (law) · checks the non-negotiable invariants, the 72h long-session readiness gate, retrieval DoD and the read-only net-value score against the documented rules.';

    public function handle(AtlasAiCognitiveRuntimeService $service): int
    {
        try {
            // Safe reference sample: a clean action violates no invariant; a
            // session with no metric in alert is ready; a fully-described
            // retrieval is DoD-mature; a net-value score is computed read-only.
            $invariants = $service->evaluateInvariants([
                'violations' => [],
                'prompt_contaminated' => false,
                'ship_contaminated' => false,
                'manual_memory_assembly' => false,
            ]);

            $longSession = $service->evaluateLongSessionReadiness([
                'alerts' => [],
            ]);

            $retrieval = $service->evaluateRetrievalQuality([
                'refs' => [
                    [
                        'source' => 'docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md',
                        'reason' => 'canonical law for cognitive runtime',
                        'scope' => 'architecture',
                        'priority' => 100,
                        'provider_safe_summary' => 'governed memory + retrieval + 72h sessions',
                        'bypassed_filters' => false,
                    ],
                ],
                'ranking_privileges_canonical' => true,
                'records_excluded_refs_with_reason' => true,
                'code_tasks_get_code_and_tests' => true,
                'quality_metrics_measured' => true,
            ]);

            $netValue = $service->computeNetValue([
                'gain' => 10,
                'wrong_context' => 1,
                'avoidable_repetition' => 1,
                'objective_drift' => 0,
                'cognitive_token_cost' => 2,
                'policy_privacy_violations' => 0,
            ]);

            $payload = [
                'doc' => 'atlas-ai-cognitive-runtime',
                'invariants' => $invariants,
                'long_session' => $longSession,
                'retrieval' => $retrieval,
                'net_value' => $netValue,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'doc' => 'atlas-ai-cognitive-runtime',
                'error' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
