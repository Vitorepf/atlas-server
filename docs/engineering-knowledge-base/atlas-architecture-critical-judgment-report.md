---
id: atlas-architecture-critical-judgment-report
type: engineering_knowledge
title: Atlas Architecture Critical Judgment Report
status: active
category: architecture
priority: 90
summary: Julgamento crítico (READ-ONLY) Fase 2 sobre se a arquitetura atual do Atlas leva ao produto final desejado. Veredicto principal — Kernel canônico é scaffold paralelo NÃO integrado à produção; pausar para consolidação antes de novos domínios.
tags:
  - atlas-ai
  - architecture-judgment
  - integration-audit
  - production-vs-scaffold
  - 2026-05-18
capabilities:
  - critical_judgment
  - integration_verification
  - severity_classification
  - next_order_recommendation
decisions:
  - Veredicto principal — Kernel Mission/Router/Policy/Tool/Evidence/Certification NÃO está integrado no path de produção (AiWorker), apenas em smoke CLI.
  - Pausar promoção de novos domínios; consolidar integração Kernel ↔ AiWorker primeiro.
  - Tolerância silenciosa em PolicyBridge / EvidenceBridge é risco crítico.
maintenance:
  - Regenerar quando integração Kernel→AiWorker estiver pronta.
  - Atualizar quando Cyber/Automation/Strategy ganharem orchestrator + registry entry.
related_paths:
  - docs/engineering-knowledge-base/atlas-full-architecture-understanding-report.md
  - docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-tool-economy.md
  - docs/engineering-knowledge-base/domains/README.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-architecture-critical-judgment-report

graph_title: Atlas Architecture Critical Judgment Report

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-full-architecture-understanding-report

graph_status: active

graph_source: repo

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md

allowed_changes:
  - Atualizar quando integração Kernel ↔ AiWorker for entregue.
  - Atualizar status de gap quando teste E2E canônico for criado.

forbidden_changes:
  - Suavizar veredicto sem evidência de path real em produção integrado.
  - Declarar Kernel integrado sem prova em controller HTTP/Filament.

depends_on:
  - atlas-full-architecture-understanding-report
  - atlas-ai-architecture-audit

flows_to:
  - atlas-ai-architecture-audit
  - atlas-ai-evolution-roadmap

unlocks:
  - kernel-integration-priority-plan

governs:
  - architecture-judgment

evidence:
  - app/Services/Ai/Mission/MissionLifecycleService.php
  - app/Services/Ai/Mission/MissionCertificationService.php
  - app/Services/Ai/Policy/PermissionGateService.php
  - app/Services/Ai/RouterRuntime/FlowRouterService.php
  - app/Services/Ai/ToolRuntime/ToolPolicyBridgeService.php
  - app/Services/Ai/ToolRuntime/ToolReceiptService.php
  - app/Http/Controllers/AtlasCodeForgeRuntimeDispatchController.php
  - config/atlas_ai.php

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

next_actions:
  - Criar teste E2E canônico Mission→Router→Domain→Policy→Tool→Evidence→Certification que cubra controller HTTP real.
  - Reconectar AiWorker ao Kernel canônico (ou explicitar status `legacy_path`).
  - Endurecer ToolPolicyBridge/ToolReceiptService — remover tolerância silenciosa.
  - Endurecer MissionCertificationService::runChecks — sair de shape-only para quality-aware.
  - Criar orchestrators + registry entry para cyber, automation, strategy OU mover para `scaffold` explícito.
  - Adicionar testes para strategic_decision e self_improvement (hoje 0 testes cada).

---

# Atlas Architecture Critical Judgment Report

## Resumo
**Veredicto executivo (com evidência, sem afago):**

A arquitetura do Atlas é **coerente no design** com o objetivo final (Autonomous
Intelligence OS multi-domínio), mas operacionalmente é **scaffold canônico
paralelo**, não produto integrado. Mission + Router + Policy + Tool + Evidence +
Certification existem como código real (verificado) com testes próprios e
state machines não-triviais — mas **não recebem tráfego de produção**.

Evidência dura (inspeção `rg`/find em `/Users/vitorepf/develop/Atlas/atlas-server`):

- `MissionFactoryService::create()` é chamado apenas por:
  `app/Console/Commands/AtlasAiMissionFoundationCommand.php` (CLI), 3 smoke
  services de domínio, e 6+ arquivos de teste em `tests/Feature/Ai/*`.
  **Zero HTTP controllers, zero Filament resources** referenciam `MissionFactoryService`
  ou `MissionLifecycleService` (`rg -ln "MissionFactoryService|MissionLifecycleService" app/Http app/Filament routes` → vazio).
- `FlowRouterService` aparece apenas em `AtlasAiRouterRuntimeCommand.php`
  (CLI) e em testes `tests/Feature/Ai/RouterRuntime/*`.
- O único controller HTTP que despacha trabalho Code/Forge
  (`AtlasCodeForgeRuntimeDispatchController.php`) usa
  `AtlasForgeRuntimeDispatchService` (namespace `Programming\`), **não** o
  canônico `RuntimeDispatchService` do Meta 6 Router Runtime.
- `AiWorker` (processador real de prompts HTTP) usa
  `AiProviderManager` + `AtlasProgrammingOrchestrator` direto. Não invoca
  Mission, Router canônico, PolicyGate canônico nem CertificationRuntimeService.

**Conclusão**: o Atlas hoje tem duas linhas paralelas: (a) o **path legado de
produção** (AiWorker → AiProviderManager + AtlasForge*RuntimeDispatchService +
AtlasProgrammingOrchestrator), que serve prompts reais; (b) o **Kernel
canônico Meta 1–9** (Mission/Router/Policy/Tool/Evidence/Certification),
construído com rigor e governança, mas validado só por Artisan smoke CLI.

A rota deve **pausar a expansão multi-domínio** e priorizar a integração
Kernel ↔ AiWorker antes de mais Company Runtimes.

## Papel no Atlas
Este relatório é juiz arquitetural, não construtor. Existe para:

1. Diferenciar **coerência de design** (alta) de **integração real** (baixa).
2. Resistir à ilusão de progresso provocada pela contagem de
   migrations/services/tests da Fase 1.
3. Recomendar **a próxima ordem com menor risco e maior multiplicador** —
   provavelmente consolidação, não expansão.
4. Servir de checkpoint contra a tese **canal único**
   (`atlas-ai-thesis-multiplier-channel.md:141-149`): hoje o "canal único" é
   o path legado, não o Kernel canônico. Multiplicador positivo exige reunir
   os dois.

Não substitui APs nem cancela trabalho em andamento. É evidência para o
operador decidir prioridade.

## Onde Se Encaixa
Filho de `atlas-full-architecture-understanding-report` (Fase 1, leitura
fiel). Fase 2 aplica julgamento sobre os mesmos artefatos com inspeção
direta de código (não só counts/agregados).

Autoridade: este doc **não vence** Layer −1 nem Kernel; apenas reporta
discrepâncias entre Master Architecture (`atlas-ai-master-architecture.md`)
e estado real do código.

## Contratos
Os 11 contratos canônicos do Atlas (mission.v1, objective.v1, work_order.v1,
EvidencePack/Receipt/Claim/Artifact/SourceRef/GateRun/TestResult/OperatorDecision/Certification/Blocker/AuditEvent,
AiPolicy*/Permission/Approval/Budget/Risk/Safety/Forbidden, tool_runtime.*,
control_plane.*) **existem como tabelas + models PHP** — verificado em
Fase 1.

**Mas** a auditoria Fase 2 mostra dois níveis de “verificação real do contrato”:

| Contrato | Estado contratual | Estado de uso real |
|----------|-------------------|--------------------|
| mission lifecycle (draft→…→completed) | guardCompletion estrito (`MissionLifecycleService.php:121-131`) | invocado só em smoke CLI |
| certification shape | runChecks lista 5 itens (`MissionCertificationService.php:94-120`) | **shape-only** — verifica `count>0`, não qualidade |
| permission gate decision | `PermissionGateService::evaluate` real (188 linhas, hard-block + risk_tolerance + approval_rules) | 2 callers reais (`ProgrammingPolicyBridge`, `ToolPolicyBridgeService`) — outros são CLI/tests |
| tool→policy gate | `ToolPolicyBridgeService::evaluate` | **tolerância**: se tabela ausente ou exceção → fallback `allow` para low-risk |
| tool→evidence | `ToolReceiptService::emit` | **tolerância**: se Evidence Runtime ausente → receipt local com marker `evidence_runtime_unavailable` |
| router decision persistence | `FlowRouterService::decideFlow` cria `AiAtlasFlowRoute` com `status: 'ready'` | **ninguém consome** esse FlowRoute no path HTTP |

Contratos são estruturalmente honrados onde invocados; mas o uso é
**parcial e tolerante**, não enforcement universal.

## Fluxo
**Fluxo canônico documentado** (`atlas-ai-kernel-architecture.md:129-131`):
```
Input → Envelope → Intent/Routing → Decide → Decision Receipt
→ Domain/Profile/Flow → Context → Policy → Runtime → Gates → Repair
→ Evidence Ledger → Learning → Output
```

**Fluxo real em produção (traçado via `AiWorker.php`)**:
```
HTTP request → AiThreadController / AiJobController → AiWorker::process()
→ AiProviderManager (Claude/Codex/Gemini direto)
→ AtlasProgrammingOrchestrator (para tarefas de programação)
→ side channels: Finance compliance, Programming governance, Memory delta
```

Não passa por Mission, Router canônico, PolicyGate central nem
CertificationService. **O “Pipeline canônico Atlas” descrito nas docs é
exercitado apenas por:**

- `atlas:ai:mission-foundation --action=smoke` (orquestração manual CLI).
- 3 smoke services de domínio (Finance, Programming).
- Testes Feature/Ai/Mission e Feature/Ai/RouterRuntime.

`RouterRuntimeMissionIntegrationTest.php` explicitamente documenta a desconexão
("Mission Foundation tables are NOT created here"; assert `mission: null` ao
rotear sem Mission tables) — ou seja, o próprio time já testa o Router
**em modo desacoplado** do Mission.

## Regras para IA
Invariantes documentados x estado real de enforcement:

| Invariante | Documentado | Enforcement real |
|-----------|-------------|------------------|
| Mission só termina com evidência | `atlas-mission-mode.md:24` | ✅ `guardCompletion` real, mas só dispara em smoke CLI |
| Certification gate universal | `atlas-evidence-certification-runtime.md:376` | ⚠️ `certify()` retorna record FAILED mas não joga exceção; caller decide |
| Live trading bloqueado | `domains/finance.md:168` | ⚠️ `FinanceComplianceService::assertNotLiveTrade` real, chamado de Finance domain interno; sem prova de invocação no path HTTP → broker |
| Cyber defensivo por default | `domains/security.md:128-153` | ⚠️ Cyber **não tem orchestrator** nem entry no registry; manifest `status: planned` |
| Marketing publish exige approval | `domains/README.md:178` | ✅ packet retorna `external_publish_allowed: false`; testado (stub) |
| Domain não cria Kernel paralelo | `atlas-programming-domain-adapter-integration-plan.md:333-348` | ⚠️ Finance/Programming/Health construíram **subsistemas paralelos** — domain has own compliance/governance, não consome Policy/Evidence canônicos |
| Cognitive quarantine padrão | `memory/cognitive-immune-learning-kernel.md:121-159` | ✅ implementado em CaptureService/CurationProposalService |
| Atlas Dev ≠ Forge | `atlas-dual-core-engineering-system.md:24-32` | ✅ código separado |
| Surface/Provider/Tool não decidem | `atlas-ai-kernel-architecture.md:148-151` | ❌ `AiWorker` + `AiProviderManager` decidem hoje |
| Self-modification gated | `domains/self-improvement.md:21-23` | ⚠️ orchestrator existe; 0 testes |
| Evidence append-only | `multi-domain-sequence:287` | ✅ schema apropriado |
| AtlasVault/CLAUDE.md secundários | `atlas-ai-knowledge-governance-system.md:299-311` | ✅ projeção gerada; canônico no repo |

Resumo: **3 invariantes verdadeiramente enforced** (cognitive quarantine,
Atlas Dev ≠ Forge, evidence append-only). **6 declarados mas com gap real**
(certification record-not-gate, live trade só dentro do domínio, cyber não
roteável, domain cria subsistema paralelo, surface ainda decide,
self-improvement sem teste). **2 fully verified by code** (mission guard
when invoked, marketing publish gate).

## Escopo de Implementacao
Objetivo final declarado (`atlas-autonomous-intelligence-operating-system.md:158-171,274-288`):
Atlas AI como Autonomous Intelligence OS multi-domínio que substitui/supera
Claude Code/Codex para programação **e** opera finance/marketing/cyber/strategy/research/personal-development/automation
com evidência, certificação, execução segura, menos interação humana.

Estado real vs objetivo:

- **Programming**: enterprise-grade (309 tests, AtlasDev+AtlasForge funcionais
  em produção). Único domínio que ataca o objetivo final hoje, mas via path
  legado (não via Kernel canônico).
- **Finance/Research/Security/Operations/Learning/Background**: moderado
  (25–48 tests, runtime + orchestrator + manifest; review/draft-only).
- **Marketing/Writing/QA/Personal Development/General/Health**: 1–163 tests;
  Health é exceção (deep). Demais são packet-shape only.
- **Strategic Decision**: **0 tests** apesar de orchestrator existir.
- **Self-Improvement**: **0 tests** apesar de orchestrator existir.
- **Cyber Security**: scaffold (runtime sim, orchestrator não, manifest
  `planned`, fora do registry `config/atlas_ai.php`).
- **Automation/Tool Factory**: scaffold (runtime sim, orchestrator não, fora
  do registry).
- **Strategy/Venture Studio**: scaffold (StrategyRuntimeService completo,
  StrategyDomainManifestSeeder existe, mas sem orchestrator e fora do
  registry).

Conclusão: o produto **substitui Claude Code/Codex em programming** (parcial,
path legado), e o resto é **predominantemente plan/review/draft-only**, não
"empresa digital autônoma" como o objetivo final pede.

## Dependencias
Verificações que **NÃO** fiz e que afetam o veredicto se mudarem:

- **AiWorker integration roadmap**: existe AP/spec declarando que AiWorker
  será migrado para consumir o Kernel canônico? Se sim, este veredicto
  precisa de timeline.
- **Forge externa de Rivals**: flag `external_rivals_certification` continua
  BLOCKED por design? Memory project diz que sim; não validei.
- **`atlas-forge-operating-system-contracts.md`**: existe doc filha com
  `status: implemented` que justifique o índice ainda apontar para `future`?
- **Atlas Cyber Security** em `docs/cyber-security/`: tem implementação
  cruzada com `app/Services/Ai/Cyber/`? Esta auditoria cobriu só
  engineering-knowledge-base + app/Services.
- **Local Agent Memory Ingestion**: bloqueio é por dependência ou por
  decisão? Pré-requisitos (Mission/Policy/Evidence/Tool) estão verdes para
  fins de smoke; podem não estar para fins de produção.
- **ChromaDB/MCP remoto**: status de AP atual desconhecido.

Cada item acima pode reduzir um Gap aqui listado se já estiver em produção
verificada.

## Evidencias
### Avaliação por Camada

| Camada | Status | Evidência |
|--------|--------|-----------|
| Mission / Objective / WorkOrder | **partial** | Lifecycle service real (`MissionLifecycleService.php:35-87` state machine), guardCompletion estrito; mas zero callers HTTP/Filament. |
| Router Runtime | **partial** | `FlowRouterService.php:15-40` cria FlowRoute persistido; nenhum consumidor no path HTTP. Production HTTP usa `AtlasForgeRuntimeDispatchService` (legado). |
| Domain Runtime | **partial** | 15 orchestrators registrados em `config/atlas_ai.php:360-522`; 3 órfãos (cyber/automation/strategy). Não acionados via Mission, apenas via legado. |
| Domain Routing Governance | **docs-only** | Governança escrita em `domains/domain-routing-governance.md`; gate real no código limitado a heurística em `DomainRouterService` + smoke CLI. |
| Policy / Permission / Budget | **partial** | `PermissionGateService` real e rigoroso (188 linhas); mas só 2 callers reais (ProgrammingPolicyBridge, ToolPolicyBridge), e ToolPolicyBridge tem **tolerância silenciosa**. |
| Evidence / Certification | **risky** | Schema completo; `MissionCertificationService::runChecks` é **shape-only** (count>0); `ToolReceiptService` degrada para local-only se Evidence ausente. |
| Tool Runtime | **partial** | Invocation/Receipt/Health/Validation reais; mas Policy e Evidence bridges são tolerantes; produção só usa subset via ProgrammingToolBridge. |
| Control Plane | **partial** | Snapshot/Readiness/NextActions reais; **read models verdes só refletem estado de tabelas, não execução produtiva**. Smoke valida leitura, não escrita por execuções reais. |
| Memory / Compounding | **partial** | Memory é o subsistema mais maduro (50+ services, 7 migs, 269+ refs em tests). Compounding tem 11 svcs / 2 tests — risco de regressão. |
| UX/Desktop | **missing** | Sem evidência de integração desktop/app com Mission/Router canônicos. Atlas Code controllers despacham via Forge dispatcher local. |
| Domain Company Runtimes | **partial** | Apenas Programming opera como Company Runtime de fato; demais são plan/review/draft. |

### Avaliação por Domínio

| Domínio | Doc | Backend | Tests | Readiness/smoke | Status real |
|---------|-----|---------|-------|-----------------|-------------|
| Programming | ✅ canônico | enterprise (80+ svcs) | 309 (profundos) | sim | **implemented (enterprise)** |
| Research | ❌ doc canônica em `/domains/` ausente | 10 svcs + Meta 8A | 48 (médios) | sim | **partial (moderate)** |
| Strategy / Venture Studio | ❌ | runtime sim, orchestrator não | n/a | n/a | **scaffold (fora do registry)** |
| Finance | ✅ | 21 files | 47 (médios) | sim | **partial (review-only)** |
| Marketing | ❌ | 13 files | 19 (stub) | sim | **partial (draft-only)** |
| Cyber Security | ❌ doc em `/domains/` | runtime + manifest `planned` | n/a | n/a | **scaffold (não roteável)** |
| Personal Development | ✅ | 6 files | 1 (stub) | sim | **partial (plan-only)** |
| Learning | ✅ | seed + svcs | 39 (médios) | sim | **partial (plan-only)** |
| Automation / Tool Factory | ❌ | runtime sim, orchestrator não | n/a | n/a | **scaffold (fora do registry)** |
| Local Agent Memory Ingestion | ✅ spec | nenhum código | nenhum | n/a | **docs-only (BLOCKED)** |
| Operations | ✅ | seed | 25 (médios) | sim | **partial (diagnostic-only)** |
| QA | ✅ | seed | 14 (stub) | sim | **partial (review-only)** |
| Writing | ✅ | seed | 26 (stub) | sim | **partial (draft-only)** |
| Health | ✅ | seed | 163 (deep) | sim | **partial (non-clinical)** |
| General | ✅ | seed | 15 (stub) | sim | **partial (triage)** |
| Background | ✅ | seed | 48 (médios) | sim | **partial (low autonomy)** |
| Strategic Decision | ❌ doc em `/domains/` | 13 files | **0** | n/a | **unsafe (review-only sem teste)** |
| Self-Improvement | ✅ spec | 18 svcs | **0** Feature dedicados | sim (schedule) | **unsafe (untested orchestrator)** |

## Riscos
### Confusões doc-vs-doc / doc-vs-código

1. **Forge OS `status: future` vs 32 svcs + 50 ForgeRivals + 81 tests em produção** → leitor cai em "achei que não tinha".
2. **Programming Governance `status: building` vs 8 migrations vivas + CLI em produção**.
3. **`implemented/ready` (domínio) ≠ Company Runtime promovido** → 13/15 domains são plan/draft/review.
4. **Strategic Decision review-only declarado vs Venture Studio Company Runtime target** — boundary sem fechamento.
5. **Security defensivo (`domains/security.md`) vs Cyber Company Runtime** (`docs/cyber-security/`) — duas autoridades, uma sem orchestrator.
6. **Finance review-only vs Investment Company Runtime futuro** — `live_trade_blocked_by_default` é interno ao Finance Domain, não invariante do Kernel.
7. **Atlas Code naming proliferation** — Atlas AI / Atlas Dev / Atlas Forge / Atlas Code / Atlas Code Forge / Atlas Code Obra Command Center / Cockpit / Closed Loop Level 7 — sem doc desambiguador.
8. **Open Brain vs Memory Context Core** — registries vs exporter sem diferença explícita.
9. **Research runtime (lei P0) vs Research domain (executor)** — leitor pode confundir law com adapter.
10. **CLAUDE.md/AGENTS.md** — alguns ainda tratam como source-of-truth.

### Arquitetura escala?

| Pergunta | Resposta com evidência |
|----------|------------------------|
| 20 domínios novos aguentam? | **Não no estado atual.** 3 dos atuais já estão órfãos (cyber/automation/strategy); naming proliferation já preocupa; doc canônica falta para 8 de 15. Sem Kernel integrado, cada domínio replica subsistema. |
| Prompt ambíguo protegido? | **Não.** AiWorker decide direto via provider; Router canônico não recebe tráfego HTTP. Domain Creation Gate é doc-only. |
| Ação perigosa bloqueada? | **Parcialmente.** Live trade dentro do Finance Domain sim; cyber offensive em geral não (cyber não tem orchestrator); ferramenta nova em `external_action` group tem default `blocked` (`atlas-tool-economy.md:151`), mas só se o tráfego passar pelo Tool Runtime. |
| IA nova entra e sabe o quê fazer? | **Não.** Bootstrap protocol (`atlas-ai-knowledge-governance-system.md:198-214`) existe mas falta consolidação Meta 1..14. Esta sessão precisou cruzar 5+ docs para inferir estado real. |
| Gate de domínio novo suficiente? | **Doc-only.** 10 perguntas existem; sem teste verificando recusa de domínio mal-justificado. |
| Control Plane visível? | **Parcial.** Snapshot real, mas reflete tabelas — não execuções produtivas. |

### Gaps por severidade

**CRITICAL (bloqueia produto final):**

1. **Kernel canônico não integrado ao path HTTP.** Mission/Router/Policy/Tool/Evidence/Certification não recebem tráfego de produção. AiWorker bypassa.
   - Evidência: `rg -ln "MissionFactoryService" app/Http app/Filament routes` → vazio.
   - Impacto: invariantes documentados não enforced em produção. Multi-domain promise é cosmética.
   - Correção: AP de integração AiWorker → Mission + Router (estimativa N semanas).
   - Bloqueia: Meta 7 (Programming Adapter), todas as Company Runtimes 8B/8C/8D/8E/8F.

2. **Tool Policy/Evidence bridges são tolerantes (silent degrade).**
   - Evidência: `ToolPolicyBridgeService::evaluate` linhas 62-71 captura exceção e retorna fallback; `ToolReceiptService::emit` linhas 90-96 idem.
   - Impacto: policy violation pode passar invisível em produção; evidence omitida sem ruído.
   - Correção: configurar mode `strict` em produção; log obrigatório de fallback.
   - Bloqueia: certificação real.

3. **Mission Certification é shape-only, não quality-aware.**
   - Evidência: `MissionCertificationService::runChecks` apenas conta artefatos.
   - Impacto: passa certificação com 1 objective trivial + 1 evidence ref vazio + 1 work_order com hash aleatório.
   - Correção: adicionar quality_score, source_verification, claim_verification em runChecks.

**HIGH:**

4. **Cyber/Automation/Strategy órfãos** — runtime sim, orchestrator não, fora do registry `config/atlas_ai.php`.
   - Correção: orchestrator + registry entry OU mover para `scaffold` explícito.
5. **Strategic Decision e Self-Improvement com 0 testes** — orchestrator existe, contrato não verificado.
6. **Forge OS / Programming Governance doc status desalinhado do código** — "achei que não tinha, mas tinha".
7. **Sem teste E2E canônico** Mission→Router→Domain→Policy→Tool→Evidence→Certification.
8. **AiWorker não consome Kernel canônico** — duplo path arquitetural sem ADR explicando.

**MEDIUM:**

9. **8 de 15 domínios sem doc canônica em `domains/`** (research, marketing, strategic_decision, personal_development, self_improvement, cyber, automation, strategy).
10. **Experimentation Engine (2 tests) e World Model (0 tests dedicados)** — dependência declarada do Autonomous Engineering OS.
11. **Compounding Engineering (11 svcs / 2 tests)** sub-coberto.
12. **Local Agent Memory Ingestion BLOCKED** — pré-requisitos verdes, mas sem AP de desbloqueio.
13. **Naming proliferation** (Atlas Code / Forge / Obra / Cockpit) sem doc desambiguador.

**LOW:**

14. **9 docs em `split_required`** (pré-existente; runbook 2.646 linhas).
15. **CLAUDE.md/AGENTS.md tratados como source-of-truth por contribuidor desavisado**.
16. **Open Brain vs Memory Context Core nomeação fundida**.

## Exemplos
**Trace real (hoje) de um prompt HTTP "implementa endpoint /foo":**

1. Request HTTP → `AiThreadController` → `AiWorker::process()`.
2. `AiWorker` chama `AiProviderManager` → escolhe Claude/Codex.
3. `AtlasProgrammingOrchestrator` executa pipeline programming (real, robusto).
4. `ProgrammingPolicyBridge::evaluate(...)` → chama `PermissionGateService`.
5. `ProgrammingToolBridge` invoca `ToolInvocationService` → policy+receipt via bridges (com tolerância silenciosa).
6. Evidence é emitida via Programming-specific evidence services.
7. Nenhuma Mission/Objective/WorkOrder canônico criados. Nenhum
   `MissionCertificationService::certify` rodado. Nenhum
   `AiAtlasRouterDecision`/`AiAtlasFlowRoute` persistido.
8. Control Plane snapshot mostra "0 missions completed" — porque não há
   missions, apenas execuções programming.

**Trace canônico (smoke) do mesmo prompt:**

1. CLI `atlas:ai:mission-foundation --action=smoke` cria mission draft.
2. ObjectiveDecomposerService decompõe.
3. transition→planned→running.
4. WorkOrderFactoryService cria work order com receipt_hash.
5. Evidence test-only atachado.
6. transition→certifying → certify() retorna `passed`.
7. guardCompletion verifica evidence_count>0 e certification.status='passed'.
8. transition→completed.

Os dois nunca se encontram. O exemplo evidencia o gap arquitetural.

## Proximas Acoes
### Continuar / pausar / consolidar

**Continuar:**
- Programming (Dev+Forge) — único domínio que realmente serve o objetivo.
- Memory/Open Brain — subsistema mais maduro; manter cadência.
- Documentation OS / Knowledge Governance — funcionando bem.

**Pausar:**
- Promoção de novos domínios para Company Runtime (cyber/marketing/finance/strategy completos) até integração Kernel ↔ AiWorker estar provada.
- Self-Improvement Activation Cockpit / Closed Loop Level 7 — sem teste base e sem doc desambiguador.

**Consolidar antes de novos domínios:**
1. AP: integração AiWorker → Mission + Router canônicos.
2. AP: endurecer ToolPolicyBridge/ToolReceipt (modo strict em produção).
3. AP: enriquecer MissionCertificationService.runChecks com quality checks.
4. AP: criar teste E2E canônico Mission→Router→Domain→Policy→Tool→Evidence→Certification.
5. AP: orchestrator + registry para cyber/automation/strategy OU explicitar como scaffold.
6. AP: testes Feature para strategic_decision e self_improvement orchestrators.
7. Doc: `atlas-meta-roadmap-current-state.md` consolidando Meta 1..14 com status real.

### Claudes paralelos seguros / não-paralelos

- **OK paralelo**: Memory consolidation, doc canônica para 8 domínios faltando, teste para strategic_decision, teste para self_improvement, naming-disambiguation doc — cada um toca arquivos diferentes.
- **NÃO paralelo**: integração AiWorker↔Kernel + endurecimento ToolPolicy/Evidence + teste E2E canônico — esses três tocam o mesmo path (AiWorker/Bridges/Mission); precisam serialização.

### Testes e certificação faltantes
- **E2E canônico**: 1 teste Feature que exerça Mission→Router→Domain→Policy→Tool→Evidence→Certification com asserts em estado final de cada tabela.
- **Routing fixtures**: matriz prompt→domínio×flow esperado, com ≥10 casos cobrindo ambíguo, ofensivo, financeiro, conversational, debug.
- **Domain Creation Gate test**: cenário onde IA pede domínio novo mal-justificado e o gate REJEITA, exigindo flow no domínio existente.
- **Anti-false-completion**: teste explícito de `MissionLifecycleService::guardCompletion` recusando completed quando evidence é trivial / certification não real.
- **Policy enforcement strict-mode**: teste com tabelas Policy ausentes → exceção, não fallback.
- **Live trade end-to-end**: simulação de tentativa de tool/broker `finance.live_trade` rejeitada em todas as camadas (policy + finance compliance + tool runtime).
- **Cyber offensive RoE gate**: simulação de pedido ofensivo sem RoE → bloqueado.
- **Marketing publish gate**: tentativa de publish sem approval → bloqueado.
- **Control Plane real-state**: teste assertando que snapshot reflete execução produtiva, não tabelas vazias.
- **Strategic Decision review-only**: teste assertando que orchestrator nunca emite `executable_decision`.
- **Self-Improvement**: teste cobrindo o flow scheduled real, não só readiness.
- **Cartografia/AtlasVault sync** (se relevante): teste de não-vazamento entre vault e Memory Registry.

### Final Recommendation
- **Continuar a rota atual?** **Com ajustes — pausar expansão e consolidar.** A coerência de design é alta; falta integração. Acelerar agora é multiplicar dívida.
- **Próximo passo mais importante:** **integrar AiWorker ao Kernel canônico Mission+Router**. Sem isso, todo investimento Meta 1–9 é teatro.
- **Maior risco se continuarmos sem corrigir:** o Atlas continuará entregando programming via path legado e empilhando docs/scaffold para os outros domínios. A tese **canal único** falha — todo tráfego real fica fora do Evidence Ledger canônico, e a multiplicação prometida (`Output_Atlas = Output_Provider × Multiplicador`) não se materializa porque o canal único é o legado, não o canônico. Em 6–12 meses, refazer ficará caro.
- **Menor correção que mais reduz risco:** endurecer `ToolPolicyBridgeService::evaluate` e `ToolReceiptService::emit` — remover tolerância silenciosa (ou exigir log explícito + alarme). Custo de uma AP curta; bloqueia que policy/evidence vazem silenciosamente.

**Gates finais (2026-05-18):**
- `php artisan atlas:engineering:knowledge docs-health --json` → confirmar 0
  violations e este doc abaixo do limite após escrita.
- `git diff --check` no atlas-server.
- Nenhuma doc canônica alterada por este relatório.
