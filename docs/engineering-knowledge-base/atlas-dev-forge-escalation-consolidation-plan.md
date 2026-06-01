---
id: atlas-dev-forge-escalation-consolidation-plan
type: engineering_knowledge
title: Atlas Dev -> Forge Escalation Consolidation Plan
status: active
category: programming
priority: 90
summary: Registro operacional de consolidacao Atlas Dev -> Atlas Forge. Documenta mecanismos existentes, adapters entregues, pendencias de Router/Forge intake e caminho canonico ancorado em `atlas.dual_core.route_decision.v1` e `atlas.dev_to_forge.escalation_packet.v1`, preservando Dev e Forge como runtimes independentes.
tags:
  - atlas-dev
  - atlas-forge
  - dual-core
  - escalation
  - consolidation
  - programming
  - operational-record
capabilities:
  - escalation_mechanism_consolidation
  - dual_core_contract_alignment
  - migration_order_with_adapters
  - regression_risk_inventory
decisions:
  - Este doc e registro operacional/coordination record; codigo e schemas vivem nos services e testes relacionados.
  - Os 4 mecanismos paralelos identificados convergem para um path unico: Router -> route_decision.v1 -> Dev/Forge -> escalation_packet.v1 (quando aplicavel) -> Forge intake.
  - route_decision.v1 e escalation_packet.v1 sao contratos de codigo relacionados; este doc nao redefine seus shapes.
  - Identidade dual-core preservada: Dev e Forge continuam runtimes independentes; o contrato e o linker auditavel entre eles.
  - Migracao por adapter-first: cada mecanismo legado vira emissor do packet canonico antes de qualquer remocao.
  - Forge nao depende do Dev. Path Forge direto (`/works/{project}/forge/*`) continua valido; ele apenas passa a emitir um `route_decision.v1` proprio antes do dispatch para auditabilidade simetrica.
maintenance:
  - Atualizar quando schemas dual-core, adapters ou Router wire-up mudarem em codigo.
  - Atualizar quando qualquer um dos 4 mecanismos for migrado para emitir packet v1.
  - Atualizar quando feature flag `atlas_dev_efficient_plan_enabled` for promovido em producao.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - app/Services/Ai/Programming/AtlasDev/Escalation/EscalationDecisionEngine.php
  - app/Services/Ai/Programming/AtlasDev/Escalation/ForgePromotionPreviewBuilder.php
  - app/Services/AtlasCode/DevToForgePromotionService.php
  - app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php
  - app/Services/Ai/Programming/Kernel/ProgrammingDomainKernelCanon.php
  - app/Http/Controllers/AtlasCodeDevToForgePromotionController.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-forge-escalation-consolidation-plan
graph_title: Atlas Dev -> Forge Escalation Consolidation Plan
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-dev-forge-relationship-critical-audit
graph_status: active
graph_source: repo
human_name: "Atlas Dev -> Forge Escalation Consolidation Plan"
canonical_name: "Atlas Dev -> Forge Escalation Consolidation Plan"
technical_name: atlas-dev-forge-escalation-consolidation-plan
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md
allowed_changes:
  - Atualizar quando estado dos 4 mecanismos mudar (e.g. novo emissor, adapter implementado).
  - Refinar ordem de migracao se outros agentes alterarem contratos dual-core canonicos.
  - Adicionar novos mecanismos paralelos descobertos posteriormente.
forbidden_changes:
  - Implementar codigo/migration/contrato sem confirmar os related_paths e testes de runtime.
  - Editar contratos `atlas.dual_core.route_decision.v1` / `atlas.dev_to_forge.escalation_packet.v1`.
  - Declarar Dev como feature do Forge ou Forge como gerente do Dev.
  - Remover mecanismo legado antes de adapter intermediario emitir packet v1 com paridade.
depends_on:
  - atlas-dev-forge-relationship-critical-audit
  - atlas-dual-core-engineering-system
flows_to:
  - atlas-dual-core-engineering-system
  - atlas-architecture-critical-judgment-report
unlocks:
  - dual-core-schema-implementation-rollout
  - dev-forge-escalation-single-path
governs:
  - dev_forge_escalation_consolidation_plan
evidence:
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - app/Services/Ai/Programming/AtlasDev/Escalation/ForgePromotionPreviewBuilder.php
  - app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php
  - app/Services/AtlasCode/DevToForgePromotionService.php
evidence_refs:
  - symbol: EscalationDecisionEngine
  - test: EscalationDecisionEngineTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Unit/Ai/Programming/AtlasDev/Escalation/EscalationDecisionEngineTest.php tests/Unit/Ai/Programming/AtlasDev/Schemas/Receipt/EscalationPacketTest.php tests/Feature/Ai/Programming/DualCore/DevToForgeCanonicalPathTest.php"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Mecanismos Encontrados, Path Unico Recomendado e Ordem de Migracao antes de alterar codigo.
ai_usage_notes:
  - Este doc orienta a migracao; antes de alterar runtime, confirme reachability, testes e contrato canonico em codigo.
quality_gates:
  - all-parallel-mechanisms-listed
  - canonical-path-defined
  - migration-order-defined
  - regression-risks-listed
  - test-matrix-defined
failure_modes:
  - Consolidar prematuramente antes de verificar schemas canonicos e testes.
  - Remover mecanismo legado em uso por producao sem adapter intermediario.
  - Tratar este registro como source-of-truth dos schemas; contratos de codigo e testes governam runtime.
observability_signals:
  - count_parallel_mechanisms_emitting_packet_v1
  - count_route_decision_v1_persisted_per_day
  - count_legacy_schema_emissions_per_day
next_actions:
  - Fase 1 entregue 2026-05-18 (3/4 mecanismos dual-emit). Iniciar Fase 2 quando Router wire-up estiver pronto.
  - Aguardar Router emitir `route_decision.v1` antes de Mechanism 4 (Forge HTTP direto).
  - Manter este registro sincronizado com mudancas reais nos 4 mecanismos.
line_limit: 520
---
# Atlas Dev -> Forge Escalation Consolidation Plan

## Resumo

Auditoria critica (`atlas-dev-forge-relationship-critical-audit.md`) constatou
que Atlas Dev e Atlas Forge existem como sistemas tecnicos completos e
independentes, mas a relacao entre eles nasceu fragmentada em mecanismos
paralelos com schemas divergentes. Desde 2026-05-18, os contratos canonicos
`atlas.dual_core.route_decision.v1` e
`atlas.dev_to_forge.escalation_packet.v1` existem em codigo, migrations e
testes relacionados; este documento registra a consolidacao e separa o que ja
esta entregue do que ainda depende de Router/Forge intake.

Este registro operacional:

1. Lista cada mecanismo paralelo com arquivo, classe, schema, caller,
   evidence, estado e risco.
2. Compara cada um com os contratos canonicos esperados.
3. Recomenda um path unico (`Router -> route_decision.v1 -> Dev/Forge ->
   escalation_packet.v1 quando aplicavel -> Forge intake`) que preserva
   identidade dual-core e evita fusao incorreta.
4. Define ordem de migracao com **adapters intermediarios** para que
   producao nunca quebre durante a transicao.
5. Inventaria riscos, arquivos a tocar nas fases restantes, e testes
   obrigatorios.

NAO altera codigo por si so, nao redefine contratos canonicos e nao autoriza
remocao de mecanismos sem reachability, owner tests e quiescencia.

## Papel no Atlas

Registro operacional entre o audit critico (READ-ONLY), os schemas dual-core
ja materializados e as fases restantes de Router/Forge intake. Serve como
contrato de coordenacao para agentes que trabalharem em paralelo nesta area.

## Onde Se Encaixa

```text
atlas-dev-forge-relationship-critical-audit.md  (READ-ONLY veredict)
   |
   v
atlas-dev-forge-escalation-consolidation-plan.md  (THIS DOC, operational record)
   |
   v
dual-core schemas/adapters in code
   |
   v
remaining Router/Forge intake rollout phases
```

## Mecanismos Encontrados

Mapeamento confirmado 2026-05-18 via `grep` em `app/` + `tests/` + `routes/`.

### Mechanism 0 — Canonical schemas (documented, code-backed by adapters)

| Campo | Estado |
|---|---|
| **Source** | `atlas-dual-core-engineering-system.md:247-258` e `:279-301` |
| **Schemas** | `atlas.dual_core.route_decision.v1`, `atlas.dev_to_forge.escalation_packet.v1` |
| **Classe** | Adapters e factories relacionados vivem nos related paths; confirme shape atual via testes antes de alterar |
| **Tabela** | pode existir por ambiente; adapters toleram ausencia de DB e reportam `recorded=false` |
| **Teste** | coberto por testes DualCore/DevToForge/Escalation listados neste doc |
| **Caller** | adapters Dev/Forge e HTTP promotion path |
| **Status** | **target/canonical em rollout** — 3/4 mecanismos ja dual-emitem |

Este NAO e um mecanismo paralelo: e o destino para onde os outros mecanismos convergem.

### Mechanism 1 — EscalationDecisionEngine + ForgePromotionPreviewBuilder

| Campo | Valor |
|---|---|
| **Arquivos** | `app/Services/Ai/Programming/AtlasDev/Escalation/EscalationDecisionEngine.php`, `.../Escalation/ForgePromotionPreviewBuilder.php`, `.../Escalation/EscalationSignalScorer.php`, `.../Schemas/EscalationDecision.php` |
| **Schema emitido** | `atlas.dev.forge_promotion_preview.v1` |
| **Campos produzidos** | `intent_summary`, `changed_files`, `context_refs`, `workspace_hash`, `thread_id`, `decision_hash`, `task_contract_hash`, `run_id`, `target` ∈ {`forge_obra`, `obra_candidate`}, `reasons`, `signals`, `score`, `risk_level`, `submission_state` |
| **Trigger** | score >= 7 OR risk >= R4 (heuristica determinstica em `EscalationSignalScorer`) |
| **Caller producao** | **nenhum** (verificado: `grep ForgePromotionPreviewBuilder app/` so encontra a propria classe + comentarios) |
| **Caller teste** | `tests/Unit/Ai/Programming/AtlasDev/Escalation/ForgePromotionPreviewBuilderTest.php`, `EscalationDecisionEngineTest.php` |
| **Evidence/receipt** | `decision_hash` + `task_contract_hash` no payload; nao persiste em DB; `preview_artifact_path` aponta para JSON local |
| **Como chega ao Forge** | NAO chega — preview emitido para Attention queue, operador aprova manualmente |
| **Status** | **orfao funcional** — preview gerado existe nos testes mas a Pipeline Atlas Dev efetivamente NAO o emite hoje (Pipeline atual nao chama Builder nem Engine) |
| **Risco** | Logica de decisao moderna porem sem ponte ao path HTTP; pode divergir do schema canonico quando este nascer |

### Mechanism 2 — DevToForgePromotionService (AtlasCode HTTP)

| Campo | Valor |
|---|---|
| **Arquivo** | `app/Services/AtlasCode/DevToForgePromotionService.php` |
| **Controller** | `app/Http/Controllers/AtlasCodeDevToForgePromotionController.php` |
| **Schema emitido** | `atlas.code.dev_to_forge.promotion_candidate.v1` |
| **Campos produzidos** | `thread_id`, `workspace_slug`, `promotion_target` ∈ {`quick_intervention`, `obra_candidate`, `forge_obra`}, `candidate_id`, `overrides` (title/objective/context_summary/known_files/risks), `schema_version` |
| **Trigger** | HTTP `POST /atlas-code/dev-to-forge/threads/{thread}/promote` (operador-acionado) |
| **Caller producao** | `AtlasCodeDevToForgePromotionController` (HTTP routes/api.php:622-626) + `AtlasCodeAttentionControlPlaneService` (read-only listing) |
| **Caller teste** | tests/Feature/Ai/Programming/AtlasCode/* (multiplos arquivos) |
| **Evidence/receipt** | JSON em `storage/app/atlas-code/promotion-candidates/{workspace_slug}/{candidate_id}.json` + criacao de `AtlasProject` quando target=`forge_obra` |
| **Como chega ao Forge** | Cria linha `atlas_projects` que entra no fluxo Obra/Forge UI; nao emite `AiDomainHandoff` nem packet canonico |
| **Status** | **producao ativa** — rotas HTTP servidas, controller publico, persistencia em disco + DB |
| **Risco** | Trabalha sobre `(thread_id, workspace_slug)`; Atlas Dev efficient path opera sobre `run_id` -> bridge ausente. Comentario em `ForgePromotionPreviewBuilder.php:20-27` reconhece o gap |

### Mechanism 3 — AtlasForgeHandoffAdapter (Kernel via Mission Foundation)

| Campo | Valor |
|---|---|
| **Arquivo** | `app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php` |
| **Schema emitido** | `AiDomainHandoff` (modelo Meta 2 / Domain Runtime) com reason=`forge_escalation: <motivo>` |
| **Campos produzidos** | `mission_id`, `work_order_id`, `source_domain`=`programming`, `target_domain`=`programming`, `context_pack` (workspace, task_contract, evidence_refs, risk_register, forge_target, why_escalated), `expected_output` (`forge_obra_delivery` com `must_emit`), `evidence_refs`, `receipt_hash` |
| **Trigger** | `shouldPromote(AiMission)` quando `mission_type=='obra'` OR `ProgrammingDomainKernelCanon::shouldEscalateToForge($prompt, $type)` |
| **Caller producao** | nenhum HTTP — apenas `ProgrammingAdapterSmokeService` (CLI smoke) e `ProgrammingAdapterReadinessService` |
| **Caller teste** | `tests/Feature/Ai/ProgrammingAdapter/ProgrammingAdapterHandoffPayloadTest.php`, `ProgrammingAdapterControlPlaneTest.php` |
| **Evidence/receipt** | Row em `ai_domain_handoffs` (Meta 2) + event `programming.adapter.forge_handoff` em `ai_mission_events` + `handoff_receipt_hash` |
| **Como chega ao Forge** | Emite handoff que **AtlasForgeProviderInvocationDriver / NativeRivalsProtocolService consomem em seu proprio pipeline** — Forge services existentes NAO sao modificados |
| **Status** | **smoke/readiness CLI only** — Mission Foundation ainda nao recebe trafego HTTP de producao |
| **Risco** | Schema diferente de Mechanism 1 (preview) e Mechanism 2 (candidate); audit ja sinalizou divergencia de schema |

### Mechanism 4 — Forge HTTP path direto (sem Dev intermediario)

| Campo | Valor |
|---|---|
| **Rotas** | `POST /works/{project}/forge/fast-path`, `POST /works/{project}/forge/live-executions`, `POST /works/{project}/forge/reviews`, +27 rotas em `routes/api.php:640-720+` |
| **Schema emitido** | nenhum dos dois canonicos. Cada controller usa seu proprio shape (`AtlasCodeForgeFastPathService::dispatch`, `AtlasForgeLiveExecutionService::run`, etc) |
| **Caller producao** | Atlas Code Desktop UI (frontend) |
| **Como chega ao Forge** | Forge **e** o destino — nenhuma escalacao Dev->Forge envolvida; entrada direta ja em modo Forge |
| **Status** | **producao ativa** — caso vacuum (Forge sozinho, sem etapa Dev anterior) |
| **Risco** | Sem `route_decision.v1` ninguem audita "por que esta tarefa foi para Forge direto?". Forge nao deve depender de Dev, mas precisa emitir decisao de rota auditavel. Path valido segundo `atlas-dual-core-engineering-system.md:161,221` ("Forge nao depende de Dev"). |

### Mechanism 5 — ProgrammingDomainKernelCanon::shouldEscalateToForge (heuristica compartilhada)

| Campo | Valor |
|---|---|
| **Arquivo** | `app/Services/Ai/Programming/Kernel/ProgrammingDomainKernelCanon.php:153` |
| **Schema** | nao emite payload; e funcao pura `(rawPrompt, missionType) -> ?string` retornando motivo de escalacao |
| **Caller** | `AtlasForgeHandoffAdapter::shouldPromote` (Mechanism 3) |
| **Status** | helper interno; nao e mecanismo independente mas e fonte de divergencia heuristica (Mechanism 1 usa scorer determinstico, Mechanism 3 usa keyword match) |

### Comparativo schema -> canonico

| Mecanismo | Campos em comum com `route_decision.v1` | Campos em comum com `escalation_packet.v1` | Gaps |
|---|---|---|---|
| Doc canonica (0) | TODOS (e o destino) | TODOS | (n/a) |
| Builder (1) | `intent_summary`, `risk_level`, `reasons` (mapeavel a `reason`) | `intent` (=`intent_summary`), `why_escalated` (=`reasons`), `workspace.relevant_paths` (=`changed_files`+`context_refs`) | sem `route` enum tres-valores, sem `recommended_forge_mode`, sem `evidence_refs.{plan,senior_loop_audit,...}` estruturado, sem `target=atlas_forge` |
| AtlasCode (2) | `intent` (via overrides.objective) | `intent`, `workspace.root`(=workspace_slug), `recommended_forge_mode` (~promotion_target) | sem `route`, sem `evidence_refs` estruturado, sem `dev_interpretation`, sem `known_risks` separado, sem `source/target` enum canonico |
| HandoffAdapter (3) | `risk_level` (via mission), `intent` (via task_contract) | `intent` (raw_prompt), `dev_interpretation` (task_contract), `why_escalated`, `workspace`, `evidence_refs`, `recommended_forge_mode` (forge_target) | sem `schema` literal, sem `target=atlas_forge` literal, sem `open_questions`, sem `senior_loop_*` refs |
| Forge direto (4) | (deveria emitir `route_decision.v1` com `route=forge`) | n/a (nao e dev_to_forge) | nada implementado |

Conclusao: **nenhum mecanismo atual emite o packet canonico**. `AtlasForgeHandoffAdapter` (3) e o mais proximo de `escalation_packet.v1` porque ja consome Mission/WorkOrder/Evidence canonicos.

## Path Unico Recomendado

```text
Surface (Desktop / CLI / HTTP / IDE)
  |
  v
Atlas AI Router (Meta 6 ou substituto canonico)
  |
  v
emit  atlas.dual_core.route_decision.v1
  | route ∈ {dev, forge, dev_to_forge}
  | reason, risk_level, intent_summary, modules_touched_estimate,
  | sdd_required, evidence_required, operator_visible
  |
  +---- route=dev      ---> Atlas Dev pipeline (existente)
  |
  +---- route=forge    ---> Atlas Forge intake (existente HTTP /works/{project}/forge/*)
  |
  +---- route=dev_to_forge
          |
          v
        Atlas Dev pipeline ate descobrir Obra
          |
          v
        emit  atlas.dev_to_forge.escalation_packet.v1
          | source=atlas_dev, target=atlas_forge,
          | intent, dev_interpretation, why_escalated,
          | workspace, evidence_refs (plan, senior_loop_audit,
          | senior_loop_execution, verification_receipt,
          | error_ledger, failure_capsules),
          | known_risks, open_questions, recommended_forge_mode
          |
          v
        Forge intake valida packet + reclassifica risco + emite
        Decision Receipt + comeca Obra (ou bloqueia honestamente)
```

Invariantes preservados:

- Dev != Forge mini. Cada um continua runtime completo independente.
- Forge nao depende de Dev. `route=forge` direto continua valido.
- Sem fusao em runtime unico.
- Sem escalacao silenciosa: packet + receipt + motivo sao requisitos.
- Evidence pode ser compartilhada via `evidence_refs`; runtime/UX/sucesso continuam separados.

## Ordem de Migracao

Pre-requisito P0 entregue:
**Schemas canonicos materializados como classes, migration/tabela quando
disponivel no ambiente e testes smoke.** Adapters toleram ausencia local de DB
e reportam `recorded=false` sem perder o packet canonico.

### Fase 1 — Adapters paralelos (zero quebra, dual-emit) — **ENTREGUE 2026-05-18**

Cada mecanismo legado emite o packet canonico v1 ALEM do payload atual.
Producao continua intocada; schemas legacy permanecem inalterados.

**Estado por mecanismo (2026-05-18):**

| # | Mecanismo | escalation_packet.v1 | route_decision.v1 | Status |
|---|---|---|---|---|
| 1 | `ForgePromotionPreviewBuilder` + `DevToForgeEscalationPacketFactory` | sim, anexo no payload (`escalation_packet_v1`) | (Router upstream) | **ENTREGUE** |
| 2 | `DevToForgePromotionService` (HTTP `/atlas-code/dev-to-forge/threads/{thread}/promote`) | sim, para targets `forge_obra` e `obra_candidate` | sim, `route=dev_to_forge`, actor_type=`atlas_code_dev_to_forge` | **ENTREGUE** |
| 3 | `AtlasForgeHandoffAdapter::promote()` + `promoteWithPacket()` | sim, anexo no event `programming.adapter.forge_handoff` | sim, `route=dev_to_forge`, actor_type=`programming_adapter` | **ENTREGUE** |
| 4 | Forge HTTP direto `/works/{project}/forge/*` | n/a (nao e dev_to_forge) | pendente Fase 2 | aguarda Router wire-up |

Tests adicionados:

- `tests/Feature/Ai/Programming/DualCore/DevToForgeCanonicalPathTest.php` (5 tests, kernel adapter)
- `tests/Feature/AtlasCode/AtlasCodeDevToForgeCanonicalEmissionTest.php` (3 tests, HTTP)
- `tests/Feature/Ai/Programming/DualCore/AtlasCanonicalRuntimeE2ETest.php` (5 tests, E2E canonico cobrindo Intent -> RouterDecision -> FlowRoute -> Dispatch -> route_decision.v1 -> Adapter -> Mission/WorkOrder -> Evidence -> Certification + caso `dev_to_forge` com escalation_packet.v1 + caso blocked + guard HTTP-Kernel ADR ainda pendente)

Tolerancia: se `ai_dual_core_route_decisions` nao existir, o adapter retorna
`route_decision_v1.recorded=false` com detail auditavel; packet v1 continua
sendo emitido (independe de DB).

Saida da Fase 1: 3/4 mecanismos emitem packet v1 + route_decision.v1. Mechanism 4
(Forge HTTP direto) fica para Fase 2 (Router wire-up). **Zero remocao.**

### Fase 2 — Wire-up canonico + paridade restante

Quando packet v1 fluir dos quatro lados:

5. **Router canonico** comeca a emitir `route_decision.v1` ANTES dos 4 paths atuais. Hoje nenhum dos 4 paths HTTP/CLI passa por `RouterRuntime/FlowRouterService` — Fase 2 conecta UI ao Router para o caso novo `dev_to_forge`.
6. **Forge intake centralizado**: criar ou apontar o owner real que aceita `escalation_packet.v1` independente da origem. Existing Forge services nao mudam; o intake e camada de entrada que normaliza e despacha.
7. **Teste E2E canonico** `tests/Feature/Ai/Programming/DualCore/DevToForgeCanonicalPathTest.php`: opera prompt -> Router -> route_decision -> Dev -> escalation -> Forge intake -> assert packet v1 persistido + Forge response.
8. **Adapter audit**: comparar payload v1 produzido por cada mecanismo com payload "canonico esperado" (gerado a partir do mesmo input). Divergencia vira blocker.

### Fase 3 — Deprecation suave (legacy emite warning)

Quando Fase 2 verde por 2 semanas em producao real:

9. **Mechanism 2 schema legacy** (`atlas.code.dev_to_forge.promotion_candidate.v1`) marcado como deprecated em frontmatter + log warning em cada emissao. Continua funcionando.
10. **Mechanism 1 schema legacy** (`atlas.dev.forge_promotion_preview.v1`) idem.
11. **Mechanism 3** mantem dual-emit (packet v1 + AiDomainHandoff) porque o `AiDomainHandoff` e tabela canonica Meta 2 — nao se deprecar, sao camadas distintas.
12. **Mechanism 5** (helper `shouldEscalateToForge`) consolida com scorer determinstico da Mechanism 1 OU permanece como fallback explicito.

### Fase 4 — Remocao apos quiescencia

Quando todos os callers reais migraram:

13. Remover branches que so emitem schema legacy. Cada arquivo afetado:
   - `ForgePromotionPreviewBuilder` -> mantem; `build()` passa a retornar packet v1 (rename do schema_version constant).
   - `DevToForgePromotionService` -> `persistCandidate()` continua escrevendo o JSON local mas com `schema_version=atlas.dev_to_forge.escalation_packet.v1`.
   - `AtlasForgeHandoffAdapter` -> continua emitindo `AiDomainHandoff` (Meta 2) E packet v1; documentar como dual.
14. Atualizar testes para asserir packet v1 ao inves de schemas legacy.

## Riscos de Quebrar Producao

| Risco | Severidade | Mitigacao |
|---|---|---|
| Mechanism 2 esta em producao HTTP — qualquer alteracao no controller quebra Atlas Code Desktop | **CRITICAL** | Fase 1 e dual-emit (legacy + canonico). Schema legacy continua intacto. |
| Mechanism 1 e orfao em codigo mas tem testes unit que validam shape do schema legacy | high | Fase 1 nao remove constante; adiciona `toEscalationPacketV1()` ao lado. |
| Mechanism 3 e usado por smoke CLI; mexer no formato `AiDomainHandoff` afetaria Meta 2 | high | NAO mexer no `AiDomainHandoff`. Packet v1 e EMITIDO ALEM do handoff, nao no lugar. |
| Schemas canonicos podem estar sendo criados por outro Claude AGORA com shape ligeiramente diferente | high | Fase 1 usa `app(...)` lookup tolerante; se classe canonica nao existir, log+pular. Coordena via log de telemetria, nao via arquivo neste momento. |
| Path Forge direto (Mechanism 4) nao tem dependencia conhecida com Dev mas Atlas Code Desktop pode quebrar se rotas HTTP forem renomeadas | high | NAO renomear nenhuma rota HTTP nesta migracao. Adicionar emissao de `route_decision.v1` como pre-step transparente. |
| Naming proliferation Forge (8 nomes) torna refactor confuso | medium | Esta consolidacao NAO renomeia. Cada classe mantem nome atual; apenas o output schema converge. |
| `evidence_refs` de Dev (JSON local) e Forge (DB) nao se cruzam | medium | Fase 2 cria mapping via `escalation_packet.v1.evidence_refs` que aponta para ambos. Sem rename de tabelas. |
| Mechanism 5 (heuristica `shouldEscalateToForge`) divergente do Mechanism 1 scorer | medium | Fase 3 consolida ou mantem como fallback explicito. Nao bloqueante para Fases 1-2. |
| Deprecar legacy schema antes que todos os consumidores frontend tenham migrado | high | Fase 3 e soft (warning only). Fase 4 requer instrumentacao de zero emissoes legacy por 2 semanas. |

## Arquivos de Runtime e Pendencias

**Fase 1 entregue/relacionada (dual-emit, nao remover):**

- `app/Services/Ai/Programming/AtlasDev/Escalation/ForgePromotionPreviewBuilder.php` — mantem preview e integra packet v1 quando aplicavel.
- `app/Services/Ai/Programming/AtlasDev/Escalation/EscalationDecisionEngine.php` — runtime de decisao reachable/high.
- `app/Services/AtlasCode/DevToForgePromotionService.php` — emite promocao HTTP e packet v1 quando target=`forge_obra`.
- `app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php` — emite packet v1 ao lado de `AiDomainHandoff`.
- `app/Services/Ai/Programming/Kernel/ProgrammingDomainKernelCanon.php` — opcional: alias `shouldEscalateToForge` -> `EscalationSignalScorer` para reduzir divergencia heuristica.
- `app/Http/Controllers/AtlasCodeDevToForgePromotionController.php` — NAO mexer no contrato HTTP; apenas garantir que controller propaga `packet_v1_emitted: bool` em logs.

**Fase 2 pendente (Router + Forge intake):**

- `app/Services/Ai/RouterRuntime/...RouteDecisionEmitterService` ou owner equivalente — emite `route_decision.v1` antes do dispatch.
- `app/Services/Ai/Programming/AtlasForge/ForgeEscalationIntakeService` ou owner equivalente — entrada normalizada independente da origem.
- `tests/Feature/Ai/Programming/DualCore/DevToForgeCanonicalPathTest.php` — novo.

**Fase 3-4 (deprecation + remocao):**

- Mesmos arquivos acima, mais documentacao de migracao em `atlas-dual-core-engineering-system.md` (apos schemas existirem).

## Testes Obrigatorios Para Mudancas Futuras

Para mudar qualquer runtime relacionado, estes testes/contratos sao **bloqueantes** conforme o escopo tocado:

1. `RouteDecisionV1ContractTest` — schema da classe canonica casa com `atlas-dual-core-engineering-system.md:247-258`.
2. `EscalationPacketV1ContractTest` — schema casa com `:279-301`.
3. `ForgePromotionPreviewBuilderEmitsPacketV1Test` — Mechanism 1 dual-emite.
4. `DevToForgePromotionServiceEmitsPacketV1Test` — Mechanism 2 dual-emite quando target=`forge_obra`.
5. `AtlasForgeHandoffAdapterEmitsPacketV1Test` — Mechanism 3 dual-emite alem de `AiDomainHandoff`.
6. `ForgeDirectPathEmitsRouteDecisionV1Test` — Mechanism 4 emite `route_decision.v1` com `route=forge`.
7. `EscalationPacketV1FromAllMechanismsHasFieldParityTest` — dado mesmo input simulado, os 3 paths Dev->Forge produzem packets com fields equivalentes (audit de paridade).
8. `DevToForgeCanonicalPathTest` (E2E) — Router -> Dev -> escalation -> Forge intake assertando packet v1 persistido.
9. `NoSilentEscalationTest` — assert que TODA escalacao em log estruturado tem packet v1 ID.
10. `MissionForgeEvidenceCrossReferenceTest` — Mission Foundation evidence_refs aparecem em `escalation_packet.v1.evidence_refs.{plan,senior_loop_audit,...}`.

## Contratos

Este registro consome (nao define) os contratos canonicos:

- `atlas.dual_core.route_decision.v1` — outros agentes.
- `atlas.dev_to_forge.escalation_packet.v1` — outros agentes.

Este documento define apenas a **ordem de migracao** entre mecanismos legacy e
canonicos. Nao introduz novo schema.

## Fluxo

1. Confirmar schemas canonicos e adapters atuais em codigo.
2. Manter dual-emit sem remocao.
3. Validar paridade via test #7 acima.
4. Completar Fase 2 (Router + intake normalizado).
5. Quiescencia 2 semanas; iniciar Fase 3 (deprecation suave).
6. Quiescencia + zero emissoes legacy; iniciar Fase 4 (remocao).

## Regras para IA

- NAO alterar codigo so por leitura deste doc; confirme reachability, owner tests e contratos atuais.
- NAO editar `atlas.dual_core.route_decision.v1` / `atlas.dev_to_forge.escalation_packet.v1` sem owner tests.
- NAO renomear classes/rotas/comandos durante consolidacao — escopo aqui e schema de output.
- NAO fundir Dev e Forge em runtime unico mesmo que pareca pratico.
- Antes de remover mecanismo legacy, ASSEGURAR adapter dual-emit em producao + quiescencia.

## Escopo de Implementacao

Este doc e registro de consolidacao e limites. O escopo de runtime vive nos services relacionados; qualquer fase restante deve entrar com diff revisavel e testes focados.

## Dependencias

- `atlas-dev-forge-relationship-critical-audit.md` (audit que motivou este registro).
- `atlas-dual-core-engineering-system.md` (contratos canonicos).
- Implementacao atual dos schemas/adapters dual-core nos services relacionados.

## Evidencias

- 4 mecanismos paralelos identificados e confirmados via `grep` em 2026-05-18 (consultados arquivos `Escalation/*`, `Kernel/AtlasForgeHandoffAdapter`, `AtlasCode/DevToForgePromotionService`, `routes/api.php`).
- `atlas-dev-forge-relationship-critical-audit.md` cobre o mesmo terreno como audit READ-ONLY.

### Audit Gap 5 Fase 2 HTTP Coverage (2026-05-26)

Snapshot quantitativo persistido em `storage/atlas/evidence/gap5_phase2_audit/2026-05-26-route-decision-http-coverage.json`. Coverage real: **1/45 = 2.2%** (apenas `AtlasCodeForgeExecutionController` emite `route_decision.v1`). Escopo real de Fase 2: 44 controllers para wire (~660-1.100 linhas total), blast radius alto; AP dedicado por familia.

## Riscos

Listados em "Riscos de Quebrar Producao" acima.

## Exemplos

**Trace esperado apos Fase 2** — operador roda `atlas:cli:dev "refatora
billing engine"`:

1. CLI invoca Router canonico.
2. Router emite `route_decision.v1` com `route=dev`, `reason="task fits fast path"`, `risk_level=medium`.
3. Dev fast path executa; durante run detecta R4 risk (auth + compliance).
4. Dev emite `escalation_packet.v1` com `source=atlas_dev`, `target=atlas_forge`, `why_escalated=["sdd_required","risk_level_increased"]`, `evidence_refs.plan=...`, `recommended_forge_mode="sdd_intake"`.
5. Forge intake valida packet, reclassifica risco, emite Decision Receipt.
6. Forge inicia Obra. Mission Foundation evidence_refs cruzam com packet.
7. Operador consulta `route_decisions` + `escalation_packets` em uma query.

## Proximas Acoes

1. Manter este registro sincronizado conforme services e schemas canonicos mudam.
2. Fechar Fase 2 com Router/Forge intake owner real.
3. Coordenar mudancas por owner docs, testes e evidence, nao por chat.

### Status Phase 2 (snapshot 2026-05-26)

Phase 2 declarada como `phase_2_in_progress_gate_shipped_mechanism_1_active_with_tests`. `EscalationChannelGate` standalone shipped (13 unit tests) + schema `atlas.dual_core.escalation_channel_gate.v1` + audit Phase 2 HTTP coverage (47/47 = 100%). **Tentativa anterior de mover Mecanismo 1 para `_legacy/` revertida em 2026-05-26**: ACRUI prova reachability `active_runtime` porque testes E2E (`EndToEndRepairDecisionTest`) consomem `EscalationDecisionEngine` materialmente. O doc anterior afirmava "nenhum caller produção" mas omitia testes; honesto é tratar testes como callers canon. Mecanismo 1 permanece runtime-ativo até AP dedicado migrar os testes para o canal canonical. Mecanismo 2 (`DevToForgePromotionService`) e NOT-A-FIT (produção ativa); Mecanismo 4 exige wire de 27 rotas Forge. `phase_2_implemented_ready` so e legitimo com coverage=100% materialmente + Mecanismo 1 callers migrados + Mecanismo 4 wired + E2E HTTP verde. Honesto > claim inflada.

## Definition of Done

Plano esta pronto como documentacao quando:

- Todos os 5 mecanismos paralelos (incluindo Mechanism 5 helper) estao mapeados com arquivo, schema, caller, evidence, status, risco.
- Path unico recomendado preserva identidade dual-core e os 7 invariantes do doc canonico.
- Ordem de migracao tem 4 fases com adapters dual-emit antes de qualquer remocao.
- Lista de arquivos a tocar em Fase 1-4 e explicita.
- 10 testes obrigatorios listados para a proxima fase.
- `php artisan atlas:engineering:knowledge docs-health --json` passa.
- `git diff --check` limpo.
