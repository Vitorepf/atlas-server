<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMetaSddContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Meta-SDD Contract gate CLI.
 *
 *   php artisan atlas:aaeos:meta-sdd-contract [--json]
 *
 * Runs the top-level Meta-SDD gate against a safe reference scenario: a complete
 * 14-field meta-spec for a high-risk change WITHOUT a rollback strategy and with
 * one prohibition (hidden maturity promotion) violated. The contract must block
 * it — proving the gate enforces "No spec that lacks rollback for risky changes"
 * and "No hidden maturity promotion". Read-only and deterministic; it never edits
 * files, runs a provider, writes evidence or promotes maturity.
 *
 * @see docs/engineering-knowledge-base/self-construction/meta-sdd-contract.md
 */
class AtlasMetaSddContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:meta-sdd-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Self-Construction Meta-SDD Contract · gates a proposed Atlas core change against the meta-spec fields, prohibitions, required questions and minimal-exception path.';

    public function handle(AtlasMetaSddContractService $service): int
    {
        try {
            // Safe reference: a high-risk change whose meta-spec is otherwise
            // complete but omits the rollback strategy, and which violates the
            // "hidden maturity promotion" prohibition. The contract must block it.
            $metaSpec = [
                'id' => 'meta-spec-ref',
                'title' => 'Reference Meta-SDD',
                'target_layer' => AtlasMetaSddContractService::LAYER_L1,
                'target_capability' => 'kernel decision evidence',
                'current_maturity' => 'L2',
                'target_maturity' => 'L3',
                'problem' => 'gap in evidence chain',
                'goal' => 'close the evidence gap',
                'non_goals' => 'no autonomy change',
                'dependencies' => 'evidence ledger',
                'affected_authority_docs' => 'meta-sdd-contract.md',
                'affected_runtime_components' => 'kernel',
                'risk_level' => 'high',
                'autonomy_allowed' => 'advisory',
                'rollback_strategy' => '',           // missing on a high-risk change
                'evidence_required' => 'docs-health',
            ];

            $verdict = $service->gate(
                $metaSpec,
                [AtlasMetaSddContractService::PROHIBITION_HIDDEN_MATURITY_PROMOTION],
            );

            $payload = [
                'ok' => true,
                'schema' => AtlasMetaSddContractService::SCHEMA,
                'verdict' => $verdict,
                'meta_spec_field_count' => count(AtlasMetaSddContractService::META_SPEC_FIELDS),
                'flow_stage_count' => count(AtlasMetaSddContractService::FLOW_STAGES),
                'layer_count' => count(AtlasMetaSddContractService::LAYER_LADDER),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // The reference run is "healthy" (the gate worked) when the change is
            // correctly blocked for both the missing rollback and the violated
            // prohibition.
            $healthy = $verdict['proceed'] === false
                && in_array('risky_change_without_rollback', $verdict['blocking_reasons'], true)
                && in_array('prohibition_violated', $verdict['blocking_reasons'], true);

            return $healthy ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_meta_sdd_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
