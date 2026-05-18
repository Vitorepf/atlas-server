---
id: atlas-full-architecture-understanding-report
type: engineering_knowledge
title: Atlas Full Architecture Understanding Report
status: active
category: architecture
priority: 85
summary: Auditoria leitor (READ-ONLY) que cruza 40 docs canônicas com o estado real do backend em /atlas-server. Mapeia o que é o Atlas AI, camadas, domínios, invariantes, gaps e incertezas em 2026-05-18.
tags:
  - atlas-ai
  - architecture-audit
  - cross-doc-synthesis
  - meta-roadmap-snapshot
  - 2026-05-18
capabilities:
  - audit_architecture
  - distinguish_documented_vs_implemented
  - flag_naming_confusion
  - report_gaps
decisions:
  - Relatório de auditoria, não altera contratos.
  - Toda afirmação ancorada em path:linha ou comando.
  - Diferencia documentado / implementado / testado / certificado.
maintenance:
  - Regenerar quando Meta nova for promovida.
  - Não editar para mudar arquitetura; apenas atualizar leitura.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-tool-economy.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-control-plane.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/domains/README.md
  - docs/engineering-knowledge-base/domains/domain-routing-governance.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-full-architecture-understanding-report

graph_title: Atlas Full Architecture Understanding Report

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-architecture-audit

graph_status: active

graph_source: repo

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-full-architecture-understanding-report.md

allowed_changes:
  - Atualizar este relatório quando uma Meta nova for promovida ou um sub-projeto antes não-verificado for inspecionado.

forbidden_changes:
  - Alterar contratos ou refatorar arquitetura a partir deste doc; este relatório só lê.
  - Afirmar implementação sem path / migration / teste / comando como prova.

depends_on:
  - atlas-ai-canonical-architecture-index
  - atlas-ai-documentation-operating-system
  - atlas-ai-knowledge-governance-system

flows_to:
  - atlas-ai-architecture-audit

unlocks:
  - architecture-gap-prioritization

governs:
  - architecture-reading

evidence:
  - docs/engineering-knowledge-base/atlas-full-architecture-understanding-report.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

next_actions:
  - Verificar via rg presença real do `ProgrammingDomainRuntimeAdapter` e demais bridges Meta 7.
  - Promover doc Forge OS de `future` para `implemented` ou criar doc gêmea de subspecs.
  - Desbloquear Local Agent Memory Ingestion (Mission/Policy/Evidence/Tool já verdes).
  - Consolidar manifesto canônico de Self-Improvement (domain × cockpit × closed-loop).

---

# Atlas Full Architecture Understanding Report

## Resumo
Atlas AI é o produto: *Autonomous Intelligence Operating System* multi-domínio.
Não é chat, wrapper ou framework de programação
(`atlas-ai-vision.md:108`; `atlas-ai-master-architecture.md:115-120`;
`atlas-ai-layer-0-glossary.md:131`). A tese **Multiplier Channel**
(`atlas-ai-thesis-multiplier-channel.md:123-149`) formaliza
`Output_Atlas = Output_Provider × Multiplicador_Ecossistema`: Atlas é canal
único auditável sobre Claude/GPT/Gemini/Codex, não competidor de modelo.

Este relatório cruza 40 docs canônicas com inspeção do backend
(`atlas-server/app/Services/Ai/*`, migrations, tests, Artisan commands) em
2026-05-18 e responde 10 perguntas: identidade, objetivo, camadas, domínios,
roteamento de domínio, estado real do backend, invariantes, riscos de
confusão, gaps e incertezas. Nada nele altera contratos; todo achado é
ancorado em `arquivo:linha` ou em comando reproduzível.

## Papel no Atlas
Lê (audit-only) e consolida visão de arquitetura. Serve duas funções:

1. **Onboarding rápido para arquitetos/auditores**: a sessão que abrir este
   doc passa a ter o mapa “o que está documentado × o que está em código ×
   o que está testado”, sem ler as 220 docs uma a uma.
2. **Insumo para próximas decisões de arquitetura**: registra gaps
   priorizáveis (Experimentation/World Model scaffold, Forge OS doc status,
   Self-Improvement triplicado) e incertezas a verificar.

Não substitui o índice canônico
(`atlas-ai-canonical-architecture-index.md`) nem o audit índice
(`atlas-ai-architecture-audit.md`); é foto cruzada num timestamp.

## Onde Se Encaixa
Layer system, filho de `atlas-ai-architecture-audit`
(`graph_parent`). Hierarquia canônica de autoridade
(`atlas-ai-canonical-architecture-index.md:154-231`):

| Layer | Conteúdo |
|-------|----------|
| -1 | Thesis / Multiplier Channel / Antifragilidade |
| 0 | Identidade, glossário, AtlasVault |
| 0.5 | Documentation OS + session bootstrap |
| 0.55 | Epistemic OS (truth, drift, contradiction) |
| 0.6 | Research Intelligence governance |
| 0.7-0.9 | SDD, Programming Governance, Forge OS, Self-Construction, Autonomous Intelligence OS |
| 1 | Kernel: envelope, receipt, ledger, SDKs |
| 1.5 | Runtime boundaries (Laravel, Python, Go, Swift) |
| 2 | Master Architecture: planes, domains, surfaces |
| 3 | Pipeline + Core-vs-Domain |
| 4 | Domain specs |

Resolução de conflitos: Layer −1 vence sempre; Kernel vence comportamento
executável; Master vence direção de produto; surface conforma; legacy → corpus
audit.

## Contratos
Os contratos canônicos cruzados nesta auditoria
(`atlas-kernel-mission-foundation.md:176`,
`atlas-objective-intelligence.md:102-109`,
`atlas-evidence-certification-runtime.md:267-371`,
`atlas-tool-economy.md`, `atlas-permission-budget-safety-layer.md:176-191`):

- `atlas.ai.mission.v1` — Mission lifecycle draft→planned→running→certifying→completed.
- `atlas.ai.objective.v1` (+ metric/constraint/dod) — Objective decompõe Mission em sucesso mensurável.
- `atlas.ai.work_order.v1` — unidade executável com `expected_artifacts/tests`, `risk_notes`, `rollback_plan`, `receipt_hash`.
- `EvidencePack / Receipt / Claim / Artifact / SourceRef / GateRun / TestResult / OperatorDecision / Certification / Blocker / AuditEvent` — 11 objetos canônicos.
- `AiPolicyProfile / AiPermissionGate / AiApprovalRequest / AiBudgetEnvelope / AiRiskAssessment / AiSafetyDecision / AiForbiddenAction` — 7 modelos da Safety Layer.
- `atlas.ai.tool_runtime.*` — definitions, capabilities, plans, invocations, receipts, health, validation.
- `atlas.ai.control_plane.snapshot.v1` + readiness/mission_state/blocker/next_action/tool/router schemas.
- `atlas.ai.router.decision.v1` + intent_kernel/flow/domain/dispatch contratos do Meta 6.

## Fluxo
Kernel Law (`atlas-ai-kernel-architecture.md:129-131`):
```
Input -> Operation Envelope -> Intent/Routing -> Decide -> Decision Receipt
-> Domain/Profile/Flow -> Context -> Policy -> Runtime -> Gates -> Repair
-> Evidence Ledger -> Learning/Proposals -> Output
```

Mission Foundation pipeline real
(`atlas-kernel-mission-foundation.md:216-226`):
`MissionFactoryService::create` → `ObjectiveDecomposerService::decompose` →
`MissionLifecycleService::transition(planned)` →
`WorkOrderFactoryService::plan` → `running` →
`MissionEvidenceService::attach` → `certifying` →
`MissionCertificationService::certify` → `completed`.

Como decidir domínio (`domains/domain-routing-governance.md:112-211`):
1. Intent Kernel classifica prompt (verbo + objeto).
2. Domain Routing Governance escolhe `primary_domain` + `secondary_domains`.
3. Domain Runtime Registry resolve runtime.
4. Dentro do domínio: flow / profile / capability.
5. Se nada serve, **Domain Creation Gate** (10 perguntas; resposta fraca →
   flow/profile, não domínio novo).

**Flow vs Domain** (`atlas-domain-runtime-contract.md:239-250`,
`atlas-ai-router-flow-routing-contract-v1.md:139-141`):
Domain = empresa digital (charter, ontologia, departments, gates, métricas).
Flow = caminho executável dentro de um domínio.
Profile = variante de autonomia/risco.
Capability = habilidade declarada.
Tool = execução concreta via Tool Runtime.

## Regras para IA
Invariantes críticos que toda IA operando no Atlas precisa respeitar:

1. **Mission só termina com evidência verificável**
   (`atlas-mission-mode.md:24`; `atlas-kernel-mission-foundation.md:213`:
   `completed` exige `certifying` + `evidenceRefs >= 1` +
   `certification.status === passed`).
2. **Certification gate universal**: nada vira `completed` sem evidence_refs
   não-vazio e missing_requirements vazio
   (`atlas-evidence-certification-runtime.md:376-387`).
3. **Live trading bloqueado por default**: `FinanceComplianceService::assertNotLiveTrade`,
   profile `finance.live_trade_blocked_by_default`
   (`domains/finance.md:168,204`).
4. **Cyber/Security defensivo por default**: ofensivo só com autorização
   escrita + escopo + legal/privacy gate + evidence chain
   (`domains/security.md:128-153`; `atlas-domain-company-runtimes.md:264-265`).
5. **Marketing publish/spend exige approval**
   (`domains/README.md:178`; `atlas-domain-company-runtimes.md:259`).
6. **Domain não cria Kernel/Policy/Tool/Evidence paralelo**: bridges são
   tradutores finos, não refatoram core
   (`atlas-programming-domain-adapter-integration-plan.md:333-348`;
   `atlas-ai-kernel-architecture.md:148-151`).
7. **Cognitive quarantine padrão**: raw capture, evidence, learning signal,
   memory, context e decision são camadas separadas; capture nasce com
   `memory_eligible=false` / `context_eligible=false` / `embedding_allowed=false`
   (`memory/cognitive-immune-learning-kernel.md:121-159`).
8. **Atlas Dev ≠ Atlas Forge**: dois sistemas completos separados; escalação
   por packet explícito (`atlas-dual-core-engineering-system.md:24-32,270-304`).
9. **Surface/Provider/Tool não decidem**: Kernel é a lei executável
   (`atlas-ai-kernel-architecture.md:148-151`).
10. **Self-modification gated**: propostas reviewables, sem auto-aplicar
    refator/promoção/migration sem AP + evidência + approval
    (`atlas-ai-research-self-improvement-runtime.md:283-306`;
    `domains/self-improvement.md:21-23,200-207`).
11. **Evidence append-only**
    (`atlas-ai-multi-domain-implementation-sequence.md:287`).
12. **AtlasVault / provider memory não governam runtime**: só promoção via
    flow governado vira memória/policy/runtime
    (`atlas-ai-knowledge-governance-system.md:299-311`;
    `atlas-ai-memory-context-core-open-brain.md:189-202`).

## Escopo de Implementacao
Cobre **apenas leitura** dos docs canônicos listados em `related_paths` + 36
docs adjacentes citadas no corpo, e inspeção via `rg`/find em
`/Users/vitorepf/develop/Atlas/atlas-server`. Não cobre:

- Sub-projetos fora de `engineering-knowledge-base/`: cyber-security/,
  embodiment/, atlas-app/, atlas-desktop/, dissecar/.
- Verificação Meta 7 individual de cada bridge (ver §Dependencias).
- Estado do flag `external_rivals_certification` (project memory diz
  BLOCKED; não validado diretamente).
- Sincronização real entre `atlas-vault-backup/` e Memory Registry.

Os 15 docs de domínio em `domains/` declaram `status: active` e
`implemented/ready` (`domains/README.md:169-187`), mas `implemented/ready` ≠
Company Runtime promovido (`domains/README.md:154-167`). Hoje só
`programming` é Company Runtime de fato; demais têm spec + seed
(`atlas-ai-multi-domain-implementation-sequence.md:148-187` lista targets de
upgrade: research, strategy/venture studio, finance, marketing, cyber,
personal-dev/learning, automation).

## Dependencias
Documentação consumida (não exaustivo, ver `related_paths` para canônicas):
START_HERE, canonical-architecture-index, master-architecture, vision,
layer-0-glossary, thesis-multiplier-channel, mission-mode, objective-intelligence,
kernel-mission-foundation, kernel-architecture, domain-routing-governance,
domain-company-runtimes, domain-runtime-contract, multi-domain-implementation-sequence,
router-runtime-enterprise-upgrade, router-flow-routing-contract-v1,
permission-budget-safety-layer, evidence-certification-runtime,
evidence-truth-layer, tool-economy, autonomous-control-plane,
autonomous-software-company-runtime, programming-domain-adapter-integration-plan,
dual-core-engineering-system, atlas-dev-index,
atlas-dev-efficient-programming-flow-v1, forge-operating-system,
programming-governance-system, research-self-improvement-runtime,
content-intelligence-curation, compounding-engineering-intelligence,
local-agent-memory-ingestion, memory-context-core-open-brain,
cognitive-immune-learning-kernel, experimentation-engine, world-model,
autonomous-engineering-operating-system, mais 12 domain specs.

Incertezas não verificadas (insumo necessário para futuro audit):

- Presença das 9 bridges Meta 7 como classes
  (`ProgrammingDomainRuntimeAdapter`, `AtlasDevMissionAdapter`,
  `AtlasForgeHandoffAdapter`, `ProgrammingEvidenceBridge`,
  `ProgrammingPolicyBridge`, `ProgrammingToolBridge`,
  `ProgrammingControlPlaneProjection`, `ProgrammingDomainManifestSeeder`,
  `AtlasAiProgrammingDomainCommand`). Comando: `rg -l "class ProgrammingDomain(Runtime|Manifest)Adapter" app`.
- Manifests completos das Company Runtimes Marketing/Cyber em
  `ai_domain_manifests`.
- Doc filha `atlas-forge-operating-system-contracts.md` (`status: implemented`)
  que justificaria índice `future`.
- AP / fase atual de ChromaDB / Streamable HTTP
  (`atlas-ai-memory-context-core-open-brain.md:204-218`).

## Evidencias
Inspeção de backend (`/Users/vitorepf/develop/Atlas/atlas-server`):

| Subsistema | Classificação | Evidência |
|-----------|---------------|-----------|
| Mission Foundation (Meta 1) | **IMPLEMENTED & TESTED** | `app/Services/Ai/Mission/` (10 svcs), models `AiMission*`, mig `2026_05_17_900000`, 8 Feature tests, cmd `atlas:ai:mission-foundation` |
| Objective/WorkOrder | **IMPLEMENTED & TESTED** | `ObjectiveDecomposerService`, `WorkOrderFactoryService`, `AiObjective`, `AiWorkOrder` |
| Kernel Pipeline | **IMPLEMENTED & TESTED** | `app/Services/Ai/Kernel/Pipeline/` (13 svcs), 636 files em Kernel/* (Behavior/Gates/Evidence/Capability/Provider/Surface/Decision/Failure/Repair/Slo/Domain/Mcp/Envelope), cmd `atlas:ai:pipeline` |
| Domain Runtime (Meta 2) | **IMPLEMENTED & TESTED** | `app/Services/Ai/DomainRuntime/` (10 svcs), 15+ migs domain seeds, cmd `atlas:ai:domain-runtime`/`atlas:ai:domains` |
| Policy/Permission/Budget (Meta 3) | **IMPLEMENTED & TESTED** | `app/Services/Ai/Policy/` (10 svcs), mig `2026_05_18_020000`, 12 tests, cmd `atlas:ai:decide`, rotas `/ai/policies/*` |
| Evidence/Certification (Meta 4) | **IMPLEMENTED & TESTED** | `app/Services/Ai/Evidence/` (15 svcs), mig `2026_05_18_020000_*evidence*`, 10 tests, cmd `atlas:ai:evidence` |
| Tool Economy (Meta 5) | **IMPLEMENTED & TESTED** | `app/Services/Ai/ToolRuntime/` (13 svcs), 7 models `AiTool*`, mig `2026_05_18_030000_*tool*`, 12 tests |
| Router Runtime (Meta 6) | **IMPLEMENTED & TESTED** | `app/Services/Ai/RouterRuntime/` (11 svcs), 5 tables `ai_atlas_*`, 10 tests |
| Control Plane (Meta 9) | **IMPLEMENTED & TESTED** | `app/Services/Ai/ControlPlane/` (11 svcs), 8 tests, cmd `atlas:ai:control-plane` |
| Programming Governance | **IMPLEMENTED & TESTED** (doc diz `building`) | `app/Services/Ai/Programming/Governance/` + 80+ svcs, 8 migs `atlas_programming_*`, 91 tests, cmds `atlas:programming:*` |
| Atlas Dev | **IMPLEMENTED & TESTED** | 138 files em `Programming/AtlasDev/`, 80+ tests, 40+ cmds CLI |
| Atlas Forge | **IMPLEMENTED & TESTED** (doc diz `future`) | 32 svcs `AtlasForge*` + 50 svcs `ForgeRivals*`, 81 Feature tests |
| Research Domain (Meta 8A) | **IMPLEMENTED & TESTED** | `app/Services/Ai/ResearchDomain/` (10 svcs), mig `2026_05_18_040000`, 10 tests, cmd `atlas:ai:research-domain` |
| Self-Improvement Runtime | **IMPLEMENTED & TESTED** | `app/Services/Ai/SelfImprovement/` (18 svcs), 4+F + 3+U tests, cmd `atlas:ai:self-improve` |
| Memory / Open Brain | **IMPLEMENTED & TESTED** | 50+ Atlas*Memory* svcs, 7 migs (semantic, entries, deltas, privacy, projection), 269+ refs em testes |
| Compounding Engineering | **PARTIAL** | 11 svcs `AtlasCompounding*`, 2 tests, cmd `atlas:ai:compounding` |
| Experimentation Engine | **SCAFFOLD ONLY** | 2 models (`AiExperimentPlan`, `AiMarketingExperiment`), 2 tests, sem domain runtime dedicado |
| World Model | **SCAFFOLD ONLY** | 3 models `AiCodebaseWorldModel*`, integrado via `ProgrammingSemanticCodeGraphService`, 0 testes dedicados |
| Local Agent Memory Ingestion | **DOCS-ONLY (BLOCKED)** | spec declara bloqueado até Mission/Policy/Evidence/Tool estáveis (`atlas-local-agent-memory-ingestion.md:114-115`) |
| Readiness/Smoke/Control-Plane | **AMPLAMENTE PRESENTE** | `--action=readiness|smoke|control-plane|certify` em todos os Meta entregues |

Métricas totais brutas: 1.768 services PHP em `app/Services/Ai/*`, 257 models,
57+ migrations, 586 Feature tests, 793 Unit tests, 239+ Artisan commands,
~100 endpoints REST `atlas/ai/*`.

Baseline `php artisan atlas:engineering:knowledge docs-health --json`
(2026-05-18T12:48Z, antes deste relatório): **0 violations**; vários
`split_required` warnings em docs pré-existentes (atlas-dev-* runbook,
forge-continuum, forge-rivals-*, self-improvement-activation-cockpit-v1,
self-construction agent control plane).

## Riscos
Riscos de **confusão de leitura** detectados, em ordem de gravidade:

1. **Doc status vs realidade backend** (alto): Forge OS doc diz
   `status: future`; código tem 32 svcs `AtlasForge*` + 50 `ForgeRivals*` +
   81 tests. Programming Governance diz `status: building`; 8 migrations vivas
   + CLI em produção. Leitor cai em "achei que não tinha, mas tinha" —
   exatamente o que `atlas-ai-documentation-operating-system.md:233-243`
   proíbe.
2. **`implemented/ready` (domínio) ≠ Company Runtime** (alto): alerta existe
   em `domains/README.md:154-167`, mas o leitor desavisado pode achar que
   `finance` (`ready`) já tem todo o stack Company Runtime. Só `programming`
   está cravado nesse nível.
3. **Naming proliferation** (médio): Atlas AI (produto) / Atlas Dev (flow) /
   Atlas Forge (sistema irmão) / Atlas Code (surface IDE) / Atlas Code Forge
   (UX orchestrator) / Atlas Code Obra Command Center (cabine). Layer-0 fixa
   Surface/Flow/Domain (`atlas-ai-layer-0-glossary.md:162-182`), mas projetos
   recentes acumulam nomes paralelos.
4. **Self-Improvement triplicado** (médio): (a) domínio `self_improvement`,
   (b) Activation Cockpit, (c) Closed Loop Level 7. Os três precisam de
   um doc canônico desambiguador.
5. **Strategic Decision review-only vs Venture Studio** (médio):
   `domains/strategic-decision.md:121-126` afirma review-only estrito;
   sequence (`atlas-ai-multi-domain-implementation-sequence.md:148-187`) lista
   "Strategy / Venture Studio" como Company Runtime target. Boundary não
   fechada.
6. **Security defensiva vs Cyber Company Runtime** (médio):
   `domains/security.md:128-134` diz "Security Domain NÃO é cyber executor";
   Atlas Cyber Security é projeto separado em `docs/cyber-security/*`.
   Coexistem, mas dois donos do mesmo escopo são proibidos.
7. **Research runtime vs Research domain** (baixo):
   `atlas-ai-research-self-improvement-runtime.md` é P0 law (Layer 0.6);
   Research Domain (Meta 8A) é executor. Risco de confundir lei com
   adapter.
8. **Open Brain = Memory Context Core?** (baixo): doc compacto trata como o
   mesmo (`atlas-ai-memory-context-core-open-brain.md`); mas
   `memory/open-brain-mcp.md` é surface MCP/HTTP. Risco de fundir o
   registry com o exporter.
9. **CLAUDE.md / AGENTS.md secundários** (baixo): ambos são projeções; hierarquia
   fixada em `atlas-ai-knowledge-governance-system.md:184-195`.
10. **Atlas é wrapper ≠ substituto** (baixo): tese fixa
    (`atlas-ai-thesis-multiplier-channel.md`). Quando provider salta,
    multiplicador herda; Atlas não compete no nível de modelo.

Gaps de **implementação/teste**:

- Experimentation Engine (2 tests) e World Model (0 testes dedicados) são
  scaffold, mas dependência declarada do Autonomous Engineering OS
  (`atlas-autonomous-engineering-operating-system.md:197-207`).
- Compounding Engineering (11 svcs, 2 tests) sub-coberto vs papel canônico.
- Local Agent Memory Ingestion bloqueado por Mission/Policy/Evidence/Tool —
  agora todos verdes; pode desbloquear.
- Meta 7 (Programming Domain Adapter): 9 bridges em
  `atlas-programming-domain-adapter-integration-plan.md:268-280`; presença
  efetiva como classes não confirmada nesta auditoria.

Gaps de **documentação**:

- Forge OS doc status `future` desalinhado do código.
- Programming Governance doc status `building` desalinhado do código.
- Falta `atlas-meta-roadmap-current-state.md` consolidando Meta 1..14 com
  status real.
- 9 docs em `split_required` (atlas-dev-* runbook 2.646 linhas, contracts-v1
  2.084, flow-map 2.013). Pré-existente; registrado.

## Exemplos
Trace canônico de uma missão "implementar feature X em programming domain":

1. Prompt entra na surface → Intent Kernel classifica
   (`atlas-ai-router-flow-routing-contract-v1.md:253-278`).
2. Router escolhe flow `atlas_dev` (workspace_present + composer_task=dev).
3. `MissionFactoryService::create(prompt)` → mission `draft`
   (`atlas-kernel-mission-foundation.md:216`).
4. `ObjectiveDecomposerService::decompose` → N objectives.
5. `MissionLifecycleService::transition(planned)`.
6. Domain Routing Governance resolve `primary_domain=programming`.
7. ProgrammingDomainRuntimeAdapter (Meta 7, presença não confirmada nesta
   auditoria) traduz objective → `ProgrammingExecutionRequest`.
8. Permission Gate avalia (`PermissionGateService`); Budget Envelope abre
   limite; Risk Assessment marca nível.
9. Tool Runtime invoca tools (`ToolInvocationService` → `ToolReceiptService`)
   com policy bridge.
10. Atlas Dev executa pipeline `app/Services/Ai/Programming/AtlasDev/*`.
11. Cada etapa atacha `Receipt` + `Artifact` + `TestResult` no `EvidencePack`.
12. `MissionLifecycleService::transition(certifying)`.
13. `MissionCertificationService::certify` valida evidence_refs +
    missing_requirements.
14. Se `passed` → `completed`; senão `failed` / `repairing`.
15. Control Plane projeta status
    (`AtlasControlPlaneSnapshotService::snapshot`).
16. Outcome Evaluator (Compounding) gera `LearningCandidate` para Self-Improvement
    propor melhoria (sem auto-aplicar).

Cada etapa tem comando readiness/smoke equivalente (`atlas:ai:*-domain
--action=readiness|smoke|control-plane|certify`).

## Proximas Acoes
1. **Verificar Meta 7 bridges**: rodar
   `rg -l "class ProgrammingDomain(Runtime|Manifest)Adapter" app` e
   `rg -l "class AtlasDevMissionAdapter|class AtlasForgeHandoffAdapter" app`.
   Atualizar este doc com presença confirmada.
2. **Reconciliar status Forge OS**: promover
   `atlas-forge-operating-system.md` para `implemented`/`active` ou criar
   doc gêmea declarando subspecs `future`. Mesmo para Programming
   Governance (`building` → `implemented`).
3. **Criar manifesto Self-Improvement**: doc curta desambiguando domain ×
   Activation Cockpit × Closed Loop Level 7, com link para o canônico de cada.
4. **Desbloquear Local Agent Memory Ingestion**: pré-requisitos
   (Mission/Policy/Evidence/Tool) estão verdes; criar AP de implementação.
5. **Criar `atlas-meta-roadmap-current-state.md`**: tabela canônica Meta 1..14
   com estado real (entregue/parcial/scaffold/docs-only) e link de evidência.
6. **Fechar gap de teste em Experimentation/World Model/Compounding**:
   criar APs com testes Feature mínimos antes de Autonomous Engineering OS
   exigir esses subsistemas.
7. **Resolver split_required** (9 docs): plano de partição em filhos
   focados, fora do escopo deste relatório mas registrado.
8. **Gates de fechamento desta entrega**:
   - `php artisan atlas:engineering:knowledge docs-health --json` →
     confirmar 0 violations após este arquivo.
   - `git diff --check` no atlas-server.
   - Nenhuma doc canônica alterada por este relatório.
