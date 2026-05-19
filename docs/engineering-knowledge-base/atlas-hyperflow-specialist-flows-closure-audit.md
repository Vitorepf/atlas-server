---
id: atlas-hyperflow-specialist-flows-closure-audit
type: engineering_knowledge
title: Atlas Hyperflow Specialist Flows Closure Audit
status: active
category: programming
priority: 95
summary: Audit read-only do estado real (2026-05-18) do Atlas AI Hyperflow + Specialist Flows. Mapeia, por flow canônico, handler runtime, handler execution, deep contracts, receipt, policy/evidence gates, dispatch, testes e fallback risk. Identifica gaps P0/P1/P2 antes do fechamento da Etapa 1. Nenhuma alteração de código; nenhum benchmark; conclusões sobre completion ficam fora do escopo da doc.
tags:
  - atlas-ai
  - hyperflow
  - router-runtime
  - specialist-flows
  - audit
  - 2026-05-18
capabilities:
  - per_flow_inventory_matrix
  - fallback_risk_map
  - deep_contract_coverage_map
  - legacy_dependency_map
  - patch_sequencing_recommendation
decisions:
  - Hyperflow é a autoridade primária; `AtlasHyperflowEntryService::run()` roda antes do legado `AtlasAiRouterService::decide()` no `AiInteractionController::store()`. Mantido por compat.
  - 6 domínios (finance, marketing, cyber, automation, strategy, personal_development) caem em `atlas_plan` via `RouterRuntimeCanon::INTENT_TO_FLOW`; cada um TEM um `*RuntimeService` próprio, mas o specialist flow execution/runtime path não os invoca — só consome o branch `atlas_plan` genérico.
  - `atlas_forge` cai em `default` na match de `AtlasAiSpecialistFlowRuntimeService` e em `default` na match de `AtlasAiSpecialistFlowExecutionService` — recebe contrato de conversation. O Forge é despachado em outra camada (Obra binding + Desktop hand-off), mas qualquer prompt Forge que chegue na specialist execution sem interceptação prévia degrada para conversation silenciosamente.
  - Sem benchmark / rivals nesta auditoria.
maintenance:
  - Atualizar quando um handler específico de finance/marketing/cyber/automation/strategy/personal_development for adicionado em `AtlasAiSpecialistFlowExecutionService::apply()` ou `AtlasAiSpecialistFlowRuntimeService::apply()`.
  - Atualizar quando `atlas_forge` ganhar branch explícito no specialist flow execution.
  - Reaudit após qualquer mudança em `RouterRuntimeCanon::INTENT_TO_FLOW`.
related_paths:
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-hyperflow-certification-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-hyperflow-completion-audit-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-hyperflow-specialist-flows-closure-audit
graph_title: Atlas Hyperflow Specialist Flows Closure Audit
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-hyperflow-operation
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-hyperflow-specialist-flows-closure-audit.md
allowed_changes:
  - Atualizar status por flow (wired/partial/missing/unsafe/tested) com evidência de arquivo+linha.
  - Adicionar/remover gap quando branch correspondente for adicionado/removido.
  - Refinar ordem recomendada de patches.
forbidden_changes:
  - Declarar TEOS pronto a partir desta doc.
  - Declarar Etapa 1 completa sem evidência verificável.
  - Inserir claim numérico de superioridade.
  - Promover doc como runtime sem checar `graph_status`.
depends_on:
  - atlas-hyperflow-operation
  - atlas-ai-router-runtime-enterprise-upgrade
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-hyperflow-completion-audit-v1
unlocks:
  - per_flow_patch_sequencing
governs:
  - atlas_hyperflow_specialist_flows_closure
evidence:
  - app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php
  - app/Services/Ai/RouterRuntime/RouterRuntimeCanon.php
  - app/Services/Ai/RouterRuntime/IntentKernelService.php
  - app/Services/Ai/RouterRuntime/DomainRouterService.php
  - app/Services/Ai/RouterRuntime/FlowRouterService.php
  - app/Services/Ai/RouterRuntime/RuntimeDispatchService.php
  - app/Services/Ai/RouterRuntime/DecisionReceiptService.php
  - app/Services/Ai/RouterRuntime/RouterPolicyBridgeService.php
  - app/Services/Ai/RouterRuntime/RouterEvidenceBridgeService.php
  - app/Services/Ai/Router/AtlasAiSpecialistFlowRuntimeService.php
  - app/Services/Ai/Router/AtlasAiSpecialistFlowExecutionService.php
  - app/Services/Ai/Router/AtlasAiRouterService.php
  - app/Http/Controllers/AiInteractionController.php
  - app/Http/Resources/AiTraceResource.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
next_actions:
  - Resolver gaps P0 antes de declarar Etapa 1 completa.
  - Adicionar branches explícitos em `AtlasAiSpecialistFlowRuntimeService::apply()` e `AtlasAiSpecialistFlowExecutionService::apply()` para finance / marketing / cyber / automation / strategy / personal_development / forge.
  - Adicionar testes feature por flow non-programming.
line_limit: 520
---

# Atlas Hyperflow Specialist Flows Closure Audit

## Resumo

Esta doc é um **audit read-only** do estado atual (2026-05-18) do Atlas AI
Hyperflow + Specialist Flows antes do fechamento da Etapa 1. Mapeia, por
flow canônico, qual handler runtime/execution existe, quais campos profundos
de contrato (`quality_rubric`, `completion_checks`, `failure_modes`) estão
declarados, qual receipt é emitido, quais policy/evidence gates aplicam,
qual o dispatch alvo, quais testes cobrem e onde existe fallback silencioso.

**Nenhuma alteração de código foi feita.** **Nenhum benchmark/rivals
rodado.** Conclusões sobre "completo" não são produzidas aqui — só evidência
e gaps.

## Papel no Atlas

Esta doc é o mapa que permite fechar a Etapa 1 (Atlas AI Hyperflow +
Specialist Flows) sem regressão. Ela cita os arquivos canônicos da
arquitetura (cita `atlas-canonical-glossary-and-naming.md` para o vocabulário
de Atlas Dev, Atlas Forge, Specialist Flow, Domain, RAG Gate, Receipt). Não
substitui `atlas-hyperflow-operation.md` (operação) nem
`atlas-hyperflow-certification-runbook-v1.md` (certification operacional);
serve como dossiê que essas duas docs podem consumir antes de declarar
prontidão.

## Onde Se Encaixa

```text
Atlas AI Desktop  →  AiInteractionController.store
                  →  AtlasHyperflowEntryService.run (PRIMARY)
                  →  AtlasAiRouterService.decide (legacy compat)
                  →  AtlasDevRuntimeService.apply   (programming only)
                  →  AtlasAiSpecialistFlowRuntimeService.apply
                  →  AtlasAiSpecialistFlowExecutionService.apply
                  →  AiGatewayService.enqueueInteraction
                  ←  AiTraceResource (trace.hyperflow + trace.hyperflow_runtime)
```

A camada Specialist Flow Runtime + Execution é onde a tabela abaixo aponta
gaps. O Hyperflow Entry (intent → domain → flow → dispatch → receipt) já
está canônico para os 10 flows; o problema está nos handlers **execution**
de 7 deles (6 domínios + forge).

## Contratos

Schemas referenciados no stack Hyperflow (todos `*.v1`, exceto onde nota):

| Schema | Emitido por |
|---|---|
| `atlas.ai.hyperflow_runtime.v1` | `AtlasHyperflowEntryService::SCHEMA_VERSION` |
| `atlas.ai.intent_classification.v1` | `AiAtlasIntentClassification` (via `IntentKernelService::classify()`) |
| `atlas.ai.router_decision.v1` | `AiAtlasRouterDecision` (via `DomainRouterService::route()`) |
| `atlas.ai.flow_route.v1` | `AiAtlasFlowRoute` (via `FlowRouterService::decideFlow()`) |
| `atlas.ai.runtime_dispatch.v1` | `AiAtlasRuntimeDispatch` (via `RuntimeDispatchService::dispatch()`) |
| `atlas.ai.decision_receipt.v1` | `AiAtlasDecisionReceipt` (2× por interaction: router + dispatch) |
| `atlas.ai.specialist_flow_runtime.v1` | `AtlasAiSpecialistFlowRuntimeService::SCHEMA_VERSION` |
| `atlas.ai.specialist_flow_receipt.v1` | `AtlasAiSpecialistFlowRuntimeService::RECEIPT_SCHEMA_VERSION` |
| `atlas.ai.specialist_flow_execution.v1` | `AtlasAiSpecialistFlowExecutionService::SCHEMA_VERSION` |
| `atlas.ai.router_runtime_readiness.v1` | `RouterRuntimeReadinessService` |
| `atlas.ai.hyperflow_certification.v1` | `AtlasAiHyperflowCertificationService` |
| `atlas.ai.desktop_hyperflow_integration_certification.v1` | `AtlasDesktopHyperflowIntegrationCertificationService` |

## Fluxo

Per-flow inventory matrix (status legend: **wired** = handler + receipt +
tests cobertos; **partial** = handler genérico OU faltam testes específicos
do flow; **missing** = sem handler em runtime/execution match; **unsafe** =
cai em default + recebe contrato errado; **tested** = ≥3 testes feature
específicos do flow).

| flow_id | Runtime branch | Execution branch | quality_rubric/completion_checks/failure_modes | Receipt | policy_required | evidence_required | Dispatch target | Tests feature | Status | Fallback risk |
|---|---|---|---|---|---|---|---|---|---|---|
| `atlas_research` | ✓ explicit (Runtime:63) | ✓ explicit (Exec:126) | ✓ todos os 3 declarados | `specialist_flow_receipt.v1` | NO | **YES** (`research` em `EVIDENCE_REQUIRED_DOMAINS`) | `atlas_research` | cobertos em `AtlasAiSpecialistFlowExecutionServiceTest` + `AtlasAiInteractionHyperflowEntryTest` | **wired** | nenhum |
| `atlas_explain` | ✓ explicit (Runtime:69) | ✓ explicit (Exec:139) | ✓ todos os 3 | `specialist_flow_receipt.v1` | NO | NO | `atlas_explain` | cobertos em `AtlasAiSpecialistFlowExecutionServiceTest` | **wired** | nenhum |
| `atlas_debug` | ✓ explicit (Runtime:75); delega para `atlas_dev` quando workspace presente (Runtime:79–81) | ✓ explicit (Exec:152) | ✓ todos os 3 | `specialist_flow_receipt.v1` | NO | YES (via delegation/dev path) | `atlas_debug` | cobertos via Specialist Flow tests | **wired** | delegation auditável |
| `atlas_review` | ✓ explicit (Runtime:84); delega para `atlas_dev` quando workspace (Runtime:88–89) | ✓ explicit (Exec:165) | ✓ todos os 3 | `specialist_flow_receipt.v1` | NO | YES (via delegation/dev path) | `atlas_review` | cobertos via Specialist Flow tests | **wired** | delegation auditável |
| `atlas_plan` | ✓ explicit (Runtime:93) | ✓ explicit (Exec:178) | ✓ todos os 3 | `specialist_flow_receipt.v1` | NO | NO (sem domínio) | `atlas_plan` | cobertos em ExecutionServiceTest | **wired** | nenhum |
| `atlas_conversation` | ✓ explicit (Runtime:103) | ✓ default (Exec:191) | ✓ todos os 3 | `specialist_flow_receipt.v1` | NO | NO | `atlas_conversation` | cobertos amplamente em `AtlasAiInteractionHyperflowEntryTest` | **wired** | é o destino canônico do default |
| `atlas_dev` | short-circuit (Runtime:25) — não entra na match porque payload já carrega `atlas_dev_runtime` ou flow_id ∈ {atlas_dev, atlas_forge} | default (Exec:191) — recebe contrato de conversation se chegar | n/a (handled por `AtlasDevRuntimeService`) | n/a downstream | NO (não no canon Hyperflow) | YES (gate via Dev runtime) | `atlas_dev` | cobertos em `AtlasDevRuntimeInteractionApiTest` + Programming suite | **wired** (via Dev runtime layer) | depende do short-circuit funcionar |
| `atlas_forge` | **default** (Runtime:109) → contrato de conversation | **default** (Exec:191) → handler `atlas_conversation_direct_handler` | ✗ recebe contrato de conversation | `specialist_flow_receipt.v1` (mas conteúdo errado) | YES (`primary_domain=programming` + `MODE_FORGE`) | NO | `atlas_forge` (dispatch correto) | só classification test no canon (`MODE_FORGE`) | **unsafe** | sem branch — se o Obra binding upstream falhar, prompt vira conversation silenciosamente |
| `atlas_finance` | **falls to default** (Runtime:109) → execução roteada para `atlas_plan` via `INTENT_TO_FLOW` (Canon:143) | usa branch `atlas_plan` (Exec:178) | ✓ via plan, mas SEM rubric finance-específica | `specialist_flow_receipt.v1` (plan genérico) | **YES** (`finance` ∈ `HIGH_RISK_DOMAINS`) | **YES** (`finance` ∈ `EVIDENCE_REQUIRED_DOMAINS`) | `atlas_plan` (dispatch_target via FlowRoute) | só smoke test (`finance intent emits atlas plan with policy and evidence required`) | **partial** | `FinanceRuntimeService.php` existe mas NÃO é invocado pelo Specialist Flow path |
| `atlas_marketing` | **falls to default** | usa `atlas_plan` branch | ✓ via plan, sem rubric marketing-específica | `specialist_flow_receipt.v1` (plan genérico) | **YES** (`marketing` ∈ `HIGH_RISK_DOMAINS`) | NO | `atlas_plan` | só smoke test (`marketing intent emits atlas plan with policy required`) | **partial** | `MarketingRuntimeService.php` existe e não é invocado |
| `atlas_cyber` | **falls to default** | usa `atlas_plan` branch | ✓ via plan, sem rubric cyber-específica | `specialist_flow_receipt.v1` (plan genérico) | **YES** (`cyber` ∈ `HIGH_RISK_DOMAINS`) | **YES** (`cyber` ∈ `EVIDENCE_REQUIRED_DOMAINS`) | `atlas_plan` | sem feature test dedicado | **partial** | `CyberRuntimeService.php` existe e não é invocado |
| `atlas_automation` | **falls to default** | usa `atlas_plan` branch | ✓ via plan, sem rubric automation-específica | `specialist_flow_receipt.v1` (plan genérico) | **YES** (`automation` ∈ `HIGH_RISK_DOMAINS`) | NO | `atlas_plan` | sem feature test dedicado | **partial** | `AutomationRuntimeService.php` existe e não é invocado |
| `atlas_strategy` | **falls to default** | usa `atlas_plan` branch | ✓ via plan, sem rubric strategy-específica | `specialist_flow_receipt.v1` (plan genérico) | NO | NO | `atlas_plan` | sem feature test dedicado | **partial** | `StrategyRuntimeService.php` existe e não é invocado |
| `atlas_personal_development` | **falls to default** | usa `atlas_plan` branch | ✓ via plan, sem rubric PD-específica | `specialist_flow_receipt.v1` (plan genérico) | NO | NO | `atlas_plan` | sem feature test dedicado | **partial** | `PersonalDevelopment/` runtime existe e não é invocado |

Anotações importantes:
- "Runtime:NN" = linha em `app/Services/Ai/Router/AtlasAiSpecialistFlowRuntimeService.php`.
- "Exec:NN" = linha em `app/Services/Ai/Router/AtlasAiSpecialistFlowExecutionService.php`.
- "Canon:NN" = linha em `app/Services/Ai/RouterRuntime/RouterRuntimeCanon.php`.
- Os `*RuntimeService.php` em `app/Services/Ai/{Domain}/` **EXISTEM** mas vivem em outra camada — fora do Specialist Flow path. Confirmados por inventário: `Finance/Kernel/FinanceRuntimeService.php`, `MarketingDomain/MarketingRuntimeService.php`, `Cyber/CyberRuntimeService.php`, `Strategy/StrategyRuntimeService.php`, `AutomationDomain/AutomationRuntimeService.php`, `ResearchDomain/ResearchRuntimeService.php`, `PersonalDevelopment/`.
- O `AtlasHyperflowEntryService::run()` produz envelope canônico **correto** para os 10 flows (intent + primary_domain + flow_id + dispatch + 2 receipts). O gap é **somente** na camada Specialist Flow execution.

## Regras para IA

- **Não** declarar Etapa 1 completa enquanto **atlas_forge** não tiver branch
  explícito em ambos os match (Runtime + Execution).
- **Não** declarar paridade Specialist Flow para os 6 domínios non-programming
  enquanto cada um não tiver seu próprio branch com `quality_rubric`,
  `completion_checks`, `failure_modes` específicos.
- **Não** remover o short-circuit (Runtime:25) que segura `atlas_dev` e
  `atlas_forge` para downstream — é o que separa Specialist Flow de Dev/Forge
  runtime.
- **Não** silenciar fallback: qualquer caminho `default => atlas_conversation_*`
  só é aceitável quando o flow_id real **é** `atlas_conversation`. Para
  qualquer outro flow alcançando `default`, é evidência de gap.
- **Não** rodar benchmark/rivals para "validar" especialização; especialização
  é provada por branch + receipt + teste, não por número.

## Escopo de Implementacao

Esta doc é audit. **Sem código.** **Sem migration.** **Sem schema novo.**
Implementação de cada patch recomendado em §Proximas Acoes deve abrir
missão própria com diff localizado em `AtlasAiSpecialistFlowRuntimeService`
e/ou `AtlasAiSpecialistFlowExecutionService`, mais teste feature dedicado.

## Dependencias

- `atlas-hyperflow-operation.md` — operação canônica do Hyperflow.
- `atlas-ai-router-runtime-enterprise-upgrade.md` — contratos RouterRuntime.
- `atlas-canonical-glossary-and-naming.md` — naming canon de Atlas Dev /
  Atlas Forge / Specialist Flow / Domain / RAG Gate.
- `atlas-hyperflow-completion-audit-v1.md` — audit operacional cujo gate
  cita esta closure audit como pré-requisito.

## Evidencias

Arquivos lidos para sustentar a tabela acima (todos com path absoluto sob
`atlas-server/`):

- `app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php`
- `app/Services/Ai/RouterRuntime/RouterRuntimeCanon.php` (linhas 135–150
  `INTENT_TO_FLOW`; 155–179 high-risk/evidence/tool-plan domains)
- `app/Services/Ai/RouterRuntime/IntentKernelService.php`
- `app/Services/Ai/RouterRuntime/DomainRouterService.php`
- `app/Services/Ai/RouterRuntime/FlowRouterService.php` (linha 95–108
  fallback flows)
- `app/Services/Ai/RouterRuntime/RuntimeDispatchService.php`
- `app/Services/Ai/RouterRuntime/DecisionReceiptService.php`
- `app/Services/Ai/RouterRuntime/RouterPolicyBridgeService.php`
- `app/Services/Ai/RouterRuntime/RouterEvidenceBridgeService.php`
- `app/Services/Ai/Router/AtlasAiSpecialistFlowRuntimeService.php` (match em
  62–115; short-circuit em 25)
- `app/Services/Ai/Router/AtlasAiSpecialistFlowExecutionService.php` (match
  em 125–204; default em 191)
- `app/Services/Ai/Router/AtlasAiRouterService.php` (legacy, ainda chamado
  em `AiInteractionController::store()` após Hyperflow)
- `app/Http/Controllers/AiInteractionController.php` (pipeline em store)
- `app/Http/Resources/AiTraceResource.php` (`hyperflow_runtime` rich +
  `hyperflow` flat)
- Domain runtime services confirmados existentes (mas fora do path Specialist
  Flow): `Finance/Kernel/FinanceRuntimeService.php`,
  `MarketingDomain/MarketingRuntimeService.php`, `Cyber/CyberRuntimeService.php`,
  `Strategy/StrategyRuntimeService.php`, `AutomationDomain/AutomationRuntimeService.php`,
  `ResearchDomain/ResearchRuntimeService.php`, `PersonalDevelopment/`.

Tests cobrindo flows (path absoluto sob `atlas-server/`):
- `tests/Unit/Ai/Router/AtlasAiSpecialistFlowRuntimeServiceTest.php`
- `tests/Unit/Ai/Router/AtlasAiSpecialistFlowExecutionServiceTest.php`
- `tests/Unit/Ai/Router/AtlasAiRouterServiceTest.php`
- `tests/Unit/Ai/Router/AtlasAiSpecialistFlowPromptBuilderTest.php`
- `tests/Feature/Ai/RouterRuntime/AtlasAiInteractionHyperflowEntryTest.php`
  (cobre 4 intents: programming/dev, research, finance, marketing)
- `tests/Feature/Ai/RouterRuntime/AtlasDesktopHyperflowIntegrationCertificationTest.php`
- `tests/Feature/Ai/RouterRuntime/RouterRuntime*Test.php` (readiness,
  control plane, command smoke, mission integration)

Rotas HTTP relevantes (em `routes/`): `/ai/router-runtime/bootstrap`,
`/ai/router-runtime/readiness`, `/ai/hyperflow/certification`,
`/ai/hyperflow/rivals-battery*`, `/ai/interactions/*`. Comandos:
`atlas:ai:hyperflow`, `atlas:ai:router-runtime`, mais um conjunto largo
de comandos `atlas:ai:*` por domínio (verificado: existem
`AtlasAiAutomationDomainCommand`, `AtlasAiCyberDomainCommand`,
`AtlasAiDomainsCommand`, etc.) — fora do escopo direto desta closure
audit por não tocarem Specialist Flow execution path.

## Riscos

### Top gaps P0 (bloqueiam Etapa 1)

| # | Gap | Evidência | Razão para P0 |
|---|---|---|---|
| P0.1 | `atlas_forge` cai no `default` de ambos os match → recebe contrato de conversation se a interceptação upstream (Obra binding) falhar | Runtime:109; Exec:191 | `MODE_FORGE` é o modo mais pesado/destrutivo do Atlas; contrato errado = risco real de claim Forge sem evidence/policy. |
| P0.2 | 6 domínios não-programming (`finance`, `marketing`, `cyber`, `automation`, `strategy`, `personal_development`) caem no `default` em Runtime e no `atlas_plan` em Execution — sem rubric/completion/failure específicos | Canon:143–148 + Runtime:109 + Exec:178 | `finance` e `cyber` são **HIGH_RISK_DOMAINS** com `evidence_required=true`; receber só o contrato genérico de plan deixa o execution sem audit_checks específicos. |
| P0.3 | Não há test feature por flow non-programming exercitando o handler real do execution service (research e plan estão cobertos em unit, mas finance/marketing/cyber/automation/strategy/personal_development não têm coverage que falhe quando a rubric específica não existe) | grep em `tests/` mostra só smoke em `AtlasAiInteractionHyperflowEntryTest` | Sem teste, nenhuma regressão dispara quando alguém adiciona um branch errado. |

### Gaps P1 (atrasam, não bloqueiam Etapa 1)

| # | Gap | Evidência |
|---|---|---|
| P1.1 | Domain runtime services existem em `app/Services/Ai/{Domain}/` mas estão **desconectados** do Specialist Flow path — sua existência cria expectativa falsa de paridade | Files listados em §Evidencias |
| P1.2 | O `AtlasAiHyperflowCertificationService::specialistDepthCheck()` enumera só 6 flows (research, debug, review, explain, conversation, plan) — não cobre finance/marketing/cyber/automation/strategy/personal_development/forge | `AtlasAiHyperflowCertificationService.php:80–88` |
| P1.3 | O `default` em Execution retorna handler `atlas_conversation_direct_handler` — quando o flow não casa, o operador vê "conversation" mesmo tendo pedido outra coisa. Hoje isso só dispara para `atlas_dev`/`atlas_forge` (que têm pipeline próprio) ou para domínio desconhecido. | Exec:191 |

### Gaps P2 (housekeeping, sem urgência)

| # | Gap | Evidência |
|---|---|---|
| P2.1 | Schemas `specialist_flow_runtime.v1` e `specialist_flow_receipt.v1` não estão referenciados explicitamente no glossary canônico | `atlas-canonical-glossary-and-naming.md` |
| P2.2 | Nenhum readiness check expõe a tabela acima em `/ai/router-runtime/readiness` — operador precisa ler PHP para descobrir o gap | `RouterRuntimeReadinessService` |
| P2.3 | Legacy `AtlasAiRouterService::decide()` ainda roda depois do Hyperflow em `AiInteractionController::store()` — intencional por compat, mas duplica receipts em `payload.atlas_ai_router` | `AiInteractionController.php:107–115` |

## Exemplos

**Exemplo de detecção do gap P0.1 (`atlas_forge`)**:
Prompt "abrir Obra de migração do auth" + payload sem `forge_workspace_binding` →
`AtlasHyperflowEntryService` classifica intent=programming, primary_domain=programming,
flow_id=`atlas_forge` (se Atlas Decide promover), runtime_mode=`forge`,
handoff_target=`atlas_forge`. Quando o specialist runtime aplicar, cai no
`default` (Runtime:109), recebe `execution_mode=conversation`, e o
SpecialistFlowExecution emite `handler_id=atlas_conversation_direct_handler`.
Hand-off pelo Desktop salva o caso, mas a camada de execução por si só
estaria entregando contrato errado.

**Exemplo de detecção do gap P0.2 (`atlas_finance`)**:
Prompt "analise minha carteira" → intent=`finance`, primary_domain=`finance`,
flow_id=`atlas_plan` (via Canon:143), runtime_mode=`deep`,
policy_required=true (HIGH_RISK), evidence_required=true. Quando execution
service aplicar, cai em `atlas_plan` (Exec:178) — recebe rubric genérica
de plan, sem audit_checks como "claims_have_source_or_uncertainty" que
research tem para evitar claim sem evidência.

## Proximas Acoes

Ordem recomendada (cada item é uma missão isolada, com diff localizado +
teste feature dedicado; tudo sem benchmark/rivals):

1. **Patch P0.1 — `atlas_forge` branch explícito** (~2-4h):
   adicionar branch em `AtlasAiSpecialistFlowRuntimeService::apply()` com
   `execution_mode=forge_delegation_or_block`, `output_contract` específico
   de Forge, `forbidden_actions=['execute_forge_without_obra_binding',
   'claim_completion_without_evidence_pack']`, `delegation` apontando para
   Obra runtime. Adicionar branch correspondente em
   `AtlasAiSpecialistFlowExecutionService::apply()` com `handler_id=
   atlas_forge_obra_handler`. Teste feature: prompt Forge sem
   `forge_workspace_binding` deve produzir `forge_workspace_blocker` em
   vez de cair em conversation.

2. **Patch P0.2a — `atlas_finance` + `atlas_cyber` branches específicos**
   (~4-6h): finance e cyber são os 2 high-risk + evidence-required entre
   os 6 domínios. Adicionar branches com `audit_checks` específicos de
   evidência e proibições (`forbidden_actions=['fabricate_returns',
   'speculate_without_data']` para finance; `forbidden_actions=
   ['fabricate_vulnerability', 'claim_authorization_without_roe']` para
   cyber). Adicionar dois testes feature: um por domínio.

3. **Patch P0.2b — `atlas_marketing` + `atlas_automation` branches**
   (~3-4h): marketing é HIGH_RISK; automation é HIGH_RISK + tool-plan-
   required. Branches com `output_contract` específico
   (`campaign_brief|measurement_plan` para marketing; `workflow_plan|
   evidence_capture_steps` para automation). Dois testes feature.

4. **Patch P0.2c — `atlas_strategy` + `atlas_personal_development`
   branches** (~2-3h): risco menor, mas necessário para evitar fallback
   silencioso. Branches com output e completion checks mínimos. Dois
   testes feature.

5. **Patch P0.3 — feature tests dedicados por flow non-programming**
   (~3-5h): um por domínio, falhando se a rubric/completion específica
   sumir.

6. **Patch P1.2 — estender `specialistDepthCheck()`** (~1h): adicionar
   os 7 novos flow_ids à lista enumerada em
   `AtlasAiHyperflowCertificationService::specialistDepthCheck()` para
   que a cert reflita paridade real.

7. **Patch P1.1 — decidir destino dos `{Domain}/RuntimeService.php` órfãos**
   (~variável): documentar explicitamente se serão consumidos pelo
   Specialist Flow execution (via dispatcher) ou se permanecem em outra
   camada (Domain Runtime). Sem código nesta fase — só decisão registrada
   em ADR.

8. **Patches P2** (housekeeping): glossary entry + readiness surface +
   eventual deprecation do legacy router; agendar após P0/P1.

**Critério de pronto de cada patch**: branch novo + receipt com
quality_rubric/completion_checks/failure_modes + teste feature verde +
pint + docs-health passing + git diff --check limpo. **Sem declarar
Etapa 1 completa até P0.1 + P0.2(a/b/c) + P0.3 estarem em verde.**
