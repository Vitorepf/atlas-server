# ARCH BLUEPRINT — Runtime de Execução

> status: draft-REFUTADO (verify adversarial 2026-07-22) — redesign em curso
> FURO FATAL 1: correlação da Fusão 2 usa objective_hash/diff_hash que NÃO existem nos models AiRealExecution* (confirmado); canônico não tem command-ledger → Evaluator "command∧diff∧test" inavaliável como especificado. Exige migração aditiva explícita OU mapeamento goal↔objective.
> FURO FATAL 2: ≥9 consumidores por PATH-LITERAL (File::exists/fileCheck/token-scan) em 3 fusões — delete-dir quebra certificações de produto e gates de arquitetura em silêncio (ex.: Product PATH_AVCEL_SERVICE L151, ProgrammingRuntimeReadiness L442, KernelScanner tokens L9762-9815); contradição interna na fatia 4 (delete dir vs delegador congelado no mesmo dir).
> EMENDAS: CandidateRoleReceiptEvaluator teto 2000 < ~2400 (pariria godfile) · AiPermissionSession read precisa entrar no Gateway (Policy zero-I/O) · seeding de ai_permission_gates pré-flip strict (caminhos autônomos transitivos: Orchestrator→Harness→test.run, AtlasTaskController) · VCE tem 8 consumidores (não 2/3) · compat deve preservar estáticos (availableTools).
> data: 2026-07-22
> obra: GOD Debulk / capability Runtime de Execução
> insumos: CONSOLIDATION-MAP.md (fusões proposed do cluster RUNTIME)
> modelo: SelfConstructionReadiness.md

## 1. Contexto provado

### 1.1 Estado atual (verificado por leitura + rg)

| Bloco | LOC | Files | Papel real provado |
|---|---|---|---|
| `Runtime/` | 2.879 | 14 | Executor **real** de 19 tools (`AiToolRuntime::execute` L75-96: file.write, shell.run, git.apply_patch, test.run...). Permission engine própria, audit em `AiToolEvent` idempotente. **Sem receipt governado, sem policy-gate de `ai_permission_gates`** (0 refs a AiToolReceipt — verificado). |
| `ToolRuntime/` | 2.080 | 15 | Pipeline **governado mas mock**: policy gate strict fail-closed → `mockExecute` ("No real external side effects") → receipt fail-closed. Models `AiToolDefinition/Invocation/Receipt`. |
| `RealExecution/` | 6.431 | 8 | Delivery kernel real sobre 8 models `AiRealExecution*`. God interno: `AtlasRealEngineeringExecutionKernelService` (3.989 LOC) mistura linhagem de execução com ~2.400 LOC de famílias `candidate*` do EngineeringCompany. |
| `VerifiedExecution/` | 1.044 | 2 | AVER: 2º ledger patch→test→repair→certify sobre 6 models `AtlasAver*`; `certify()` com regra própria (L422-424). |
| `RuntimeReadiness/` | 857 | 1 | `report()` + `uxBundle()` agregando ~12 services; controller + command. |
| `RuntimeReleaseGate/` | 263 | 1 | Wrapper puro ("Delega 100%"); re-emite com `certification_hash` próprio sobre o hash do readiness (duplo-hash). 2 callers. |
| `RuntimeEfficiency/` | 2.269 | 4 | Governor de path/custo: `govern/recordOutcome/compilePolicy/counterfactualReplay` sobre models próprios + `EfficiencyOutcomeRecorder`. |
| `VerifiedContextExecution/` | 442 | 1 | Loop `shadow()/certify()` com deps 100% do RuntimeEfficiency. **Verificado: shadow() não grava nenhum outcome (0 refs)** — o aprendizado morre no payload. |

### 1.2 As quatro fraturas

1. **Execução real sem governança / governança sem execução** — nenhum call real hoje sai com receipt governado.
2. **Dois ledgers de evidência para o mesmo ato** — o mesmo diff pode sair `certified` num e `failed`/ausente no outro; consumidores divergem (Hyperflow/Conductor/StrategicOS leem AVER; Obra/Mission leem RealExecution).
3. **Duplo-hash do release gate** — dois hashes "canônicos" para um único estado.
4. **Loop verificado que não ensina o governor** — `compilePolicy`/`counterfactualReplay` aprendem só do caminho não-verificado.

### 1.3 Consumo externo real
- Runtime real: `AtlasRuntimeCommand`, `EngineeringTestMatrixService`, `SkillDiscoveryService`, `AtlasCliQualityService`, `AtlasCliCheckpointService` + hot path Search. Interface crítica: `execute(ToolInvocation): ToolResult`.
- ToolRuntime mock: command + `ProgrammingToolBridge` (2 callers) + 14 feature tests que congelam hashes/receipts/strict.
- AVER: 9 callers (commands aver, ChangeOrchestrator, AWEOS, HyperflowEntry, CertifierClassificationLedger, RunConductor, StrategicOS, binding AppServiceProvider:1147). Interface crítica: 10 métodos array-in/out + status `blocked`.
- RealExecution: `DeliverAtlasMissionJob`, commands Obra/Mission/Deliver, ObraExecutor, binding.
- ReleaseGate: command + `AtlasPreBenchmarkReadinessService` — compat crítica = schema `atlas.ai.runtime_release_gate.v1`.
- VCE: command + `AtlasCognitionScoreCardService`.

## 2. Owners-alvo

Namespaces-destino (4 sobreviventes): `Ai/Runtime/`, `Ai/RealExecution/`, `Ai/RuntimeReadiness/`, `Ai/RuntimeEfficiency/`.

### 2.1 Fusão 1 — ToolRuntime ⇒ Runtime (execução real governada com receipt)

| Classe | Sufixo | Responsabilidade | Teto |
|---|---|---|---|
| `ToolExecutionFacade` | Facade | Único entrypoint: `run(toolId,input,ctx)` / `runPreview(...)`. Sequência fixa: resolve (Provider) → decide (Policy) → executa (Runtime) → receipt (Gateway). Zero lógica. | ≤800 |
| `ToolExecutionPolicy` | Policy | Decisão pura fail-closed: funde permission engine local + veredicto do gate. Campo ausente/bridge down em strict ⇒ blocked. Zero I/O. | 500 |
| `ToolPolicyGateway` | Gateway | Único I/O do gate (`ai_permission_gates`/`ai_policy_profiles`; absorve ToolPolicyBridgeService, preserva strict). | 350 |
| `ToolExecutionRuntime` | Runtime | Executor real dos 19 tools (absorve o corpo de AiToolRuntime::execute). Modo `preview` honesto substitui mockExecute. Só executa com `PolicyDecision::allowed` — por construção. | 1.800 |
| `ToolReceiptGateway` | Gateway | Único writer de evidência: `AiToolReceipt` (bridge Evidence, fail-closed) + `AiToolEvent` no MESMO uuid. Execução sem receipt durável ⇒ blocked. | 600 |
| `ToolCatalogProvider` | Provider | Registry/definições/seeds/catálogo; valida na carga: definição executável mapeia p/ tool real; sem executor ⇒ só preview. | 700 |
| `ToolRuntimeProjector` | Projector | Read-only: health/control-plane/readiness/planning. | 700 |

Compat ≤1 ciclo: `AiToolRuntime::execute` delega à Facade (assinatura preservada p/ 6 callers + Search); `ToolInvocationService::invoke` delega com `mode=preview` `@deprecated`. Dados sem drop: `AiToolInvocation` canônico; `AiToolEvent` em dupla escrita correlacionada até migração aprovada.

### 2.2 Fusão 2 — VerifiedExecution (AVER) ⇒ RealExecution (linhagem única)

| Classe | Sufixo | Responsabilidade | Teto |
|---|---|---|---|
| `ExecutionEvidenceFacade` | Facade | Superfície AVER preservada 1:1 (plan/runCommand/verifyDiff/runTest/repair/certify/controlPlane) + entrada do fluxo kernel. | ≤800 |
| `ExecutionEvidenceGateway` | Gateway | Único writer: `AiRealExecution*` canônico + espelho `AtlasAver*` (dupla escrita correlacionada por objective_hash/diff_hash). SEM drop de tabela. | 900 |
| `ExecutionCertificationEvaluator` | Evaluator | A regra de certificação ÚNICA (funde os dois certify): certified ⇔ command∧diff∧test passed no ledger canônico; fail-closed. Um veredicto por diff_hash por construção. | 800 |
| `ExecutionLineageProjector` | Projector | controlPlane/readiness/claimPolicy read-only sobre view unificada. | 700 |
| `RealExecutionKernelRuntime` | Runtime | Debulk do god: só orquestração worktree→patch→test→repair + delivery pack + handoff. Certificação → Evaluator; escrita → Gateway. | 1.800 |
| `CandidateRoleReceiptEvaluator` | Evaluator | Extração das famílias `candidate*` (~2.400 LOC EngineeringCompany dentro do kernel); re-home futuro avaliado fora desta obra. | 2.000 |

Compat: `AtlasVerifiedExecutionRuntimeService` congela como delegador (9 callers + binding intactos); `atlas:aver*` mantêm flags/schema; `AtlasSoftwareTwinRuntimeService` (cita path literal L69) atualizado na mesma fatia.

### 2.3 Fusão 3 — RuntimeReleaseGate ⇒ RuntimeReadiness

`AtlasAiRuntimeReadinessService` ganha `releaseGate(): array` produzindo o schema v1 **a partir do payload de `report()` computado 1x em memória** — hash de origem única. Compat: service antigo vira one-liner `@deprecated`; snapshot byte-compat do schema; dir deletado ao fim do ciclo. Teto: 1.200 (857+~250).

### 2.4 Fusão 4 — VerifiedContextExecution ⇒ RuntimeEfficiency

`VerifiedContextLoopService` (move+rename) em `RuntimeEfficiency/`: ao fim de cada `shadow()` verificado, grava outcome via `recordOutcome` com `decision_ref` — o MESMO store que alimenta replay/policy. Compat: class_alias datado; dir deletado. Teto: 600.

## 3. Grafo one-way

```
Commands / Controllers / Jobs
  │
  ▼
Facades/Services de topo: ToolExecutionFacade · ExecutionEvidenceFacade ·
AtlasAiRuntimeReadinessService(releaseGate) · Governor · VerifiedContextLoopService
  │        compat ≤1 ciclo: AiToolRuntime→Facade · ToolInvocationService→Facade(preview) ·
  │        AVER service→Facade · ReleaseGate→releaseGate()
  ▼
Policies (puras): ToolExecutionPolicy
Evaluators: ExecutionCertification · CandidateRoleReceipt
Projectors: ToolRuntime · ExecutionLineage
Runtimes (mutação explícita): ToolExecutionRuntime · RealExecutionKernelRuntime
  │
  ▼
Gateways/Providers: ToolPolicyGateway · ToolReceiptGateway · ExecutionEvidenceGateway · ToolCatalogProvider
  │
  ▼
Models: AiToolDefinition/Invocation/Receipt/Event · AiRealExecution*(canônico) ·
AtlasAver*(espelho até migração) · AtlasRuntimeEfficiency* · AiToolProcessRunner · WorkspaceProfiler
```

Regras: setas só descem; Policy zero I/O; Gateway zero decisão; Projector zero mutação; Runtime só executa após `allowed`; fail-closed em toda borda; RealExecution↔VerifiedExecution nunca se importam — só Facade+Gateway conhecem os dois conjuntos durante a dupla escrita.

## 4. Padrões aplicados

1. **Um call, um pipeline, um receipt** — impossível executar sem decidir; impossível `succeeded` sem receipt durável.
2. **Writer único por linhagem de evidência** — dupla escrita transitória correlacionada por hash canônico, num único lugar.
3. **Convergência sem drop** — canônico `AiRealExecution*`/`AiToolInvocation`; espelhos viram adapter de leitura até migração aprovada.
4. **Nomes honestos** — `mockExecute` morre; modo sem efeito = `preview` declarado no receipt.
5. **Hash de origem única** — release gate e certificação derivam de UM payload/ledger.
6. **Abstração com 2º consumidor provado** — 9+6 consumidores dos dois lados da linhagem.
7. **Compat por alias datado ≤1 ciclo** com verificação de CI pós-data.

## 5. Mapa fusão → ganho (teste do patamar)

| Fusão | Ganho | Teste do patamar |
|---|---|---|
| ToolRuntime⇒Runtime | execução real GOVERNADA c/ receipt | um `shell.run` real via Facade produz no MESMO uuid: Invocation c/ policy_decision_ref + Receipt durável + Event. Contraprova: deny nunca toca o ProcessRunner (spy); Evidence down em strict ⇒ blocked. Hoje esse teste é IMPOSSÍVEL de escrever. |
| AVER⇒RealExecution | UMA linhagem | certificar o mesmo diff_hash pelas 2 rotas retorna o MESMO veredicto do Evaluator. Contraprova de hoje (characterization pré-fatia): AtlasAverCertifiedExecution certified com AiRealExecutionCertification ausente para o mesmo diff. |
| ReleaseGate⇒Readiness | um estado, um hash | `releaseGate()` invoca `report()` exatamente 1x (spy); hash deriva do mesmo array; snapshot byte-compat v1. |
| VCE⇒Efficiency | loop ensina a decisão | após shadow() verificado existe Outcome ligado à decision, e replay/policy incluem o loop. Hoje: zero escrita (verificado). |

## 6. Ordem de migração (characterization → fatia → compat → delete)

**Fatia 1 — Release gate** (menor risco, 263 LOC): snapshot v1 + StubReadinessService existente → `releaseGate()` movido (não reescrito) + report-count==1 → compat one-liner → delete dir.
**Fatia 2 — Verified context loop** (442 LOC, 3 callers): characterization → move + `recordOutcome` (campo `outcome_ref` aditivo) → class_alias → delete dir.
**Fatia 3 — Tool runtime governado** (coração): 3a Gateways+Provider+Policy (re-embalagem) · 3b Runtime absorve executor + receipt+event mesmo uuid (teste do patamar 1) · 3c Facade + compat (6 callers + Search com benchmark antes/depois) · delete `ToolRuntime/` + corpo antigo; `NoExternalExecutionTest` substituído explicitamente pelo teste de gate ("nunca executa sem allow").
**Fatia 4 — Linhagem única** (maior risco, por último): 4a Gateway + dupla escrita · 4b Evaluator único (teste do patamar 2) · 4c Facade + compat 9 callers + SoftwareTwin path · 4d debulk do god (CandidateRoleReceipt extraído; pode escorregar de ciclo sem quebrar) · delete `VerifiedExecution/`; tabelas AtlasAver* ficam como espelho até migração aprovada.

## 7. Riscos

1. **Hot path Search** ganha gate DB — decisão cacheada por request p/ read-only; benchmark 3c; rollback via compat.
2. **Strict no executor real muda comportamento dos 6 callers** (hoje executam sem gate) — é o objetivo; breaking change documentado da fatia 3 com classificação por tool (read-only pode ter default allow declarado; mutadores nunca).
3. **Dupla escrita divergente** — canônico primeiro; espelho best-effort com `mirror_lag`; leitura de certificação NUNCA usa espelho.
4. **God kernel entrelaçado com EngineeringCompany** — sub-fatia 4d isolada, por último, rg de callers por método antes do corte.
5. **Hashes estáveis** — characterization decide quais têm consumidor vivo (byte-compat); resto ganha schema_version novo.
6. **4 aliases simultâneos** — data de remoção + CI falha pós-data.
7. **Dependentes do mockExecute** — só 2 callers provados; payload preview congelado com alias de campo 1 ciclo.
