<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingGovernanceSystemContractsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Programming Governance System Contracts validator CLI.
 *
 *   php artisan atlas:aaeos:programming-governance-system-contracts [--json]
 *
 * Read-only, deterministic. Demonstrates the four load-bearing rejection rules
 * of the contracts doc with safe-default payloads: a retroactive spec fails
 * even when complete (Contract 2), a textual-only evidence payload is rejected
 * (Contract 5), a diff touching forbidden_files is escalated (Contract 3), and
 * an unreviewed learning proposal does not auto-apply governance (Contract 6).
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 */
class AtlasProgrammingGovernanceSystemContractsCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-governance-system-contracts {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas kernel · programming-governance-system contracts validator (per-contract required fields + retroactive-spec / textual-evidence / out-of-contract-diff / learning-review rules).';

    public function handle(AtlasProgrammingGovernanceSystemContractsService $service): int
    {
        try {
            // Contract 2: a fully-populated spec that is retroactive still fails.
            $retroSpec = $service->gateSpecBeforeCode([
                'objective' => 'o', 'canonical_context' => 'c', 'expected_behavior' => 'b',
                'likely_files' => ['a.php'], 'inputs' => ['i'], 'outputs' => ['o'],
                'risks' => ['r'], 'tests' => ['t'], 'evidence_required' => ['e'],
                'rollback' => 'rb', 'completion_criteria' => ['cc'],
                'retroactive' => true,
            ]);

            // Contract 5: prose-only evidence with no mechanical proof is rejected.
            $textualEvidence = $service->gateEvidence([
                'spec_id' => 's1', 'task_id' => 't1', 'agent_runner' => 'runner',
                'changed_files' => [], 'executed_commands' => [], 'test_result' => '',
                'updated_docs' => ['d'], 'updated_cartography' => ['c'], 'errors' => [],
                'residual_risk' => 'low', 'completion_decision' => 'done',
                'summary' => 'it works',
            ]);

            // Contract 3: a diff into a forbidden path is escalated.
            $forbiddenDiff = $service->classifyDiff(
                ['allowed_files' => ['src/a.php'], 'forbidden_files' => ['config/kernel.php']],
                ['src/a.php', 'config/kernel.php'],
            );

            // Contract 6: an unreviewed learning proposal does not apply governance.
            $unreviewedLearning = $service->evaluateLearning([
                'trigger' => 'repair', 'observation' => 'gate weak', 'proposal' => 'tighten gate',
                'review_state' => 'pending_review',
            ]);

            $payload = [
                'ok' => true,
                'contracts' => $service->contracts(),
                'retroactive_spec_demo' => $retroSpec,
                'textual_evidence_demo' => $textualEvidence,
                'forbidden_diff_demo' => $forbiddenDiff,
                'unreviewed_learning_demo' => $unreviewedLearning,
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'programming_governance_system_contracts_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
