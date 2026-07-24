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
- [ ] Remaining behavior-sensitive stringOption (~11: no-trim / keep-untrimmed variants)
- [x] Drop thin shells: memoryLimitToBytes, clamp01 (call sites), gitWorkspaceState wrappers
- [x] Empty Maestro ClosedLoop+Pinning dirs removed
- [x] ExternalBrain loadFacts → LoadsFactsFileOption (3 cmds)
- [ ] NativeImplementation loadJson trait variant

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
- [ ] AtlasAaeosCommand further thin (loadSignals/service extract residual)
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

- [ ] PipelineRunExecutor leave Http + test port (live DI already KernelRunExecutor)
- [x] config/atlas.php: loop → atlas_loop_legacy.php (~1704L extracted; atlas.php 5409→3707)
- [x] ProviderCatalog SSOT for invocation/auto-live/council (Gateway+Routes wired; config overrides optional)
- [x] ProviderCatalog adopt in AtlasDecideService invocation list
- [ ] ProviderCatalog adopt in Manager/Forge residual
- [x] AppServiceProvider: legacy AcosMax/Cognitive aliases → AtlasLegacyNamespaceAliasServiceProvider
- [ ] AppServiceProvider further domain peels (Organism/Vox/Swarm/…)
- [x] routes/api.php: atlas-code group → routes/api/atlas-code.php
- [x] routes splits: atlas-code, atlas-cartography, stewardship, operator-intelligence
- [x] routes/api/patamar4.php split
- [ ] routes/api.php remaining (voice/mobile/middleware core/…)
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
