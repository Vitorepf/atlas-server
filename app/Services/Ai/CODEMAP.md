# AI CODEMAP — initial navigation skeleton

<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->

This is intentionally incomplete. It is a small, verified starting point for
navigation during GOD-DEBULK, not a corpus-complete ownership map.

| Change concern | Concrete navigation target |
| --- | --- |
| Run AAEOS control cycle (intent→mode→admission→dispatch) | `App\Services\Ai\Aaeos\Control\AaeosCycleRuntime::runCycle` |
| Run zero-operator Autonomos AAEOS cycle | `App\Services\Ai\Aaeos\Control\AaeosCycleRuntime::runAutonomosCycle` |
| Run the AAEOS cycle from the CLI (`atlas:aaeos:cycle`) | `App\Console\Commands\AtlasAaeosCycleCommand::handle` |
| Certify AAEOS GOD/SOTA (`atlas:aaeos:certify`) | `App\Console\Commands\AtlasAaeosCertifyCommand::handle` |
| Project the AAEOS scorecard (`atlas:aaeos:scorecard`) | `App\Services\Ai\Aaeos\Control\AaeosScorecardProjector::project` |
| Select executor mode Dev\|Forge\|Autonomos | `App\Services\Ai\Aaeos\Control\AaeosModeSelector::select` |
| Admit cycle (auto/notify/halt_sovereign) | `App\Services\Ai\Aaeos\Control\AaeosAdmissionPolicy::admit` |
| Enforce shared N9+N11 spine on intake | `App\Services\Ai\Aaeos\Spine\AaeosSpineGate::stamp` |
| Shared engineering spine contract | `App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine::assertShared` |
| Project AAEOS org state (read-only) | `App\Services\Ai\Aaeos\Control\AaeosOrgStateProjector::project` |
| Record AAEOS learning candidate (no auto-promote) | `App\Services\Ai\Aaeos\Control\AaeosCycleOutcomeRecorder::record` |
| Map AAEOS mode → DualCore route | `App\Services\Ai\Aaeos\Control\AaeosModeToDualCoreRoute::map` |
| Source connector governance (Brain frontier) | `App\Services\Ai\AutonomousEvolution\Brain\AtlasSourceConnectorsAndCaptureService::classifySource` |
| Classify incoming AI intent before routing | `App\Services\Ai\Router\AtlasAiIntentKernelService::classify` |
| Route legacy keyword intents | `App\Services\Ai\Router\AiIntentRouter::route` |
| Persist a sequenced stream event | `App\Services\Ai\Streaming\AiStreamRecorder::record` |
| Load a canonical Atlas skill | `App\Services\Ai\Skills\AiSkillStore::load` |
| Evaluate post-run response quality | `App\Services\Ai\Analysis\AiQualityEvaluator::evaluateTrace` |
| Plan quality remediation | `App\Services\Ai\Analysis\AiQualityActionService::planFor` |
| Authorize a job's permission runtime | `App\Services\Ai\Governance\AiPermissionEngine::authorizeJob` |
| Resolve permission policy inputs | `App\Services\Ai\Governance\AiPermissionEngineSupport::resolve` |
| Serialize a permission decision | `App\Services\Ai\Governance\AiPermissionDecision::runtimePayload` |
| Sanitize a final human-facing response | `App\Services\Ai\Surface\AtlasFinalResponseSanitizer::sanitize` |
| Record a surface handoff | `App\Services\Ai\Surface\AiSurfaceHandoffService::record` |
| Project human execution state | `App\Services\Ai\HumanSurface\AiExecutionPresentationState::providerChoice` |
| Resolve or create an AI thread | `App\Services\Ai\ConversationOps\AiThreadResolver::resolve` |
| Ensure an active AI session | `App\Services\Ai\ConversationOps\AiSessionManager::ensureActive` |
| Update structured AI session state | `App\Services\Ai\ConversationOps\AiSessionStateService::updateForUserInput` |
| Purge a user-visible thread | `App\Services\Ai\ConversationOps\AiThreadDeletionService::delete` |
| Build a provider-safe context-feedback proposal | `App\Services\Ai\AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::advise` |
| Project safe trace artifacts for a trace | `App\Services\Ai\Instrumentation\AiTraceArtifactsProjection::forTrace` |
| Project an engineering review for a trace | `App\Services\Ai\Instrumentation\AiTraceEngineeringReviewProjection::forTrace` |
| Write an AI worker lifecycle event | `App\Services\Ai\Instrumentation\AiWorkerLogger::event` |
| Generate a provider-safe projection | `App\Services\Ai\Instrumentation\AtlasProviderProjectionService::generate` |
| Audit a provider projection apply | `App\Services\Ai\Instrumentation\AtlasProviderProjectionAuditService::recordApply` |
| Authorize provider-projection audit purge | `App\Services\Ai\Instrumentation\AtlasProviderProjectionAuditPurgePolicy::evaluate` |
| Ingest canonical YouTube knowledge | `App\Services\Ai\Knowledge\YouTubeKnowledgeIngestionService::ingestFromInput` |
| Project canonical YouTube knowledge | `App\Services\Ai\Knowledge\YoutubeCanonicalProjection::projectIngestion` |
| Aggregate a multi-provider council trace | `App\Services\Ai\Arena\AiCouncilCoordinator::sync` |
| Classify compaction loss before a write | `App\Services\Ai\Compaction\CompactionLossPolicy::classify` |
| Compose a provider-safe context pack | `App\Services\Ai\Context\AiContextPackBuilder::build` |
| Persist a provider input context snapshot | `App\Services\Ai\Context\AiContextSnapshotRecorder::record` |
| Build recent conversation context | `App\Services\Ai\Context\AiConversationContextBuilder::build` |
| Record a user or assistant conversation turn | `App\Services\Ai\Context\AiConversationRecorder::recordUserMessage` |
| Mark unresolved memory tensions in provider context | `App\Services\Ai\Context\AtlasDialecticTensionService::tensionMarks` |
| Carry the provider prompt contract | `App\Services\Ai\ValueObjects\AiPrompt::__construct` |
| Steer an active AI interaction safely | `App\Services\Ai\ControlPlane\AiInteractionSteeringService::steer` |
| Refresh an expired decision receipt before a provider call | `App\Services\Ai\AtlasDecide\AiDecisionReceiptRefreshService::refreshExpiredBeforeProviderCall` |
| Resolve the effective AI execution policy | `App\Services\Ai\Policy\AtlasAiPolicyService::effectiveProfile` |
| Compose an effective policy from runtime inputs | `App\Services\Ai\Policy\AtlasEffectivePolicyComposer::compose` |
| Update a domain-level policy override | `App\Services\Ai\Policy\AtlasDomainProfilePolicyService::updateDomain` |
| Resolve a declared domain profile | `App\Services\Ai\Policy\AtlasDomainProfileRegistry::resolve` |
| Read effective AI runtime settings | `App\Services\Ai\Policy\AtlasAiRuntimeSettings::effective` |
| Enforce the configured runtime budget | `App\Services\Ai\Policy\AiRuntimeBudgetService::assertAllows` |
| Propose a memory delta from workspace evidence | `App\Services\Ai\Memory\AiMemoryDeltaProposer::proposeForWorkspace` |
| Recall hybrid memory safely | `App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService::recall` |
| Compose ranked memory into provider context | `App\Services\Ai\Memory\AtlasMemoryContextComposer::compose` |
| Promote an accepted memory delta | `App\Services\Ai\Memory\AtlasMemoryDeltaPromotionService::promote` |
| Run learning-driven memory promotion | `App\Services\Ai\Memory\AtlasMemoryLearningPromotionService::run` |
| Run governed memory maintenance | `App\Services\Ai\Memory\AtlasMemoryMaintenanceService::run` |
| Produce a memory-quality scorecard | `App\Services\Ai\Memory\AtlasMemoryQualityService::scorecard` |
| Record a canonical memory entry | `App\Services\Ai\Memory\AtlasMemoryRegistryService::record` |
| List memory items awaiting review | `App\Services\Ai\Memory\AtlasMemoryReviewQueueService::queue` |
| Record memory usage for a recall | `App\Services\Ai\Memory\AtlasMemoryUsageService::recordRecallUsages` |
| Explain recall uncertainty | `App\Services\Ai\Memory\AtlasRecallUncertaintyMap::forRecall` |
| Record a governed verbatim memory | `App\Services\Ai\Memory\AtlasVerbatimMemoryService::record` |
| Scan a memory entry for governance relations | `App\Services\Ai\MemoryGovernance\AtlasMemoryGovernanceService::scan` |
| Decide whether memory is provider-safe | `App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService::providerAllowed` |
| Project source privacy for memory input | `App\Services\Ai\MemoryGovernance\AtlasMemorySourcePrivacyPolicy::project` |
| Compose memory-health dimensions | `App\Services\Ai\MemoryGovernance\MemoryHealthCompositePolicy::compose` |
| Classify memory-quality readiness | `App\Services\Ai\MemoryGovernance\MemoryQualityStatusPolicy::classify` |
