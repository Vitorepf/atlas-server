# Full-Pass DEBTS

## Done (session + prior)

- [x] MCP tools() catalog extract (`OpenBrainMcpToolCatalog`)
- [x] Many Support/* pure helpers created
- [x] CLI Concerns traits created + partial adoption
- [x] CodexReviewMerge later-cycle trait
- [x] Honesty renames BriefGrounding, SuiteRedTriage
- [x] Loop config operate-path honesty comments
- [x] **Rescan #2 multi-agente** → `scan/FINDINGS-RESCAN-2.md`

## Open — ranked residual (from RESCAN-2)

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
- [ ] AtlasAaeosCommand further thin (loadSignals/appendOptionalJsonObserve residual)
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
- [ ] Readiness PartN / HubDelegators peel collapse

### Architecture

- [ ] PipelineRunExecutor leave Http + test port (live DI already KernelRunExecutor; AAEOS R103 retain census — staged later)
- [x] config/atlas.php: loop → atlas_loop_legacy.php (~1704L extracted; atlas.php 5409→3707)
- [x] ProviderCatalog SSOT for invocation/auto-live/council (Gateway+Routes wired; config overrides optional)
- [x] ProviderCatalog adopt in AtlasDecideService invocation list
- [ ] ProviderCatalog adopt in Manager/Forge residual
- [x] AppServiceProvider: legacy AcosMax/Cognitive aliases → AtlasLegacyNamespaceAliasServiceProvider
- [x] AppServiceProvider ACOS watchdog → AtlasAcosWatchdogServiceProvider
- [x] AppServiceProvider Organism domain → AtlasOrganismServiceProvider
- [x] AppServiceProvider Vox DI → AtlasVoxServiceProvider
- [x] AppServiceProvider Swarm DI → AtlasSwarmServiceProvider (ASP 1067→856)
- [x] AppServiceProvider Patamar4 wiring → AtlasPatamar4ServiceProvider
- [x] AppServiceProvider Mission/self-construction/ADML → AtlasMissionServiceProvider (ASP →523)
- [ ] AppServiceProvider further residual (CCR/compression/sentinels/…)
- [x] routes/api.php: atlas-code group → routes/api/atlas-code.php
- [x] routes splits: atlas-code, atlas-cartography, stewardship, operator-intelligence
- [x] routes/api/patamar4.php split
- [x] routes/api control-plane + hermes-hooks splits
- [x] routes/api voice + mobile + vox splits (api.php 762→643)
- [x] routes/api arena + code-native + agent-governance + memory-vault (api.php →488; 74 dead imports pruned)
- [x] routes/api engineering (runs/tools/knowledge/benchmarks + task eng) (api.php →411)
- [x] routes/api projects-routines + semantic-and-lifestyle (api.php →293)
- [ ] routes/api.php remaining (ai interactions / providers / jobs / harness core)
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

### Floors (do not rush)

- RSI Session runCycle
- Evidence redesign
- Readiness probe hub as product redesign
- AtlasLoop* keep-list renames (alias only; never prefix-delete)
- TaskQueueOrchestrator rehome (HIGH muscle)
- file-by-file receipts 13399 paths
