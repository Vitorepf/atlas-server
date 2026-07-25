# Full-Pass DEBTS

## Done (session + prior)

- [x] MCP tools() catalog extract (`OpenBrainMcpToolCatalog`)
- [x] Many Support/* pure helpers created
- [x] CLI Concerns traits created + partial adoption
- [x] CodexReviewMerge later-cycle trait
- [x] Honesty renames BriefGrounding, SuiteRedTriage
- [x] Loop config operate-path honesty comments
- [x] **Rescan #2 multi-agente** → `scan/FINDINGS-RESCAN-2.md`
- [x] **Rescan #3 multi-agente** → `scan/FINDINGS-RESCAN-3.md`

## Open — ranked residual (RESCAN-2 + RESCAN-3)

### Density landed (RESCAN-3 wave)

- [x] OperatorComprehensionGateSupport elevate (tokens/privacy/signalKind)
- [x] OperatorLearningRuntimeCaptureSupport + ContextCompose + ProfileRegistry supports
- [x] SignalDetector → DetectSupport heuristics; Classify privacy/risk fuse
- [x] NamingPolicyRules pure string gates
- [x] UnattendedLivenessFactsNormalizer pure facts

### Wave A — cheap adoption (P0)

- [x] YesNo::format mass adoption (~309 sites / 138 files)
- [x] YesNo::trueFalse adoption (~154 sites / 78 files)
- [x] UtcIsoTimestamp::now() + mass gmdate('c') (~83 / 47 files)
- [x] EmitsCanonicalJson mass adoption on Command line(json_encode) (~705; residual complex remains)
- [x] Finish ReadsNonEmptyStringOption trait-equivalent (7 cmds)
- [x] SelfImprovement resolveJsonOption → ResolvesJsonOptionWithComponentsError (3 cmds)
- [x] Remaining stringOption variants → ReadsRawStringOption / Untrimmed / Literal traits (11 cmds)
- [x] Drop thin shells: memoryLimitToBytes, clamp01 (call sites), gitWorkspaceState wrappers
- [x] Empty Maestro ClosedLoop+Pinning dirs removed
- [x] ExternalBrain loadFacts → LoadsFactsFileOption (3 cmds)
- [x] NativeImplementation loadJson → LoadsNamedJsonOption trait

### Density / elevate

- [x] MobileArrayHelper scalar expansion + DiscussionBootstrapper/MobilePush adoption
- [x] OpenBrain StableHashSupport + TextNormalizeSupport thin wrappers
- [x] AiPromptTextSupport pure peel from AiPromptBuilder (AWIS/list/keywords helpers; builder 1677→1589)
- [x] YouTubeUrlSupport pure peel (videoId/canonical/ISO duration/timestamp; ingestion 1780→1715; 29 unit green)
- [x] YouTubeCaptionSupport + YouTubeMetadataSupport peels (ingestion 1715→1248; 39 unit/feature green)

- [x] EnterpriseReportDashboardHtml shell → Template class (host ~552L)
- [x] CertificationWorkbenchDelegators → map+__call (~1985→272L, 219 methods)
- [x] OpenBrainMcpToolCatalog::definitions → data file (catalog 25L + definitions 1089L)
- [x] EnterpriseFlowFixtureActionRuntimeService::run loadFlowFinds table (119 paths; golden 2 tests / 240 asserts)
- [x] AiWorker::completeAttempt stage peel (orchestrator + WhenCancelled/Succeeded/Failed + persistOutcome; baseline suite pre-existing 1 fail unrelated)
- [x] AtlasTaskServingService::report intake validateReportIntake peel (8 unit tests green)
- [x] AtlasTaskServingService::reportSuccessWithCommit peel (8 unit green; evidence contract suite pre-existing fails unrelated)
- [x] AtlasTaskServingService::reportSuccessDryRun peel
- [x] AtlasTaskServingService::reportGiveBackOrFailure peel (report() ~23L orchestrator)
- [x] AtlasAaeosCommand::universalGatesObserveProjectors table peel (~808→~56L method + dense table)
- [x] AaeosUniversalGatesObserveProjectors catalog class (command 1851→1094; projector unit 2 tests / 4545 asserts)
- [x] AtlasAaeosCommand further thin → AaeosUniversalGatesJsonObserveSupport (loadSignals/loadJson/appendObserve)
- [x] AtlasLedgerReplayService SLO summary/review → LedgerReplaySupport (22 unit tests)
- [x] AtlasLedgerReplayService SLO + repair summary/review → LedgerReplaySupport (22 unit green)
- [x] AtlasLedgerReplayService agent/decision/inbox summary+review → Support (careful multi-arg; 22 unit green)
- [x] AtlasLedgerReplayService kernel pipeline summary/health/review → Support (22 unit green)
- [x] AtlasLedgerReplayService selfImprovement schedule summary/review → Support
- [x] AtlasDecideService pure helpers → DecideProviderNormalization (confidence/quality/mode/research/task-type)
- [x] AtlasDecideService operationalDecision stages (resolveSelection/buildExplanation/assemble; 39 unit green)
- [x] AtlasDecideService executionGraph strategy peels (scout/council/single; 39 unit green)
- [x] OneShotTickInvokerEnvelope deny-flag fusion (~29 invokers) + OneShotTickInvokerCatalog (62 classes)
- [ ] OneShotTick invoker further normalize/factory fusion residual
- [x] Readiness HubDelegators compact forwarders (misc merge 3→1; batch/poststart/review/etc. ~8046→6044; method names preserved)
- [ ] Readiness remaining Part forests / true map+__call where no method_exists dependency

### Architecture

- [ ] PipelineRunExecutor leave Http + test port (live DI already KernelRunExecutor; AAEOS R103 retain census — staged later)
- [x] config/atlas.php: loop → atlas_loop_legacy.php (~1704L extracted; atlas.php 5409→3707)
- [x] ProviderCatalog SSOT for invocation/auto-live/council (Gateway+Routes wired; config overrides optional)
- [x] ProviderCatalog adopt in AtlasDecideService invocation list
- [x] ProviderCatalog adopt in Manager/Forge residual (AiChatModelSection, AtlasCliDevCommand, AiProviderController, AiChatCommand council)
- [x] ProviderCatalog validation `in:` SSOT (AiDecision/AiThread/MobileThread/StoreAiInteractionRequest)
- [x] ProviderCatalog residual AiChatRepl image path (inventory + honest minimax exclude)
- [x] AppServiceProvider: legacy AcosMax/Cognitive aliases → AtlasLegacyNamespaceAliasServiceProvider
- [x] AppServiceProvider ACOS watchdog → AtlasAcosWatchdogServiceProvider
- [x] AppServiceProvider Organism domain → AtlasOrganismServiceProvider
- [x] AppServiceProvider Vox DI → AtlasVoxServiceProvider
- [x] AppServiceProvider Swarm DI → AtlasSwarmServiceProvider (ASP 1067→856)
- [x] AppServiceProvider Patamar4 wiring → AtlasPatamar4ServiceProvider
- [x] AppServiceProvider Mission/self-construction/ADML → AtlasMissionServiceProvider (ASP →523)
- [x] AppServiceProvider CCR/compression → AtlasCompressionServiceProvider
- [x] AppServiceProvider cross-domain graph → AtlasCrossDomainGraphServiceProvider
- [x] AppServiceProvider AiProviderManager wiring → AtlasProviderManagerWiringServiceProvider (ASP →375)
- [x] AppServiceProvider Maestro priority + AAEL → AtlasMaestroPriorityServiceProvider
- [x] AppServiceProvider stewardship/forge authority binds → AtlasStewardshipBindingsServiceProvider (ASP →273)
- [x] AppServiceProvider memory/context → AtlasMemoryInfrastructureServiceProvider
- [x] AppServiceProvider runtime seams (serving/Hermes/fleet/maestro tier) → AtlasRuntimeSeamsServiceProvider
- [x] AppServiceProvider Obra/Dev runtime → AtlasObraServiceProvider (ASP →73 boot+sentinels shell)
- [x] AppServiceProvider residual honesty note for empty wave-19 ON path
- [ ] AppServiceProvider boot() residual only (observer/commands/harness overlay)
- [x] routes/api.php: atlas-code group → routes/api/atlas-code.php
- [x] routes splits: atlas-code, atlas-cartography, stewardship, operator-intelligence
- [x] routes/api/patamar4.php split
- [x] routes/api control-plane + hermes-hooks splits
- [x] routes/api voice + mobile + vox splits (api.php 762→643)
- [x] routes/api arena + code-native + agent-governance + memory-vault (api.php →488; 74 dead imports pruned)
- [x] routes/api engineering (runs/tools/knowledge/benchmarks + task eng) (api.php →411)
- [x] routes/api projects-routines + semantic-and-lifestyle (api.php →293)
- [x] routes/api ai-platform + ai-runtime (api.php 762→101 thin shell + requires)
- [x] routes/api inbox-tasks (api.php →~90 shell; route inventory 1388)
- [x] ProviderCatalog adopt in AiDecisionController + AtlasAiDecideCommand + PlansGatewayDecision
- [ ] Services→Controllers inversion (Forge cert/fast-path imports)
- [ ] Residual Forge homes fuse/kill zero-ref

### Honesty / naming

- [ ] AutonomousEvolutionSession* → AreaFocus/Stewardship session names
- [ ] Aaeos* types inside AEOS → Aeos*
- [ ] Dual SC CLI namespace collapse (ai:self-construction shells vs real)
- [ ] DeprecatedAliases cycle + aeos/aaeos class alignment
- [ ] MiscProjections* rename by domain
- [ ] Fable/docs loop speech retarget (CLI atlas:loop already gone)
- [ ] AtlasAutonomosMasterSwitch alias (class advertised, verify/ship)

### Residual monstruos (RESCAN-3 rank)

- [x] ActivationCockpitPresentationSupport
- [x] SelfImprovementScheduleMath
- [x] GraphPathFilterSupport
- [x] data_getYesNo mass compile bug
- [x] CodeAttentionClassifier
- [x] TaskFabricTemplateFarmSignalSupport
- [x] CommitGovernancePureMappers
- [x] ForgeProviderFallbackPolicySupport
- [x] OperatorProfilePolicyCompilerSupport

- [x] RivalsOneShot dimension credit Support
- [x] ForgeProviderCapacityClassifier pure FSM
- [x] ForgeFastPath lifecycle Support
- [x] ForgeFastPath report sanitizer residual
- [x] ProjectStackLearnSupport
- [x] WorkspaceOutcomeCommandMemory scoring Support
- [x] OpenBrain FileContext ceiling/path pure
- [x] OpenBrain ContextExpansion provider-safe render
- [ ] FileContext AURG target assertion residual (1 test)
- [x] ClosedLoop stage / ResultLedger grade
- [x] SkillScaffold frontmatter/body residual
- [x] AiWorker predicates/time residual
- [x] HubDelegators same-name one-line compact (method_exists preserved; __call reserved for workbench)
- [ ] PRE leave-Http (R103 staged)
- [x] Suite filter fatal: EliteExecutorKernel mock eventById/latestForCorrelation tenant signatures

### Floors (do not rush)

- RSI Session runCycle
- Evidence redesign
- Readiness probe hub as product redesign
- AtlasLoop* keep-list renames (alias only; never prefix-delete)
- TaskQueueOrchestrator rehome (HIGH muscle)
- file-by-file receipts 13399 paths
