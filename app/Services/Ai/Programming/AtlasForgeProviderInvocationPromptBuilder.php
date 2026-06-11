<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Throwable;

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
     * AP-815 · I-4 — token budget for the code-graph context pack injected into the
     * Forge prompt. Kept as a code default (the config flag is read for the gate; this
     * builder never edits config/atlas.php) so a flag flip is the only thing needed.
     */
    private const CODE_GRAPH_PACK_BUDGET = 4000;

    /**
     * Both dependencies are nullable so a bare `new AtlasForgeProviderInvocationPromptBuilder()`
     * (and every flag-OFF path) keeps working without them. When the flag is ON they are
     * resolved lazily from the container if not explicitly injected (see
     * {@see resolveCodeGraphRetriever()}); Laravel passes `null` for a nullable-with-default
     * constructor param, so eager constructor injection alone would never wire them. Either
     * way the code-graph seam stays a pure no-op until the operator flips
     * `atlas.code_graph.auto_context` ON.
     */
    public function __construct(
        private readonly ?CodeGraphContextRetriever $codeGraphRetriever = null,
        private readonly ?CodeGraphWorkspaceIdentity $codeGraphWorkspaceIdentity = null,
    ) {}

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

        // AP-815 · I-4 — evidence_contract is byte-identical when the flag is OFF: the
        // code-graph pack is merged in ONLY when the auto-context flag is on (and the
        // retriever produced a pack). Default-OFF → key absent → identical prompt_hash.
        $evidenceContract = [
            'context_refs' => $contextRefs,
            'must_emit_evidence_event' => true,
            'must_emit_stage_receipts' => true,
        ];
        $codeGraphPack = $this->codeGraphPack($project, $metadata, $intent, $allowedFiles);
        if ($codeGraphPack !== null) {
            $evidenceContract['code_graph_pack'] = $codeGraphPack;
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
            'evidence_contract' => $evidenceContract,
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
     * AP-815 · I-4 — build the code-graph context pack for the Forge prompt, or null.
     *
     * Returns null (→ the `code_graph_pack` key is omitted → byte-identical prompt) when:
     *   - the auto-context flag `atlas.code_graph.auto_context` is OFF (the default), or
     *   - the retriever dependency is not wired (e.g. `new` without the container), or
     *   - anything throws (fail-safe: context recall is best-effort, never a gate).
     *
     * When ON, it resolves the obra workspace_id from the project's `workspace_path`
     * metadata via {@see CodeGraphWorkspaceIdentity} (empty/missing → the primary default
     * 'atlas-server'), then asks the shared {@see CodeGraphContextRetriever::packFor()} for
     * a workspace-scoped, BM25-ranked, E-3-budgeted pack. The descriptor is:
     *   - query = the work-item/obra intent;
     *   - changed_files = the WorkItem allowed_files (biases retrieval toward what the
     *     task touches).
     *
     * @param  array<string,mixed>  $metadata
     * @param  array<int,string>  $allowedFiles
     * @return array<string,mixed>|null
     */
    private function codeGraphPack(AtlasProject $project, array $metadata, string $intent, array $allowedFiles): ?array
    {
        try {
            if (! (bool) config('atlas.code_graph.auto_context', false)) {
                return null;
            }

            $retriever = $this->resolveCodeGraphRetriever();
            if (! $retriever instanceof CodeGraphContextRetriever) {
                return null;
            }

            $workspaceId = $this->resolveCodeGraphWorkspaceId($metadata);

            return $retriever->packFor(
                $intent,
                $workspaceId,
                self::CODE_GRAPH_PACK_BUDGET,
                $allowedFiles,
            );
        } catch (Throwable) {
            // Best-effort recall: a retrieval fault must never break the governed prompt.
            return null;
        }
    }

    /**
     * The code-graph retriever: the explicitly-injected instance, else resolved lazily
     * from the container (Laravel injects `null` for a nullable-with-default ctor param,
     * so the live runtime relies on this lazy path). Returns null when neither is available
     * (e.g. a bare `new` outside any container) — the caller then omits the pack.
     */
    private function resolveCodeGraphRetriever(): ?CodeGraphContextRetriever
    {
        if ($this->codeGraphRetriever instanceof CodeGraphContextRetriever) {
            return $this->codeGraphRetriever;
        }

        $resolved = $this->fromContainer(CodeGraphContextRetriever::class);

        return $resolved instanceof CodeGraphContextRetriever ? $resolved : null;
    }

    /**
     * The workspace-identity service: the injected instance, else resolved lazily from the
     * container, else null (the caller then falls back to the configured default id).
     */
    private function resolveCodeGraphWorkspaceIdentity(): ?CodeGraphWorkspaceIdentity
    {
        if ($this->codeGraphWorkspaceIdentity instanceof CodeGraphWorkspaceIdentity) {
            return $this->codeGraphWorkspaceIdentity;
        }

        $resolved = $this->fromContainer(CodeGraphWorkspaceIdentity::class);

        return $resolved instanceof CodeGraphWorkspaceIdentity ? $resolved : null;
    }

    /**
     * Best-effort container resolution for a class, or null when there is no bound
     * application (a bare `new` in a non-Laravel context) or resolution fails. Never throws.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function fromContainer(string $class): ?object
    {
        try {
            if (! function_exists('app')) {
                return null;
            }

            /** @var T $instance */
            $instance = app($class);

            return $instance;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the obra workspace_id for code-graph scoping from the project metadata's
     * `workspace_path` (a filesystem path, resolved to a STABLE id via the identity
     * service so a second project's symbols never leak). Empty/missing path, a missing
     * identity dependency, or any fault → the configured primary default workspace id.
     *
     * @param  array<string,mixed>  $metadata
     */
    private function resolveCodeGraphWorkspaceId(array $metadata): string
    {
        $identity = $this->resolveCodeGraphWorkspaceIdentity();
        if (! $identity instanceof CodeGraphWorkspaceIdentity) {
            return (string) config('atlas.code_graph.default_workspace_id', 'atlas-server');
        }

        try {
            $path = $this->stringOrNull(data_get($metadata, 'workspace_path'));

            return $path !== null ? $identity->resolve($path) : $identity->default();
        } catch (Throwable) {
            try {
                return $identity->default();
            } catch (Throwable) {
                return 'atlas-server';
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        return AiStringListNormalizer::uniqueTrimmedStrings($value);
    }

    private function resolveWorkItem(AtlasProject $project): ?AtlasProgrammingWorkItem
    {
        // Defensive: tests run in environments where the work-items table may
        // not exist. We must never explode the prompt builder for that.
        if (! DatabaseTableAvailability::has('atlas_programming_work_items')) {
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
