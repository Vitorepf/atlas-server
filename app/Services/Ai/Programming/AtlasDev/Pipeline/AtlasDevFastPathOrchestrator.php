<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopAuditor;

/**
 * Atlas Dev fast-path orchestrator — plan-only entry point.
 *
 * Wires together the pure pipeline services so a surface adapter only needs
 * to pass the four primitive arguments (surface_id, workspace, raw_intent,
 * user_constraints) plus optional surface hints. Every step is deterministic
 * and provider-free; this orchestrator MUST NOT call any provider.
 *
 * Persisted artifacts (one JSON file per name under
 * `storage/atlas-dev/receipts/<run_id>/`):
 *   - operation_envelope.json
 *   - compact_sdd.json
 *   - context_retrieval_plan.json
 *   - code_discovery_manifest.json
 *   - open_brain_projection.json
 *   - mini_programming_spec.json
 *   - task_contract.json
 *   - prompt_projection.json
 *   - routing_decision.json
 */
class AtlasDevFastPathOrchestrator
{
    public function __construct(
        private readonly IntakeNormalizer $intake,
        private readonly TaskClassifier $classifier,
        private readonly RiskLevelScorer $riskScorer,
        private readonly SpecComposer $specComposer,
        private readonly DocContextTierSelector $tierSelector,
        private readonly CodeDiscoveryEngine $codeDiscovery,
        private readonly OpenBrainProjectionAdapter $openBrainAdapter,
        private readonly ProviderPromptBuilder $promptBuilder,
        private readonly RoutingDecisionEngine $routingEngine,
        private readonly ReceiptStorage $receiptStorage,
    ) {}

    /**
     * @param  list<string>  $userConstraints
     * @param  array<string, mixed>  $surfaceHints
     */
    public function planOnly(
        string $surfaceId,
        string $workspace,
        string $rawIntent,
        array $userConstraints = [],
        array $surfaceHints = [],
    ): PlanOnlyResult {
        $envelope = $this->intake->normalize($surfaceId, $workspace, $rawIntent, $userConstraints, $surfaceHints);
        $classification = $this->classifier->classify($envelope);

        $preliminaryRisk = $this->riskScorer->score($envelope, $classification, discovery: null);
        $compactSddPreliminary = $this->specComposer->composeCompactSdd($envelope, $classification, $preliminaryRisk);

        $contextPlan = $this->tierSelector->select($envelope, $compactSddPreliminary);
        $discovery = $this->codeDiscovery->discover($envelope, $compactSddPreliminary);

        $finalRisk = $this->riskScorer->score($envelope, $classification, $discovery);
        $compactSdd = $finalRisk === $preliminaryRisk
            ? $compactSddPreliminary
            : $this->specComposer->composeCompactSdd($envelope, $classification, $finalRisk);

        // If the risk changed the context plan must follow.
        if ($finalRisk !== $preliminaryRisk) {
            $contextPlan = $this->tierSelector->select($envelope, $compactSdd);
        }

        $projection = $this->openBrainAdapter->projectFor($envelope, $compactSdd, $contextPlan);
        $miniSpec = $this->specComposer->composeMiniSpec($envelope, $compactSdd, $discovery, $projection);
        $taskContract = $this->specComposer->composeTaskContract($envelope, $compactSdd, $miniSpec);

        // Routing decides FIRST so the prompt projection knows whether it is
        // about to be sent. Anything that is not the fast path (delegation,
        // forge preview, blocked, read-only answer) must produce an
        // explicitly non-sendable projection so downstream surfaces cannot
        // accidentally pipe it into the provider adapter.
        $routing = $this->routingEngine->decide($envelope, $classification, $compactSdd, $discovery);
        $promptIsSendable = $routing->kind === RoutingDecision::ATLAS_DEV_FAST_PATH;

        $promptProjection = $this->promptBuilder->build(
            envelope: $envelope,
            compactSdd: $compactSdd,
            miniSpec: $miniSpec,
            taskContract: $taskContract,
            discovery: $discovery,
            projection: $projection,
            providerSafe: $promptIsSendable,
        );

        $persisted = $this->persistArtifacts(
            envelope: $envelope,
            compactSdd: $compactSdd,
            contextPlan: $contextPlan,
            discovery: $discovery,
            projection: $projection,
            miniSpec: $miniSpec,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            classification: $classification,
            routing: $routing,
        );

        $result = new PlanOnlyResult(
            envelope: $envelope,
            classification: $classification,
            riskLevel: $finalRisk,
            compactSdd: $compactSdd,
            contextPlan: $contextPlan,
            discovery: $discovery,
            projection: $projection,
            miniSpec: $miniSpec,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            routing: $routing,
            persistedArtifactPaths: $persisted,
            blockers: $routing->blockers,
        );

        $seniorLoopAudit = (new SeniorEngineerLoopAuditor)->audit($result);
        $persisted[ArtifactNames::SENIOR_ENGINEER_LOOP_AUDIT] = $this->receiptStorage->writeAtomic(
            $envelope->runId,
            ArtifactNames::SENIOR_ENGINEER_LOOP_AUDIT,
            $seniorLoopAudit->toCanonicalArray(),
        );

        return new PlanOnlyResult(
            envelope: $envelope,
            classification: $classification,
            riskLevel: $finalRisk,
            compactSdd: $compactSdd,
            contextPlan: $contextPlan,
            discovery: $discovery,
            projection: $projection,
            miniSpec: $miniSpec,
            taskContract: $taskContract,
            promptProjection: $promptProjection,
            routing: $routing,
            persistedArtifactPaths: $persisted,
            blockers: $routing->blockers,
            seniorLoopAudit: $seniorLoopAudit,
        );
    }

    /**
     * @return array<string, string>
     */
    private function persistArtifacts(
        AtlasDevSchemaContract $envelope,
        AtlasDevSchemaContract $compactSdd,
        AtlasDevSchemaContract $contextPlan,
        AtlasDevSchemaContract $discovery,
        AtlasDevSchemaContract $projection,
        AtlasDevSchemaContract $miniSpec,
        AtlasDevSchemaContract $taskContract,
        AtlasDevSchemaContract $promptProjection,
        TaskClassification $classification,
        RoutingDecision $routing,
    ): array {
        $runId = (string) $envelope->toCanonicalArray()['run_id'];
        $persisted = [];
        $artifacts = [
            ArtifactNames::OPERATION_ENVELOPE => $envelope,
            ArtifactNames::COMPACT_SDD => $compactSdd,
            ArtifactNames::CONTEXT_RETRIEVAL_PLAN => $contextPlan,
            ArtifactNames::CODE_DISCOVERY_MANIFEST => $discovery,
            ArtifactNames::OPEN_BRAIN_PROJECTION => $projection,
            ArtifactNames::MINI_PROGRAMMING_SPEC => $miniSpec,
            ArtifactNames::TASK_CONTRACT => $taskContract,
            ArtifactNames::PROMPT_PROJECTION => $promptProjection,
        ];
        foreach ($artifacts as $name => $artifact) {
            $persisted[$name] = $this->receiptStorage->writeAtomic(
                $runId,
                $name,
                $artifact->toCanonicalArray(),
            );
        }
        $routingPayload = [
            'kind' => $routing->kind,
            'reasons' => array_values($routing->reasons),
            'blockers' => array_values($routing->blockers),
            'classification' => [
                'task_kind' => $classification->taskKind,
                'intent_clarity_level' => $classification->intentClarityLevel,
                'write_implied' => $classification->writeImplied,
                'matched_rules' => array_values($classification->matchedRules),
            ],
            'run_id' => $runId,
            'schema_version' => 'atlas.dev.routing_decision.v1',
        ];
        $persisted[ArtifactNames::ROUTING_DECISION] = $this->receiptStorage->writeAtomic(
            $runId,
            ArtifactNames::ROUTING_DECISION,
            $routingPayload,
        );

        return $persisted;
    }
}
