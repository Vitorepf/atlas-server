<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Aemor\AtlasAemorRuntimeService;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\EngineeringKernel\Spec\SpecAdversary;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookDevBridge;
use App\Services\Ai\Programming\AtlasDev\Discovery\CodeDiscoveryEngine;
use App\Services\Ai\Programming\AtlasDev\Discovery\DevContextBudgetDistiller;
use App\Services\Ai\Programming\AtlasDev\Discovery\DevGreenRunExemplarRetriever;
use App\Services\Ai\Programming\AtlasDev\Discovery\DocContextTierSelector;
use App\Services\Ai\Programming\AtlasDev\Discovery\OpenBrainProjectionAdapter;
use App\Services\Ai\Programming\AtlasDev\Discovery\SymbolLookup;
use App\Services\Ai\Programming\AtlasDev\Gate\MandatoryRagGate;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\PromptProjection\ProviderPromptBuilder;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsulePromptInjector;
use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\CompactSdd;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\MissingRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopAuditor;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use App\Support\AtlasSecurity;
use Throwable;

/**
 * Atlas Dev fast-path orchestrator — plan-only entry point.
 *
 * Wires together the pure pipeline services so a surface adapter only needs
 * to pass the four primitive arguments (surface_id, workspace, raw_intent,
 * user_constraints) plus optional surface hints. Every step is deterministic
 * and provider-free; this orchestrator MUST NOT call any provider.
 *
 * The six inline wiring points are extracted into a private ordered stage
 * pipeline (stages()), each with the uniform signature array → array, executed
 * by a single loop with per-stage timing recorded into run artifacts.
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
 *   - workcell_decomposition.json (additive; {@see DevWorkcellDecomposer})
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
        private readonly ?MandatoryRagGate $mandatoryRagGate = null,
        private readonly ?SpecialistFlowRouter $specialistFlowRouter = null,
        private readonly ?DevFailureCapsulePromptInjector $failureCapsuleInjector = null,
        private readonly ?SymbolLookup $callerLookup = null,
        private readonly ?AtlasAemorRuntimeService $aemorRuntime = null,
        private readonly ?DevWorkcellDecomposer $workcellDecomposer = null,
        private readonly ?DevGreenRunExemplarRetriever $exemplarRetriever = null,
        private readonly ?DevWorkcellInstructionAssembler $instructionAssembler = null,
        private readonly ?SpecAdversary $specGate = null,
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
        // NOTE: discovery enrichment now happens in the stage pipeline below

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

        // Stage pipeline: enrichment, distillation, exemplar retrieval,
        // verification receipts, decomposition — all in one ordered loop.
        $workspaceSlug = WorkspaceOriginIdentity::slug($envelope->workspace);
        $workspaceHash = $envelope->workspaceHash;
        $originHash = WorkspaceOriginIdentity::hash($envelope->workspace);

        $state = [
            'envelope' => $envelope,
            'classification' => $classification,
            'finalRisk' => $finalRisk,
            'compactSdd' => $compactSdd,
            'contextPlan' => $contextPlan,
            'discovery' => $discovery,
            'projection' => $projection,
            'miniSpec' => $miniSpec,
            'taskContract' => $taskContract,
            'routing' => $routing,
            'workspace' => $envelope->workspace,
            'workspaceSlug' => $workspaceSlug,
            'workspaceHash' => $workspaceHash,
            'originHash' => $originHash,
            'distillation' => [],
            'persistedDistillation' => null,
            'provenExemplars' => [],
            'gate' => null,
            'specVerdictArtifact' => null,
            'decomposition' => null,
            'workcellInstructions' => null,
            'workspaceOrigin' => null,
            'persistedExtra' => [],
        ];

        $state = $this->runStages($state);

        // Extract updated values from the state after the pipeline.
        $discovery = $state['discovery'];
        $routing = $state['routing'];
        $distillation = $state['distillation'];
        $persistedDistillation = $state['persistedDistillation'];
        $provenExemplars = $state['provenExemplars'];
        $gate = $state['gate'];
        $specVerdictArtifact = $state['specVerdictArtifact'];

        $promptIsSendable = $routing->kind === RoutingDecision::ATLAS_DEV_FAST_PATH;

        // M5: Compounding failure memory — feed persisted AtlasDevFailureCapsule
        // rows forward as known failure modes of the area into the prompt
        // projection. Area identity = overlap between the run's allowed_files
        // and a capsule's changed_files, AND repository/workspace identity via
        // the capsule's task_packet.workspace_slug. REUSES AtlasDevFailureCapsule
        // (model) via DevFailureCapsulePromptInjector (read-only). Injection is
        // workspace-scoped (foreign-workspace capsule never injects even when
        // its changed_files overlap — VAL-M5-007 anti cross-repo bleed),
        // area-scoped (foreign-area capsule never injects — VAL-M5-003),
        // provider-safe (secrets redacted — VAL-M5-005), deterministic and
        // deduped on failure_hash (VAL-M5-006). An empty/foreign area yields
        // an empty list and the projection stays byte-identical to the pre-M5
        // baseline (VAL-M5-004 — the renderer omits the section when empty).
        //
        // Workspace_slug normalization mirrors DevTaskPacketRuntimeService,
        // where workspace_slug falls back to the workspace string when no
        // explicit slug is supplied. The envelope carries the resolved
        // workspace (path or slug) used by the current run.
        // Workspace identity for M5 = the stable REPO ORIGIN, not the
        // checkout path: sandboxed flows run in per-run temp dirs, so the
        // raw envelope workspace never matches a previously persisted
        // capsule's slug (the write side uses the same identity).
        $knownFailureModes = ($this->failureCapsuleInjector ?? new DevFailureCapsulePromptInjector)
            ->injectFor($taskContract->allowedFiles, $workspaceSlug);

        // BUILD #3 producer (task-START): match a proven procedural playbook by
        // task kind and MERGE its provider-safe lines into the same
        // knownFailureModes prompt channel — ADVISORY (never blocks). Recording
        // the attempt (applied, keyed by run id) here gives the follow rate its
        // denominator; the outcome half closes at
        // DevOutcomeMemoryService::persist. Skipped under phpunit so the broad
        // Dev suite never pollutes the live ledger; fail-open.
        // ponytail: reuse the knownFailureModes list channel instead of
        // threading a new section through builder+mapper+VO+renderer; give the
        // playbook its own "## Proven Procedure" header only if the merged lines
        // measurably need it.
        try {
            if (! app()->runningUnitTests()) {
                $playbookLines = app(AtlasProceduralPlaybookDevBridge::class)
                    ->injectionLinesForTask($envelope->runId, $classification->taskKind);
                if ($playbookLines !== []) {
                    $knownFailureModes = array_merge($knownFailureModes, $playbookLines);
                }
            }
        } catch (Throwable) {
            // fail-open: procedural injection never breaks planning.
        }

        $promptProjection = $this->promptBuilder->build(
            envelope: $envelope,
            compactSdd: $compactSdd,
            miniSpec: $miniSpec,
            taskContract: $taskContract,
            discovery: $discovery,
            projection: $projection,
            providerSafe: $promptIsSendable,
            knownFailureModes: $knownFailureModes,
            provenExemplars: $provenExemplars,
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
            distillation: $distillation,
        );

        if ($persistedDistillation !== null) {
            $persisted['dev_context_budget_distillation'] = $persistedDistillation;
        }
        if ($specVerdictArtifact !== null) {
            $persisted['spec_adversary_verdict.json'] = $specVerdictArtifact;
        }

        // Merge extra artifacts from the stage pipeline (workcell
        // decomposition, workcell instructions, workspace origin).
        foreach ($state['persistedExtra'] as $name => $path) {
            $persisted[$name] = $path;
        }

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

        $persisted[ArtifactNames::MANDATORY_RAG_GATE] = $this->receiptStorage->writeAtomic(
            $envelope->runId,
            ArtifactNames::MANDATORY_RAG_GATE,
            $gate !== null ? $gate->toCanonicalArray() : (new MandatoryRagGate)->evaluate($envelope, $classification, $compactSdd, $contextPlan, $routing)->toCanonicalArray(),
        );

        // Specialist Flow Router (Atlas Dev Superiority Runtime).
        // Decides which of the 9 canonical specialist flows the operator is
        // really executing (plan / code / debug / review / research /
        // explain / test / refactor / forge_escalation) and the path within
        // it (fast / deep / ask_clarification / escalate). Decision is
        // advisory for the operator surface — does not change the routing
        // kind already chosen by RoutingDecisionEngine + the Mandatory RAG
        // Gate. Persisted as an auditable receipt.
        $specialistDecision = ($this->specialistFlowRouter ?? new SpecialistFlowRouter)
            ->decide($envelope, $classification, $compactSdd, $discovery, $routing);
        $persisted[ArtifactNames::SPECIALIST_FLOW_DECISION] = $this->receiptStorage->writeAtomic(
            $envelope->runId,
            ArtifactNames::SPECIALIST_FLOW_DECISION,
            $specialistDecision->toCanonicalArray(),
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
            specialistFlow: $specialistDecision,
        );
    }

    // ── Stage pipeline ──────────────────────────────────────────────────────

    /**
     * Ordered stage pipeline. Each stage receives the full run state array and
     * returns it with its contributions merged in. Uniform signature:
     * function(array $state): array.
     *
     * @return array<string, callable>
     */
    private function stages(): array
    {
        return [
            'discovery_enrichment' => fn (array $state): array => $this->stageDiscoveryEnrichment($state),
            'aemor_outcome_bridge' => fn (array $state): array => $this->stageAemorOutcomeBridge($state),
            'context_budget_distillation' => fn (array $state): array => $this->stageContextBudgetDistillation($state),
            'exemplar_retrieval' => fn (array $state): array => $this->stageExemplarRetrieval($state),
            'verification_receipts' => fn (array $state): array => $this->stageVerificationReceipts($state),
            'workcell_decomposition' => fn (array $state): array => $this->stageWorkcellDecomposition($state),
        ];
    }

    /**
     * Execute the ordered stage pipeline in a single loop, recording
     * per-stage timing (milliseconds) into the state under
     * 'stage_timings_ms'.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function runStages(array $state): array
    {
        $timings = [];
        foreach ($this->stages() as $name => $callable) {
            $start = microtime(true);
            try {
                $state = $callable($state);
            } catch (Throwable $e) {
                $state['stage_errors'][] = [
                    'stage' => $name,
                    'error' => $this->redactStageError($e->getMessage()),
                ];

                if ($this->isMandatoryPlanningStage($name)) {
                    $state = $this->blockRoutingForMandatoryStageFailure($state, $name);
                }
            }
            $elapsed = (microtime(true) - $start) * 1000; // milliseconds
            $timings[$name] = round($elapsed, 2);
        }
        $state['stage_timings_ms'] = $timings;

        // Persist stage timings as an additive run artifact.
        try {
            $state['persistedExtra']['fast_path_stage_timings.json'] = $this->receiptStorage->writeAtomic(
                (string) $state['envelope']->runId,
                'fast_path_stage_timings.json',
                [
                    'schema' => 'atlas.dev.fast_path_stage_timings.v1',
                    'stages' => $timings,
                    'total_ms' => round(array_sum($timings), 2),
                ],
            );
        } catch (Throwable) {
            // Best-effort: timing artifact is additive; failure is non-blocking.
        }
        if (($state['stage_errors'] ?? []) !== []) {
            try {
                $state['persistedExtra']['fast_path_stage_errors.json'] = $this->receiptStorage->writeAtomic(
                    (string) $state['envelope']->runId,
                    'fast_path_stage_errors.json',
                    [
                        'schema' => 'atlas.dev.fast_path_stage_errors.v1',
                        'errors' => array_values((array) $state['stage_errors']),
                    ],
                );
            } catch (Throwable) {
                // Best-effort audit artifact; routing already reflects mandatory failures.
            }
        }

        return $state;
    }

    private function isMandatoryPlanningStage(string $name): bool
    {
        return $name === 'verification_receipts';
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function blockRoutingForMandatoryStageFailure(array $state, string $name): array
    {
        $routing = $state['routing'] ?? null;
        if (! $routing instanceof RoutingDecision) {
            return $state;
        }

        $state['routing'] = new RoutingDecision(
            kind: RoutingDecision::BLOCKED,
            reasons: AtlasDevStringListNormalizer::uniqueTrimmedStrings(array_merge(
                $routing->reasons,
                ['mandatory_planning_stage_failed:'.$name],
            )),
            blockers: AtlasDevStringListNormalizer::uniqueTrimmedStrings(array_merge(
                $routing->blockers,
                ['mandatory_planning_stage_failed'],
            )),
            delegation: $routing->delegation,
        );

        return $state;
    }

    private function redactStageError(string $message): string
    {
        $message = AtlasSecurity::redactString($message);
        $redacted = preg_replace('#/(?:Users|private/var|var/folders|tmp)/[^\s"\']+#', '[path-redacted]', $message);

        return is_string($redacted) ? $redacted : $message;
    }

    /**
     * Stage 1: Enrich the discovery manifest with likely_callers — the
     * strongest production consumers of the likely files, discovered via
     * SymbolLookup. Purely additive (fail-open). Any internal error yields
     * the original manifest untouched.
     */
    private function stageDiscoveryEnrichment(array $state): array
    {
        $discovery = $state['discovery'];
        $workspace = $state['workspace'];

        try {
            $callers = $this->discoverLikelyCallers($discovery, $workspace);
            $state['discovery'] = new CodeDiscoveryManifest(
                runId: $discovery->runId,
                likelyFiles: $discovery->likelyFiles,
                relatedSymbols: $discovery->relatedSymbols,
                relatedTests: $discovery->relatedTests,
                relatedCommands: $discovery->relatedCommands,
                confidence: $discovery->confidence,
                missingRefs: $discovery->missingRefs,
                forbiddenFiles: $discovery->forbiddenFiles,
                providerSafe: $discovery->providerSafe,
                manifestHash: $discovery->manifestHash,
                likelyCallers: $callers,
                recentOutcomeFacts: $discovery->recentOutcomeFacts,
            );
        } catch (Throwable) {
            // fail-open: keep discovery unchanged
        }

        return $state;
    }

    /**
     * Stage 2: Read recent AEMOR run outcomes for the files in the discovery
     * manifest and fold them in as compact facts. Purely additive (fail-open):
     * unavailable runtime or a thrown exception yields empty facts, never a
     * failed run.
     */
    private function stageAemorOutcomeBridge(array $state): array
    {
        $discovery = $state['discovery'];

        $facts = $this->discoverRecentOutcomeFacts($discovery);

        // Only rebuild if we have new facts and they're not already present.
        if ($facts !== [] || $discovery->recentOutcomeFacts !== []) {
            $state['discovery'] = new CodeDiscoveryManifest(
                runId: $discovery->runId,
                likelyFiles: $discovery->likelyFiles,
                relatedSymbols: $discovery->relatedSymbols,
                relatedTests: $discovery->relatedTests,
                relatedCommands: $discovery->relatedCommands,
                confidence: $discovery->confidence,
                missingRefs: $discovery->missingRefs,
                forbiddenFiles: $discovery->forbiddenFiles,
                providerSafe: $discovery->providerSafe,
                manifestHash: $discovery->manifestHash,
                likelyCallers: $discovery->likelyCallers,
                recentOutcomeFacts: $facts,
            );
        }

        return $state;
    }

    /**
     * Stage 3: Distill discovery/projection evidence down to what the
     * CompactSdd already declared as the char budget, so a small model gets
     * less noise and more of the acceptance-critical evidence (callers, tests,
     * decisions). Non-blocking: any distillation error is swallowed and the
     * distillation receipt is skipped.
     */
    private function stageContextBudgetDistillation(array $state): array
    {
        try {
            $distillation = (new DevContextBudgetDistiller)->distill(
                $this->buildContextSections($state['discovery'], $state['projection'], $state['compactSdd']),
                $state['compactSdd']->contextBudget->maxChars,
            );
            $persistedDistillation = $this->receiptStorage->writeAtomic(
                $state['envelope']->runId,
                'dev_context_budget_distillation',
                $distillation,
            );
            $state['distillation'] = $distillation;
            $state['persistedDistillation'] = $persistedDistillation;
        } catch (Throwable) {
            $state['distillation'] = [];
            $state['persistedDistillation'] = null;
        }

        return $state;
    }

    /**
     * Stage 4: Retrieve proven green-run exemplars for the LIVE prompt (not
     * just the workcell_instructions.json receipt). Real runs of the same task
     * kind / design path / files that already passed verification here. Uses
     * DevGreenRunExemplarRetriever which never throws (fail-open); an empty
     * store keeps the prompt byte-identical.
     */
    private function stageExemplarRetrieval(array $state): array
    {
        $state['provenExemplars'] = ($this->exemplarRetriever ?? new DevGreenRunExemplarRetriever)->retrieve(
            $state['classification']->taskKind,
            $this->designPathFromSpec($state['miniSpec']->toCanonicalArray()),
            $state['taskContract']->allowedFiles,
            workspaceHash: $state['workspaceHash'],
            originHash: $state['originHash'],
        );

        return $state;
    }

    /**
     * Stage 5: Verification receipts — Mandatory RAG Gate (fail-closed) and
     * Spec Adversary (fail-closed structural check). The Mandatory RAG Gate
     * evaluates whether the run has sufficient context; for non-trivial
     * engineering tasks, insufficient context forces routing → BLOCKED. The
     * Spec Adversary (Obra #2) attacks fidelity-of-spec; a structural refusal
     * forces BLOCKED. Both are deterministic and provider-free.
     */
    private function stageVerificationReceipts(array $state): array
    {
        $envelope = $state['envelope'];
        $classification = $state['classification'];
        $compactSdd = $state['compactSdd'];
        $contextPlan = $state['contextPlan'];
        $routing = $state['routing'];
        $miniSpec = $state['miniSpec'];
        $taskContract = $state['taskContract'];

        // Mandatory RAG Gate (fail-closed). After classification/spec/plan
        // are deterministic, the gate decides whether the run can proceed.
        // For non-trivial engineering tasks (write_implied, high risk, or
        // any kind != read-only-question/review), insufficient context
        // forces routing -> BLOCKED. Bypass only via explicit auditable
        // operator constraint + config flag.
        $gate = ($this->mandatoryRagGate ?? new MandatoryRagGate)
            ->evaluate($envelope, $classification, $compactSdd, $contextPlan, $routing);

        if ($gate->isBlocked()) {
            $routing = new RoutingDecision(
                kind: RoutingDecision::BLOCKED,
                reasons: AtlasDevStringListNormalizer::uniqueTrimmedStrings(array_merge(
                    $routing->reasons,
                    ['mandatory_rag_gate:'.$gate->reason],
                )),
                blockers: AtlasDevStringListNormalizer::uniqueTrimmedStrings(array_merge(
                    $routing->blockers,
                    $gate->blockers,
                )),
                delegation: $routing->delegation,
            );
        }

        $state['gate'] = $gate;
        $state['routing'] = $routing;

        // SPEC-ADVERSARY (Obra #2) — fidelity-OF-spec, provider-free, fail-closed. Before the
        // composed criteria are frozen into a sendable prompt, the deterministic spec floor attacks
        // them. A STRUCTURAL refusal (a recognized write verb with ZERO acceptance criteria, or an
        // unwitnessed spec in the autonomous lane) forces BLOCKED. Two invariants are DEFERRED here,
        // not skipped: oracle_adequacy (no test is authored yet at plan time — discrimination is
        // discharged downstream by the sovereign floor's mutation_kill_ratio at certify) and
        // ambiguity_resolved (surfaced to the operator via the clarification queue, not a hard block).
        $specVerdictArtifact = $state['specVerdictArtifact'];
        if ($this->specGate !== null && $routing->kind === RoutingDecision::ATLAS_DEV_FAST_PATH) {
            $specVerdict = $this->specGate->contest(
                new SpecDraft(
                    intentText: $miniSpec->goal,
                    acceptanceCriteria: $miniSpec->acceptanceCriteria,
                    expectedFiles: $miniSpec->expectedFiles,
                    forbiddenFiles: $miniSpec->forbiddenFiles,
                    nonGoals: $miniSpec->nonGoals,
                ),
                new IntentEnvelope(
                    rawGoal: $envelope->rawIntent,
                    recognizedVerbs: $taskContract->intentVerbs,
                ),
                TrustLevel::Dev,
            );
            $structuralGaps = array_values(array_diff($specVerdict->gaps, ['oracle_adequacy', 'ambiguity_resolved']));
            if ($structuralGaps !== []) {
                $routing = new RoutingDecision(
                    kind: RoutingDecision::BLOCKED,
                    reasons: AtlasDevStringListNormalizer::uniqueTrimmedStrings(array_merge(
                        $routing->reasons,
                        ['spec_adversary:'.$specVerdict->status],
                    )),
                    blockers: AtlasDevStringListNormalizer::uniqueTrimmedStrings(array_merge(
                        $routing->blockers,
                        array_map(static fn (string $gap): string => 'spec_'.$gap, $structuralGaps),
                    )),
                    delegation: $routing->delegation,
                );
            }
            try {
                $specVerdictArtifact = $this->receiptStorage->writeAtomic(
                    $envelope->runId,
                    'spec_adversary_verdict.json',
                    $specVerdict->toArray(),
                );
            } catch (Throwable) {
                $specVerdictArtifact = null;
            }
            $state['routing'] = $routing;
            $state['specVerdictArtifact'] = $specVerdictArtifact;
        }

        return $state;
    }

    /**
     * Stage 6: Decompose the spec into workcells and assemble one executable
     * instruction per workcell boosted with green-run exemplars. Additive,
     * never blocks. Also persists the workspace origin identity for future
     * cross-sandbox exemplar matching.
     */
    private function stageWorkcellDecomposition(array $state): array
    {
        $envelope = $state['envelope'];
        $miniSpec = $state['miniSpec'];
        $discovery = $state['discovery'];
        $classification = $state['classification'];
        $distillation = $state['distillation'];
        $workspaceHash = $state['workspaceHash'];
        $originHash = $state['originHash'];

        $decomposer = $this->workcellDecomposer ?? new DevWorkcellDecomposer;
        $decomposition = $decomposer->decompose($miniSpec->toCanonicalArray(), $discovery->toCanonicalArray());
        $state['decomposition'] = $decomposition;

        $state['persistedExtra']['workcell_decomposition.json'] = $this->receiptStorage->writeAtomic(
            $envelope->runId,
            'workcell_decomposition.json',
            $decomposition,
        );

        // ADDITIVE: one executable instruction per workcell, boosted with real green-run
        // exemplars. Same fail-open contract as the distillation above — never blocks planOnly().
        $state['persistedExtra']['workcell_instructions.json'] = $this->receiptStorage->writeAtomic(
            $envelope->runId,
            'workcell_instructions.json',
            $this->assembleWorkcellInstructions($decomposition, $miniSpec->toCanonicalArray(), $classification->taskKind, $distillation, $workspaceHash, $originHash),
        );

        // Stable origin identity of this run's workspace, so future exemplar
        // retrieval can match runs whose CHECKOUT PATH differs (per-run
        // sandboxes) but whose REPO is the same. Only the hash is persisted
        // (the slug may be a git remote URL). Additive artifact.
        $state['persistedExtra']['workspace_origin.json'] = $this->receiptStorage->writeAtomic(
            $envelope->runId,
            'workspace_origin.json',
            [
                'schema' => 'atlas.dev.workspace_origin.v1',
                'origin_hash' => $originHash,
            ],
        );

        return $state;
    }

    // ── Original private helpers (unchanged) ────────────────────────────────

    /**
     * Enriches the discovery manifest with likely_callers — evidence a
     * small model needs most, so the context pack is not just "what files" but
     * "who else depends on them". Purely additive: every existing manifest
     * field is untouched, and ANY failure inside enrichment (missing collaborator,
     * lookup exception, unexpected shape) yields the ORIGINAL unenriched
     * manifest — enrichment can only ever add evidence, never block or corrupt
     * a Dev run.
     *
     * NOTE: recent_outcome_facts enrichment has been extracted into
     * stageAemorOutcomeBridge so the AEMOR outcome bridge is an independent
     * pipeline stage with its own timing and fail-open boundary.
     */
    private function enrichDiscovery(CodeDiscoveryManifest $discovery, string $workspace): CodeDiscoveryManifest
    {
        try {
            return new CodeDiscoveryManifest(
                runId: $discovery->runId,
                likelyFiles: $discovery->likelyFiles,
                relatedSymbols: $discovery->relatedSymbols,
                relatedTests: $discovery->relatedTests,
                relatedCommands: $discovery->relatedCommands,
                confidence: $discovery->confidence,
                missingRefs: $discovery->missingRefs,
                forbiddenFiles: $discovery->forbiddenFiles,
                providerSafe: $discovery->providerSafe,
                manifestHash: $discovery->manifestHash,
                likelyCallers: $this->discoverLikelyCallers($discovery, $workspace),
                recentOutcomeFacts: $discovery->recentOutcomeFacts,
            );
        } catch (Throwable) {
            return $discovery;
        }
    }

    /**
     * Reuses the SAME code-intelligence lookup (SymbolLookup) already consulted for
     * relatedSymbols: for each likely file's class-like basename, any OTHER file the lookup
     * associates with that symbol is a candidate production consumer.
     *
     * @return list<ContextRef>
     */
    private function discoverLikelyCallers(CodeDiscoveryManifest $discovery, string $workspace): array
    {
        if ($this->callerLookup === null) {
            return [];
        }

        $callers = [];
        foreach ($discovery->likelyFiles as $candidate) {
            if (! $candidate instanceof CodeCandidate) {
                continue;
            }
            $symbol = pathinfo($candidate->path, PATHINFO_FILENAME);
            if ($symbol === '') {
                continue;
            }

            try {
                $hits = $this->callerLookup->find($workspace, $symbol);
            } catch (Throwable) {
                continue;
            }

            foreach ($hits as $hit) {
                $path = (string) ($hit['path'] ?? '');
                if ($path === '' || $path === $candidate->path) {
                    continue;
                }
                $callers[$path] = new ContextRef(
                    kind: ContextRef::KIND_FILE,
                    ref: 'file://'.$path,
                    reason: (string) ($hit['reason'] ?? ('likely caller of '.$symbol)),
                );
            }
        }

        $list = array_values($callers);
        usort($list, static fn (ContextRef $a, ContextRef $b): int => strcmp($a->ref, $b->ref));

        return $list;
    }

    /**
     * Reads recent AEMOR run outcomes when the runtime is available, as compact facts. Fail-open:
     * an unavailable runtime or a thrown exception yields an empty list, never a failed run.
     *
     * @return list<string>
     */
    private function discoverRecentOutcomeFacts(CodeDiscoveryManifest $discovery): array
    {
        if ($this->aemorRuntime === null || $discovery->likelyFiles === []) {
            return [];
        }

        try {
            $controlPlane = $this->aemorRuntime->controlPlane();
        } catch (Throwable) {
            return [];
        }

        $facts = [];
        foreach ((array) ($controlPlane['blockers'] ?? []) as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }
            $status = (string) ($blocker['status'] ?? '');
            if ($status === '') {
                continue;
            }
            $signature = (string) ($blocker['failure_signature'] ?? '');
            $facts[] = trim("outcome:{$status}".($signature !== '' ? ":{$signature}" : ''));
        }

        return array_values(array_unique($facts));
    }

    /**
     * Assembles the labeled context sections the distiller trims — text is derived purely from
     * already-computed discovery/projection evidence, never fetched or invented here.
     *
     * @return array<string, string>
     */
    private function buildContextSections(
        CodeDiscoveryManifest $discovery,
        AtlasDevSchemaContract $projection,
        CompactSdd $compactSdd,
    ): array {
        $joinRefs = static function (array $refs): string {
            $lines = [];
            foreach ($refs as $ref) {
                if ($ref instanceof ContextRef) {
                    $lines[] = $ref->ref.' :: '.$ref->reason;
                }
            }

            return implode("\n", $lines);
        };

        $projectionArray = $projection->toCanonicalArray();

        return [
            'owner_docs' => $joinRefs(array_map(
                static fn (array $r): ContextRef => ContextRef::fromArray($r),
                (array) ($projectionArray['knowledge_refs'] ?? []),
            )),
            'symbols' => $joinRefs($discovery->relatedSymbols),
            'callers' => $joinRefs($discovery->likelyCallers),
            'tests' => $joinRefs($discovery->relatedTests),
            'decisions' => $joinRefs(array_map(
                static fn (array $r): ContextRef => ContextRef::fromArray($r),
                (array) ($projectionArray['memory_refs'] ?? []),
            )),
            'risks' => $compactSdd->riskLevel.': '.implode(', ', array_map(
                static fn (MissingRef $r): string => $r->what,
                $discovery->missingRefs,
            )),
            'recent_outcomes' => implode("\n", $discovery->recentOutcomeFacts),
        ];
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
        array $distillation = [],
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

        // NOTE: workcell decomposition, workcell instructions, and workspace
        // origin are now handled by stageWorkcellDecomposition in the pipeline.

        return $persisted;
    }

    /**
     * @param  array<string,mixed>  $decomposition  DevWorkcellDecomposer::decompose() output
     * @param  array<string,mixed>  $spec  MiniProgrammingSpec::toCanonicalArray()
     * @param  array<string,mixed>  $distillation  DevContextBudgetDistiller::distill() output
     * @return array{schema:string, instructions:list<array{workcell_id:string, instruction_text:string, sections:list<string>, char_count:int}>}
     */
    private function assembleWorkcellInstructions(array $decomposition, array $spec, string $taskKind, array $distillation, ?string $workspaceHash = null, ?string $originHash = null): array
    {
        $assembler = $this->instructionAssembler ?? new DevWorkcellInstructionAssembler;
        $retriever = $this->exemplarRetriever ?? new DevGreenRunExemplarRetriever;
        $designPath = $this->designPathFromSpec($spec);

        $instructions = [];
        foreach ((array) ($decomposition['workcells'] ?? []) as $workcell) {
            if (! is_array($workcell)) {
                continue;
            }
            $allowedFiles = array_values(array_map('strval', (array) ($workcell['allowed_files'] ?? [])));
            $exemplars = $retriever->retrieve($taskKind, $designPath, $allowedFiles, workspaceHash: $workspaceHash, originHash: $originHash);
            $assembled = $assembler->assemble($workcell, $distillation, $spec, $exemplars);
            $instructions[] = ['workcell_id' => (string) ($workcell['workcell_id'] ?? '')] + $assembled;
        }

        return [
            'schema' => 'atlas.dev.workcell_instructions.v1',
            'instructions' => $instructions,
        ];
    }

    /** @param  array<string,mixed>  $spec */
    private function designPathFromSpec(array $spec): string
    {
        foreach ((array) ($spec['canonical_context'] ?? []) as $entry) {
            if (is_array($entry) && (string) ($entry['kind'] ?? '') === 'design_path') {
                return (string) ($entry['ref'] ?? '');
            }
        }

        return '';
    }
}
