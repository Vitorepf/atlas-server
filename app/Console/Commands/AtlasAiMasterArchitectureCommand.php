<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiMasterArchitectureService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Master Architecture gate CLI.
 *
 *   php artisan atlas:aaeos:atlas-ai-master-architecture [--json]
 *
 * Exercises the canonical-flow + non-negotiable-rule gate on two safe sample
 * traces: a clean request that traverses the full canonical pipeline in order
 * and breaches nothing (admitted), and a drifted request where a provider
 * decided, runtime ran before a Decision Receipt existed, and AtlasVault was
 * treated as operational truth (rejected with machine reasons). Read-only and
 * deterministic; it never calls a provider, runs a tool, mutates code, walks the
 * vault or touches the database.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-master-architecture.md
 */
class AtlasAiMasterArchitectureCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-ai-master-architecture {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI Master Architecture · canonical-flow ordering + the 11 non-negotiable plane-authority rules as a deterministic admit/reject gate.';

    public function handle(AtlasAiMasterArchitectureService $service): int
    {
        try {
            // A clean request: full canonical flow, in order, Atlas decides,
            // receipt before runtime, nothing flagged. Admitted.
            $clean = $service->evaluate([
                'stages' => $service->canonicalFlow(),
                'decide_actor_kind' => 'atlas',
                'vault_operational_truth' => false,
                'business_context_type' => 'context',
                'repeated_signals' => [
                    ['signal' => 'refactor.pattern', 'promoted_to_core' => true],
                ],
                'important_events' => [
                    ['event' => 'runtime.applied', 'has_evidence' => true],
                ],
                'curator_proposals' => [
                    ['id' => 'cp_1', 'critical' => true, 'auto_applied' => false, 'reviewed' => true],
                ],
            ]);

            // A drifted request: provider decided, runtime ran with no receipt
            // upstream, vault used as operational truth. Rejected.
            $drifted = $service->evaluate([
                'stages' => [
                    'surface', 'input', 'envelope', 'intent', 'business_context',
                    'domain_profile_flow', 'context', 'policy', 'decide',
                    'runtime', 'gates', 'evidence', 'learning', 'output',
                ],
                'decide_actor_kind' => 'provider',
                'vault_operational_truth' => true,
                'business_context_type' => 'cognitive_domain',
                'important_events' => [
                    ['event' => 'critical.change', 'has_evidence' => false],
                ],
                'curator_proposals' => [
                    ['id' => 'cp_2', 'critical' => true, 'auto_applied' => true, 'reviewed' => false],
                ],
            ]);

            $payload = [
                'ok' => true,
                'schema' => AtlasAiMasterArchitectureService::SCHEMA,
                'canonical_flow' => $service->canonicalFlow(),
                'clean_sample' => [
                    'verdict' => $clean['verdict'],
                    'breaches' => $clean['breaches'],
                ],
                'drifted_sample' => [
                    'verdict' => $drifted['verdict'],
                    'breaches' => $drifted['breaches'],
                    'flow_missing' => $drifted['canonical_flow']['missing'],
                ],
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'schema' => AtlasAiMasterArchitectureService::SCHEMA,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
