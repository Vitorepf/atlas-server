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
- [ ] Remaining behavior-sensitive stringOption (~11: no-trim / keep-untrimmed variants)
- [x] Drop thin shells: memoryLimitToBytes, clamp01 (call sites), gitWorkspaceState wrappers
- [x] Empty Maestro ClosedLoop+Pinning dirs removed
- [x] ExternalBrain loadFacts → LoadsFactsFileOption (3 cmds)
- [ ] NativeImplementation loadJson trait variant

### Density / elevate

- [x] EnterpriseReportDashboardHtml shell → Template class (host ~552L)
- [x] CertificationWorkbenchDelegators → map+__call (~1985→272L, 219 methods)
- [x] OpenBrainMcpToolCatalog::definitions → data file (catalog 25L + definitions 1089L)
- [ ] EnterpriseFlowFixtureActionRuntimeService::run table-drive (~1200L)
- [x] AiWorker::completeAttempt stage peel (orchestrator + WhenCancelled/Succeeded/Failed + persistOutcome; baseline suite pre-existing 1 fail unrelated)
- [ ] AtlasTaskServingService::report stage peel (~550L)
- [ ] AtlasAaeosCommand::universalGates → observe service (~808L)
- [ ] AtlasLedgerReplayService family projectors (~2225L file)
- [ ] AtlasDecideService pure policy extract (~1722L file)
- [ ] Readiness OneShotTick invoker registry (~62 classes)
- [ ] Readiness PartN / HubDelegators peel collapse

### Architecture

- [ ] PipelineRunExecutor leave Http + test port (live DI already KernelRunExecutor)
- [x] config/atlas.php: loop → atlas_loop_legacy.php (~1704L extracted; atlas.php 5409→3707)
- [ ] ProviderCatalog single source (Decide/Gateway/Manager/Forge)
- [ ] AppServiceProvider domain providers
- [ ] routes/api.php domain split
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
