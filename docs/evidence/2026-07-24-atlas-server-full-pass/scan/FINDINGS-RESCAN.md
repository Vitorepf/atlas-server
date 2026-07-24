# Varredura Full-Pass — Atlas Server (rescans multi-agente)

**Data:** 2026-07-24  
**Modo:** read-only · estático em 100% de `app/**/*.php` + 6 subagentes explore por zona  
**Canon:** 30 áreas (`atlas-full-pass-hygiene-areas.md`)  
**Inventário:** 13 399 files · 3,02M LOC · 176 buckets  

> **Honestidade:** “linha por linha em 3M LOC” nesta sessão = (1) **censo 100%** + (2) **AST/método em todos os 6 496 PHP de `app/`** + (3) **auditoria profunda multi-agente** nos buckets de maior massa e monstruos.  
> Visita formal arquivo-a-arquivo com receipt (`FILE-BY-FILE.md`) continua a fila de execução — este doc é o **mapa do que fazer**, não o fechamento `pending=0`.

---

## 0. Escala medida (estático)

| Métrica | Valor |
|---|---:|
| PHP em `app/` | 6 496 |
| Métodos parseados | **44 710** |
| Métodos > 80 LOC | **2 830** |
| Métodos > 200 LOC | **218** |
| PHP app > 2000 LOC | **2** (Session 3597 · LedgerReplay 2211) |
| Bodies byte-idênticos (clusters) | **94** |
| Classes smell (Helper/Manager/Bar/…) | **21** |
| TODO/FIXME/HACK | **92** |
| `catch (Throwable` | **41** |

Artefato máquina: `scan/STATIC_APP.json`.

### Top mass dirs (`app/`)

| LOC | Dir |
|---:|---|
| 358 760 | SelfConstruction |
| 136 837 | Console/Commands |
| 132 932 | Programming |
| 117 938 | SoftwareCompanyStewardship |
| 73 380 | Engineering |
| 43 902 | Kernel |
| 43 837 | Holding |
| 43 264 | AgenticEngineeringOs |
| 41 164 | AutonomousEvolution |

---

## 1. O que precisa — por tipo de atuação

### 1.1 Padronizar nomes

| Problema | Onde | Ação |
|---|---|---|
| **AutonomousEvolution*** em Stewardship | `…/AreaFocusLoop/AutonomousEvolutionSessionService.php` | Renomear mental/produto → AreaFocus/AP-786 (aliases); nome colide com ACDE + Autônomos |
| **Aaeos vs Aeos vs AAEOS** | `Aaeos/` vs `AgenticEngineeringOs/` (`Aaeos*` bleed) | CODEMAP + renome: control = Aaeos; gates = Aeos |
| **Triple Kernel** | `Kernel/` · `EngineeringKernel/` · `Programming/Kernel/` | Labels: Runtime / Elite / Domain adapter |
| **Loop speech em path vivo** | `AtlasLoop*` keep-list + Fable reports `atlas:loop:*` | Keep-list fica; **retarget** operate speech → brain/task |
| **Misc* banido e usado** | Readiness `MiscProjections*` | Renomear por domínio (política de naming do próprio repo) |
| **Invoker monstro de nome** | `AgentAutomaticDispatchSchedulerOneShotTick*Invoker` (~60) | Registry + nomes de papel real |
| **Helper/Manager** | `BriefGroundingHelper`, `SuiteRedTriageHelper`, `*Manager`, `Bar.php` (Finance) | Promover/renomear honestamente |
| **CLI dual** | `atlas:aeos` vs `atlas:aaeos`; `atlas:ai:self-construction` vs `atlas:self-construction` | Um canônico + alias |
| **Ai*Command vs Atlas*** | `AiChatCommand`, etc. | Padronizar `Atlas*` |
| **Seam/Shared vanity** | `Shared*Seam`, `*Shared` traits | Drop vanity |

### 1.2 Arrumar (cleanup estrutural)

| Problema | Onde | Ação |
|---|---|---|
| Peel forest Readiness | `SelfConstruction/Readiness/*PartNN*` + HubDelegators | Colapsar peels → projectors + data tables |
| Empty dirs | `Maestro/ClosedLoop`, `Pinning`, `Aaeos/Control/Adapters`, AE empty shells | Delete ou seed |
| Root misplaced | `AgentControlPlaneTaskQueueOrchestrator.php` na raiz SC | Mover p/ `ControlPlane/` |
| Config monstro + **loop morto** | `config/atlas.php` **5400** (`loop` ~1.7k) | Split por domínio; **quarentenar `loop`** |
| AppServiceProvider god | `register()` **779L** | Domain providers (Autonomos/Vox/Stewardship/…) |
| HTTP domain logic | `Http/.../PipelineRunExecutor.php` `execute` **1311L** | Mover p/ Services |
| Test gods espelho | Mother test **31811L**; Aaeos test **12678L** | Particionar com o command |
| Loop tests residual | `tests/Feature/Loop/` | Audit keep-list vs assert de CLI morto |
| Dual front-door OpenBrain pack | `AtlasOpenBrainService` vs ContextPack vs `AiContextPackBuilder` | Matriz de portas no CODEMAP |
| Programming root dump | dezenas `AtlasForge*` flat vs `Forge/` | Rehome Invocation/ |

### 1.3 Elevar lógica (patamar superior)

| Hotspot | LOC método | Elevação |
|---|---:|---|
| `YouTubeKnowledgeIngestionService::parseCaptionPayload` | **1463** | Parser pipeline / strategies |
| `EnterpriseReportDashboardHtml::render` | **1409** | Sections HTML já parciais → completar |
| `PipelineRunExecutor::execute` | **1311** | Pipeline stages + sair de Http |
| `EnterpriseFlowFixtureActionRuntimeService::run` | **1207** | load→act→attest→status |
| `AtlasOpenBrainMcpService::tools` | **1084** | ToolRegistry data-driven |
| `VoiceAudit::scan…` / Architecture catalogs | **1000+** | Data-driven check tables |
| `AtlasAaeosCommand::universalGates` | **808** | Service observe, CLI thin |
| `AppServiceProvider::register` | **779** | Composition root split |
| `AtlasTaskServingService::report` | **550** | Stage pipeline live muscle |
| `AiWorker::completeAttempt` | **533** | Completion state handlers |
| `AtlasLoopAutoMergeService::mergeOneCritical` | **510** | Split keep-list safe |
| Cert coverage `report` | **505** | Table-driven rows |
| `AutonomousEvolutionSessionService::runCycle` | **488** | select→preflight→exec→govern→record |
| `runOwnerFlowCycle` | **413** | Owner-flow pipeline |
| `AiWorker::runNextMatching` | **369** | JobAdmissionGate ordered list |

**Doutrina:** live muscle (Serving, Worker, Session) = extract com golden; **cert/readiness peels** = first kill (baixo risco de produto).

### 1.4 Fundir blocos → plateau

| Plateau | Fragmentos a fundir |
|---|---|
| **CanonicalPayload** | 6–9 ksort/hash traits + private `canonicalize` (SelfConstruction, Maestro, Compounding, …) |
| **Codex gate pipeline** | `AgentCodexRealInvoker*` + `*PostStart*` (~48) → pre/post stages |
| **OneShotTick registry** | ~60 invokers → dispatcher |
| **Operator integrity** | Analyzer + Inspector twins |
| **Native endgame template** | HumanCompletion / RuntimePromotion / RealProviderSmoke / OperatorEvidence families |
| **Runtime modes** | Continuous + Unattended + RuntimeDaemon + AutonomousRuntime |
| **Replenishment** | 4 namespaces |
| **ProviderCatalog** | Decide + Gateway + Manager + Forge router (minimax drift) |
| **StewardshipLoopRuntime** | 24h + continuous + first-full + always-on |
| **GateObserve collaborators** | GateObserve0N Part clones |
| **Workforce Holding** | Buildout vs MandateRegistry sections |
| **gitWorkspaceState** | ProgrammingRivals + ForgeNativeRivals + Benchmark (STATIC dups) |
| **memorySafety / percentile / corpusSignature** | Memory/OpenBrain/Engineering clones |
| **OpenBrain opts** | intOpt/stringOpt em FileContext/Guard/Mcp |

### 1.5 Otimizar

| Alvo | Como |
|---|---|
| Gate chain DB locks × N | Batch authorize / 1 txn por stage |
| ProviderDriverRegistry `app()` scan | Index por id + lazy |
| Peel density artificial | Menos hops Readiness→Invoker→Gate |
| Config conflict magnet | Split files (menos merge hell) |
| Test suite time | Particionar god tests (não só “mais testes”) |
| Context pack budget | Já AOBG; evitar novos packs paralelos |

### 1.6 Reaproveitar (dups confirmados — top)

| Dup | Paths | Ação |
|---|---|---|
| `loadFacts` ×6 | SelfConstruction *Command | Trait/base command |
| `laterCycleChain*` ×5 | CodexReviewMerge Part07–11 | 1 section shared |
| `resolveJsonOption` ×4 | SelfImprovement commands | Trait |
| `projectRootFor` ×4 | AtlasCli* | Shared |
| `metadata` / `safetySummary` | Memory commands | Shared |
| `canonicalize` ×4+ | AE/SC/Specialist/Compounding | CanonicalPayload |
| `invocationModel` ×3 | Claude/Codex/Jarvis CLI | Base CLI provider |
| `gitWorkspaceState` ×2–4 | Programming/Rivals/Benchmark | GitWorkspaceStateReader |
| `memoryLimitToBytes` ×3 | Kernel + Controllers | Shared util |
| `parseJson` ×2 | Marketing | Shared |

### 1.7 Defatorar (OS gêmeos)

| Twin | Regra |
|---|---|
| Aaeos vs AgenticEngineeringOs | Control vs gates; sem bleed de nome |
| Stewardship vs AEOS vs Aaeos | Stewardship = factory de áreas, **não** 4º OS |
| SelfConstruction serving vs AutonomousEvolution brain | Correto; limpar speech “Loop” |
| Programming/Forge flat vs `Forge/` package | Um house |
| Decide vs ProviderManager vs Gateway “who picks model” | Decide owns policy |
| Three repair stacks | Shared failure vocabulary, owners distintos |
| Dual evidence ledgers | Projeções OK; proibir dual write do mesmo evento |
| Five “control plane” Programming | Um schema de projeção |

---

## 2. Prioridade P0 (fazer primeiro)

| # | Área | Path / sistema | O quê | Esforço |
|---:|---|---|---|---|
| 1 | eliminate / honesty | `config/atlas.php` `loop` + tests Loop que assertam CLI morto | Quarentenar loop config; limpar asserts mortos | M |
| 2 | density / simplify | `AtlasOpenBrainMcpService::tools` (1084) | Tool catalog registry | M |
| 3 | density | `EnterpriseReportDashboardHtml::render` (1409) | Split HTML sections | M |
| 4 | architecture | `PipelineRunExecutor` fora de Http | Mover + thin controller | L |
| 5 | density / simplify | `EnterpriseFlowFixtureActionRuntimeService::run` (1207) | Phase pipeline | L |
| 6 | reuse | CanonicalPayload (ksort/hash) | 1 implementação | M |
| 7 | density | Readiness PartN + HubDelegators | Collapse peels | L |
| 8 | density | OneShotTick invokers (~60) | Registry | L |
| 9 | elevate | `AtlasTaskServingService::report` (550) | Stage pipeline | M |
| 10 | elevate | `AiWorker::completeAttempt` (533) | Completion handlers | M |
| 11 | density / rename | `AutonomousEvolutionSessionService` (3597) | Peel runCycle + rename | XL |
| 12 | density | `AtlasLedgerReplayService` (2211) | Report projectors por domínio | L |
| 13 | config | Split `atlas.php` (ai/aobg/sc/loop/finance) | Multi-file | M |
| 14 | providers | Split `AppServiceProvider::register` | Domain SPs | L |
| 15 | surface | Mother + Aaeos commands + god tests | Thin CLI + part tests | L |
| 16 | contracts | ProviderCatalog unificado | Zerar drift minimax/etc. | M |
| 17 | operate | Fable/Weekly reports `atlas:loop:*` | Retarget brain/task/aaeos | S–M |
| 18 | fuse | Codex pre/post gate pipelines | 1 pipeline | L |
| 19 | defactor | Ownership matrix Stewardship/AEOS/Aaeos | Doc + moves leves | M |
| 20 | keep-list | ACDE 26 AtlasLoop* machine allowlist | Anti-delete errado | S |

---

## 3. God methods (app) — fila de elevação (top 20)

| LOC | File::method |
|---:|---|
| 1463 | `YouTubeKnowledgeIngestionService::parseCaptionPayload` |
| 1409 | `EnterpriseReportDashboardHtml::render` |
| 1311 | `PipelineRunExecutor::execute` |
| 1207 | `EnterpriseFlowFixtureActionRuntimeService::run` |
| 1084 | `AtlasOpenBrainMcpService::tools` |
| 1021 | `VoiceAudit::scanVoiceRealtimeProductionPromotionGate` |
| 1004 | `AtlasArchitectureOperationsCatalog::commands` |
| 847 | `AiSkillStore::defaultFiles` |
| 832 | `ProviderAudit::scanProviderUsagePerformanceContract` |
| 808 | `AtlasAaeosCommand::universalGates` |
| 779 | `AppServiceProvider::register` |
| 667 | `AtlasToolResultNormalizer::jsonPayload` |
| 615 | `ExecutionDoctrineCertification::frontendOperationalUnderstandingCheck` |
| 585 | `EnterpriseFlowFixtureRuntimeRecords::runtimeRecordsForCompany` |
| 562 | `AiChatCommand::handle` |
| 561 | `RepairLoopAudit::scanRepairLoopContract` |
| 550 | `AtlasTaskServingService::report` |
| 533 | `AiWorker::completeAttempt` |
| 510 | `AtlasLoopAutoMergeService::mergeOneCritical` |
| 505 | `AgentControlPlaneCertificationCoverageReportService::report` |

Lista completa: `STATIC_APP.json` → `very_long_methods_gt_200` (218).

---

## 4. Commands / HTTP / Config / Tests (superfície)

### Commands gordos (thin target)

| LOC | Command |
|---:|---|
| 1836 | AtlasAaeosCommand |
| 1760 | AtlasFinanceStrategySearchCommand |
| 1648 | AtlasFinancePolyExecCommand |
| 1643 | AtlasAiSelfConstructionMotherCommand |
| 1462 | AiChatCommand |
| 1380 | AtlasCliDevCommand |
| 1279 | AtlasAiLocalRagBenchmarkCommand |
| 1239 | AtlasRivalsCommand |
| 1213 | AtlasAiVoiceRealtimeCommand |

Modelo thin: `AtlasBrainSeedCommand` / `AtlasTaskCommand` (padrão a copiar).

### HTTP

| LOC | Path | Ação |
|---:|---|---|
| 1813 | AtlasCodeWorkController | Extract services |
| 1692 | AtlasCodeForgeExecutionController | Thin store paths |
| 1666 | PipelineRunExecutor (Http!) | **Mover p/ Services** |
| 1600 | AtlasFrontendWorkspaceController | Extract |
| 1408 | AiInteractionController | Extract |

### Tests monstro

| LOC | Path |
|---:|---|
| 31811 | AtlasAiSelfConstructionCommandTest |
| 12678 | AtlasAaeosCommandTest |
| 5641 | EngineeringHarnessRunnerTest |
| 5043 | AutonomousEvolutionSessionServiceTest |
| 4005 | AtlasOpenBrainMcpServiceTest |

---

## 5. Cobertura da varredura (não esqueci?)

| Camada | Status |
|---|---|
| Inventário 13 399 paths | Gerado |
| Parse métodos todos `app/*.php` | **44 710** |
| Buckets SC / Programming / Kernel / Aaeos / Stewardship / AE / Holding / AEOS | Subagente ✅ |
| Commands / Http / Providers / config / tests gods | Subagente ✅ |
| OpenBrain / Memory / Marketing / Rivals / Engineering | Subagente ✅ |
| Deep monstruos Session/Ledger/Worker/MCP/config | Subagente ✅ |
| `tests/` linha a linha | **Não** (massa 1M+ LOC) — coberto por god list + Loop residual audit |
| `docs/` linha a linha | **Não** — operate-path honesty amostrada |
| Receipt formal por arquivo | **Pendente** (execução FILE-BY-FILE) |

**Lacunas conscientes (próxima rescan):**  
- `database/`, `bin/`, `scripts/` só no inventário  
- Domínios menores (Vox, Finance full, Hermes) — mass menor; dups parcialmente no estático  
- Re-gerar inventário se tree mudar  

---

## 6. Ordem de ataque recomendada (execução)

```
Semana 1 — “dinheiro fácil” + honesty
  · dups loadFacts / canonicalize / gitWorkspaceState / memorySafety / opts OpenBrain
  · tools() MCP catalog
  · loop config quarantine + loop tests mortos
  · ProviderCatalog sketch

Semana 2 — superfície
  · thin Aaeos + Mother (com split de testes)
  · AppServiceProvider split início
  · config atlas.php peels (ai, aobg, loop)

Semana 3 — density live
  · Serving report stages
  · AiWorker completeAttempt
  · PipelineRunExecutor out of Http
  · Rivals HTML render

Semana 4+ — monstruos
  · Readiness peel collapse
  · OneShotTick registry
  · Session runCycle + rename
  · Ledger report domains
  · Holding run() + workforce fuse
```

**Anti-Goodhart:** não começar por shave de LOC em Serving/Worker sem golden; não deletar `AtlasLoop*` por prefixo.

---

## 7. Subagentes usados

| ID / foco | Resultado |
|---|---|
| SelfConstruction | 25 ações; peels Readiness/ControlPlane dominam |
| Programming/Kernel/Aaeos | Provider drift; Fable loop; Forge dual house |
| Stewardship/AE/Holding/AEOS | Session god; Holding density; OS matrix |
| Commands/Http/Config/Tests | CLI fat; config loop zombie; test gods |
| OpenBrain/Memory/Marketing/Rivals/Eng | MCP tools; dual pack doors; HTML 1409 |
| Deep gods | Plateau ≤5 owners por monstro |

---

## 8. Artefatos

| Path | Uso |
|---|---|
| `scan/STATIC_APP.json` | Métodos, dups, smells |
| `scan/FINDINGS-RESCAN.md` | **Este relatório** |
| `inventory/*` | Censo file-level |
| planos full-pass | Execução F0–F8 + FILE-BY-FILE |

---

## 9. Veredito

O Atlas Server **não está “sujo aleatório”** — está no meio de **GOD-DEBULK incompleto**:

1. Peels e sections já existem, mas **orquestradores e catalogs** ainda são deuses.  
2. **Nomes mentem** boundaries (Evolution/Loop/Aaeos).  
3. **Duplicata mecânica** (hash/canonicalize/opts/CLI glue) é ROI imediato.  
4. **Superfície** (commands/config/tests) multiplica o custo de cada monstro de serviço.  
5. Full-pass 30 áreas **tem mapa acionável**; falta **executar** com receipts.

**Próximo passo de execução:** P0 #1–#6 da tabela §2, com commits escopados e scoreboard por área.
