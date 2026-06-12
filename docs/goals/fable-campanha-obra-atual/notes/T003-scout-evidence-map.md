# T003 — Scout evidence map for O-1

## Commands run

Workspace: `/Users/vitorepf/develop/Atlas/atlas-server`

### Bootstrap

```bash
/opt/homebrew/bin/php artisan atlas:ai:session-bootstrap --task="O-1 Certification Sweep da espinha de engenharia + Marco Zero" --json
```

Result summary:

- command exit: `0`
- `status`: `ok`
- `task`: `O-1 Certification Sweep da espinha de engenharia + Marco Zero`
- `gate_status`: `blocked`
- `session_gate.status`: `blocked`
- block reason: `feature_placement_gate_blocked`
- blocked_when: `ambiguous_placement_requires_more_specific_feature_or_hint`
- required before code includes owner docs, duplicate candidates, documentation reality gate, code intelligence gate, ACRUI, cartography navigation slice, software twin/verified evolution gates as applicable.
- owner docs exist:
  - `docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md`
  - `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
  - `docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md`
  - `docs/engineering-knowledge-base/atlas-ai-master-architecture.md`
- code intelligence summary from bootstrap:
  - status: `ready`
  - module_count: `23`
  - symbol_count: `118099`
  - route_count: `601`
  - command_count: `964`
  - migration_count: `1447`
  - test_count: `23192`
  - last_indexed_at: `2026-06-11T19:15:55Z`

Interpretation: read-only Scout may continue; **Worker/code edits are blocked** until placement ambiguity is resolved or explicitly scoped.

### AOBG/Open Brain context

```bash
bin/atlas open-brain context "O-1 Certification Sweep da espinha de engenharia + Marco Zero" --json
```

Result summary:

- command exit: `0`
- JSON valid under top-level `open_brain`
- `schema_version`: `1`
- `summary.context_refs_count`: `13`
- `summary.memory_refs_count`: `8`
- `summary.recall_count`: `9`
- `summary.registry_count`: `8`
- `summary.semantic_count`: `5`
- `summary.provider_safe`: `true`

Interpretation: AOBG is available and provider-safe. It should be used as curated top-K context, not as sole source of truth.

### Marco Zero artifact check

```bash
test -f storage/app/atlas/evidence/marco-zero-fable-2026-06-11.json
python3 <provider-safe-json-summary>
```

Result summary:

- artifact exists: `yes`
- file size: `3207` bytes
- top-level keys: `schema_version`, `campaign`, `recorded_at`, `purpose`, `baseline`, `success_definition`
- metrics observed:
  - `baseline.learning_capture_quality_7d.ai_learning_proposals_rows`: `64`
  - `baseline.learning_capture_quality_7d.ai_learning_proposals_distinct_kept`: `7`
  - `baseline.learning_capture_quality_7d.ai_compounding_memories_noise_reason`: `meta_stub=58`
  - `baseline.learning_capture_quality_7d.total_waste`: `115`
  - `baseline.learning_capture_quality_7d.waste_rate`: `0.94`
  - `baseline.loop_campaign_running.status`: `running`
  - `baseline.loop_campaign_running.proposals_certified_for_review`: `72`
  - `baseline.provider_performance_168h.success_rate`: `0.9894`
  - `baseline.provider_performance_168h.unknown_cost_events`: `94`
  - `baseline.maturity_scorecard.acos_scorecard_state`: `self-declared (nao resolved-evidence) - alvo O-5`

Interpretation: Marco Zero artifact exists and matches the ledger directionally (`waste 94%`, `72` proposals, scorecard self-declared). Full Evidence Ledger event lookup still remains a possible proof step if O-1 closure requires it.

## Candidate evidence map by O-1 floor

### 1) Dev pipe real: provider manager + conductor + workspace-mutating providers

Primary candidate files:

- `app/Services/Ai/AiProviderManager.php`
- `app/Services/Ai/Programming/AtlasDev/WorkspaceMutatingProviders.php`
- `app/Http/Controllers/AtlasDev/Support/PipelineRunExecutor.php`
- `app/Services/Ai/Programming/AtlasDev/PromptProjection/ProviderPromptBuilder.php`
- `app/Providers/AppServiceProvider.php`

Conductor candidates:

- `app/Services/Ai/AtlasDecide/AtlasConductorResolverGuard.php`
- `app/Services/Ai/AtlasDecide/AtlasConductorRoutingMemory.php`
- `app/Services/Ai/AtlasDecide/AtlasSwarmConductorService.php`
- `app/Services/Ai/AtlasDecide/AtlasEngineeringRunConductorService.php`
- `app/Services/Ai/AtlasDecide/AtlasConductorTurnBudget.php`
- `app/Services/Ai/AtlasDecide/AtlasConductorPlanGate.php`

Initial test candidates from search output:

- `tests/Feature/Console/AtlasSwarmExecuteArmCommandTest.php`
- `tests/Feature/CodeGraph/WorkspaceProviderLoopCodeGraphSeamTest.php`

Need narrower follow-up search/read before allowed_files.

### 2) Forge gates

Primary candidate files:

- `app/Services/Ai/Programming/Forge/Intelligence/ForgeObraContextGateService.php`
- `app/Services/Ai/Programming/Forge/ForgeMilestoneGateRunner.php`
- `app/Services/Ai/Programming/Forge/Qa/ForgeQaGateRunner.php`
- `app/Services/Ai/Programming/Forge/Qa/ForgeSddSpecGate.php`
- `app/Console/Commands/AtlasCodeForgeReviewCommand.php`
- `app/Http/Controllers/AtlasCodeForgeReviewCompletionController.php`
- `app/Services/Ai/ProgrammingRuntime/AtlasProgrammingFinalCertificationService.php`

Initial test candidates:

- `tests/Feature/Ai/Programming/Forge/ForgeObraCertificationServiceTest.php`
- `tests/Feature/Ai/Programming/AtlasCodeForgeReviewCompletionTest.php`
- `tests/Feature/Ai/ProgrammingRuntime/AtlasProgrammingFinalCertificationServiceTest.php`
- `tests/Feature/Ai/Programming/DevForgeRobustFlowCertificationServiceTest.php`

Note: some existing file names contain vocabulary prohibited for new code/docs. Do not introduce new uses; treat existing paths only as evidence.

### 3) Loop stack and drivers

Primary candidate files:

- `app/Services/Ai/AutonomousEvolution/Persistence/AtlasLoopStore.php`
- `app/Services/Ai/AutonomousEvolution/AtlasEvolutionLoopRunner.php`
- `app/Services/Ai/AutonomousEvolution/AtlasAutonomousEvolutionLoopService.php`
- `app/Services/Ai/AutonomousEvolution/AtlasLoopTaskGrinder.php`
- `app/Services/Ai/AutonomousEvolution/AtlasLoopWorkspaceMaterializer.php`
- `app/Services/Ai/AutonomousEvolution/AtlasLoopIntentVerifierFactory.php`
- `app/Services/Ai/AutonomousEvolution/AtlasLoopSemanticImplementationCertifier.php`
- `app/Services/Ai/AutonomousEvolution/AtlasLoopProposalOutOfProcessVerifier.php`
- `app/Services/Ai/AutonomousEvolution/Campaign/AtlasLoopCampaignSupervisor.php`
- `app/Services/Ai/AutonomousEvolution/AtlasUnifiedLoopOrchestrator.php`
- `app/Services/Ai/AutonomousEvolution/AtlasUnifiedLoopSupervisorService.php`
- `app/Console/Commands/AtlasLoopGrindTaskCommand.php`
- `app/Console/Commands/AtlasLoopMaterializeCommand.php`
- `app/Console/Commands/AtlasLoopPromoteCommand.php`
- `app/Console/Commands/AtlasLoopVerifyProposalsCommand.php`
- `app/Console/Commands/AtlasLoopQualityScoreCommand.php`

Initial test candidates:

- `tests/Feature/Loop/AtlasLoopGrindTaskCommandTest.php`
- `tests/Feature/Loop/AtlasLoopCompileVerifierCommandTest.php`
- `tests/Feature/Loop/AtlasLoopCertifyImplementationCommandTest.php`
- `tests/Feature/Loop/AtlasLoopReviewFeedbackCommandTest.php`
- `tests/Feature/Loop/AtlasLoopCampaignSupervisorTest.php`
- `tests/Feature/Loop/AtlasLoopWorkerPoolTest.php`
- `tests/Feature/Loop/AtlasLoopGuardsTest.php`
- `tests/Unit/Loop/AtlasLoopDbResilienceTest.php`
- `tests/Unit/Ai/AutonomousEvolution/AtlasEvolutionLoopRunnerTest.php`
- `tests/Unit/Ai/AutonomousEvolution/AtlasLoopProposalMaterializerTest.php`
- `tests/Unit/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGateTest.php`
- `tests/Unit/Ai/AutonomousEvolution/AtlasLoopProposalReverserTest.php`
- `tests/Unit/Ai/AutonomousEvolution/AtlasLoopProposalOutOfProcessVerifierTest.php`

### 4) Compounding flywheel + capture quality gate

Evidence candidates:

- `AGENTS.md` mentions governed write-back via capture quality gate and `atlas_record_outcome` branch-only behavior.
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousLoopReceiptIntegrityService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPostCycleAuditorService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopQualityDriftDetectorService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPreflightCycleFirewallService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusLoopOperationalCertificationService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Loop24hCertificationHarnessService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopInvariantHarnessService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopProviderRoutingService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopResourceGovernorService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopMergeRetryQueueService.php`
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopCycleFailureTaxonomyService.php`

Initial test candidates:

- `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousLoopReceiptIntegrityServiceTest.php` if present; otherwise search exact name next.
- `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPostCycleAuditorServiceTest.php`
- `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPreflightCycleFirewallServiceTest.php`
- `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Loop24hCertificationHarnessServiceTest.php`
- `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopQualityDriftDetectorServiceTest.php`
- `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopMergeRetryQueueServiceTest.php`
- `tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopCycleFailureTaxonomyServiceTest.php`

## Recommended next board move

Do **not** activate Worker yet.

Create/activate a PM gate-resolution task to run a more specific placement for O-1 and read required owner docs. Then activate a narrower Scout/Judge pair or a first Worker only after:

1. placement no longer blocks the session, or the block is converted into a specific allowed owner scope;
2. first Worker has exact `allowed_files` and `verify` commands;
3. gate/measurement/imune risk is explicitly classified.

## Candidate first Worker package after gate resolution

Likely safest first Worker package is **Marco Zero integrity / O1-S1**, because it is mostly evidence/contract hardening and directly supports the DoD.

Potential objective:

> Freeze/repair a regression that proves the Marco Zero evidence artifact and ledger summary remain coherent enough for O-1/O-2 measurement.

But this is not yet approved because allowed files/tests require gate resolution and focused reads.
