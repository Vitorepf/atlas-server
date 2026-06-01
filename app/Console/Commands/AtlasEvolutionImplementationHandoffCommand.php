<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasEvolutionImplementationHandoffService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator entry point for the Evolution Implementation Handoff decider.
 *
 * Demonstrates the doc's three contracts on safe defaults: the Canonical Order
 * (next allowed work + an out-of-order example), the seven-field Agent Handoff
 * statement (a partial handoff names its missing fields), and Done Means (a
 * structural split_required blocker keeps the work not_done).
 *
 * @see docs/engineering-knowledge-base/evolution/implementation-handoff.md
 */
final class AtlasEvolutionImplementationHandoffCommand extends Command
{
    protected $signature = 'atlas:aaeos:evolution-implementation-handoff {--json : Machine-readable JSON output}';

    protected $description = 'Decide Evolution Implementation Handoff rules: canonical order, agent handoff completeness and Done Means.';

    public function handle(AtlasEvolutionImplementationHandoffService $service): int
    {
        try {
            // First two canonical items done, third open => proceed on AP-101.
            $orderProceed = $service->nextWork([
                'ap_99_provider_performance_contract',
                'ap_100_context_pack_manifest_reflection',
            ]);

            // A later item done while an earlier one is open => out_of_order.
            $orderOutOfOrder = $service->nextWork([
                'ap_99_provider_performance_contract',
                'ap_101_retrieval_router',
            ]);

            // Handoff missing tests/validation/docs => incomplete.
            $partialHandoff = $service->evaluateHandoff([
                'ap_or_child_doc' => 'AP-99 Provider Performance Contract',
                'contract_extended' => 'AtlasProviderPerformanceRoadmapService',
                'files_owned' => ['app/Services/Ai/Aaeos/Generated/Example.php'],
                'migrations_or_events' => 'none',
                // tests_added, validation_commands_run, docs_updated missing
            ]);

            // Done Means: a split_required blocker keeps the work not_done even
            // when every validation command is green.
            $blockedDone = $service->evaluateDoneMeans([
                'criteria' => [
                    'no_split_required_blocker' => false,
                    'no_parallel_subsystem' => true,
                    'canonical_index_points_to_child_doc' => true,
                    'evidence_and_policy_explicit' => true,
                    'override_and_autonomy_documented' => true,
                ],
                'validation' => [
                    'architecture_validate' => true,
                    'docs_health' => true,
                    'knowledge_sync' => true,
                    'index_code' => true,
                    'git_diff_check' => true,
                ],
            ]);

            $result = [
                'order_proceed' => $orderProceed,
                'order_out_of_order' => $orderOutOfOrder,
                'partial_handoff' => $partialHandoff,
                'blocked_done' => $blockedDone,
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
