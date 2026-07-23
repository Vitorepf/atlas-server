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
