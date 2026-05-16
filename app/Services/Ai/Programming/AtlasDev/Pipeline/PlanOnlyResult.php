<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\ContextRetrievalPlan;
use App\Services\Ai\Programming\AtlasDev\Schemas\LightTaskContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\MiniProgrammingSpec;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use App\Services\Ai\Programming\AtlasDev\Schemas\OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;

/**
 * Result of {@see AtlasDevFastPathOrchestrator::planOnly()}.
 *
 * Carries every artifact produced by the plan layer plus the routing decision
 * and the list of persisted artifact paths. Provider was never called; no
 * patch was produced. Surface adapters project this into their native shape.
 *
 * Intentionally not a serialized schema — orchestrator output is a transport
 * struct; the canonical persisted artifacts each own their own schema/hash.
 */
final class PlanOnlyResult
{
    /**
     * @param  array<string, string>  $persistedArtifactPaths  artifact_name => absolute_path
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly OperationEnvelope $envelope,
        public readonly TaskClassification $classification,
        public readonly string $riskLevel,
        public readonly CompactSdd $compactSdd,
        public readonly ContextRetrievalPlan $contextPlan,
        public readonly CodeDiscoveryManifest $discovery,
        public readonly OpenBrainProgrammingProjection $projection,
        public readonly MiniProgrammingSpec $miniSpec,
        public readonly LightTaskContract $taskContract,
        public readonly ProviderPromptProjection $promptProjection,
        public readonly RoutingDecision $routing,
        public readonly array $persistedArtifactPaths,
        public readonly array $blockers,
    ) {}

    public function routingKind(): string
    {
        return $this->routing->kind;
    }

    public function isFastPath(): bool
    {
        return $this->routing->kind === RoutingDecision::ATLAS_DEV_FAST_PATH;
    }

    public function isForgePreview(): bool
    {
        return $this->routing->kind === RoutingDecision::FORGE_PROMOTION_PREVIEW;
    }

    public function isReadOnly(): bool
    {
        return $this->routing->kind === RoutingDecision::READ_ONLY_ANSWER;
    }

    public function isBlocked(): bool
    {
        return $this->routing->kind === RoutingDecision::BLOCKED;
    }

    public function isDelegation(): bool
    {
        return $this->routing->kind === RoutingDecision::DELEGATE_TO_OTHER_FLOW;
    }

    public function suggestedFlow(): ?string
    {
        return $this->routing->suggestedFlow();
    }

    /**
     * Flat summary suitable for JSON output.
     *
     * Provider-safety (F-04): the summary intentionally exposes only the
     * non-absolute, workspace-redacted view of the run. The absolute workspace
     * path lives on the canonical envelope; UI surfaces use `workspace_label`
     * (basename) and `workspace_hash` (provider-safe identifier) instead.
     * Persisted artifact paths are surfaced via {@see persistedArtifactRefs()}
     * — never the absolute storage paths.
     *
     * Internal consumers that need the absolute paths can still read the
     * `persistedArtifactPaths` property directly.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'run_id' => $this->envelope->runId,
            'workspace_label' => basename($this->envelope->workspace),
            'workspace_hash' => $this->envelope->workspaceHash,
            'surface_id' => $this->envelope->surfaceId,
            'task_kind' => $this->classification->taskKind,
            'risk_level' => $this->riskLevel,
            'intent_clarity_level' => $this->envelope->intentClarityLevel,
            'mode' => $this->compactSdd->mode,
            'routing_decision' => $this->routing->kind,
            'routing_reasons' => $this->routing->reasons,
            'suggested_flow' => $this->routing->suggestedFlow(),
            'delegation_reason' => $this->routing->delegation?->reason,
            'blockers' => $this->blockers,
            'discovery_confidence' => $this->discovery->confidence,
            'expected_files' => $this->workspaceSafePaths($this->miniSpec->expectedFiles),
            'allowed_files' => $this->workspaceSafePaths($this->miniSpec->allowedFiles),
            'verification_commands' => $this->miniSpec->verificationPlan->commands,
            'hashes' => [
                'envelope' => $this->envelope->envelopeHash,
                'compact_sdd' => $this->compactSdd->compactSddHash,
                'mini_spec' => $this->miniSpec->miniSpecHash,
                'task_contract' => $this->taskContract->taskContractHash,
                'discovery_manifest' => $this->discovery->manifestHash,
                'open_brain_projection' => $this->projection->projectionHash,
                'prompt_projection' => $this->promptProjection->promptProjectionHash,
                'context_plan' => $this->contextPlan->planHash,
            ],
            'persisted_artifact_refs' => $this->persistedArtifactRefs(),
            'prompt_sendable' => $this->promptProjection->isSendable(),
        ];
    }

    /**
     * Return the canonical refs for the persisted artifact map. Refs are
     * relative to the storage receipts base (`receipts/<run_id>/<filename>`)
     * so a Desktop / API client never sees the absolute server path.
     *
     * @return array<string, string>
     */
    public function persistedArtifactRefs(): array
    {
        $refs = [];
        foreach ($this->persistedArtifactPaths as $name => $absolute) {
            if (! is_string($absolute) || $absolute === '') {
                continue;
            }
            $refs[(string) $name] = 'receipts/'.$this->envelope->runId.'/'.basename($absolute);
        }

        return $refs;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function workspaceSafePaths(array $paths): array
    {
        $workspace = rtrim($this->envelope->workspace, DIRECTORY_SEPARATOR);
        $label = basename($workspace);
        $safe = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $path);
            $workspaceNormalized = str_replace('\\', '/', $workspace);

            if ($workspaceNormalized !== '' && str_starts_with($normalized, $workspaceNormalized.'/')) {
                $safe[] = $label.'/'.ltrim(substr($normalized, strlen($workspaceNormalized)), '/');

                continue;
            }

            $safe[] = ltrim($normalized, '/');
        }

        return array_values($safe);
    }
}
