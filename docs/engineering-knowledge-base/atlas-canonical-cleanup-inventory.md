---
id: atlas-canonical-cleanup-inventory
type: engineering_knowledge
title: Atlas Canonical Cleanup Inventory
status: active
category: architecture
priority: 95
summary: Inventario read-only (2026-05-18) de docs canonicas e codigo do Atlas para crescimento seguro de Atlas AI / Dev / Forge. Classifica status real vs declarado, lista naming duplications e service overlaps, propoe sequencia de cleanup que NUNCA apaga doc ou codigo.
tags:
  - atlas-ai
  - cleanup-inventory
  - audit
  - continuity
  - 2026-05-18
capabilities:
  - canonical_inventory
  - status_mismatch_detection
  - naming_duplication_mapping
  - service_overlap_mapping
  - safe_cleanup_sequencing
decisions:
  - Inventario NAO altera docs canonicas existentes. NAO move codigo. NAO remove visao futura. Apenas classifica e mapeia.
  - Cada afirmacao cita arquivo/classe/doc. Quando falta evidencia, declara hipotese.
  - Esta doc consolida e referencia atlas-architecture-critical-judgment-report.md e atlas-dev-forge-relationship-critical-audit.md; nao os substitui.
  - Visao futura valida (Sovereign OS, Epistemic OS, Cartographic Knowledge OS, Next Patamar) PERMANECE intocada — `status: future` aqui significa "tese estrategica ainda nao construida", nao "obsoleta".
maintenance:
  - Regenerar quando integracao AiWorker -> Kernel canonico estiver entregue.
  - Atualizar quando uma das 8 docs em "status duvidoso" for reconciliada.
  - Atualizar quando um cluster de service-overlap for consolidado.
related_paths:
  - docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-canonical-cleanup-inventory
graph_title: Atlas Canonical Cleanup Inventory
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
owner: architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-canonical-cleanup-inventory.md
allowed_changes:
  - Atualizar contagens e classificacoes quando o codigo ou docs mudarem.
  - Adicionar novos clusters de overlap quando surgirem.
forbidden_changes:
  - Apagar entradas marcadas como "visao futura valida".
  - Promover doc para `active` sem evidencia em codigo verificada.
  - Recomendar remocao de codigo ou doc sem checklist verde.
depends_on:
  - atlas-architecture-critical-judgment-report
  - atlas-dev-forge-relationship-critical-audit
flows_to:
  - atlas-ai-canonical-architecture-index
  - atlas-ai-multi-domain-implementation-sequence
unlocks:
  - safe-cleanup-sequencing
governs:
  - canonical-inventory
evidence:
  - app/Services/Ai/RouterRuntime/
  - app/Services/Ai/Router/AtlasAiRouterService.php
  - app/Services/Ai/Programming/AtlasDev/
  - app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php
  - app/Services/Ai/DualCore/DualCoreRouteDecisionService.php
  - app/Services/Ai/Mission/MissionLifecycleService.php
  - app/Services/Ai/Evidence/CertificationRuntimeService.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
ai_entrypoints:
  - Leia Resumo, Top Status Duvidoso, Top Naming Duplications, Top Service Overlaps e Cleanup Seguro Sequenciado antes de qualquer cleanup ou renomeacao.
quality_gates:
  - inventory-complete
  - status-cross-checked
  - duplication-mapped
  - cleanup-sequenced
failure_modes:
  - Cleanup deletando visao futura estrategica.
  - Renomear cluster sem ADR.
  - Promover doc para active sem prova em producao.
observability_signals:
  - docs-health pass count
  - oversized doc count
  - canonical violation count
next_actions:
  - Operador escolhe item da secao "Cleanup Seguro Sequenciado" para criar AP.
  - Quando audit Phase 3 rodar, regenerar este inventario.
line_limit: 540
---
# Atlas Canonical Cleanup Inventory
## Resumo
Atlas hoje tem **601 docs canonicos** em `docs/engineering-knowledge-base/`,
**~700+ services** em `app/Services/Ai/**`, e duas auditorias profundas
recentes (`atlas-architecture-critical-judgment-report.md` e
`atlas-dev-forge-relationship-critical-audit.md`, ambas de 2026-05-18) que
ja diagnosticaram os maiores riscos.
Esta doc **consolida** esses diagnosticos e **adiciona inventario filesystem**
de naming clusters e service overlaps em forma classificada e auditavel.
Estado real (verificado por `grep`/`find` em 2026-05-18):

- **169 docs `active`**, **10 docs `future`**, **4 `building`**, **4
  `deprecated`**, **3 `scaffold`**, **2 `split_required`**, **4 `draft`**,
  **2 `archived`**, mais 4 entradas raras (`source_material`, `proposed`,
  `canon`). Contagens: `grep -h "^status: " docs/engineering-knowledge-base/*.md | sort | uniq -c`.
- **0 callers HTTP/Filament** chamam o Kernel canonico Mission/Router
  (`grep -rln "MissionFactoryService\|FlowRouterService" app/Http app/Filament routes` -> vazio).
- **5 surfaces de "Router"** convivem no codigo (provider/dual-core/Atlas
  Decide/Meta 6/Programming SDD), so um deles e canonico Meta 6.
- **286 services em `app/Services/Ai/SelfConstruction/`** — maior cluster de
  proliferacao de naming do repo.
- **`atlas.dual_core.route_decision.v1`** ja implementado (3 arquivos em
  `app/Services/Ai/DualCore/`); **`atlas.dev_to_forge.escalation_packet.v1`**
  tambem ja implementado (3 arquivos em `app/Services/Ai/Programming/AtlasDev/Escalation/`
  e `Schemas/EscalationPacket.php`) — corrige claim original do audit
  Dev/Forge `:111-112` que dizia "0 matches".

Esta missao **NAO apaga**, **NAO move**, **NAO renomeia**. Apenas inventaria
e propoe sequencia segura.

## Papel no Atlas

Inventario governanca-leve para:

1. **Pre-flight cleanup**: dar ao operador um mapa de baixo risco do que
   tocar e em que ordem.
2. **Continuity check**: novas IAs leem isto antes de assumir que doc
   `future` significa "nada existe" ou que doc `active` significa "tudo
   funciona em producao".
3. **Anti-deletion guardrail**: catalogar a visao futura estrategica
   (Sovereign/Epistemic/Cartographic OS, Next Patamar, Forge OS contracts)
   para que nenhum cleanup zelo-excessivo a apague.
4. **Naming hygiene baseline**: registrar onde naming proliferacao ja virou
   risco operacional (`Atlas Code` / `Atlas Forge` / `Atlas Code Forge` / `Cockpit`).

## Onde Se Encaixa

Filho de `atlas-ai-canonical-architecture-index.md`. Consome os dois audits
acima e Cross-checka com `atlas-ai-multi-domain-implementation-sequence.md`
e `atlas-dual-core-engineering-system.md`. Nao vence Layer -1 nem Kernel;
e instrumento operacional de cleanup planning.

## Contratos

Esta doc nao introduz contratos novos. Cita estes:

- `atlas_canonical_module_doc.v1` (formato deste arquivo).
- `atlas.dual_core.route_decision.v1` — implementado (`app/Services/Ai/DualCore/`).
- `atlas.dev_to_forge.escalation_packet.v1` — implementado (`app/Services/Ai/Programming/AtlasDev/Escalation/`).
- `atlas.ai.mission.v1`, `atlas.ai.objective.v1`, `atlas.ai.work_order.v1` — Meta 1.
- `atlas.ai.router_decision.v1`, `atlas.ai.flow_route.v1` — Meta 6.

## Fluxo

```
Inventario (este doc)
  -> Operator escolhe item P0/P1 de "Cleanup Seguro Sequenciado"
  -> AP individual com escopo fechado
  -> Diff cirurgico + teste verde + docs-health green
  -> Regenerar este inventario depois de cada cleanup
```

## Top Docs Com Status Duvidoso

Cross-check entre `^status:` declarado vs evidencia em codigo. Os 11 itens
abaixo sao os de maior load-bearing onde a divergencia gera risco
("achei que nao tinha, mas tinha" ou inverso).

| Doc | Status declarado | Evidencia real | Diagnostico | Origem |
|---|---|---|---|---|
| `atlas-forge-operating-system.md` | `future` | 32 svcs + 46 ForgeRivals svcs + 30-40 endpoints HTTP + 81 tests | **status invertido** — codigo em producao | judgment-report `:341` |
| `atlas-forge-operating-system-contracts.md` | `future` | 37 invariants implementados em `AtlasForgeContinuumCertificationService.php` | **status invertido** | dev-forge-audit `:114` |
| `atlas-forge-operating-system-runbook.md` | `future` | runbook real para Forge em producao via `atlas:forge:*` commands | **status invertido** | inferido (consistencia) |
| `atlas-programming-governance-system.md` | `building` | 8 migrations vivas + CLI em producao + Programming domain enterprise | **building atrasado** vs estado real | judgment-report `:341` |
| `atlas-programming-governance-system-runbook.md` | `building` | runbook ativo invocado por Programming Domain | **building atrasado** | judgment-report `:341` |
| `atlas-programming-governance-system-contracts.md` | `future` | contratos consumidos por `ProgrammingPolicyBridge` + `AtlasProgrammingOrchestrator` | **status invertido** | judgment-report `:341` |
| `atlas-dev-efficient-programming-flow-v1.md` | `draft` | `AtlasDevFastPathOrchestrator` em producao; 138 files / 20.7k LOC em `AtlasDev/` | **draft atrasado** — codigo enterprise | dev-forge-audit `:265-281` |
| `atlas-dev-flow-map-and-product-options-v1.md` | `draft` | feature flag `atlas_dev_efficient_plan_enabled` documentado; Pipeline real | **draft atrasado** | dev-forge-audit `:114` |
| `atlas-ai-router-flow-routing-contract-v1.md` | `future` | Meta 6 RouterRuntime implementado (5 services); 36 tests verdes | **status invertido** parcial — contract v1 may differ from Meta 6 shape | inventario filesystem |
| `atlas-code-scor-1-implementation-contract.md` | `future` | desconhecido; nao verificado se existe codigo equivalente | **hipotese:** pode ser visao futura valida; precisa confirmacao | inventario filesystem |
| `atlas-cartographic-knowledge-os.md`, `atlas-sovereign-operating-system.md`, `atlas-epistemic-operating-system.md`, `atlas-next-patamar-operating-systems.md` | `future` | sem codigo equivalente | **visao futura VALIDA — NUNCA REMOVER** — tese estrategica de Layer 0.45-0.56 | canonical-architecture-index `:155-159` |

Outros docs em `future`/`draft` sao **tese estrategica intocavel** ou
sub-componentes de Forge/Programming Governance ja cobertos pelos itens
acima. Catalogo completo em `grep -lE "^status: (future|draft)" docs/engineering-knowledge-base/*.md`.

## Top Naming Duplications

Clusters onde 3+ termos co-existem para conceitos proximos. Documentados
em audit `:333-348` e verificados via filesystem.

### Cluster 1 — Forge / Atlas Code / Obra

Termos co-existentes (8 nomes, audit `:336`):

```text
Atlas Forge / Atlas Code Forge / Atlas Code Forge Fast Path /
Atlas Code Obra Command Center / Atlas Forge Continuum OS /
ForgeRivals / Forge Workspace / Obra
```

Evidencia (`ls app/Services/Ai/Programming/Atlas{Code,Forge}*.php`):

- `AtlasCodeForgeFastPathService.php` + `AtlasCodeForgeFastPathStatusService.php`
- `AtlasCodeForgeReviewCompletionService.php` + `AtlasCodeForgeUxOrchestratorService.php`
- `AtlasCodeForgeWorkIntakeService.php`
- `AtlasCodeObraCommandCenterService.php`
- `AtlasCodeEnterpriseCertificationService.php`
- `AtlasForgeContinuumCertificationService.php` + `AtlasForgeRuntimeCertificationService.php`
- `AtlasForgeRuntimeDispatchService.php` + `AtlasForgeLiveExecutionService.php`
- 5 `AtlasForgeProviderInvocation*` + 6 `AtlasForgeNativeRivals*` + 46 svcs em `ForgeRivals/`

**Diagnostico**: nao ha doc desambiguador entre `Atlas Forge` (sistema
operacional pesado), `Atlas Code Forge` (UX facade dentro do Atlas Code),
`Obra` (unidade produtiva persistente), e `Forge Continuum`/`ForgeRivals`
(certification suites). Audit recomenda doc desambiguador P3.

### Cluster 2 — Router

5 entidades sao chamadas "Router" e tem responsabilidades DISTINTAS:

| Router | Caminho | Funcao |
|---|---|---|
| `AtlasAiRouterService` | `app/Services/Ai/Router/` | Provider/flow routing legado (Atlas Decide companion) — populates `ai_router_decisions` table com `selected_provider` |
| `RouterRuntime\\FlowRouterService`/`DomainRouterService`/`RuntimeDispatchService` | `app/Services/Ai/RouterRuntime/` | **Meta 6 canonico** — Intent->Domain->Flow->Dispatch, populates `ai_atlas_router_decisions` |
| `AiIntentRouter` | `app/Services/Ai/AiIntentRouter.php` | Intent routing legado (chamado por AiGateway/AiWorker) |
| `Programming\\Sdd\\IntentRouter` | `app/Services/Ai/Programming/Sdd/IntentRouter.php` | SDD intent routing dentro de Programming |
| `AtlasControlPlaneRouterService` | `app/Services/Ai/ControlPlane/` | Control plane router (read model) |
| `AtlasForgeProviderInvocationDriverRouter` | `app/Services/Ai/Programming/` | Driver routing dentro do Forge provider invocation |
| `Context\\ContextRetrievalRouter` | `app/Services/Ai/Context/` | Context retrieval routing (Memory/RAG) |

**Diagnostico**: a palavra `Router` virou genericamente "decide qual N
chamar de M". Cada cluster e local e coerente; falta doc consolidando
"qual router e o canonico para uma decisao X" (judgment-report `:345`).

### Cluster 3 — Certification

`find app/Services -name "*Certification*Service.php"` retorna **~45 services**.
Os de maior impacto:

- `Mission\\MissionCertificationService` — Meta 1 (mission-level).
- `Evidence\\CertificationRuntimeService` — Meta 4 (universal).
- `Programming\\AtlasForgeContinuumCertificationService` — 37 invariants Forge Continuum.
- `Programming\\AtlasForgeRuntimeCertificationService` — runtime certification (boundary nao obvia com Continuum).
- `Programming\\AtlasCodeEnterpriseCertificationService` — Atlas Code surface certification.
- `Router\\AtlasAiHyperflowCertificationService` — Hyperflow certification.
- `AtlasDev\\Runtime\\AtlasDevDesktopCertificationService` — Atlas Dev Desktop.
- 30+ em `SelfConstruction/Agent*Certification*.php` (cobre agent loop primitives).

**Diagnostico**: certification e termo overloadado. Audit `:341-348`
sugere doc desambiguador "qual certification para qual unidade de trabalho".

### Cluster 4 — Receipt

Servicos "Receipt" com escopos distintos: `DecisionReceiptService` (Meta 6),
`Evidence\\ReceiptService` (Meta 4), `ToolReceiptService` (Meta 5),
`AiDecisionReceiptRefreshService` (legacy projection), 12+
`SelfConstruction\\*HumanCompletionReceipt*Service` (agent completion).

### Cluster 5 — Mission / Obra / WorkOrder / Work Packet / WorkItem

`AiMission` + `AiWorkOrder` (Meta 1) | `AtlasCodeWorkPacket` (Atlas Code) |
`AtlasProgrammingWorkItem` (Programming) | `DailyMission` (Personal Dev,
nao relacionado) | `AiDualCoreRouteDecision` (dual-core). Relacao
documentada em `atlas-kernel-mission-foundation.md:131-147`, falta indice
consolidado.

### Cluster 6 — Runtime / Domain / Flow

Diretorios: `Mission/` (Meta 1) | `RouterRuntime/` (Meta 6) |
`DomainRuntime/` (Meta 2) | `Domain/` (older) | `Runtime/` (Workspace
Profiler) | 15+ domain orchestrator namespaces (`{Research,Marketing,Finance,Programming,Cyber}Domain/`).

## Top Service / Code Overlaps

Top 6 zonas onde 2+ paths fazem trabalho similar. Audit Phase 2 cobre os 4
maiores; este inventario adiciona 2.

### Overlap 1 — Dev->Forge Escalation (4 mecanismos paralelos, audit `:387-400`)

| # | Service | Schema | Trigger |
|---|---|---|---|
| 1 | `AtlasDev/Escalation/ForgePromotionPreviewBuilder` | `atlas.dev.forge_promotion_preview.v1` (local) | score>=7 OR risk>=R4 |
| 2 | `AtlasDev/Escalation/DevToForgeEscalationPacketFactory` + `AtlasDev/Schemas/EscalationPacket.php` | `atlas.dev_to_forge.escalation_packet.v1` (canonico, NOVO 2026-05-18) | substituir item 1 |
| 3 | `App\\Services\\AtlasCode\\DevToForgePromotionService` | thread+workspace via DB | HTTP `/dev-to-forge/threads/{thread}/promote` |
| 4 | `Programming\\Kernel\\AtlasForgeHandoffAdapter::promote` | `AiDomainHandoff` (Meta 2) | `mission_type==obra` heuristic |

**Diagnostico**: os 4 ainda co-existem. Recomendacao do audit Dev/Forge
`:443-446`: P0 = consolidar para 1 path canonico emitindo `escalation_packet.v1`.
**Atualizacao 2026-05-18**: o schema canonico ja existe em codigo (verificado
via `grep -rln 'atlas\\.dev_to_forge\\.escalation_packet\\.v1' app/` -> 3 files).
Consolidacao pode iniciar.

### Overlap 2 — AiWorker bypassa Kernel canonico (audit `:365-369`)

HTTP path: `AiThreadController -> AiWorker::process() -> AiProviderManager
-> AtlasProgrammingOrchestrator` — Mission/Router/Policy/Tool/Evidence
NAO invocados. CLI path: `atlas:ai:mission-foundation` exercita o Kernel
canonico. Os dois nunca se encontram. **P0 absoluto** do audit `:443-446`.

### Overlap 3 — Forge Dispatch (audit `:131-138`)

`AtlasForgeRuntimeDispatchService` (Programming/) recebe HTTP
`/works/{project}/forge/*`; `RouterRuntime/RuntimeDispatchService` (Meta 6)
so recebe smoke CLI. Atlas Code Forge nao consome `AtlasAiRouterService`
nem o canonico Meta 6.

### Overlap 4 — Tool Policy/Evidence Bridges (audit `:371-377`)

Coexistem `ProgrammingPolicyBridge`, `ToolPolicyBridgeService` (Meta 5,
tolerancia silenciosa em `:62-71`), `RouterPolicyBridgeService` (Meta 6),
`ResearchEvidenceBridge`, `RouterEvidenceBridgeService` e evidence services
Programming-specific. Bridges tolerantes escondem violacoes em producao;
audit recomenda mode `strict`.

### Overlap 5 — Context / RAG / Compounding (audit `:309`)

`AtlasCompoundingRuntimeService` + `AtlasCompoundingEngineeringIntelligenceService`
(11 svcs, 2 tests) | `LocalRag*Service` (Context) | `EngineeringContextPackService`
| 4 `Ai*ContextBuilder/Composer/Injection` (Memory/Open Brain) | 
`ContextRetrievalRouter` + `ContextPackSelfReflectionGate`. Compounding
sub-coberto; risco de regressao.

### Overlap 6 — Self-Construction Receipt Proliferation (novo achado deste inventario)

`ls app/Services/Ai/SelfConstruction/ | wc -l` = **286 service files**.
12+ deles sao `*HumanCompletionReceipt*Service.php` (ex: `Draft`, `Verifier`,
`Dossier`, `PreflightService`, `EndgameVerifier`, `Runbook`, `PreSubmission`,
`MutationGuard`). Outros 15+ sao `AgentControlPlane*CertificationService.php`.

**Diagnostico**: o cluster `SelfConstruction/` cresceu organicamente
durante a serie de releases 2026-05-13..14 (memorias `atlas_code_obra_command_center`
+ `atlas_self_improvement_closed_loop_level7`). Audit Phase 1
contabilizou; Phase 2 nao avaliou se ha redundancia interna ao cluster.
**Hipotese:** alguns destes podem ser consolidaveis em 2-3 services
genericos com behavior modes — verificacao requer leitura linha-a-linha.

## Code Classification

Esquema do brief: canonical / active_adapter / legacy_supported /
deprecated_pending_removal / orphan_candidate / test_only / unknown.

| Cluster | Class | Nota |
|---|---|---|
| `Mission/`, `Policy/`, `Evidence/`, `ToolRuntime/`, `RouterRuntime/`, `DualCore/` | **canonical** | Meta 1/3/4/5/6 + dual-core schema |
| `ResearchDomain/`, `MarketingDomain/`, `FinanceDomain/`, demais Domain Company Runtimes | **canonical** | Meta 8A+ |
| `Programming/AtlasDev/` (138 files) | **canonical (path B HTTP)** | feature flag promove pendente |
| `Programming/AtlasForge*.php` flat (26) + `ForgeRivals/` (46) | **active_adapter** | path C HTTP em producao |
| `AtlasProgrammingOrchestrator` + `AiWorker.php` | **legacy_supported** | path A HTTP; alvo P0 |
| `AtlasDecideService` + `Router/AtlasAiRouterService` | **active_adapter** | Atlas Decide legado coexiste com Meta 6 |
| `AiIntentRouter.php` | **legacy_supported** | intent router antigo |
| `SelfConstruction/` (286 files) | **canonical (parcial) + unknown (parcial)** | core ok; 12+ HumanCompletionReceipt + 15+ AgentControlPlaneCertification merecem leitura |
| `AtlasCode/DevToForgePromotionService.php` | **active_adapter** | sera substituido por P3.1 |
| Cyber/Automation/Strategy domains | **orphan_candidate (parcial)** | runtime sim, orchestrator nao (audit `:303`) |
| Strategic Decision + Self-Improvement orchestrators | **canonical (sem teste)** | 0 tests; audit `:262` marca unsafe |

Zero itens classificados **deprecated_pending_removal** neste inventario.
Deletar qualquer item requer AP explicita.

## Riscos De Remocao Indevida

Itens que parecem candidatos a cleanup mas NUNCA devem ser removidos:

1. **Sovereign OS / Epistemic OS / Cartographic Knowledge OS / Next Patamar**
   — `status: future` legitimo, tese estrategica Layer 0.45-0.56
   (`atlas-ai-canonical-architecture-index.md:155-159`).
2. **Local Agent Memory Ingestion** — BLOCKED por design (audit `:393`).
3. **External Rivals Certification** flag — BLOCKED por design (memorias `project_atlas_forge_rivals_*`).
4. **AtlasForge CLI driver stubs** (`provider_driver_missing`) — placeholders
   para futuro provider invocation real (dev-forge-audit `:295`).
5. **`CLAUDE.md` / `AGENTS.md`** — provider projection; nao source of truth,
   mas obrigatorias.
6. **Resolver Corpus** (`docs/resolver-o-que-vale-a-pena/`) — governed legacy.
7. **Compounding services** sub-cobertos — alvo de mais testes, nao remocao.

## Cleanup Seguro Sequenciado

Lista priorizada de itens **sem risco de deletar visao futura**. Cada item
e ESCOPO DE AP, nao trabalho deste inventario.

### P0 — Consolidacao Antes De Expansao (audit Phase 2 `:443-446`)

| # | Acao | Tipo | Custo |
|---|---|---|---|
| P0.1 | Integrar AiWorker -> Mission + Router canonico | **codigo + AP** | N semanas |
| P0.2 | Endurecer `ToolPolicyBridgeService::evaluate` + `ToolReceiptService::emit` (modo strict producao + log obrigatorio em fallback) | **codigo + AP** | curto |
| P0.3 | `MissionCertificationService::runChecks` quality-aware (sair de shape-only) | **codigo + AP** | medio |
| P0.4 | Teste E2E canonico Mission->Router->Domain->Policy->Tool->Evidence->Certification | **teste + AP** | medio |

### P1 — Reconciliacao De Status

So **documentacao**, sem codigo. Reduz "achei que nao tinha mas tinha":

| # | Acao | Custo |
|---|---|---|
| P1.1 | Mudar `atlas-forge-operating-system.md` de `future` para `active` (ou `active_adapter`) com referencia ao codigo em producao | curto |
| P1.2 | Mudar `atlas-forge-operating-system-contracts.md` + `atlas-forge-operating-system-runbook.md` idem | curto |
| P1.3 | Mudar `atlas-programming-governance-system.md` de `building` para `active` | curto |
| P1.4 | Mudar `atlas-programming-governance-system-contracts.md` de `future` para `active` | curto |
| P1.5 | Mudar `atlas-dev-efficient-programming-flow-v1.md` + `atlas-dev-flow-map-and-product-options-v1.md` de `draft` para `active` (com nota de feature flag pendente) | curto |
| P1.6 | Verificar `atlas-ai-router-flow-routing-contract-v1.md` vs Meta 6 RouterRuntime — promover para `active` OU marcar `superseded` por Meta 6 | medio |
| P1.7 | Confirmar `atlas-code-scor-1-implementation-contract.md` ainda relevante; se nao, marcar `superseded` | curto |

### P2 — Naming Disambiguation (so documentacao)

| # | Acao | Custo |
|---|---|---|
| P2.1 | Criar `atlas-forge-naming-disambiguator.md` mapeando os 8 nomes do Cluster 1 -> responsabilidades | medio |
| P2.2 | Criar `atlas-router-naming-disambiguator.md` para os 5 routers do Cluster 2 | medio |
| P2.3 | Indice consolidado de "qual certification para qual unidade" (Cluster 3) | curto |
| P2.4 | Tabela `Mission/Obra/WorkOrder/WorkPacket/WorkItem` -> camada (Cluster 5) | curto |

### P3 — Consolidacao De Codigo

| # | Acao | Custo |
|---|---|---|
| P3.1 | Consolidar Overlap 1 (4 mecanismos escalation Dev->Forge) em 1 path canonico emitindo `escalation_packet.v1` | medio |
| P3.2 | Mover `AtlasForge*.php` flat para `app/Services/Ai/Programming/AtlasForge/` subdir (audit `:380`) | medio |
| P3.3 | Adicionar testes Feature para Strategic Decision e Self-Improvement orchestrators (0 tests hoje) | medio |
| P3.4 | Decidir orchestrator + registry para Cyber/Automation/Strategy OU mover para `scaffold` explicito | medio |
| P3.5 | Auditar 286 services em `SelfConstruction/` — identificar candidatos a consolidacao (12+ HumanCompletionReceipt + 15+ AgentControlPlaneCertification) | grande |
| P3.6 | Bridge Rivals -> completion loop (learning compounding) — audit `:455` | grande |

### Nunca Remover

- `atlas-cartographic-knowledge-os.md`, `atlas-sovereign-operating-system.md`,
  `atlas-epistemic-operating-system.md`, `atlas-next-patamar-operating-systems.md`
- `atlas-ai-thesis-multiplier-channel.md`
- Resolver Corpus (`docs/resolver-o-que-vale-a-pena/`)
- Driver stubs Forge CLI (`AtlasForge{Claude,Codex,Gemini}CliInvocationDriver`)
- AtlasVault sync / Open Brain context injection
- `CLAUDE.md` / `AGENTS.md` (provider projection — nao source of truth, mas necessario)

## Regras Para IA

- Nao executar P3 sem P0 + P1 estarem entregues.
- Nao mover doc para `superseded` sem confirmacao de codigo equivalente em
  producao + AP explicita.
- Nao consolidar codigo sem ler testes existentes + audit Phase 3 (futuro).
- Toda mudanca de status em doc canonica requer atualizar **este inventario**.
- Se nova IA encontrar doc `future` que parece morta, **consultar este
  inventario** antes de presumir.

## Escopo De Implementacao

Esta missao terminou quando esta doc existe + os 2 gates passam. Nenhum
codigo de producao alterado. Nenhuma doc canonica alterada exceto este
arquivo recem-criado.

## Dependencias

- `atlas-architecture-critical-judgment-report.md` — Phase 2 audit (lei).
- `atlas-dev-forge-relationship-critical-audit.md` — Phase 2 Dev/Forge (lei).
- `atlas-ai-multi-domain-implementation-sequence.md` — sequencia Meta 1..14.
- `atlas-ai-canonical-architecture-index.md` — autoridade arquitetural.
- `atlas-ai-documentation-operating-system.md` — limites de tamanho/status.

## Evidencias

Comandos usados para gerar este inventario:

```bash
grep -h "^status: " docs/engineering-knowledge-base/*.md | sort | uniq -c | sort -rn
grep -lE "^status: (future|planned|building|superseded|deprecated|historical|scaffold|draft)" docs/engineering-knowledge-base/*.md
find app/Services -type f -name "*Router*.php" | sort
find app/Services -type f -name "*Certification*Service.php" | sort
ls app/Services/Ai/SelfConstruction/ | wc -l   # 286
ls app/Services/Ai/Programming/ForgeRivals/ | wc -l   # 46
grep -rln "MissionFactoryService\|FlowRouterService" app/Http app/Filament routes   # vazio
grep -rln 'atlas\.dev_to_forge\.escalation_packet\.v1' app/   # 3 files
grep -rln 'atlas\.dual_core\.route_decision\.v1' app/   # 3 files
```

## Riscos

1. Deletar visao futura — mitigado por "Nunca Remover".
2. Cleanup gera regressao — mitigado priorizando P1 (so doc) antes de P3 (codigo).
3. Audit Phase 2 envelhece — regenerar este doc apos cada P0 entregue.
4. Naming consolidation muda imports — exige teste verde + grep de callers.

## Exemplos

- "Apagar `atlas-forge-operating-system.md`? Status future" -> NAO: status
  declarado errado, codigo em producao. Acao: P1.1.
- "Criar mais um Router" -> ja existem 5+; usar Meta 6 ou adapter.
- "Deletar `DevToForgePromotionService.php`" -> `active_adapter`; substituir
  via P3.1.

## Proximas Acoes

Operador escolhe item P0/P1 para abrir AP individual. Cada AP completada
-> atualizar este inventario. Quando Phase 3 audit rodar, regenerar este doc.

## Definition Of Done

- Top 11 docs com status duvidoso classificados (✅).
- Top 6 clusters de naming mapeados (✅).
- Top 6 zonas de overlap de codigo listadas (✅).
- Lista P0/P1/P2/P3 com 20+ itens priorizados (✅).
- Lista "Nunca Remover" explicita (✅).
- `docs-health --json` sem violacao para este arquivo (✅).
- `git diff --check` passa (✅).
- Nenhuma doc existente alterada (✅).
