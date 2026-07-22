# ARCH BLUEPRINT — Runtime de Execução

> status: draft-v2 RE-VERIFICADO (rodada 2 do verify: FATAIS CONSERTADOS — aprovado p/ operador). Migração aditiva conferida contra migrations/models reais (AtlasAverExecution sem goal_record_id; AiAutonomousEngineeringGoal existe sem objective_hash; 5 models canônicos com goal_record_id); 10 path-literals reconfirmados; PSR-4 do lifecycle de dir consistente; 19 tools do F3-pre batem nome a nome.
> 3 RESSALVAS DE EXECUÇÃO (não bloqueiam): (a) F4a — a chave do AVER é execution_id (não command_run_id); o "1:1" do espelho é estrutural, e ai_real_execution_command_runs leva goal_record_id como os irmãos; (b) F2 — incluir AtlasProgrammingFinalCertificationServiceTest:256/268 (fixtures com path-literal VCE) no mesmo commit do literal #2; (c) typo de doc: path completo do Scanner é app/Services/Ai/Kernel/Architecture/.
> v2: correlação Fusão 2 por migração aditiva provada contra fillables reais (objective_hash NÃO existe no canônico; diff_hash AVER = hash de payload ≠ sha256 do diff cru — NÃO equivalentes); command-ledger ganha tabela canônica aditiva; inventário fechado de 10 consumidores path-literal com atualização no mesmo commit; ciclo de vida de diretório sem contradição (dir só esvazia no ciclo seguinte, junto com alias).
> data: 2026-07-22 · obra: GOD Debulk / capability Runtime de Execução · modelo: SelfConstructionReadiness.md

## 1. Contexto provado

### 1.1 Estado atual (verificado)
| Bloco | LOC | Files | Papel real |
|---|---|---|---|
| Runtime/ | 2.879 | 14 | Executor REAL de 19 tools (AiToolRuntime::execute L75-96). Sem receipt governado, sem policy-gate (0 refs a AiToolReceipt) |
| ToolRuntime/ | 2.080 | 15 | Pipeline governado mas MOCK (gate strict → mockExecute → receipt fail-closed) |
| RealExecution/ | 6.431 | 8 | Delivery kernel real (8 models AiRealExecution*); god interno 3.989 LOC com ~2.400 de candidate* (EngineeringCompany) |
| VerifiedExecution/ | 1.044 | 2 | AVER: 2º ledger (6 models AtlasAver*), certify próprio |
| RuntimeReadiness/ 857/1 · RuntimeReleaseGate/ 263/1 (wrapper duplo-hash) · RuntimeEfficiency/ 2.269/4 · VerifiedContextExecution/ 442/1 (shadow() não grava outcome — 0 refs) | | | |

### 1.2 As quatro fraturas
1. Execução real sem governança / governança sem execução. 2. Dois ledgers para o mesmo ato. 3. Duplo-hash do release gate. 4. Loop verificado que não ensina o governor.

### 1.3 Consumo externo real
- Runtime real: AtlasRuntimeCommand, AtlasAiToolRuntimeCommand, EngineeringTestMatrixService, SkillDiscoveryService, AtlasCliQualityService, AtlasCliCheckpointService + hot path Search. Interface crítica: `execute(ToolInvocation): ToolResult` **+ estático `availableTools()` (L24, callers SkillDiscoveryService/SessionSearchRuntimeTest)** — compat preserva ambos.
- ToolRuntime mock: command + ProgrammingToolBridge + 14 feature tests.
- AVER: 9 callers (commands aver, ChangeOrchestrator, AWEOS, HyperflowEntry, CertifierClassificationLedger, RunConductor, StrategicOS, binding AppServiceProvider:1147). Interface: 10 métodos array-in/out + status blocked.
- RealExecution: DeliverAtlasMissionJob, commands Obra/Mission/Deliver, ObraExecutor, binding.
- ReleaseGate: command + AtlasPreBenchmarkReadinessService — compat crítica = schema v1.
- VCE: **8 consumidores** (verificado): command · CognitionScoreCard:517 (classe — alias cobre) · Product PATH_AVCEL_*:151-155 (path) · ProgrammingFinalCert:588-624 (path) · QualityPreservingEfficiency:91/108 (File::exists) · AutonomousEvolutionSession:150 · AreaFocusDeepFinding:2118/2383 · CodeIntelligenceAutomaticGate:97.

### 1.4 Consumidores por PATH-LITERAL (inventário FECHADO por rg; regra: cada fatia atualiza o literal NO MESMO COMMIT do move)
| # | Consumidor | Literal | Fatia |
|---|---|---|---|
| 1 | Product/AtlasAiProductCertificationService:151-155 | consts PATH_AVCEL_* | 2 |
| 2 | ProgrammingRuntime/AtlasProgrammingFinalCertificationService:590-593 | paths VCE | 2 |
| 3 | RuntimeEfficiency/AtlasQualityPreservingEfficiencySystemService:91/108 | File::exists VCE | 2 |
| 4 | AreaFocusLoop/AutonomousEvolutionSessionService:149-150 | dirs VerifiedExecution/+VCE/ | 2 e 4c |
| 5 | AreaFocusLoop/AreaFocusDeepFindingEngineService:2117-2118/2383-2384 | dirs + source/test VCE | 2 e 4c |
| 6 | Engineering/AtlasCodeIntelligenceAutomaticGateService:97 | path VCE | 2 |
| 7 | ProgrammingRuntime/ProgrammingRuntimeReadinessService:442 | paths ToolPolicyBridge/ToolReceipt | 3a |
| 8 | Kernel/Architecture/KernelArchitectureStaticScanner:9762-9815 | token-scan do CONTEÚDO de AiToolRuntime.php | 3b (tokens re-apontados p/ ToolExecutionRuntime.php) |
| 9 | Engineering/AtlasSoftwareTwinRuntimeService:69 | path AVER | 4c |
| 10 | Engineering/AtlasSoftwareTwinVerifiedEvolutionCertificationService:33 | fileCheck path AVER + tokens planFromVerifiedEvolutionContract/source_verified_evolution_contract_hash — o delegador congelado RETÉM os tokens | 4c |

## 2. Owners-alvo (destinos: Ai/Runtime/ · Ai/RealExecution/ · Ai/RuntimeReadiness/ · Ai/RuntimeEfficiency/)

### 2.1 Fusão 1 — ToolRuntime ⇒ Runtime (execução real governada)
| Classe | Sufixo | Responsabilidade | Teto |
|---|---|---|---|
| ToolExecutionFacade | Facade | run/runPreview: resolve→decide→executa→receipt num call. Zero lógica | ≤800 |
| ToolExecutionPolicy | Policy | decisão pura fail-closed sobre INPUTS materializados (veredicto do gate + PermissionSessionSnapshot entregue pelo Gateway). **Zero I/O de verdade — a query AiPermissionSession (PermissionEngine:83-96) NÃO entra aqui** | 500 |
| ToolPolicyGateway | Gateway | único I/O: ai_permission_gates/ai_policy_profiles **+ leitura de AiPermissionSession** (entrega snapshot à Policy) | 450 |
| ToolExecutionRuntime | Runtime | executor real dos 19 tools; preview honesto substitui mockExecute; só executa com allowed | 1.800 |
| ToolReceiptGateway | Gateway | único writer: AiToolReceipt (fail-closed) + AiToolEvent no MESMO uuid | 600 |
| ToolCatalogProvider | Provider | registry/seeds/catálogo; sem executor mapeado ⇒ só preview | 700 |
| ToolRuntimeProjector | Projector | health/control-plane/readiness/planning read-only | 700 |

Compat ≤1 ciclo: AiToolRuntime congela como delegador — execute() delega à Facade **e os estáticos (availableTools etc.) são preservados delegando ao ToolCatalogProvider**; ToolInvocationService::invoke delega com mode=preview @deprecated. AiToolInvocation canônico; AiToolEvent dupla escrita correlacionada.

### 2.2 Fusão 2 — AVER ⇒ RealExecution (linhagem única)

**Correlação — decidida com fillables reais**: (a) canônico é chaveado por goal_record_id→AiAutonomousEngineeringGoal (sem objective_hash); (b) AVER por execution_id→AtlasAverExecution (tem objective_hash); (c) **patch_hash/test_hash NÃO correlacionam**: AVER diff_hash = hash do payload (L233) vs canônico sha256 do diff cru (L539) — VERIFICADO, não equivalentes. **Decisão: migração aditiva mínima, zero backfill:**
```
1. atlas_aver_executions + goal_record_id (nullable, index) — Gateway preenche no ato; NULL ⇔ legado
2. tabela do AiAutonomousEngineeringGoal + objective_hash (string(64) nullable, index)
3. NOVA tabela ai_real_execution_command_runs (command-ledger canônico) — espelho estrutural 1:1 de AtlasAverCommandLedger (command_run_id unique, status, hashes, exit_code, safety_gate json, ledger_hash…)
Backfill: NENHUM. Linhas legadas (NULL) = leitura histórica por-fonte, jamais re-certificadas.
```
**Command-ledger**: vira tipo de run canônico (tabela 3). Justificativa: regra por-fonte manteria 2 writers para sempre; AtlasAverTestLedger já referencia command_ledger_id — command é cidadão da linhagem.

| Classe | Sufixo | Responsabilidade | Teto |
|---|---|---|---|
| ExecutionEvidenceFacade | Facade | superfície AVER 1:1 + entrada kernel | ≤800 |
| ExecutionEvidenceGateway | Gateway | único writer: canônico primeiro (incl. command_runs); espelho AtlasAver* best-effort correlacionado por goal_record_id — nunca por hash recomputado. SEM drop | 900 |
| ExecutionCertificationEvaluator | Evaluator | regra ÚNICA: certified ⇔ patch passed ∧ test passed ∧ zero command failed/blocked p/ o goal_record_id, no ledger CANÔNICO. Fail-closed: ato AVER sem command_run canônico ⇒ blocked; goal NULL (legado) ⇒ fora do escopo | 800 |
| ExecutionLineageProjector | Projector | view unificada + leitura histórica por-fonte | 700 |
| RealExecutionKernelRuntime | Runtime | só orquestração worktree→patch→test→repair + delivery + handoff | 1.800 |
| CandidateReceiptSealEvaluator | Evaluator | família candidate*OwnerReceiptValid + binding/signature/seal (7 roles, Kernel L623-3143) | ≤1.400 |
| CandidateDispositionEvaluator | Evaluator | família candidate*Disposition + *Evidence (7 roles); re-home futuro p/ EngineeringCompany fora desta obra | ≤1.400 |

Compat: AtlasVerifiedExecutionRuntimeService congela como delegador (9 callers + binding) **retendo os tokens textuais do fileCheck do SoftwareTwin (#10)**; atlas:aver* mantêm flags/schema; literais #4/#5/#9/#10 atualizados na 4c.

### 2.3 Fusão 3 — ReleaseGate ⇒ Readiness
releaseGate() no readiness a partir do payload de report() computado 1x — hash de origem única. Compat: one-liner @deprecated; snapshot byte v1; **dir retém só o one-liner até a data do alias; esvazia no ciclo seguinte**. Teto 1.200.

### 2.4 Fusão 4 — VCE ⇒ RuntimeEfficiency
VerifiedContextLoopService movido; shadow() grava outcome via recordOutcome com decision_ref (campo outcome_ref aditivo). Compat: class_alias datado; **dir retém só o stub até a data; os 8 consumidores (6 path-literal, §1.4 #1-6) atualizados no mesmo commit do move**. Teto 600.

## 3. Grafo one-way
```
Commands/Controllers/Jobs → Facades/Services de topo
  (compat ≤1 ciclo: AiToolRuntime→Facade · ToolInvocationService→Facade(preview) · AVER→Facade · ReleaseGate→releaseGate())
→ Policies: ToolExecutionPolicy · Evaluators: ExecutionCertification/CandidateReceiptSeal/CandidateDisposition · Projectors: ToolRuntime/ExecutionLineage · Runtimes: ToolExecutionRuntime/RealExecutionKernelRuntime
→ Gateways/Providers: ToolPolicyGateway · ToolReceiptGateway · ExecutionEvidenceGateway · ToolCatalogProvider
→ Models: AiToolDefinition/Invocation/Receipt/Event · AiPermissionSession(via Gateway) · AiRealExecution* + ai_real_execution_command_runs (canônico) · AtlasAver* (espelho, +goal_record_id) · AiAutonomousEngineeringGoal (+objective_hash) · AtlasRuntimeEfficiency*
```
Regras: setas só descem; Policy zero I/O; Gateway zero decisão; Projector zero mutação; Runtime só com allowed; fail-closed em toda borda; RealExecution↔VerifiedExecution nunca se importam.

## 4. Padrões
1. Um call, um pipeline, um receipt. 2. Writer único por linhagem (dupla escrita transitória canônico-primeiro). 3. Convergência sem drop (aditivo explícito §2.2). 4. Nomes honestos (mockExecute morre; preview declarado). 5. Hash de origem única. 6. Abstração com 2º consumidor provado. 7. Compat por alias datado ≤1 ciclo com CI pós-data.

## 5. Testes do patamar (por fusão)
| Fusão | Prova |
|---|---|
| 1 | um shell.run real via Facade produz no MESMO uuid Invocation(policy_decision_ref)+Receipt+Event; deny nunca toca o ProcessRunner (spy); Evidence down em strict ⇒ blocked. Hoje IMPOSSÍVEL de escrever |
| 2 | certificar o mesmo goal_record_id pelas 2 rotas retorna o MESMO veredicto do Evaluator; contraprova atual (Aver certified sem cert canônica) congelada como characterization |
| 3 | releaseGate() invoca report() exatamente 1x (spy); snapshot byte v1 |
| 4 | após shadow() verificado existe Outcome ligado à decision; replay/policy incluem o loop (hoje: 0 escrita) |

## 6. Ordem de migração (characterization → fatia → compat → esvaziar)
**Regra de ciclo de vida de diretório**: nenhum dir de origem é deletado no ciclo da fatia — retém EXCLUSIVAMENTE o delegador/alias até a data; no ciclo seguinte dir+alias morrem no MESMO commit com CI pós-data. Path-literals re-apontados no commit da própria fatia.
- **F1 Release gate**: snapshot v1 + StubReadinessService → releaseGate() movido + report-count==1 → one-liner → esvazia ciclo seguinte.
- **F2 VCE**: characterization → move + recordOutcome (outcome_ref aditivo) → alias → **literais #1-#6 no mesmo commit** → esvazia depois.
- **F3-pre (NOVO) Seeding de ai_permission_gates ANTES do flip strict**: catálogo declarado dos 19 tools — read-only default-allow (9): workspace.profile, package.detect, file.read, session.search, search.rg, git.status, git.diff, programming.git_diff, programming.code_search; mutadores gate-obrigatório (10): file.write, file.patch, shell.run, git.apply_patch, checkpoint.restore, test.run, programming.test/lint/quality_scan/visual_smoke. Caminhos autônomos transitivos com seed prévio: Orchestrator→EngineeringHarness/TestMatrix→execute('test.run') (L173); AtlasTaskController; diretos: 5 commands + Search (read-only coberto).
- **F3a** Gateways+Provider+Policy (AiPermissionSession migra p/ Gateway — Policy zero-I/O real) + **literal #7**. **F3b** Runtime absorve executor + receipt+event mesmo uuid + **tokens do Scanner #8 re-apontados**. **F3c** Facade + compat (estáticos preservados; benchmark do hot path Search); NoExternalExecutionTest → teste de gate. Dir esvazia depois.
- **F4a** migração aditiva §2.2 → Gateway + dupla escrita. **F4b** Evaluator único. **F4c** Facade + compat 9 callers + **literais #4/#5/#9/#10; delegador retém tokens do SoftwareTwin**. **F4d** debulk do god (2 Evaluators ≤1.400; pode escorregar de ciclo). Tabelas AtlasAver* ficam como espelho.

## 7. Riscos
1. Hot path Search ganha gate DB — decisão cacheada por request p/ read-only; benchmark F3c; rollback via compat. 2. Strict muda comportamento dos callers — F3-pre seeding + breaking change documentado com catálogo por tool. 3. Dupla escrita divergente — canônico primeiro; espelho best-effort com mirror_lag; leitura de certificação NUNCA usa espelho. 4. God entrelaçado com EngineeringCompany — F4d isolada, rg por método antes do corte. 5. Hashes golden — characterization decide quais têm consumidor vivo. 6. Aliases eternos — data + CI pós-data. 7. Dependentes do mockExecute — payload preview congelado com alias de campo. **8. Literal esquecido — o rg do §1.4 re-roda como gate de saída de cada fusão (zero hits fora de dirs de compat). 9. Linhas legadas NULL — permanentemente por-fonte; relatórios pré/pós-fusão declaram o corte, nunca inferem equivalência por hash (provado não-equivalente).**
