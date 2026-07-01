---
id: atlas-ai-self-construction-loop-acde-v3-ownership-map
type: engineering_knowledge
title: Loop / ACDE v3 Ownership Map
status: active
category: architecture
priority: 97
summary: Inventory of every AutonomousEvolution root class mapped to its v3 owner or marked for retirement.
tags:
  - atlas-ai
  - self-construction
  - loop
  - acde
  - v3-migration
  - ownership-map
capabilities:
  - self_construction_loop_acde_v3_ownership_inventory
decisions:
  - Every legacy Loop/ACDE class earns a v3 owner or is explicitly marked for retirement — no class is left unaccounted for.
  - This inventory is the single canonical entry point for the Loop→v3 migration; do not fork a parallel list elsewhere.
maintenance:
  - Update whenever a class is added, removed, or reassigned to a different v3 owner inside app/Services/Ai/AutonomousEvolution.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap-waves.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 600
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-loop-acde-v3-ownership-map

graph_title: Loop / ACDE v3 Ownership Map

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-runtime-implementation-roadmap

graph_status: active

graph_source: repo
human_name: Loop / ACDE v3 Ownership Map
canonical_name: Loop / ACDE v3 Ownership Map
technical_name: atlas-ai-self-construction-loop-acde-v3-ownership-map
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/loop-acde-v3-ownership-map.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/loop-acde-v3-ownership-map.md

allowed_changes:
  - Reassign a class's v3 owner or retirement status when its role changes; keep evidence current.

forbidden_changes:
  - Declaring a class migrated or retired without verifiable evidence of its production caller (or the lack of one).

depends_on:
  - atlas-autonomous-engineering-government

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - loop-acde-v3-migration-execution

governs:
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/loop-acde-v3-ownership-map.md
evidence_refs:
  - symbol: AtlasLoopMasterSwitch

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

next_actions:
  - Reassign owners class-by-class as each v3 court/plane/kernel actually absorbs the corresponding capability.
---

# Loop / ACDE v3 Ownership Map

This is the canonical Loop/ACDE → v3 migration ownership inventory demanded by the Atlas Autonomous
Engineering Government doc: every legacy class living directly in
`app/Services/Ai/AutonomousEvolution` (root files only) either earns one of the nine v3 owners
below, or is marked `retire`. Every class name in the table below exists on disk exactly as
written. Evidence is each class's strongest production caller path found in `app/`, `routes/`, or
`database/`; classes with no production caller (test-only or genuinely unreferenced) show their
strongest available caller (often a test) or the literal word `none`.


| Class | Current Role | V3 Owner | Evidence |
|---|---|---|---|
| AtlasAaelLoopExecutionBridge | The bridge that turns the existing AAEL (Atlas Autonomous Evolution Loop) | Autonomos | app/Console/Commands/AtlasAaelCommand.php |
| AtlasAutonomousEvolutionCertificationService | Certifies AAEL runtime completeness (docs/models/commands/wiring present) | Autonomos | app/Console/Commands/AtlasAaelCertifyCommand.php |
| AtlasAutonomousEvolutionLoopService | Runs AAEL portfolio cycles: opportunities, experiments, promotions, audits | Autonomos | app/Console/Commands/AtlasAaelCommand.php |
| AtlasEvolutionFrozenJudge | The FROZEN JUDGE — the autoresearch `evaluate_bpb` of the Atlas evolution loop. | Verification Court | app/Console/Commands/AtlasFinanceStrategyEvolveCommand.php |
| AtlasEvolutionLoopRunner | The LOOP RUNNER — the governed autoresearch loop over a queue of tasks. | Autonomos | app/Console/Commands/AtlasEvolutionLoopCommand.php |
| AtlasEvolutionScenarioDiffMetricsCalculator | Stateless diff-metrics collaborator extracted from {@see AtlasEvolutionScenarioExplorer}: pure | Spec Court | app/Console/Commands/AtlasLoopScenarioDiffMetricsCommand.php |
| AtlasEvolutionScenarioExplorer | The SCENARIO EXPLORER — the senior-vs-junior engine. | Spec Court | app/Console/Commands/AtlasFinanceStrategyEvolveCommand.php |
| AtlasEvolutionTaskGenerator | The TASK GENERATOR — the Ladder Sources→Ideas→Hypotheses stage, automated. | Spec Court | app/Console/Commands/AtlasEvolutionLoopCommand.php |
| AtlasLoopAbstainAndAsk | §5 · ABSTAIN-AND-ASK — the model-bound frontier cerca (honest, NEVER Goodhart). | Policy Plane | app/Console/Commands/AtlasLoopAbstainAndAskCommand.php |
| AtlasLoopAdversarialVerifierPool | P5 mitigation: adversarial re-read pool for proposals that already passed the deterministic verifier. | Verification Court | app/Providers/AppServiceProvider.php |
| AtlasLoopAmbitionLeapProposer | Proposes ambition leaps, refusing proxy-only deltas (cyclomatic, formatting, rename) | Spec Court | app/Console/Commands/AtlasLoopAmbitionFacultyCommand.php |
| AtlasLoopAntiFarmFloor | §3 · ANTI-FARM FLOOR — the merge-eligibility floor for COMPREHENSION-originated work. | Policy Plane | app/Services/Ai/AutonomousEvolution/AtlasLoopLeapRiskAuditor.php |
| AtlasLoopAntiGoodhartUnifiedRefusal | Unified anti-Goodhart refusal verdict, FACT-only by construction. | Policy Plane | app/Providers/AppServiceProvider.php |
| AtlasLoopArchitectPhaseGate | §1/§3 · ARCHITECT PHASE as a REUSABLE GATE — "projetar cada evolução como principal engineer ANTES de | Spec Court | app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php |
| AtlasLoopArchitectureDraftService | ARQUITETAR (ultra-profissional) — phase 4, the PROJEÇÃO phase that DOMINATES quality (docs/loop-canonical- | Spec Court | app/Console/Commands/AtlasLoopArchDraftCommand.php |
| AtlasLoopArmedCoverageReporter | ARMED-COVERAGE REPORTER — the honest read-model over {@see AtlasLoopCrossLeverageRegistry} (the intended | Verification Court | app/Console/Commands/AtlasAaelCommand.php |
| AtlasLoopAtomParaphraseAudit | ACDE F8 (honest, non-Goodhart form) — paraphrase audit over the HUMAN-FROZEN verification atoms. | Verification Court | app/Console/Commands/AtlasLoopAtomParaphraseAuditCommand.php |
| AtlasLoopAttemptLedger | NEXT-LEVER 5 — PER-OBRA WORKING MEMORY (anti-thrash / anti-context-rot). | Learning-Application Controller | app/Services/Ai/AutonomousEvolution/Frozen/contracts.manifest.json |
| AtlasLoopAutoArchitectureProposalService | L6-4: code-graph driven auto-architecture proposals. | Spec Court | app/Console/Commands/AtlasLoopAutoArchitectureProposalCommand.php |
| AtlasLoopAutoMergeService | A travessia merge-livre v2 (decisão do operador, 11-12/06): propostas CERTIFICADAS do | Governor | app/Providers/AppServiceProvider.php |
| AtlasLoopAutonomousConductor | ABSURD-LEAP 1 — the AUTONOMOUS CONDUCTOR (the integration capstone that ARMS + chains the whole stack). | Autonomos | app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php |
| AtlasLoopBacklogAutoFeederService | L4-2 · daily auto-feeder for the Loop backlog manifest. | Maestro/Task Fabric | app/Console/Commands/AtlasLoopAmbitionFacultyCommand.php |
| AtlasLoopBacklogAutoFeederServiceSupport | Collects backlog-feeder source signals (loss observer, scorecard) | Maestro/Task Fabric | app/Services/Ai/AutonomousEvolution/AtlasLoopBacklogAutoFeederService.php |
| AtlasLoopBacklogManifestService | Shared append-only writer for the Loop backlog manifest. | Maestro/Task Fabric | app/Services/Ai/AutonomousEvolution/AtlasLoopBacklogAutoFeederService.php |
| AtlasLoopBehavioralEquivalenceGate | Lever 3 — the behavioral-equivalence STRENGTH gate. | Verification Court | app/Console/Commands/AtlasLoopBehavioralEquivalenceCommand.php |
| AtlasLoopBenchmarkHarness | §11.2 performance — the benchmark harness + PERF-CERT (the loop can PROVE a speedup). | Verification Court | app/Providers/AppServiceProvider.php |
| AtlasLoopBroaderRegressionGate | THE BROADER REGRESSION GATE — the load-bearing safety piece of obra-auto-merge. | Verification Court | database/migrations/2026_06_17_000100_create_atlas_loop_test_coverage_edges_table.php |
| AtlasLoopBudgetScheduler | ABSURD-LEAP 5 — the BUDGET-OPTIMAL multi-obra scheduler (engineering org, not one engineer). | Governor | app/Services/Ai/AutonomousEvolution/AtlasLoopObraCostEstimator.php |
| AtlasLoopCalibratedConfidenceGate | ACDE lever DG1 — the calibrated-abstention MERGE gate (closes a live write->read pair). | Verification Court | app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php |
| AtlasLoopCapabilityTrendService | ACDE lever D1 — the per-DELIVERY capability-trend instrument (the only thing that answers "is | Learning-Application Controller | app/Console/Commands/AtlasLoopCapabilityTrendCommand.php |
| AtlasLoopChangeClassDrainGate | ACDE lever DG2 — the per-change-class EARNED-AUTONOMY drain gate (the missing READ of a live pair). | Governor | app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php |
| AtlasLoopChangedSymbolCoverageCensus | ACDE Leap 7 (spec/index-completeness ceiling) — the CHANGED-PUBLIC-SYMBOL coverage census (refuse-until-named). | Verification Court | app/Console/Commands/AtlasLoopChangedSymbolCoverageCommand.php |
| AtlasLoopCharacterizationTestVerifier | The acceptance keystone of the auto-characterization-test lane. Given a coverage gap (a target file | Verification Court | app/Console/Commands/AtlasLoopCoverageGapsCommand.php |
| AtlasLoopClarificationRouter | ACDE U7 — deterministic CLARIFICATION ROUTER. Maps an abstention (reason + objective family + how many | Policy Plane | app/Models/AtlasLoopClarificationRequest.php |
| AtlasLoopCompletenessGate | NEXT-LEVER 1 — COMPLETENESS certification (the "complete" dimension). | Verification Court | app/Console/Commands/AtlasLoopCompletenessGateCommand.php |
| AtlasLoopComprehensionOriginator | §5.6 · LAYER 2 — CROSS-MODEL ORIGINATION: the "decide" phase ORIGINATING a new evolution, not merely | Spec Court | app/Services/Ai/SelfConstruction/Quaternity/DialogueToPackets/CortexGroundingSnapshot.php |
| AtlasLoopConfidenceCalibrator | ABSURD-LEAP 3 — the CALIBRATION flywheel (arms the honest ">93% confidence"). | Learning-Application Controller | database/migrations/2026_06_15_000100_create_atlas_loop_confidence_samples_table.php |
| AtlasLoopContractGapScanner | CONTRACT-GAP scanner (shared organ). Detects an INTERFACE declared in a set of files that has ZERO concrete | Spec Court | app/Console/Commands/AtlasBrainContractGapsCommand.php |
| AtlasLoopCortexRoleTokenSemanticDisambiguator | ROLE-TOKEN SEMANTIC DISAMBIGUATOR — kills the false-twin bug where the replenisher's orphan-wiring search | Engineering Kernel | app/Services/Ai/SelfConstruction/AtlasTaskBrainReplenisher.php |
| AtlasLoopCriterionStabilitySelector | ACDE lever V3 — criterion-stability selection across the best-of-N feature fan-out. | Verification Court | app/Console/Commands/AtlasLoopCriterionStabilitySelectCommand.php |
| AtlasLoopCrossFileConsumerGateService | L6-6 cross-file semantic gate. | Verification Court | app/Providers/AppServiceProvider.php |
| AtlasLoopCrossLeverageRegistry | CROSS-LEVERAGE REGISTRY — the single canonical MANIFEST of every cross-leverage primitive in the Loop area, | Maestro/Task Fabric | app/Services/Ai/AutonomousEvolution/AtlasLoopArmedCoverageReporter.php |
| AtlasLoopCrossModelTriangulator | Cross-model fact transport for disputed certification verdicts. | Verification Court | app/Providers/AppServiceProvider.php |
| AtlasLoopCrossTypeLeverageSelector | §3 · CROSS-TYPE LEVERAGE — "o cérebro escolhe o MAIOR passo, por valor real" ACROSS every comprehension | Spec Court | app/Services/Ai/AutonomousEvolution/AtlasLoopCrossLeverageRegistry.php |
| AtlasLoopCycleGitContract | THE CYCLE GIT CONTRACT — the drift-proof lifecycle the operator mandated (2026-06-17): | Engineering Kernel | app/Console/Commands/AtlasLoopCampaignCommand.php |
| AtlasLoopDbResilience | The transient-DB resilience guard the campaign supervisor wraps its durable writes in. | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopTransientDbException.php |
| AtlasLoopDeadCodeProducer | IN-CAMPAIGN persistence for the deterministic dead-code work-type. The work-type | Maestro/Task Fabric | app/Console/Commands/AtlasLoopDeadCodeSweepCommand.php |
| AtlasLoopDeadCodeRemover | DETERMINISTIC dead-code AUTHOR — the provider-LESS missing half of the dead-code work-type. | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopDeterministicDeadCodeWorkType.php |
| AtlasLoopDecompositionOutcomeRecorder | ACDE Leap 5 — appends + reads the decomposition outcome corpus (the compounding substrate). | Spec Court | app/Models/AtlasLoopDecompositionOutcome.php |
| AtlasLoopDecompositionService | DECOMPOR (phase 5 of the canonical 8-phase live cycle) — split a CLEARED architecture draft (phase 4) into a | Spec Court | app/Services/Ai/AutonomousEvolution/AtlasLoopArchitectureDraftService.php |
| AtlasLoopDecompositionShapePrior | ACDE Leap 5 — the shape-prior verdict (Wilson lower-bound certified-rate, n-guarded). | Spec Court | database/migrations/2026_06_16_000100_create_atlas_loop_decomposition_outcomes_table.php |
| AtlasLoopDeliveryConfidenceModel | Lever 2 — CALIBRATED delivery confidence (the ">93% confidence everything is correct" enabler). | Learning-Application Controller | app/Console/Commands/AtlasLoopDeliveryConfidenceCommand.php |
| AtlasLoopDeliveryContractRecorder | ACDE lever B4b — the certified-delivery BRAIN-FEEDBACK recorder (the MULTIPLIER write-end of the brain | Learning-Application Controller | app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php |
| AtlasLoopDeliveryDimensionResolver | ACDE lever D2 — the SINGLE machine-resolved per-delivery dimension definition. | Learning-Application Controller | app/Services/Ai/AutonomousEvolution/AtlasLoopDeliveryDossierService.php |
| AtlasLoopDeliveryDossierService | ACDE lever F3 — the per-DELIVERY HMAC-signed dossier + feature-outcome ledger. | Learning-Application Controller | app/Console/Commands/AtlasLoopDeliveryDossierCommand.php |
| AtlasLoopDeliveryQualityScore | ACDE Bloco C — the DELIVERY QUALITY SCORE (the falsifiable ">=2x vs ultracode" instrument). | Learning-Application Controller | app/Console/Commands/AtlasLoopDqsExtractAceCommand.php |
| AtlasLoopDeliveryRecallService | ACDE lever B3 — provider-safe DELIVERY RECALL into the loop's prompt window. | Learning-Application Controller | app/Services/Ai/AutonomousEvolution/WorkspaceProviderLoopExecutionDriver.php |
| AtlasLoopDeterministicDeadCodeWorkType | The DETERMINISTIC, PROVIDER-LESS dead-code work-type — the full mill→author→cert chain for one file with | Engineering Kernel | app/Console/Commands/AtlasLoopDeadCodeSweepCommand.php |
| AtlasLoopDeterministicWorkType | A DETERMINISTIC, provider-LESS loop work-type: it MILLS→AUTHORS→CERTIFIES a real, value-bearing change to | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopDeterministicDeadCodeWorkType.php |
| AtlasLoopDriftRestartDebounce | LOOP-OS · FASE 5 · SLICE 13 — drift-restart re-enable, EXTERNALLY triggered + DEBOUNCED. | Governor | app/Console/Commands/AtlasLoopKeepaliveCommand.php |
| AtlasLoopEarnedAutonomyDecisionTrace | ACDE lever S2 (observe-only slice) — a PROVIDER-SAFE, OBSERVE-ONLY trace of the EarnedAutonomy gate's | Governor | app/Services/Ai/Foundry/Rsi/RsiSelfImprovementProposalGate.php |
| AtlasLoopEscalationLadder | NEXT-LEVER 2 — the ESCALATION LADDER ("runs as many rounds as needed to certify extreme quality"). | Governor | app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php |
| AtlasLoopExplorerStrategyBanditService | L6-3: UCB portfolio over explorer strategy hints. | Spec Court | app/Console/Commands/AtlasLoopExplorerStrategyBanditCommand.php |
| AtlasLoopFeatureCompletenessResolver | ACDE lever F2 — FEATURE COMPLETENESS CHECKLIST resolver. | Spec Court | app/Services/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactory.php |
| AtlasLoopFeatureSequencePlanner | ACDE lever F1 — the in-lane SEQUENCED-FEATURE planner. | Spec Court | app/Console/Commands/AtlasLoopFeatureSequencePlanCommand.php |
| AtlasLoopFeatureSequenceWalker | ACDE F4 — close the verified orphan: the EXECUTABLE walk of {@see AtlasLoopFeatureSequencePlanner}. | Spec Court | app/Services/Ai/AutonomousEvolution/AtlasLoopDeliveryDossierService.php |
| AtlasLoopFleetGovernor | §4 · FLEET GOVERNOR — a GLOBAL cap on in-flight grinds ACROSS every campaign, so the loop never thrashes the | Governor | app/Console/Commands/AtlasLoopKeepaliveCommand.php |
| AtlasLoopFleetSizeAutotuner | Autotunes fleet size from a pressure sampler snapshot | Governor | app/Console/Commands/AtlasLoopKeepaliveCommand.php |
| AtlasLoopFormalInvariantGateService | L6-8 formal-light invariant gate for the sensitive Loop/kernel floor. | Verification Court | app/Console/Commands/AtlasLoopFormalInvariantGateCommand.php |
| AtlasLoopFrontierGapModel | Fact-only frontier gap detector: it never scores ambition, it only connects already-persisted evidence ids. | Spec Court | app/Console/Commands/AtlasLoopAmbitionFacultyCommand.php |
| AtlasLoopFrozenTestContentBuilder | Frozen-test codegen collaborator extracted from {@see AtlasLoopIntentVerifierFactory}: pure | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactory.php |
| AtlasLoopFrozenTestSourceRenderer | Stateless frozen-test source renderer extracted from {@see AtlasLoopIntentVerifierFactory}: pure | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactory.php |
| AtlasLoopFunnelService | L3-1 · Funil instrumentado do Loop. | Maestro/Task Fabric | app/Console/Commands/AtlasLoopObservabilityDashboardCommand.php |
| AtlasLoopGateWorkspaceProvisioner | Stateless collaborator extracted from AtlasLoopTaskGrinder: builds and cleans | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php |
| AtlasLoopGoodhartReceiptLedger | Append-only persistent ledger for AtlasLoopAntiGoodhartUnifiedRefusal verdicts. Every verdict | Verification Court | tests/Unit/Ai/AutonomousEvolution/AtlasLoopGoodhartReceiptLedgerTest.php |
| AtlasLoopGroundedProjectionRoles | §3 · ARCHITECT PHASE — the design↔critique critic made GROUNDED (the phase that guarantees quality). | Spec Court | app/Services/Ai/AutonomousEvolution/AtlasLoopWorkTypeContract.php |
| AtlasLoopHardCaseAutoDiscovery | HARD-CASE AUTO-DISCOVERY — mines existing ledger rows (AtlasLoopAttemptLedger + the | Spec Court | app/Console/Commands/AtlasLoopHardCaseDiscoverCommand.php |
| AtlasLoopHardCaseHarness | P5 mitigation: deterministic hard-case deck from historical loop failures. | Verification Court | app/Providers/AppServiceProvider.php |
| AtlasLoopHarnessGuard | L3-12 · Guardrail do meta-loop (o Loop melhora o PRÓPRIO harness — com freio). | Policy Plane | app/Console/Commands/AtlasLoopV4SelfArchitectureSentinelCommand.php |
| AtlasLoopHeldOutDeltaCertifier | The HELD-OUT delta certifier — the anti-gaming moat of the optimize lane. | Verification Court | app/Services/Ai/AutonomousEvolution/LiveCycle/AtlasLoopCertifyPhaseRunner.php |
| AtlasLoopHermeticCommandEnvironment | Load-bearing environment for Loop acceptance/verifier subprocesses. | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopRedReasonGate.php |
| AtlasLoopIdeaDraftingRubric | ARBOR-GRAFT DD1 (text) — the engineering-translated idea_drafting rubric, injected at the generation | Spec Court | app/Services/Ai/AutonomousEvolution/AtlasEvolutionTaskGenerator.php |
| AtlasLoopImpactReceiptService | Builds an impact receipt for a proposal (changed files, canary, callers) | Learning-Application Controller | app/Console/Commands/AtlasFableDeltaSeriesCommand.php |
| AtlasLoopIntelligenceOverlay | Read-only compounding layer for the unified loop backlog. | Governor | app/Console/Commands/AtlasLoopReviewFeedbackCommand.php |
| AtlasLoopIntentVerifierFactory | Compiles a narrow human intent into a frozen executable verifier packet. | Verification Court | app/Console/Commands/AtlasLoopCompileVerifierCommand.php |
| AtlasLoopIterateToGreenExecutor | ADEP keystone — the foundational primitive the loop's execution lane LACKED. | Engineering Kernel | app/Services/Ai/AutonomousEvolution/WorkspaceProviderLoopExecutionDriver.php |
| AtlasLoopIterateToMetricOptimizer | The ARBOR ITERATE-TO-METRIC OPTIMIZER — the inner search of the evolution loop, | Engineering Kernel | app/Console/Commands/AtlasLoopArborBenchProveCommand.php |
| AtlasLoopJObjective | ARBOR-GRAFT J1 — the J objective as an explicit, READABLE artifact (scalar + gate fingerprint). | Governor | app/Services/Ai/AutonomousEvolution/AtlasLoopResearchContract.php |
| AtlasLoopJudgeConsensusGate | NEXT-LEVER 3 — INDEPENDENT MULTI-JUDGE CONSENSUS (the structural edge over a single agent). | Verification Court | app/Console/Commands/AtlasLoopJudgeConsensusCommand.php |
| AtlasLoopJudgeDisagreementDiagnostic | JUDGE-DISAGREEMENT DIAGNOSTIC — classifies WHY two or more judges disagreed on the SAME delivery, operating | Verification Court | app/Services/Ai/AutonomousEvolution/AtlasLoopHardCaseAutoDiscovery.php |
| AtlasLoopJudgeEffortEscalator | Pure, injectable judge-effort escalator. Given a verdict (`{decision, confidence, opposing_confidence, | Verification Court | app/Services/Ai/AutonomousEvolution/AtlasLoopJudgeSelfCalibrationService.php |
| AtlasLoopJudgeSelfCalibrationService | L6-2: turns historical RED-canary fix-forward cases into stricter verifier candidates. | Verification Court | app/Console/Commands/AtlasLoopJudgeSelfCalibrationCommand.php |
| AtlasLoopLeapDecompositionSeeder | Seeds task packets from an ambition leap + risk verdict, guarded | Spec Court | app/Console/Commands/AtlasLoopAmbitionFacultyCommand.php |
| AtlasLoopLeapReceiptLedger | Records leap receipts and their outcome against the attempt ledger | Learning-Application Controller | app/Console/Commands/AtlasLoopAmbitionFacultyCommand.php |
| AtlasLoopLeapRiskAuditor | Audits leap risk against closure terms (auditor/certifier/judge) | Policy Plane | app/Console/Commands/AtlasLoopAmbitionFacultyCommand.php |
| AtlasLoopLearningAppendService | APRENDER (phase 8 — the closing phase of the canonical 8-phase live cycle). It appends ONE raw FACT row per | Learning-Application Controller | app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainReflectionStream.php |
| AtlasLoopLeverImpactMeter | ROADMAP #4 — the measurement instrument that turns every lever flip from FAITH into a measured decision. | Learning-Application Controller | app/Services/Ai/AutonomousEvolution/AtlasLoopSoakReportService.php |
| AtlasLoopLeverageSelector | §2/§3 · LEVERAGE SELECTION — "o cérebro escolhe o MAIOR passo, por valor real, não proxy". | Spec Court | app/Console/Commands/AtlasLoopLeverageSelectCommand.php |
| AtlasLoopLossObserverService | L4-4 · daily loss observer for the autonomous Loop. | Learning-Application Controller | app/Console/Commands/AtlasLoopLossObserverCommand.php |
| AtlasLoopMasterSwitch | §0 · THE MASTER ON/OFF SWITCH — the single global gate that decides whether ANY part of the loop is allowed | Governor | routes/console.php |
| AtlasLoopMetaHarnessAbLiftService | L6-1: A/B lift measurement for meta-harness work. | Verification Court | app/Console/Commands/AtlasLoopMetaHarnessAbLiftCommand.php |
| AtlasLoopMetricHarness | UNIT 2.2 — the dev/test held-out METRIC HARNESS (the Arbor `eval.php` scalar harness, ported). | Verification Court | app/Console/Commands/AtlasLoopArborBenchProveCommand.php |
| AtlasLoopModelFloorReceiptLedger | MODEL-FLOOR RECEIPT LEDGER — the audit substrate that PROVES the §0 floor invariants held across each | Verification Court | app/Providers/AppServiceProvider.php |
| AtlasLoopModelProjectionCritic | §3 · ARCHITECT PHASE — the CROSS-MODEL critique seam (the canon's "projeção frontier + crítica cross-model | Verification Court | app/Services/Ai/AutonomousEvolution/AtlasLoopProjectionWorker.php |
| AtlasLoopMorningDigestService | L4-6 · Morning digest read-model for the last autonomous Loop window. | Governor | app/Console/Commands/AtlasLoopKeepaliveCommand.php |
| AtlasLoopMultiRepoMergeAuthority | L5-9 · A PORTA GOVERNADA POR-REPO (o Atlas trabalha nos SEUS projetos). | Engineering Kernel | app/Console/Commands/AtlasLoopMergeAuthPreviewCommand.php |
| AtlasLoopMultiSiteWiringPlanner | MULTI-SITE WIRING PLANNER — reads the {@see AtlasLoopCrossLeverageRegistry} manifest and, for every | Maestro/Task Fabric | app/Console/Commands/AtlasAaelCommand.php |
| AtlasLoopMutationAdequacyGateService | L6-5 mutation adequacy gate. | Verification Court | app/Providers/AppServiceProvider.php |
| AtlasLoopMutationOperators | Single source of truth for the loop's source-level mutation operators. Extracted verbatim from | Verification Court | app/Services/Ai/AutonomousEvolution/AtlasLoopCharacterizationTestVerifier.php |
| AtlasLoopNetDiffCertCollector | Deterministic collector for the cert net-diff residuals called out by | Verification Court | app/Console/Commands/AtlasLoopNetDiffCertCommand.php |
| AtlasLoopNetDiffCertJudge | NET-DIFF CERT JUDGE — the deterministic, provider-free judge that turns one frozen | Verification Court | app/Console/Commands/AtlasLoopNetDiffCertCommand.php |
| AtlasLoopNetDiffCertReceiptLedger | APPEND-ONLY ledger for net-diff cert verdicts (produced by AtlasLoopNetDiffCertJudge). One receipt per | Verification Court | app/Console/Commands/AtlasLoopNetDiffCertCommand.php |
| AtlasLoopNetDirectionGuard | L2-6 — Guard de saldo líquido (política merge-livre v2 do operador): merges continuam | Policy Plane | routes/console.php |
| AtlasLoopObjectiveDivergence | ACDE U3 — sample-N objective DIVERGENCE (Jaccard). When U1 draws K independent readings of a file, the | Policy Plane | app/Services/Ai/AutonomousEvolution/AtlasEvolutionTaskGenerator.php |
| AtlasLoopObraAutoMergeService | OBRA-AUTO-MERGE (Phase 2, the consequential one) — take a GENUINELY CERTIFIED obra branch | Governor | app/Providers/AppServiceProvider.php |
| AtlasLoopObraBridgeService | L5-2: governed Loop -> Obra bridge preflight. | Governor | app/Console/Commands/AtlasLoopObraBridgeCommand.php |
| AtlasLoopObraCostEstimator | ACDE lever M4 — the budget scheduler's missing COST SOURCE (de-orphan the economics brain). | Governor | app/Console/Commands/AtlasLoopObraCostEstimateCommand.php |
| AtlasLoopObraExecutionAdapter | #7 — the MISSING wire that makes the loop EXECUTE a coordinated multi-file refactor (not just park). | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php |
| AtlasLoopObservabilityDigest | LOOP-OS · FASE 5 · §K — observability over the async delivery pipeline (Slice 8 state), for the morning | Governor | app/Console/Commands/AtlasLoopCampaignStatusCommand.php |
| AtlasLoopOperatorReviewMobilePublisher | L5-12 · The parked review queue becomes a human interface. | Governor | app/Console/Commands/AtlasLoopOperatorReviewCommand.php |
| AtlasLoopOperatorReviewQueueService | L4-7 · Operator review queue for parked Loop proposals. | Governor | app/Console/Commands/AtlasLoopOperatorReviewCommand.php |
| AtlasLoopOriginationDeliveryBridge | THE DELIVERY BRIDGE — turns a loop-originated WIRING CLAIM into a grindable RED verifier packet, gated by | Maestro/Task Fabric | app/Providers/AppServiceProvider.php |
| AtlasLoopOriginationOutcomeRecorder | ACDE lever O2 — records the operator's accept/reject on O1 origination proposals and turns the history into | Learning-Application Controller | app/Console/Commands/AtlasLoopOriginationReviewCommand.php |
| AtlasLoopOriginationPipeline | §5.6 · LAYER 2 — the full "decide by ORIGINATING, then DESIGN" flow. | Maestro/Task Fabric | app/Console/Commands/AtlasBrainQueuedTargetsCommand.php |
| AtlasLoopOriginationProducer | ACDE lever O1 — the in-lane ORIGINATION producer (spec-only, propose-only). | Maestro/Task Fabric | app/Console/Commands/AtlasLoopOriginateCommand.php |
| AtlasLoopOrphanWiringAuthoringEngine | §5.6 · ORPHAN-WIRING execution — the §9 AUTHORING engine (the model-bound boundary, made explicit + testable). | Maestro/Task Fabric | app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php |
| AtlasLoopOrphanWiringExecutionAdapter | §5.6 · ORPHAN-WIRING · the end-to-end executor that turns a comprehension-originated dead capability into a | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopOrphanWiringRouteHandler.php |
| AtlasLoopOrphanWiringRouteHandler | §5.6 · ORPHAN-WIRING execution — the testable CORE of the grinder route (kept out of the final grinder so it | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php |
| AtlasLoopPerpetualAdversarialSweepService | L5-13: fortnightly adversarial sweep for Loop safety findings. | Verification Court | app/Console/Commands/AtlasLoopPerpetualAdversarialSweepCommand.php |
| AtlasLoopProjectionEngine | LOOP-OS · FASE 2 · SLICE 9 — the PROJECTION engine: designer ↔ critic to a CONTENT-FIXPOINT over typed | Policy Plane | app/Services/Ai/AutonomousEvolution/AtlasLoopWorkTypeContract.php |
| AtlasLoopProjectionMemoryRecall | Recalls memory (decision/learning/technical_context) for projection input | Learning-Application Controller | app/Services/Ai/AutonomousEvolution/AtlasLoopProjectionWorker.php |
| AtlasLoopProjectionObligationContracts | §3 · ARCHITECT PHASE — close "selo sem veto": make the projected design contract ENFORCEABLE. | Policy Plane | app/Services/Ai/AutonomousEvolution/AtlasLoopArchitectPhaseGate.php |
| AtlasLoopProjectionOutcomeLedger | §5 · LEARNING — the architect phase records each projection OUTCOME so the next cycle is smarter. | Learning-Application Controller | app/Services/Ai/SelfConstruction/Maestro/ProviderLearning/AtlasMaestroProviderPerformanceLedger.php |
| AtlasLoopProjectionWorker | LOOP-OS · FASE 2 · S2 — the PROJECTION worker: the async drainer that turns a DISPATCHED projection row | Policy Plane | app/Services/Ai/AutonomousEvolution/AtlasLoopGroundedProjectionRoles.php |
| AtlasLoopProposalDiffReconstructor | Reconstructs the loop candidate content from the proposal diff format. | Engineering Kernel | app/Providers/AppServiceProvider.php |
| AtlasLoopProposalMaterializer | Materializes a certified loop proposal's diff into an ISOLATED workspace so a | Engineering Kernel | app/Console/Commands/AtlasLoopMaterializeCommand.php |
| AtlasLoopProposalOutOfProcessVerifier | Independent verifier for persisted unified-loop proposals. | Verification Court | app/Providers/AppServiceProvider.php |
| AtlasLoopProposalPromotionGate | The governed merge-promotion gate — the professional way to cross (or not) the | Governor | app/Console/Commands/AtlasLoopPromoteCommand.php |
| AtlasLoopProposalReverser | G5 (materialização) — a alça de REVERSE do loop: todo proposal certificado passa | Engineering Kernel | app/Console/Commands/AtlasLoopReverseCommand.php |
| AtlasLoopProviderCircuitBreaker | §4 · PROVIDER CIRCUIT-BREAKER — the operational-ring guard that makes an unattended soak SAFE: when the | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopProviderHealthProbe.php |
| AtlasLoopProviderContextOptimizer | Deterministic pre-prompt context shaper for loop provider calls. | Engineering Kernel | app/Providers/AppServiceProvider.php |
| AtlasLoopProviderEditApplier | The missing primitive that makes a TEXT/HTTP provider (e.g. MiniMax M3) agentic inside | Engineering Kernel | app/Services/Ai/AutonomousEvolution/WorkspaceProviderLoopExecutionDriver.php |
| AtlasLoopProviderEffortPolicy | Resolves provider effort tier from task input facts | Engineering Kernel | app/Providers/AppServiceProvider.php |
| AtlasLoopProviderEffortPolicyDriverDecorator | Decorates any {@see LoopExecutionDriver} with the provider effort policy. It | Engineering Kernel | app/Providers/AppServiceProvider.php |
| AtlasLoopProviderHealthProbe | §W40 · PROVIDER-HEALTH PROBE — the EYES of substrate sovereignty. Every grind that reaches a provider | Engineering Kernel | app/Console/Commands/AtlasLoopProviderHealthCommand.php |
| AtlasLoopProviderRollbackPolicy | Provider rollback policy for the loop routing layer. | Engineering Kernel | app/Console/Commands/AtlasLoopProviderRollbackCommand.php |
| AtlasLoopProviderRouter | Lever 5 — provider routing ("use MiniMax when you should, codex as you should"). | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php |
| AtlasLoopProviderSwapPolicy | §W40 · PROVIDER-SWAP POLICY — the reversible failover brain of substrate sovereignty. It reads the | Engineering Kernel | app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php |
| AtlasLoopQualityGrader | ADEP layer — the ≥9 QUALITY BAR (operator directive: "tudo numa nota de no mínimo 9"). | Verification Court | app/Services/Ai/AutonomousEvolution/AtlasLoopSemanticImplementationCertifier.php |
| AtlasLoopRealWorkScorecardService | REAL-WORK CAMPAIGN SCORECARD · C0 — the honest ruler. | Learning-Application Controller | app/Console/Commands/AtlasLoopEvolutionReportCommand.php |
| AtlasLoopRecursiveSelfImprovementGate | §4 · RECURSIVE SELF-IMPROVEMENT — BUILT but GATED (the exponential source AND the max-Goodhart surface). | Policy Plane | app/Console/Commands/AtlasLoopRecursiveSelfImprovementGateCommand.php |
| AtlasLoopRedReasonGate | ACDE lever U2 — the red-REASON discriminator. | Policy Plane | app/Services/Ai/AutonomousEvolution/AtlasEvolutionTaskGenerator.php |
| AtlasLoopRefactorObraL410ProofService | L4-10 REAL-EXECUTION proof for a LOOP MULTI-FILE REFACTOR obra — the honest gate the obra bridge | Verification Court | app/Services/Ai/AutonomousEvolution/AtlasLoopObraExecutionAdapter.php |
| AtlasLoopRefusalCriticPanel | 3-voter adversarial panel that re-examines a candidate task BEFORE the unified refusal verdict. | Policy Plane | app/Providers/AppServiceProvider.php |
| AtlasLoopRegressionSentinel | ABSURD-LEAP 4 — the REGRESSION IMMUNE SYSTEM (trust to ship unsupervised). | Verification Court | app/Services/Ai/AutonomousEvolution/AtlasLoopRegressionWatcher.php |
| AtlasLoopRegressionWatcher | L6 — the post-merge ANTI-REGRESSION NET (the production wire on top of {@see AtlasLoopRegressionSentinel}). | Verification Court | app/Console/Commands/AtlasLoopMainHealthCommand.php |
| AtlasLoopRejectionDimensionRouter | ACDE lever X3 — structured rejection-dimension routing (the missing READ of a closed pair). | Policy Plane | app/Services/Ai/AutonomousEvolution/AtlasLoopAutonomousConductor.php |
| AtlasLoopResearchContract | ARBOR-GRAFT J3 — the Research Contract: Arbor's intake forces a goal into FIVE named components before a | Spec Court | app/Services/Ai/AutonomousEvolution/AtlasAaelLoopExecutionBridge.php |
| AtlasLoopResourceGate | CRITIC GUARD — disk-budget gate + orphan reaper for the cp -R scenario workspaces. | Governor | app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php |
| AtlasLoopScenarioProviderPortfolio | Lever 4 — the PROVIDER PORTFOLIO for cross-provider best-of-N. | Spec Court | app/Providers/AppServiceProvider.php |
| AtlasLoopSelfImprovementGroundingBridge | ACDE lever C1 — the self-improvement GROUNDING bridge (the dead builder's missing caller). | Learning-Application Controller | app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php |
| AtlasLoopSemanticImplementationCertifier | Final certificate for small semantic implementation proposals. | Verification Court | app/Console/Commands/AtlasLoopCertifyImplementationCommand.php |
| AtlasLoopSoakPlanService | THE SOAK PREFLIGHT — turns the operator's intent ("soak the loop on itself for N hours, $B budget") into a | Governor | app/Console/Commands/AtlasLoopKeepaliveCommand.php |
| AtlasLoopSoakReportService | THE SOAK INSTRUMENT — the provider-free answer to "is the loop evolving WELL, or just busy?" over a rolling | Governor | app/Console/Commands/AtlasLoopSoakReportCommand.php |
| AtlasLoopStandaloneWorkspaceMaterializer | §5.6 · ORPHAN-WIRING execution — the ISOLATION primitive that makes the grinder's orphan-wiring route safe | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopOrphanWiringRouteHandler.php |
| AtlasLoopSubstrateReceiptLedger | SUBSTRATE RECEIPT LEDGER — append-only journal that consolidates every substrate-sovereignty FACT into one | Learning-Application Controller | app/Console/Commands/AtlasLoopKeepaliveCommand.php |
| AtlasLoopTaskDecompositionAmplifier | P5 mitigation: deterministic alternative decompositions for the same task packet. | Maestro/Task Fabric | app/Providers/AppServiceProvider.php |
| AtlasLoopTaskGrinder | The shared grind core — the single path from a CLAIMED durable task to a persisted, | Maestro/Task Fabric | app/Console/Commands/AtlasLoopGrindTaskCommand.php |
| AtlasLoopTaxa2DialOverlayService | L5-5 TAXA²: raise-only, clamped Loop dial overlay. | Spec Court | app/Console/Commands/AtlasLoopTaxa2DialsCommand.php |
| AtlasLoopTerritoryLadder | SLICE C-territory-ladder — the ARMED, NOT-promoted territory ladder. | Governor | app/Console/Commands/AtlasLoopV2TerritoryPromotionCommand.php |
| AtlasLoopTransferGate | ARBOR-GRAFT J2 — the TRANSFER-SLICE holdout (the anti-Goodhart residual the dissection named). | Policy Plane | app/Services/Ai/AutonomousEvolution/AtlasEvolutionLoopRunner.php |
| AtlasLoopTransientDbException | Raised by {@see AtlasLoopDbResilience} when a DB op stayed in a TRANSIENT failure | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopDbResilience.php |
| AtlasLoopUnusedImportWorkType | A SECOND deterministic, PROVIDER-LESS work-type (same shape as the dead-code remover): certify + remove | Engineering Kernel | app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php |
| AtlasLoopUtilityGradeService | The HONEST Utility/Impact grade for the Evolution Loop — earned from committed | Learning-Application Controller | app/Console/Commands/AtlasLoopUtilityGradeCommand.php |
| AtlasLoopVerificationAtomNormalizer | VERIFICATION-ATOM NORMALIZER concern, extracted from the god-class | Verification Court | app/Services/Ai/Runtime/AiToolGitDiffSupport.php |
| AtlasLoopWeeklyAgendaProposalService | L5-1 · governed weekly agenda proposal for the Atlas Loop. | Spec Court | app/Console/Commands/AtlasLoopWeeklyAgendaCommand.php |
| AtlasLoopWiringIntentLedger | WIRING-INTENT LEDGER — the append-only, persistent history of every wiring-intent the Multi-Site Wiring | Maestro/Task Fabric | app/Services/Ai/AutonomousEvolution/AtlasLoopArmedCoverageReporter.php |
| AtlasLoopWiringMaterialGrader | THE WIRING-MATERIAL GRADER — the operator-authored external RULER for ONE debt class: | Learning-Application Controller | app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationDeliveryBridge.php |
| AtlasLoopWorkTypeContract | §2/§3 · WORK-TYPE CONTRACT — the loop's ≥5 deterministic work types made FIRST-CLASS, so the architect | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopGroundedProjectionRoles.php |
| AtlasLoopWorkspaceFloorAutotuner | WORKSPACE-FLOOR AUTOTUNER — emits an ADVISORY recommended workspace-disk floor (MB) from observed FACTS: | Governor | app/Console/Commands/AtlasLoopKeepaliveCommand.php |
| AtlasLoopWorkspaceMaterializer | Rebuilds a scoped, self-contained base workspace from a DURABLE task payload. | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php |
| AtlasLoopWorkspaceMaterializerSupport2 | Writes workspace support files (composer.json etc.) for materialization | Engineering Kernel | app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php |
| AtlasUnifiedLoopOrchestrator | THE UNIFIED LOOP — one durable, propose-only supervisor over every verifier-backed | Autonomos | app/Console/Commands/AtlasUnifiedLoopCommand.php |
| AtlasUnifiedLoopSupervisorService | Liveness supervisor for the file-based unified evolution loop. | Autonomos | app/Console/Commands/AtlasUnifiedLoopSupervisorCommand.php |
| FixtureRefactorObraNodeDelivery | DETERMINISTIC, ZERO-SPEND obra delivery for proving the multi-file execution MACHINERY | retire | tests/Feature/Loop/AtlasLoopObraExecutionAdapterTest.php |
| LoopAttemptTimedOut | Thrown by {@see TimeBoundedLoopExecutionDriver} when a single execution attempt | Engineering Kernel | app/Services/Ai/AutonomousEvolution/TimeBoundedLoopExecutionDriver.php |
| LoopExecutionDriver | The execution abstraction the evolution loop runs on. | Engineering Kernel | app/Providers/AppServiceProvider.php |
| SeniorLoopExecutionDriver | Default {@see LoopExecutionDriver} — routes the evolution loop through the | Engineering Kernel | app/Providers/AppServiceProvider.php |
| TimeBoundedLoopExecutionDriver | CRITIC GUARD — the per-attempt wall-clock kill that closes the unguarded hang. | Engineering Kernel | app/Providers/AppServiceProvider.php |
| WorkspaceProviderLoopExecutionDriver | The BREADTH driver — invokes the configured provider CLI DIRECTLY against the | Engineering Kernel | app/Providers/AppServiceProvider.php |

## Retirement Candidates

1. FixtureRefactorObraNodeDelivery

## Resumo
Inventario canonico de toda classe raiz de app/Services/Ai/AutonomousEvolution mapeada para um owner v3 ou marcada retire.

## Papel no Atlas
Fonte unica de verdade para a migracao Loop/ACDE -> governo v3; nenhuma classe legada fica sem dono ou sem decisao de retirada.

## Onde Se Encaixa
Filho do atlas-autonomous-engineering-government.md; consultado antes de reatribuir ou remover qualquer classe do Loop/ACDE.

## Contratos
Toda classe raiz de AutonomousEvolution precisa de exatamente uma linha na tabela, com um dos nove valores de V3 Owner permitidos e evidencia do chamador real ou "none".

## Fluxo
Implementador consulta esta tabela antes de mexer numa classe legada, confirma o owner v3 e a evidencia, e atualiza a linha quando a classe migra ou e retirada.

## Regras para IA
Nunca declarar uma classe migrada ou retirada sem evidencia verificavel de chamador (ou a ausencia dele); nunca remover uma linha sem que o arquivo correspondente deixe de existir.

## Escopo de Implementacao
Somente este documento de inventario; nenhuma mudanca em codigo de runtime.

## Dependencias
Depende do atlas-autonomous-engineering-government.md e do runtime-implementation-roadmap-waves.md para o contrato de fases.

## Evidencias
docs-health verde citando este doc; coluna Evidencia da tabela aponta o chamador de producao mais forte encontrado via grep, ou "none".

## Riscos
Tabela desatualizada em relacao ao codigo real; mitigado por docs-health e por reconciliacao periodica contra `ls` do diretorio raiz.

## Exemplos
Cada linha da tabela e um exemplo concreto de classe + owner v3 + evidencia.

## Proximas Acoes
Reatribuir owners conforme cada corte/plano/kernel v3 absorve de fato a capacidade correspondente.
