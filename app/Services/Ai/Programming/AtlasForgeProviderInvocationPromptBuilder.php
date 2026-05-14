<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use Illuminate\Support\Facades\Schema;

/**
 * Atlas Forge Provider Invocation Prompt Builder.
 *
 * Compiles a canonical, schema-stable prompt packet that the Provider
 * Invocation Service can hand to a runtime driver (or hash for audit). The
 * prompt is intentionally explicit about scope, quality, evidence and review
 * to prevent "do anything" prompts.
 *
 * Schema: atlas.forge.provider_invocation_prompt.v1
 * Doc: docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md
 *
 * Rules:
 *   - never asks the provider to bypass the Forge contract;
 *   - always demands patch/artifacts/evidence;
 *   - always states completion depends on human review;
 *   - always lists explicit blockers when context is insufficient.
 */
class AtlasForgeProviderInvocationPromptBuilder
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_invocation_prompt.v1';

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function build(AtlasProject $project, ?array $dispatchPlan, array $options = []): array
    {
        $obraId = (string) $project->getKey();
        $metadata = is_array($project->metadata) ? $project->metadata : [];

        $role = (string) ($options['role'] ?? ($dispatchPlan['role'] ?? AtlasForgeProviderTopologyService::ROLE_PRIMARY_BUILDER));
        $provider = (string) ($dispatchPlan['provider'] ?? '');
        $model = (string) ($dispatchPlan['model'] ?? '');
        $decisionReceiptId = (string) ($dispatchPlan['decision_receipt_id'] ?? '');
        $decisionReceiptHash = (string) ($dispatchPlan['decision_receipt_hash'] ?? '');
        $providerTopologyId = (string) ($dispatchPlan['provider_topology_id'] ?? '');
        $dispatchId = (string) ($dispatchPlan['dispatch_id'] ?? '');

        $workItem = $this->resolveWorkItem($project);
        $intent = $this->stringOrNull(
            $workItem?->intent_text
            ?? data_get($metadata, 'intent')
            ?? $project->goal
            ?? $project->description
            ?? $project->title,
        ) ?? 'Forge task without explicit intent — please refuse and ask for missing context.';

        $allowedFiles = $this->normalizeStringList(data_get($workItem?->placement_json, 'allowed_files') ?? data_get($metadata, 'allowed_files') ?? []);
        $forbiddenFiles = $this->normalizeStringList(data_get($workItem?->placement_json, 'forbidden_files') ?? data_get($metadata, 'forbidden_files') ?? []);
        $acceptanceCriteria = $this->normalizeStringList(data_get($workItem?->spec_json, 'acceptance_criteria') ?? data_get($metadata, 'acceptance_criteria') ?? []);
        $qualityGates = $this->normalizeStringList($dispatchPlan['quality_gates'] ?? data_get($metadata, 'latest_atlas_forge_provider_topology.quality_gates') ?? []);
        $contextRefs = $this->normalizeStringList(
            data_get($metadata, 'latest_atlas_forge_provider_topology.evidence_refs')
            ?? data_get($metadata, 'latest_atlas_forge_runtime_dispatch.evidence_refs')
            ?? [
                'docs/engineering-knowledge-base/atlas-forge-continuum-os.md',
                'docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-governed-provider-invocation-v1.md',
            ],
        );

        $blockers = [];
        if ($decisionReceiptId === '' || $decisionReceiptHash === '') {
            $blockers[] = 'decision_receipt_missing';
        }
        if ($provider === '' || $model === '') {
            $blockers[] = 'provider_or_model_missing';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'obra_id' => $obraId,
            'role' => $role,
            'provider' => $provider !== '' ? $provider : null,
            'model' => $model !== '' ? $model : null,
            'dispatch_id' => $dispatchId !== '' ? $dispatchId : null,
            'decision_receipt_id' => $decisionReceiptId !== '' ? $decisionReceiptId : null,
            'decision_receipt_hash' => $decisionReceiptHash !== '' ? $decisionReceiptHash : null,
            'provider_topology_id' => $providerTopologyId !== '' ? $providerTopologyId : null,
            'system_contract' => [
                'identity' => 'Atlas Forge Continuum operator, role='.$role,
                'must_obey_decision_receipt' => true,
                'must_obey_provider_topology' => true,
                'never_bypass_review_gate' => true,
                'never_call_external_tools_without_evidence' => true,
                'completion_requires_human_approval' => true,
            ],
            'task_contract' => [
                'intent' => $intent,
                'work_item_code' => $workItem?->code,
                'work_item_id' => $workItem?->id,
                'spec_hash' => $workItem?->spec_hash,
                'plan_hash' => $workItem?->plan_hash,
                'goal' => $this->stringOrNull($project->goal),
                'desired_outcome' => $this->stringOrNull($project->desired_outcome),
                'definition_of_done' => $this->stringOrNull($project->definition_of_done),
                'acceptance_criteria' => $acceptanceCriteria,
            ],
            'scope_contract' => [
                'allowed_files' => $allowedFiles,
                'forbidden_files' => $forbiddenFiles,
                'forbidden_actions' => [
                    'do_not_call_external_provider_without_governed_invocation',
                    'do_not_promote_completion_claim',
                    'do_not_bypass_review_or_completion_gate',
                    'do_not_create_obra_silently',
                    'do_not_disable_quality_gates_to_pass',
                ],
                'must_emit_patch_artifacts' => true,
                'must_emit_evidence_refs' => true,
            ],
            'quality_contract' => [
                'quality_gates' => $qualityGates,
                'preserve_review_completion_gate' => true,
                'preserve_repair_loop' => true,
                'tests_required' => true,
            ],
            'evidence_contract' => [
                'context_refs' => $contextRefs,
                'must_emit_evidence_event' => true,
                'must_emit_stage_receipts' => true,
            ],
            'output_contract' => [
                'output_format' => 'atlas.forge.provider_invocation_output.v1',
                'must_include' => [
                    'summary',
                    'changed_files',
                    'patch_or_diff',
                    'evidence_refs',
                    'next_action',
                    'blockers',
                ],
                'forbid_silent_success' => true,
                'forbid_synthetic_completion_claim' => true,
            ],
            'blockers' => $blockers,
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $entry = trim($entry);
                if ($entry !== '') {
                    $result[] = $entry;
                }
            }
        }

        return array_values(array_unique($result));
    }

    private function resolveWorkItem(AtlasProject $project): ?AtlasProgrammingWorkItem
    {
        // Defensive: tests run in environments where the work-items table may
        // not exist. We must never explode the prompt builder for that.
        if (! Schema::hasTable('atlas_programming_work_items')) {
            return null;
        }

        try {
            $metadata = is_array($project->metadata) ? $project->metadata : [];
            $id = $this->stringOrNull(data_get($metadata, 'programming_work_item_id'));
            if ($id !== null) {
                $item = AtlasProgrammingWorkItem::query()->whereKey($id)->first();
                if ($item !== null) {
                    return $item;
                }
            }

            return AtlasProgrammingWorkItem::query()
                ->where('workspace', 'like', '%'.((string) $project->getKey()).'%')
                ->orderByDesc('created_at')
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
