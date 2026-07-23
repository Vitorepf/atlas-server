# DEBTS — Obra Núcleo Essencial

## D1. Referências pendentes pré-existentes (campanhas antigas: cd018 "grok defatoração" etc.)

Scan `dangling_refs.py` sobre `app/`: **23 classes** importadas via `use App\…` mas **inexistentes no disco**. `::class`/type-hint não autoloadam, então NÃO quebram o boot (smoke = 1062 OK) — fatam só na invocação/instanciação do caminho específico.

### Bombas em ARQUIVO VIVO (fix = restaurar-classe OU redesenhar-consumidor; decisão do operador)

| Classe faltante | Consumidor(es) vivo(s) | Uso |
|---|---|---|
| `AtlasEvolutionScenarioExplorer` | `AtlasFinanceStrategyEvolveCommand` | type-hint — **quebra o trading loop vivo** (`atlas:finance:strategy-evolve`, dirigido por `AtlasFinanceStrategyLoopCommand`) |
| `AtlasIntelligenceFactoryRuntimeService` | Aemor, PersistentContext, RealitySandbox, RouterRuntime, Skills (5 serviços) | type-hint/::class — dir `IntelligenceFactory/` inexistente; alto impacto |
| `AtlasDeadCodeAnalyzer` | `AtlasCodeDeadCodeCheckCommand` | type-hint/::class — o próprio checker de dead-code está quebrado |
| `AaelStepActor` | `AtlasAaelTraceCommand` | **implements** (fatal no load do comando) — verificar se o comando é registrado |
| `AtlasAaelParallelLockManager`, `LockHandle` | `AtlasAaelParallelCommand` | type-hint / `new` |
| `SchemaDowngradeRefusedException`, `UnknownSchemaVersionException` | `AtlasTaskMaestroSchemaCommand` | type-hint — provável restauro trivial de exception |
| `InvalidProviderException` | `AtlasTaskMaestroBidCommand` | type-hint |

### Território da SESSÃO PARALELA (NÃO tocar até ela terminar)
- Readiness: `AutomaticDispatchBatch1`, `Codex`, `CodexReviewMerge`, `ReviewMerge` — prováveis imports de namespace-segment; reverificar pós-merge.
- `FoundryExhaustionRarityGateService` em `AutonomousEvolutionSessionService` (5132L, em split pela sessão paralela).

### Dentro de `AutonomousEvolution/` morto (dissolve ao deletar na Wave 2)
`AtlasLoopProviderSwapPolicy`, `AtlasLoopTerritoryLadder`, `AtlasLoopBehaviorDeltaComputer`, `AtlasLoopOrphanWiringSupplyLane`, `AtlasLoopWorkClassPriorService`, `LoopWorkerCountPlanner`, `LoopWorkerPool`, `AtlasLoopPatternCompiler`, `AtlasLoopObraPlanningPromptComposer` — todos referenciados apenas por outros arquivos ACDE-mortos.

## D2. Já resolvido (Wave 0.5, commit `0737bba7f`)
- ASP: ~15 bindings/uses mortos + bind quebrado `LoopExecutionDriver` + tree-deps do AtlasLoopBackService.
- `config/atlas.php` brain.paths `frontier-harvest` → repontado p/ `AtlasExternalBrainFrontierHarvestGovernanceRunner` (teste `AtlasBrainPortfolioConfigTest` RED→green).

## D3. Pré-existente fora de escopo
- `config/auth.php` → `App\Models\User` inexistente (Atlas single-operator local sem web-auth; default Laravel nunca exercitado).

## D4. Cluster ACDE quebrado por cd018 (decisão: deletar-cluster vs restaurar-deps)
`AtlasLoopTaskGrinder` injeta (required) `AtlasLoopExplorerStrategyBanditService` + `?AtlasLoopObraExecutionAdapter`; `ObraExecutionAdapter`/`StrategyBandit`/`CampaignSupervisor`/`DeterministicDeadCodeWorkType` importam classes deletadas por cd018 → **cluster inteiro quebrado**, mas com ~30 testes Feature/Loop e intra-wiring. NÃO é dead-solto (deletar cascateia 30 testes + TaskGrinder). Resolução requer closure de componente-conexo: (a) provar o cluster inteiro inalcançável do vivo → deletar tudo (classes+testes) atômico, OU (b) restaurar os deps cd018 (ObraPlanningPromptComposer, WorkClassPriorService, ProviderSwapPolicy, TerritoryLadder, BehaviorDeltaComputer). Consumido só como config-label (executor_organ, nunca instanciado) + testes → forte candidato a (a).

## D5. Resolvido: hard-keep RefillerSupplyLaneCoordinator un-broken
Restaurados os 2 deps self-contained (`AtlasLoopOrphanWiringSupplyLane`, `AtlasLoopWorkShapeRouter`, zero cascade) que cd018 deletou. Config `simulation-twin` executor_organ pendente (era SimulableTwinOrchestrator, quebrado — repoint quando o cluster D4 for decidido).
