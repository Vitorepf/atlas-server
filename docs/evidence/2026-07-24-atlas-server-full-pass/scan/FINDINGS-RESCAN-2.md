# Varredura Full-Pass #2 — Atlas Server (multi-agente, 2026-07-24)

**Modo:** read-only · 8 subagentes explore + censo estático residual  
**Canon:** 30 áreas (`atlas-full-pass-hygiene-areas.md`)  
**Predecessor:** `scan/FINDINGS-RESCAN.md` (rescan #1)  
**Branch:** `main`

> **Honestidade:** “linha por linha em ~3M LOC” = (1) censo 100% inventário + (2) re-medida estática de padrões residual + (3) auditoria profunda multi-agente por zona de massa.  
> **Não** é claim de receipt formal em 13 399 paths (`FILE-BY-FILE` continua fila).  
> Este doc **corrige** o rescan #1 (falsos positivos + o que já aterrissou) e **rankeia o residual real**.

---

## 0. O que mudou desde o rescan #1

| Item #1 | Status #2 (medido) |
|---|---|
| MCP `tools()` monólogo 1084L | **FEITO** — `AtlasOpenBrainMcpService::tools()` thin (~7L map); blob moveu para `OpenBrainMcpToolCatalog::definitions` **~1099L** (próximo peel de catalog, não re-fazer tools) |
| CLI Concerns + Support helpers | **Parcial** — traits/helpers existem; **adoção incompleta** (gap principal) |
| `gitWorkspaceState` fuse | **Quase** — `GitWorkspaceStateReader` existe; 4 shells + ~6 rivals ad-hoc |
| `parseCaptionPayload` 1463L | **FALSO POSITIVO** — método ~26L switch; arquivo YouTube ~1780L por muitas privates |
| `atlas:loop:*` CLI | **JÁ MORTO** em Console (0 signatures); residual = docs/speech + classes keep-list |
| PipelineRunExecutor “live Http” | **NUANCE** — live DI = `KernelRunExecutor`; PRE = legacy/tests (~30 FQCN, quase só tests); debt = **move/delete after port**, não untangle active HTTP |
| Mass stringOption | **~17–24** defs locais ainda (não zero) |
| YesNo / UtcIso / StableJson | **Sub-adotados** — helpers existem, dezenas–centenas de inlines |

### Escala residual (estático live)

| Métrica | Valor |
|---|---:|
| PHP `app/` | ~6 529 |
| Métodos > 200 LOC (approx next-fn) | **~220** |
| Métodos > 80 LOC (approx) | **~3 142** |
| `OneShotTick` hits / files | 4054 / 129 |
| `catch (Throwable` files | 1097 |
| `? 'yes' : 'no'` | **~306** |
| `gmdate('c')` | **~85** |
| `ReadsNonEmptyStringOption` adopters | **~60** |
| `stringOption` defs em Commands | **~19** |
| `config/atlas.php` | **5409** |
| `AppServiceProvider` | **1099** |
| SelfConstruction Invokers | **~112** |
| SelfConstruction Part* | **~85** |
| SelfConstruction Section* | **~152** |

---

## 1. Subagentes (#2)

| # | Foco | Resultado |
|---|---|---|
| 1 | Monstruos / god methods | 25 findings; peels parciais mapeados; YouTube FP; MCP tools done |
| 2 | Dups / reuse residual | 30 findings ROI; under-adoption de Support/Concerns |
| 3 | Naming / honesty | 25 findings; dual CLI; keep-list risk |
| 4 | SelfConstruction density | Peel forest vs live muscle; fuse order risk-aware |
| 5 | Console surface | ~958 cmds; god CLIs; dual SC/aeos fronts |
| 6 | Config / providers / HTTP | PRE census; ProviderCatalog; ASP; routes |
| 7 | OS twins / defactor | Ownership matrix; AAEOS/AEOS/Stewardship/ACDE |
| 8 | Programming / Holding / Kernel / Eng / Rivals | Dual Rivals; Services→Controllers invert; Ledger |

---

## 2. Por tipo de atuação (residual)

### 2.1 Padronizar nomes

| Problema | Onde | Ação | Esforço |
|---|---|---|---|
| `AutonomousEvolutionSession*` em AreaFocusLoop | Stewardship AFL | → `AreaFocusSession*` / `StewardshipAreaSession*` | L |
| `AtlasLoop*` no músculo vivo | keep-list 26 classes | alias Autonomos; **nunca delete por prefixo** | XL |
| `AtlasLoopMasterSwitch` + env `ATLAS_LOOP_*` | Autônomos gate | canônico `AtlasAutonomosMasterSwitch` + env Autonomos | M–L |
| Aaeos* tipos **dentro** de AgenticEngineeringOs | AEOS tree | rename → `Aeos*` (vocab já manda) | L |
| CLI `atlas:aeos` vs `atlas:aaeos` + classes `AtlasAaeos*` | Console | alinhar class↔signature; dropar DeprecatedAliases após ciclo | M |
| Dual SC: `atlas:ai:self-construction:*` shells vs `atlas:self-construction:*` real | Console | um tree operador; shells colidem em tokens | M |
| `MiscProjections*` + `MiscGuardAudit` | Readiness / Kernel | renomear por domínio | M |
| OneShotTick invoker names monstro (~62) | ControlPlane | prefix curto + registry | XL |
| Triple Kernel | Kernel / EngineeringKernel / Programming/Kernel | docs roles; move físico só com mapa | M docs / L move |
| `Shared*Seam` vanity | SC Support | dropar Shared+Seam | S–M |
| `*Helper` residual | BriefGroundingHelper, SuiteRedTriageHelper, … | merge parent / role name | S |
| Sentence-class `The*Contract` em AFL | Stewardship | short nouns | S–M |
| Finance `Bar` | StrategyLoop | **manter** (OHLCV honesto — não ban-list) | — |

### 2.2 Arrumar (cleanup estrutural)

| Problema | Ação | Esforço |
|---|---|---|
| Empty dirs: `Maestro/ClosedLoop`, `Maestro/Pinning`, `AcosMax/`, `Cognitive/` | delete ou drain aliases | S |
| Root SC: `AgentControlPlaneTaskQueueOrchestrator` (2074L) | rehome `ControlPlane/` / `TaskQueue/` | M–L (**HIGH** muscle) |
| `config/atlas.php` 5409 + `loop` ~1726 legacy | multi-file split; quarentenar loop | M–L |
| `AppServiceProvider::register` ~779 | domain SPs (padrão AtlasDev SP) | L |
| `routes/api.php` ~1038 / 200+ routes | split `routes/api/*` | M |
| Services import Http Controllers (Forge cert/fast-path) | inverter: ports de serviço | L |
| Dual Forge homes: flat `AtlasForge*`, `Programming/Forge/`, residual `Ai/Forge`, `Ai/AtlasForge` | fuse vivos; kill zero-ref | M–L |
| Test gods espelho (Mother 31k, Aaeos 12k) | particionar com thin CLI | L |
| Dual Rivals: `Ai/Rivals` + `Programming/*Rivals*` | CODEMAP + ports | M |

### 2.3 Elevar lógica (patamar superior)

| Hotspot | ~LOC | Elevação | Risco |
|---|---:|---|---|
| `EnterpriseReportDashboardHtml::render` | **1365–1409** | HTML/JS shell + payload board | M |
| `PipelineRunExecutor::execute` | **~1311** | stages já parciais; **leave Http / port tests** | XL (tests) / live já KernelRunExecutor |
| `EnterpriseFlowFixtureActionRuntimeService::run` | **~1207–1211** | table-drive findByFlow map | M–L |
| `OpenBrainMcpToolCatalog::definitions` | **~1086–1099** | split domain PHP/JSON | S–M |
| VoiceAudit / Architecture catalogs | **1000+** | data-driven tables | M |
| `AtlasAaeosCommand::universalGates` | **~808–813** | observe registry service; CLI thin | M |
| `AppServiceProvider::register` | **~779–786** | composition root split | L |
| `AtlasTaskServingService::report` | **~550–596** | stage pipeline (**live muscle**) | M–L |
| `AiWorker::completeAttempt` | **~533–534** | completion handlers | M |
| `AiChatCommand::handle` | **~563** | already sectioned; more service peel | M |
| `AutonomousEvolutionSessionService::runCycle` | **~488–493** | select→preflight→exec→govern→record (**floor RSI**) | XL |
| `runOwnerFlowCycle` / Reliable24h `run` | **~413–482** | pipelines | XL / L |
| `AtlasLedgerReplayService` families | file **~2225** | projectors por domínio | L |
| `AtlasDecideService` | file **~1722** | pure DecideTaskProfile + ProviderPolicy | M |
| YouTube file | **~1780** | collaborators metadata/caption/audio (**não** chase parseCaption FP) | M |

**Doutrina:** peels Readiness/OneShotTick = first kill (baixo risco produto). Live muscle (Serving, Worker, Session, Orchestrator claim) = extract **com golden**.

### 2.4 Fundir blocos → plateau

| Plateau | Fragmentos | Esforço |
|---|---|---|
| **YesNo / TrueFalse / UtcIso / EmitsCanonicalJson** | 80–300+ inlines | **S** (wave A) |
| **ReadsNonEmptyStringOption** finish | ~17 private stringOption | **S** |
| **CanonicalValue** + stable hash | ~8 full canonicalize + raw sha256 json | M |
| **ArrayPercentile** overloads | ~10 residual (interp vs floor) | S–M |
| **GitWorkspaceStateReader** full | 4 shells + rivals porcelain | S–M |
| **OneShotTick invoker envelope** | ~62 thin adapters | L |
| **CertificationWorkbenchDelegators** | ~1985L pure tables → catalog | S |
| **DispatchBatch / ReviewMerge / Codex Part peels** | dezenas × ~1.2k template | M–L |
| **Codex RealInvoker pre/post** | ~55 gates | L |
| **NativeImplementation families** | Smoke/Promotion/Receipt/Evidence | L |
| **ProviderCatalog SSOT** | Decide + Gateway + Manager + Forge + config | L |
| **Autonomy ladder twin** | Autonomy vs Stewardship AFL | M |
| **Enterprise suite metadata triple** | SUITES / SUITE_GUIDE / CAPABILITIES | S–M |
| **loadFacts / facts-file** | trait + 3 ExternalBrain + NativeImpl | S |

### 2.5 Otimizar

| Alvo | Como |
|---|---|
| Peel density 3-hop (Hub→Section→Invoker→Gate) | registry/catalog; menos hops readiness |
| ProviderDriverRegistry / hard lists | index + single catalog |
| Config conflict magnet | physical split |
| Gate chain DB locks | batch authorize (quando tocar gates) |
| Test suite time | part god tests (não “mais testes”) |
| Context pack | não criar packs paralelos |

### 2.6 Defatorar (OS gêmeos) — ownership alvo

| Concern | Owner | Não é |
|---|---|---|
| Org admit → dispatch + spine | `Aaeos/{Control,Spine}` | gates |
| Universal gates / maturity | `AgenticEngineeringOs` | cycle mutator |
| Area factory | `SoftwareCompanyStewardship` | 4º OS |
| Brain | `AutonomousEvolution/Brain` + `atlas:brain` | ACDE root |
| Muscle | `SelfConstruction` + `atlas:task` | re-own Brain |
| Forge runtime | `Programming/Forge/**` | flat residual roots |
| Who picks model | **Atlas Decide** | Provider/CLI as authority |
| Who runs model | Provider pipe | Decide |

---

## 3. Prioridade P0 / P1 / P2 (execução residual)

### P0 — fazer primeiro (ROI × honestidade)

| # | Área | O quê | Esforço | Nota |
|---:|---|---|---|---|
| 1 | reuse | **Wave A:** YesNo + TrueFalse + UtcIsoTimestamp + EmitsCanonicalJson adoption | S | 80–300 sites; zero risco comportamento se byte-equal |
| 2 | surface_std | Finish **stringOption** (17 cmds) + MemoryProjection trait collision | S | |
| 3 | density | **EnterpriseReportDashboardHtml::render** split | M | open DEBT |
| 4 | density | **CertificationWorkbenchDelegators** → catalog/codegen | S | readiness only |
| 5 | density | **OpenBrainMcpToolCatalog::definitions** domain split | S–M | tools() já thin |
| 6 | architecture | **ProviderCatalog** SSOT sketch + allowlists | M–L | live route risk |
| 7 | architecture | **config loop extract** + honesty docs | M | keep-list still reads keys |
| 8 | elevate | **AiWorker::completeAttempt** stages | M | open DEBT; support peels já existem |
| 9 | elevate | **AtlasTaskServingService::report** stages | M | live muscle — golden |
| 10 | architecture | **PRE leave Http / test port** (já non-live DI) | L | FQCN census ~tests |

### P1

| # | O quê | Esforço |
|---:|---|---|
| 11 | EnterpriseFlowFixture `run` table-drive | L |
| 12 | OneShotTick invoker generic envelope | L |
| 13 | Readiness Part forests (Batch/Codex/ReviewMerge) collapse | L |
| 14 | AtlasAaeosCommand universalGates peel + thin CLI | M |
| 15 | AppServiceProvider domain SPs | L |
| 16 | AtlasLedgerReplayService family projectors | L |
| 17 | AtlasDecideService pure policy extract | M |
| 18 | Dual SC CLI namespace collapse | M |
| 19 | Aaeos*→Aeos* rename outside Aaeos/ | L |
| 20 | Services→Controllers inversion (Forge cert paths) | L |
| 21 | gitWorkspace residual + CanonicalValue residual | S–M |
| 22 | Empty dirs + DeprecatedAliases cycle | S |

### P2 / floors

| # | O quê | Nota |
|---:|---|---|
| 23 | Session `runCycle` + rename AutonomousEvolution* | **RSI floor** |
| 24 | AtlasLoop* keep-list rename program | **XL; alias only** |
| 25 | TaskQueueOrchestrator rehome/split | HIGH muscle |
| 26 | Support RealInvoker gate ladder fuse | medium DB risk |
| 27 | routes/api split | low risk |
| 28 | Test god partition | L |
| 29 | file-by-file 13399 receipts | continuous |
| 30 | Fable/docs loop speech retarget | S (CLI já morto) |

---

## 4. Live muscle — **não fundir casualmente**

- `AtlasTaskServingService` (+ TaskServing/*)
- `AgentControlPlaneTaskQueueOrchestrator`
- `AtlasTaskScopedCommitter`
- `AtlasTaskServingSwitch` / `AtlasLoopMasterSwitch` (keep-list)
- `AiWorker` completeAttempt / runNextMatching
- `AutonomousEvolutionSessionService` (floor + golden)
- Decide receipt hot paths / Provider live auto-route

**Safe density zone (baixo risco a landings main):**  
`Readiness/HubDelegators`, `Part*SubSection`, `OneShotTick*Invoker`, cert/workbench catalogs, HTML report render, CLI pure glue.

**Serving NÃO usa OneShotTick invokers** — fuse peel sem tocar commits Autônomos.

---

## 5. Wave A recomendada (próximas horas)

```
1. YesNo::format + TrueFalse em CLI/reports (batch por família)
2. UtcIsoTimestamp::now() em ledgers/finance timestamps
3. EmitsCanonicalJson nos cmds que ainda json_encode PRETTY
4. stringOption → ReadsNonEmptyStringOption (17 arquivos)
5. dropar shells memoryLimitToBytes / clamp01 / gitWorkspaceState privados
6. empty Maestro dirs delete
7. LEDGER + package unit tests Support/Concerns
```

Depois: HTML monolog · Workbench catalog · Worker completeAttempt · Serving report · config loop extract · ProviderCatalog.

---

## 6. Cobertura da varredura #2

| Camada | Status |
|---|---|
| Inventário + GODFILES | reusado + re-wc top |
| Padrões residual (helpers/clones) | ✅ estático |
| Monstruos top-30 + methods >200 | ✅ |
| SelfConstruction peel vs muscle | ✅ |
| Console dual fronts + traits | ✅ |
| Config / ASP / PRE / routes | ✅ |
| OS twins ownership | ✅ |
| Programming/Holding/Kernel/Eng/Rivals | ✅ |
| Naming honesty + keep-list | ✅ |
| `tests/` linha a linha | **Não** (gods listados) |
| `docs/` operate speech | amostrado (loop residual) |
| `database/` `bin/` `scripts/` | inventário only |
| Receipt por arquivo 13399 | **Pendente** |

**Lacunas conscientes:** Finance StrategyLoop deep beyond Bar; Vox/Hermes full; mobile controllers; non-PHP assets.

---

## 7. Correções ao rescan #1 (anti-ruído)

1. **Não** re-extrair `AtlasOpenBrainMcpService::tools` — feito.  
2. **Não** caçar `parseCaptionPayload` 1.4k — FP.  
3. **Não** tratar PRE como live Http path — `KernelRunExecutor` é live.  
4. **Não** “matar atlas:loop commands” — já mortos; só speech/docs.  
5. **Não** deletar `AtlasLoop*` por prefixo.  
6. **Não** double-count Support helpers como “falta criar” — falta **adotar**.  
7. CodexReviewMerge later-cycle trait **não** matou methods 1.2k nos Part07–11 — residual peels ainda densos.

---

## 8. Veredito

O corpus **não** está “sujo aleatório”. Está em **pós-GOD-DEBULK com peels incompletos + helpers sub-adotados + monstruos de orquestração/catalog**:

1. **Adoção > invenção** nas próximas ondas baratas (YesNo, UtcIso, stringOption, JSON emit).  
2. **Density wins seguros** = Readiness peels + HTML report + ToolCatalog split + Workbench tables.  
3. **Architecture** = ProviderCatalog, config split, PRE test-port, ASP domain SPs.  
4. **Live muscle elevate** só com golden (Serving, Worker, Session floor).  
5. **Nomes mentem** boundaries (Evolution/Loop/Aaeos) — honesty program paralelo, sem mass-rename cego.

**Próximo passo:** executar Wave A (P0 #1–#2) em commits escopados na `main`, depois P0 density #3–#5.

---

## 9. Artefatos

| Path | Uso |
|---|---|
| `scan/FINDINGS-RESCAN.md` | rescan #1 |
| `scan/FINDINGS-RESCAN-2.md` | **este relatório** |
| `scan/STATIC_APP.json` | censo #1 (revalidar se tree mudar) |
| `DEBTS.md` / `LEDGER.md` / `SCOREBOARD.md` | execução |
| `inventory/*` | censo file-level |
