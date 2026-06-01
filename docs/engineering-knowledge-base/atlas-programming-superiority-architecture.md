---
id: atlas-programming-superiority-architecture
type: engineering_knowledge
title: Atlas Programming Superiority Architecture
status: source_material
category: programming
priority: 100
summary: Arquitetura canônica interna de excelência programática do Atlas — índice estratégico que explica COMO Atlas Dev + Atlas Forge buscam vantagem sistêmica por RAG governado, world model, contratos, evidence, certificação, multi-agent e compounding. Não autoriza claim externo de superioridade sem benchmark auditado.
tags:
  - atlas-dev
  - atlas-forge
  - programming-superiority
  - dual-core
  - hyperflow
  - 2026-05-18
capabilities:
  - programming_superiority_architecture
  - dev_forge_target_state
  - honest_comparison_methodology
  - canal_unico_enforcement
decisions:
  - Superioridade vem de SISTEMA (RAG+contexto+evidence+certificação+compounding+dual-core), não de prompt melhor.
  - Atlas Dev = núcleo rápido diário; Atlas Forge = núcleo pesado de Obras; sem fusão.
  - "10x/30x/100x" só pode ser declarado COM definição de métrica auditada.
  - Runtime hoje ≠ estado-alvo; este doc separa o que está vivo do que é design.
  - Claim externo contra Claude Code/Codex permanece bloqueado por policy; este doc governa arquitetura interna e backlog.
maintenance:
  - Atualizar quando dual-core route/escalation runtime, Programming readiness ou benchmark policy mudarem.
  - Atualizar quando AiWorker passar a invocar Kernel canônico.
  - Atualizar quando feature flag `atlas_dev_efficient_plan_enabled` ON em produção.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
  - docs/engineering-knowledge-base/atlas-full-architecture-understanding-report.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-programming-superiority-architecture

graph_title: Atlas Programming Superiority Architecture

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-dual-core-engineering-system

graph_status: active

graph_source: repo
human_name: Atlas Programming Superiority Architecture
canonical_name: Atlas Programming Superiority Architecture
technical_name: atlas-programming-superiority-architecture
cartography_type: index
canonical_source: docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md

allowed_changes:
  - Atualizar com novas evidências de implementação.
  - Adicionar mecanismos novos quando provados em código + teste.

forbidden_changes:
  - Declarar superioridade contra Claude Code/Codex sem métrica auditada.
  - Fundir Dev e Forge em runtime único.
  - Apagar a distinção entre runtime atual e estado-alvo.
  - Usar este doc como doc-mae de Agentic Software Engineering, Atlas Code ou Forge Continuum.

depends_on:
  - atlas-dual-core-engineering-system
  - atlas-ai-canonical-architecture-index
  - atlas-architecture-critical-judgment-report
  - atlas-dev-forge-relationship-critical-audit

flows_to:
  - atlas-programming-superiority-contracts
  - atlas-programming-superiority-roadmap

unlocks:
  - implementation_mission_sequencing
  - honest_benchmark_methodology

governs:
  - atlas_programming_superiority

evidence:
  - app/Services/Ai/DualCore/DualCoreRouteDecisionService.php
  - app/Services/Ai/Programming/AtlasDev/AtlasDevFastPathOrchestrator.php
  - app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php
  - app/Services/Ai/Programming/ProgrammingGraphRagRuntime.php
  - app/Services/Ai/Programming/ProgrammingSemanticCodeGraphService.php
  - app/Services/Ai/Programming/ProgrammingPatchVerifier.php
  - app/Services/Ai/Programming/ProgrammingGapCritic.php
  - app/Services/Ai/Programming/ProgrammingRepairExecutor.php
  - app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/DualCore/DualCoreRouteDecisionServiceTest.php tests/Unit/Ai/Programming/AtlasDev/Schemas/Receipt/EscalationPacketTest.php"

requires_evidence: true

risk_level: high

next_actions:
  - Consolidar todos os paths Dev→Forge restantes no `atlas.dev_to_forge.escalation_packet.v1`.
  - Garantir que route decisions sejam emitidas por todas as entradas relevantes, não apenas promotion/handoff.
  - Endurecer `ToolPolicyBridgeService` e `ToolReceiptService` (remover fallback silencioso).
  - Enriquecer `MissionCertificationService::runChecks` com checks quality-aware (hoje shape-only).
  - Consolidar 4 mecanismos paralelos de escalação Dev→Forge em path único.
  - Promover Atlas Dev em produção (feature flag `atlas_dev_efficient_plan_enabled` ON; Fatia 5 surface).

---
# Atlas Programming Superiority Architecture

## Resumo
**Tese central:** Atlas Dev + Atlas Forge **não superam Claude Code/Codex por
prompt** — superam por **sistema**. A vantagem real vem de 11 classes operacionais
que provedor cru não tem: contexto persistente, RAG mandatório, codebase world
model, roteamento multi-flow, dual-core (Dev rápido + Forge pesado),
evidence-as-truth, certificação quality-aware, repair loop com classifier,
compounding memory, benchmarking honesto e canal único que faz Evidence + Curador
aprenderem com cada uso (`atlas-ai-thesis-multiplier-channel.md:123-149`).

**Veredicto operacional (2026-05-18):**
- Foundation técnica existe: 138 files `AtlasDev/` + ~75 files `AtlasForge*` /
  `ForgeRivals/` / `AtlasCodeForge*` + 11 services Compounding + 7 classes PEVR
  + 8 schemas RAG canônicos + 3 models World Model.
- **Schema 1 do dual-core (`atlas.dual_core.route_decision.v1`) shipped** —
  `DualCoreRouteDecisionService` + testes e callers reais em promotion/handoff.
- **Schema 2 (`atlas.dev_to_forge.escalation_packet.v1`) shipped** —
  `EscalationPacket` + factory + handoff/promotion tests.
- AiWorker continua bypassing Kernel canônico (Phase 2 audit, ainda válido).
- **Não declaramos superioridade externa** até benchmark com métrica auditada provar.

Este doc é índice. Detalhes contratuais em
`atlas-programming-superiority-contracts.md`; fases e missões em
`atlas-programming-superiority-roadmap.md`.

## Papel no Atlas
Existe para servir 3 audiências:

1. **Arquiteto-chefe (humano ou IA):** entender qual o estado-alvo do domínio
   programming dentro do Atlas AI e o que precisa ser construído para chegar lá.
2. **IA implementadora posterior:** saber sem ambiguidade qual contrato/schema usar,
   qual serviço reutilizar, qual armadilha evitar (4 mecanismos paralelos
   de escalação Dev→Forge é a maior).
3. **Operador medindo progresso:** distinguir "shipé schema" de "shipé produto"
   — cada mecanismo de vantagem tem evidência esperada e estado real.

Não substitui `atlas-dual-core-engineering-system.md` (lei). Complementa.

## Onde Se Encaixa
Layer 0.72 (Programming) do canonical index. Filho de
`atlas-dual-core-engineering-system` no graph. Estrutura cognitiva:

```
Layer -1: Multiplier Channel thesis
  Layer 0: Identidade Atlas AI (sistema, não prompt)
    Layer 0.9: Autonomous Intelligence OS (multi-domínio)
      Layer 0.72: Programming Governance → ESTA DOC GOVERNA AQUI
        ├─ Atlas Dev (núcleo rápido, 138 files)
        ├─ Atlas Forge (núcleo pesado, ~75 files)
        └─ Dual-Core boundary (route_decision.v1 + escalation_packet.v1)
```

Supõe Layer 1 Kernel + Meta 1-9 disponíveis (mission/router/policy/tool/
evidence/certification/control plane). Confirmar via
`atlas-full-architecture-understanding-report.md` (Phase 1) que estão vivos.

## Contratos
Ver `atlas-programming-superiority-contracts.md` para field shapes completos. Aqui só o catálogo dos 14 schemas que governam superioridade programática:

| # | Schema | Status hoje |
|---|--------|-------------|
| 1 | `atlas.dual_core.route_decision.v1` | ✅ shipped + callers em promotion/handoff |
| 2 | `atlas.dev_to_forge.escalation_packet.v1` | ✅ shipped + factory/handoff/promotion tests |
| 3 | `atlas.programming.intent_classification.v1` | ⚠️ via Router IntentKernelService |
| 4 | `atlas.programming.context_pack.professional.v1` | ✅ `ProgrammingRetrievalExecutor:148` |
| 5 | `atlas.programming.agentic_rag.professional_plan.v1` | ✅ `ProgrammingRetrievalPlanner:104` |
| 6 | `atlas.programming.context_sufficiency_gate.v1` | ⚠️ computado, não enforced |
| 7 | `atlas.programming.graph_rag_runtime.v1` | ✅ `ProgrammingGraphRagRuntime:44` |
| 8 | `atlas.programming.semantic_code_graph.context.v1` | ✅ `ProgrammingSemanticCodeGraphService:81` |
| 9 | `atlas.programming.test_impact.receipt.v1` | ✅ `ProgrammingTestImpactAnalyzer:40-56` |
| 10 | `atlas.programming.patch_verifier.report.v1` | ✅ `ProgrammingPatchVerifier:11-96` |
| 11 | `atlas.programming.agentic_rag.gap_critic.v1` | ✅ `ProgrammingGapCritic:13-46` |
| 12 | `atlas.programming.repair_capsule.v1` | ✅ `ProgrammingRepairExecutor:12-35` |
| 13 | `atlas.programming.retrieval_eval.v1` | ✅ `ProgrammingRetrievalEvaluator:26` |
| 14 | `atlas.ai.compounding.{outcome,learning_candidate,memory,heuristic_update,benchmark_case}.v1` | ✅ 11 services em `app/Services/Ai/Compounding/` |

14 de 14 contratos catalogados possuem implementação observável, com
qualificação: #6 ainda é computado sem enforcement obrigatório em todos os
flows, e alguns callers dual-core ainda precisam ser consolidados.

## Fluxo
**Fluxo canônico de superioridade** (estado-alvo, não produção hoje):

```
Prompt
  ↓
Intent Kernel (classifica)
  ↓
Dual-Core Router (emite route_decision.v1)
  ↓                                                              ↓
Atlas Dev (fast path)                                       Atlas Forge (Obras)
  ↓                                                              ↓
Super RAG Spine (mandatory RAG gate)                       SDD intake
  ↓                                                              ↓
Codebase World Model (graph + symbols + tests + docs)      Obra/WorkItem/Packet
  ↓                                                              ↓
Context Pack hashed + ranked refs                          Multi-agent dispatch
  ↓                                                              ↓
Plan-Execute-Verify-Repair Loop                            Long-horizon execution
  ↓                                                              ↓
Patch + Receipt + ScopeGuard + VerificationGate            Provider topology + fallback
  ↓                                                              ↓
Evidence Pack + Certification (quality-aware)              EvidencePack + Continuum Certification
  ↓                                                              ↓
Compounding loop (outcome→distiller→memory candidate→promotion gate)
  ↓
Control Plane snapshot (operador vê estado)
```

**Fluxo de produção HOJE** (`atlas-architecture-critical-judgment-report.md`):
`HTTP → AiWorker → AiProviderManager → Provider direto + AtlasProgrammingOrchestrator`. **Não passa pelo canônico**. Ver Riscos #1.

## Regras para IA
Invariantes para qualquer IA implementando deste doc:

1. **Não claim "10x/30x/100x"** sem definição de métrica + benchmark auditado
   (ver Evidencias §Honest Comparison Methodology).
2. **Não fundir Dev e Forge** — dual-core proibido fundir (`atlas-dual-core-engineering-system.md:67-72`).
3. **Não criar 5º mecanismo de escalação** — já há 4; consolidar, não adicionar.
4. **Mandatory RAG gate**: para flows repair/forge/frontend/security/database,
   bloquear execução se `context_sufficiency_gate.status == failed_closed`.
5. **Não auto-promover memória** — cognitive immune kernel 8 gates G0–G8
   (`memory/cognitive-immune-learning-kernel.md:193-206`).
6. **Patch sem teste OU razão explicitável** = blocked
   (`ProgrammingPatchVerifier.php:72-81`).
7. **Repair com `failure_hash` repetido** = blocked
   (`ProgrammingRepairExecutor.php:14-17`).
8. **Certification sem evidência** = throw exception
   (`MissionLifecycleService:121-131`).
9. **Tool externo high-risk sem `allow` em policy** = blocked
   (`atlas-tool-economy.md:151`).
10. **Live trading bloqueado, cyber ofensivo bloqueado, marketing publish requer approval** (invariantes globais).
11. **Forge não depende de Dev** — verificado: 0 imports
    (`rg "use.*AtlasDev" app/Services/Ai/Programming/AtlasForge*.php` → vazio).

## Escopo de Implementacao
Esta trinity (3 docs) cobre **design + plano**, não código. Implementação em
missões separadas listadas em `atlas-programming-superiority-roadmap.md`.

| Doc | Escopo |
|-----|--------|
| `atlas-programming-superiority-architecture.md` (este) | Índice estratégico + tese + comparation + top 15 mecanismos + top 15 gaps + riscos. ~300 linhas. |
| `atlas-programming-superiority-contracts.md` | 14 schemas com field shapes + Super RAG Spine + Codebase World Model + Evidence grammar + tabelas DB + APIs/comandos. ~500 linhas. |
| `atlas-programming-superiority-roadmap.md` | Dev target + Forge target + PEVR loop + multi-agent + compounding + métricas + 10-phase roadmap + 10 missões + DoD. ~500 linhas. |

**Fora de escopo:** mudança de código, refactor, novo Company Runtime, UX dev,
configuração de driver real (Claude/Codex/Gemini CLI bloqueados por policy).

## Dependencias
- `atlas-dual-core-engineering-system.md` (lei do dual-core; não pode ser violada).
- `atlas-ai-canonical-architecture-index.md` (hierarquia de autoridade).
- `atlas-architecture-critical-judgment-report.md` (estado real do Kernel canônico, ainda não em produção).
- `atlas-dev-forge-relationship-critical-audit.md` (estado real Dev↔Forge, 4 mecanismos paralelos).
- `atlas-full-architecture-understanding-report.md` (estado real Meta 1-9 backend).
- `programming-agentic-rag-professional-spec.md` (lei do RAG profissional).
- `atlas-compounding-engineering-intelligence.md` (lei do compounding).

## Evidencias
### Honest Comparison Methodology
Para declarar superioridade vs Claude Code/Codex é obrigatório:

1. **Métrica definida e medida em ambos os lados** (ex: % tasks completed
   sem clarificação, time-to-first-correct-patch, bug recurrence rate em
   30 dias, repair success rate, retrieval precision/recall, evidence
   completeness, human intervention count, Forge long-horizon completion).
2. **Benchmark suite externo** (`atlas-forge-rivals-*` 20 docs + 44 services
   `ForgeRivals/`) executando casos idêlnticos em ambos sob a mesma
   workload.
3. **Atestation receipt** assinado pelo `AtlasCompoundingTemporalCertificationService`.
4. **Período de observação** suficiente (mínimo 30 dias rolling).

Sem isso, qualquer "Nx" é marketing, não engenharia.

### Onde Claude Code/Codex são fortes hoje
- Velocidade de prompt cru sem overhead.
- Modelo state-of-the-art atualizado.
- Ecossistema de ferramentas e plugins maduro.
- Distribuitíssimo — milhões de operadores treinando o próprio prompt.

### Onde Claude Code/Codex falham
- **Sem memória persistente cross-session** (cada sessão começa do zero).
- **Sem RAG mandatório governado** (retrieval ad-hoc, sem gate).
- **Sem codebase world model estruturado** (grep + heurística).
- **Sem evidence ledger auditavel** (logs efeméros).
- **Sem certification quality-aware** (sucesso = "não erroü").
- **Sem dual-core split** (mesmo motor para bug local e Obra de 3 meses).
- **Sem compounding learning auditado** (não melhora com uso).
- **Sem multi-agent governance** (cada agente reinventa contrato).
- **Sem repair classifier** (loop infinito possível).
- **Sem canal único** — cada uso direto perde Evidence.

### 11 classes de vantagem do Atlas
1. **Contexto persistente** (Memory Registry + Verbatim Store + Open Brain).
2. **RAG mandatório governado** (8 schemas canônicos; gate computado).
3. **Codebase World Model** (graph nodes + edges + queries).
4. **Routing multi-flow** (dev/forge/research/explain/debug/review/conversation).
5. **Dual-core split** (Dev rápido + Forge pesado).
6. **Evidence-as-truth** (EvidencePack + Receipt + 11 contratos canônicos).
7. **Certificação quality-aware** (aspiracional; hoje shape-only).
8. **Repair loop com classifier** (8 modos de falha + stop on same_signature_twice).
9. **Compounding memory** (11 services, outcome→distiller→memory).
10. **Benchmarking honesto** (Rivals harness paralelo).
11. **Canal único** (cada uso passa por Evidence Ledger).

### Top 15 mecanismos de vantagem (concretos)

| # | Mecanismo | Vantagem operacional | Onde |
|---|-----------|----------------------|------|
| 1 | DualCoreRouteDecisionService | route auditavel dev/forge/dev\_to\_forge | `app/Services/Ai/DualCore/` |
| 2 | Mandatory RAG gate (fail-closed strict flows) | bloqueia patch sem contexto | `ProgrammingRetrievalPlanner:137-141` |
| 3 | Semantic Code Graph (filesystem + Code Intelligence) | grep substituído por grafo | `ProgrammingSemanticCodeGraphService` |
| 4 | Graph RAG runtime com AP-683 boundary | escopo policy explícito | `ProgrammingGraphRagRuntime` |
| 5 | Context Pack hashed + replay | reprodução determinística | `ProgrammingContextPackStore` |
| 6 | Professional Reranker | source/flow bonus + noise penalty | `ProgrammingProfessionalReranker` |
| 7 | Test Impact Analyzer | testes por impacto, não chute | `ProgrammingTestImpactAnalyzer` |
| 8 | Patch Verifier | secret guard, blast radius, rollback | `ProgrammingPatchVerifier` |
| 9 | Gap Critic | detecta contradição e fontes ausentes | `ProgrammingGapCritic` |
| 10 | Repair Executor + Failure Mode Classifier | 8 modos + stop on repetition | `ProgrammingRepairExecutor` + `FailureModeClassifier` |
| 11 | Senior Engineer Loop Auditor (7 capabilities) | gate de qualidade senior | `SeniorEngineerLoopAuditor` |
| 12 | Verification Gate + Completion State Gate | honesty invariants enforced | `AtlasDev/Gate/` |
| 13 | Compounding loop (11 services) | aprender com cada execução | `app/Services/Ai/Compounding/` |
| 14 | Forge governed execution + dispatch | dry-run default + 3-approval | `AtlasForge*RuntimeDispatch*` |
| 15 | Continuum certification (37 invariants) | audit estrutural | `AtlasForgeContinuumCertificationService` |

### Top 15 gaps atuais que impedem superioridade

| # | Gap | Severidade | Evidencia |
|---|-----|-----------|-----------|
| 1 | AiWorker bypassa Kernel canônico (não invoca Mission/Router/Policy) | P0 | `AiWorker.php` constructor 23 deps, nenhuma kernel |
| 2 | DualCore route decisions ainda nao cobrem todas as entradas | P0 | callers existem em promotion/handoff; expandir para entradas restantes |
| 3 | EscalationPacket v1 existe, mas paths paralelos ainda precisam convergir | P0 | `EscalationPacket` + factory + promotion/handoff |
| 4 | Mecanismos paralelos de escalação Dev→Forge ainda exigem consolidação | P1 | auditorias e readiness de programming |
| 5 | Mandatory RAG gate computado mas não enforced | P1 | `ProgrammingRetrievalPlanner:137-141` (status retornado, não jogado) |
| 6 | MissionCertificationService::runChecks shape-only (count>0) | P1 | `MissionCertificationService:94-120` |
| 7 | Tool Policy/Evidence bridges com fallback silencioso | P1 | `ToolPolicyBridgeService:62-71`, `ToolReceiptService:90-96` |
| 8 | Drivers reais Claude/Codex/Gemini CLI bloqueados (`provider_driver_missing`) | P1 | `AtlasForgeProviderInvocationDriverRouter` |
| 9 | Sem feedback loop retrieval→uso→repromote | P2 | nenhum código observado |
| 10 | Sem multi-agent scheduler dedicado | P2 | Router + Specialist Flows; sem classe |
| 11 | Code World Model não usa edge weights no ranking | P2 | `ProgrammingProfessionalReranker` não consulta grafo |
| 12 | Local Agent Memory Ingestion bloqueado | P2 | spec `atlas-local-agent-memory-ingestion.md` |
| 13 | Forge OS parent docs com status desatualizado vs código em produção | P2 | `atlas-forge-operating-system.md` |
| 14 | Rivals fora do completion loop (sem learning→policy) | P3 | 44 services + 20 docs, sem ponte |
| 15 | Naming proliferation Forge (8 nomes) | P3 | `atlas-dev-forge-relationship-critical-audit.md` |

## Riscos
1. **Runtime canônico sem path único vs path legado:** Kernel canônico shipé sem produção cria
   2 fontes de verdade. Cada commit afasta. → Wire AiWorker urgente.
2. **Schema com cobertura parcial:** Schema 1 dual-core tem consumidores reais,
   mas ainda precisa cobrir todas as entradas relevantes. → expandir route
   decision sem criar schema paralelo.
3. **5º mecanismo de escalação:** novo dev cria 5º path em vez de consolidar.
   → Doc esta proibida (`forbidden_changes`).
4. **Driver real configurado sem governance:** habilitar Claude/Codex/Gemini CLI
   sem 3-approval + budget gate → exploit de superfície real.
5. **Compounding auto-aplicar:** AtlasCompoundingHeuristicEvolution sem review →
   muda comportamento crítico em prod.
6. **Falsa superação:** equipe declara "Atlas é 30x" sem benchmark; perde
   credibilidade externa.
7. **Forge virar Dev mini ou Dev virar Forge mini:** violação dual-core.
   → `forbidden_changes` no doc canon.
8. **Naming proliferation:** novo "Atlas Code X" sem consolidar 8 nomes existentes.
9. **RAG ruidoso por falta de feedback:** sem retrieval→uso→repromote, refs
   ruins persistem e contaminam Context Pack.
10. **Evidence theater:** ship contratos mas não checar qualidade → certificação
    vira shape-only (já acontece em Mission).

## Exemplos
**Trace estado-alvo (não produção ainda)** para prompt "implementa endpoint
/foo com testes":

1. `IntentKernelService` classifica: intent_type=DEV, ambiguity=low, risk=low.
2. `DomainRouterService` rota → `primary_domain=programming`.
3. `FlowRouterService::decideFlow` → `flow_id=atlas_dev`.
4. `DualCoreRouteDecisionService::recordFromFlowRoute` → emite
   `atlas.dual_core.route_decision.v1` (`route=dev`, evidence_required=[plan,receipt,verification]).
5. `AtlasDevFastPathOrchestrator` recebe handoff.
6. `ProgrammingRetrievalPlanner` decompõe em queries; consulta
   `ProgrammingSemanticCodeGraphService` + `ProgrammingLocalVectorIndex`;
   reranker emite `context_pack.professional.v1` com refs hashed.
7. `ProgrammingGapCritic` verifica context_sufficiency_gate → `passed`.
8. `SeniorEngineerLoopAuditor` confirma 7 capabilities.
9. Provider invocado com prompt + context pack provider-safe.
10. Patch retornado; `ProgrammingPatchVerifier` checa scope/secret/rollback.
11. `VerificationGate` roda testes; `CompletionStateGate` aplica honesty
    invariants.
12. `MissionEvidenceService` atacha receipts; `MissionCertificationService`
    valida; `MissionLifecycleService::transition(completed)`.
13. `AtlasCompoundingOutcomeEvaluator` mede outcome; `AtlasLearningDistiller`
    distila; candidatos vão para promotion gate.

## Proximas Acoes
Detalhe em `atlas-programming-superiority-roadmap.md`. Resumo das 5 missões
mais críticas (todas P0/P1, sequencial-mandatórias):

1. **M1 — Expandir route_decision.v1 para todas as entradas relevantes.**
   Callers existem em promotion/handoff; falta garantir cobertura completa sem
   schema paralelo.
2. **M2 — Consolidar `atlas.dev_to_forge.escalation_packet.v1` como packet único.**
   Schema 2 existe; paths auxiliares devem virar wrappers/adapters finos.
3. **M3 — Consolidar 4 mecanismos paralelos de escalação em 1.** (12-18h)
   Eleger `AtlasForgeHandoffAdapter::promote` (Meta 2-based) como canônico;
   deprecar os outros 3 com aviso.
4. **M4 — Endurecer ToolPolicyBridge + ToolReceiptService (strict mode).** (8-12h)
   Remover fallback silencioso; exigir log + alarme; quebrar em prod se Policy
   ou Evidence Runtime indisponíveis.
5. **M5 — Enriquecer MissionCertificationService::runChecks (quality-aware).** (10-16h)
   Adicionar `quality_score`, `source_verification`, `claim_verification`;
   recusar certificação se evidência trivial.

Próximas 5 (M6-M10) em `atlas-programming-superiority-roadmap.md` cobrem:
AiWorker → Kernel integration, Mandatory RAG gate enforcement, multi-agent
scheduler primeira encarnação, compounding feedback loop, benchmark methodology
shipping.

**Gates de fechamento desta entrega:**
- `php artisan atlas:engineering:knowledge docs-health --json` (0 violations).
- `git diff --check` (limpo).
- Trinity completa: este doc + contracts + roadmap.
