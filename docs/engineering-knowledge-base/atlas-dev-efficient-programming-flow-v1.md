---
id: atlas-dev-efficient-programming-flow-v1
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow v1
status: draft
category: programming
priority: 105
summary: Contrato canonico do Atlas Dev como fluxo especializado de desenvolvimento em workspace dentro do Atlas AI. Atlas AI e o produto/superficie unica do programador e usa engines como Claude Code/Codex/Cursor por baixo; Atlas Dev contribui com a fatia de programacao governada: patch, repair, review, frontend pontual, code generation e perguntas workspace-bound. Forge e o patamar Obra-driven para producao longa e multiagente. Esta fase nao cria benchmark, Rivals, Opus challenge ou bateria messy; avaliacao competitiva fica para outro fluxo/agente.
tags:
  - atlas-dev
  - atlas-dev-sonnet
  - efficient-programming-flow
  - compact-sdd
  - code-intelligence
  - verification-receipt
  - forge-escalation
  - runtime-quality
capabilities:
  - atlas_dev_efficient_programming_flow
  - compact_sdd
  - light_task_contract
  - adaptive_gates
  - verification_receipt
  - fast_path_runtime_quality
decisions:
  - Atlas AI e o produto/superficie unica do programador; Atlas Dev e o fluxo especializado de desenvolvimento em workspace dentro do Atlas AI.
  - Atlas AI usa Claude Code/Codex/Cursor/outros engines internamente e aplica governanca por fluxos especializados.
  - Forge e o Obra OS de elite para trabalho longo, multiagente, governado e persistente; nao e apenas Atlas Dev pesado.
  - A fase atual e construcao do Atlas Dev robusto; comparacao contra Opus/Sonnet puro fica fora deste trabalho.
  - Todo write no fast path precisa de mini-spec, task contract, scope guard e verification receipt proporcionais ao risco.
  - Provider prompt deve ser projecao de contratos fortes, nao prompt artesanal fraco.
  - Fast path precisa de telemetry schema e error ledger desde o primeiro dia para aprender quando deveria ter escalado, sem depender de benchmark.
  - Avaliacao competitiva, Rivals, Opus challenge, baterias de prompts, score competitivo e arms ficam fora deste fluxo.
  - decision_locked driver_alvo evoluir_atlas_cli_dev_workflow_service 2026-05-16
  - decision_locked receipts_persistence storage_atlas_dev_receipts_run_id_json 2026-05-16
  - decision_locked budget_unit chars 2026-05-16
  - decision_locked verification_command_profiles php_laravel_ts_react_generic_no_test 2026-05-16
  - decision_locked code_namespace app_services_ai_programming_atlasdev 2026-05-16
  - decision_locked provider_lock claude_cli_sonnet_no_fallback 2026-05-16
  - decision_locked verification_receipt_schema atlas_dev_verification_receipt_v1 2026-05-16
  - decision_locked initial_surface atlas_ai_desktop_mac_via_surface_id_atlas_desktop_ai 2026-05-16
  - decision_locked governance_mapping atlas_dev_gates_are_governance_projections_or_dev_only_gates_that_cannot_contradict_governance 2026-05-16
  - decision_locked surface_agnostic_core adapters_only_know_surfaces_core_never_knows_desktop_cli_app_api 2026-05-16
  - decision_locked delivery_strategy vertical_desktop_first_then_surface_parity 2026-05-16
  - decision_locked atlas_dev_http_routes ai_interactions_atlas_dev_plan_run_stream 2026-05-16
  - decision_locked atlas_dev_realtime snapshot_replay_then_close_with_rest_show_as_source_of_truth 2026-05-16
  - decision_superseded atlas_dev_realtime_sse_primary_with_rest_status_fallback replaced_by_snapshot_replay_then_close 2026-05-16
  - decision_locked programming_dev_successor efficient_flow_replaces_classic_programming_dev_only_when_feature_flag_enabled_and_workspace_present 2026-05-16
  - decision_locked run_confirmation single_use_confirmation_token_required_for_provider_run 2026-05-16
  - decision_locked evidence_bridge verification_receipt_references_filesystem_receipts_and_optional_governance_ledger_refs 2026-05-16
  - decision_locked provider_prompt_rendering rendered_prompt_text_required_from_blade_template_in_fatia_1_5 2026-05-16
  - decision_locked endpoint_paths POST_ai_interactions_atlas_dev_plan_and_POST_ai_interactions_atlas_dev_run 2026-05-16
  - decision_locked plan_endpoint_invariants plan_never_calls_provider_never_applies_patch_zero_token_cost 2026-05-16
  - decision_locked run_endpoint_invariants run_requires_operator_confirmed_true_and_valid_task_contract_hash_referencing_persisted_plan 2026-05-16
  - decision_locked streaming_paths GET_ai_interactions_atlas_dev_runs_run_id_stream_snapshot_replay_then_close_GET_ai_interactions_atlas_dev_runs_run_id_rest_source_of_truth 2026-05-16
  - decision_locked product_positioning atlas_ai_is_enterprise_programmer_surface_atlas_dev_is_workspace_development_flow_forge_is_obra_production_os 2026-05-16
  - decision_locked engine_strategy atlas_ai_wraps_best_available_engines_atlas_dev_applies_governance_multiplier_for_workspace_development_provider_lock_fixed_per_run_atlas_decide_between_runs 2026-05-16
  - decision_locked atlas_ai_hierarchy atlas_ai_router_routes_to_specialized_flows_atlas_dev_is_workspace_development_flow 2026-05-16
maintenance:
  - Atualize este doc quando o pipeline alto-nivel, a state machine, a tabela de R-levels ou as decisoes locked mudarem.
  - Nao adicione schema detalhado aqui; pertence ao contracts doc.
  - Nao adicione passo-a-passo de implementacao aqui; pertence ao runbook doc.
  - Nao adicione criterio de medicao/benchmark aqui; e trabalho de outra equipe.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/system-graph/atlas-ai-kernel-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/tool-runtime/evidence-gates.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts
  - app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php
  - app/Services/Ai/Programming/AtlasDevRuntimeService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-v1
graph_title: Atlas Dev Efficient Programming Flow v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-dev-flow-map-and-product-options-v1
graph_status: active
graph_source: repo
owner: programming
allowed_changes:
  - Adicionar services, testes e comandos quando o fluxo alvo virar implementacao real.
  - Refinar gates adaptativos com evidencia local de custo, tempo e qualidade.
  - Atualizar criterios de escalada Dev -> Forge quando surgirem tarefas reais.
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
depends_on:
  - atlas-dev-flow-map-and-product-options-v1
  - atlas-dev-efficient-programming-flow-contracts-v1
  - atlas-dev-efficient-programming-flow-runbook-v1
  - atlas-ai-canonical-architecture-index
  - atlas-ai-kernel-architecture
  - atlas-ai-kernel-pipeline
  - atlas-ai-spec-operating-system
  - atlas-programming-governance-system
  - open-brain-context-injection
  - code-intelligence
flows_to:
  - atlas_cli_dev
  - atlas_ai_chat
  - atlas_dev_sonnet
  - atlas_forge_promotion_preview
  - atlas-dev-efficient-programming-flow-contracts-v1
  - atlas-dev-efficient-programming-flow-runbook-v1
unlocks:
  - atlas_dev_sonnet_driver
  - compact_sdd_runtime
  - light_task_contract_runtime
  - fast_path_telemetry_schema
  - fast_path_error_ledger
  - atlas_dev_runtime_quality_foundation
governs:
  - atlas_dev.efficient_programming_flow
  - atlas_dev_sonnet.fast_path
  - atlas_dev_to_forge.escalation_preview
evidence:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md
  - app/Console/Commands/AtlasCliDevCommand.php
  - app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
  - app/Services/Ai/Programming/AtlasDevRuntimeService.php
next_actions:
  - Comecar Fatia 0 do runbook (DTOs read-only).
  - Implementar mini-spec e light task contract como surfaces reais do Atlas Dev.
  - Criar verification receipt proporcional ao risco.
  - Criar builder de prompt/provider baseado em contratos, com invariantes de qualidade.
  - Criar persistencia local de receipts e error ledger antes de qualquer comparacao externa.
  - Implementar a primeira integracao ponta-a-ponta na surface Atlas AI Desktop Mac (`surface_id=atlas_desktop_ai`).
forbidden_changes:
  - Mexer em Rivals como parte da primeira implementacao deste fluxo.
  - Tratar `needs_review`, `failed`, `blocked` ou `escalate_forge` como sucesso.
  - Rodar Forge, council, topology multi-provider ou Obra automatica dentro do fast path.
  - Criar ou buscar prompts de teste, benchmark competitivo, Opus challenge, battery ou arm de Rivals nesta fase.
  - Usar prompts soltos, vagos ou artesanais quando ja houver contrato estruturado.
  - Inserir schema detalhado de artefato aqui; pertence ao contracts doc.
  - Inserir passo-a-passo de implementacao aqui; pertence ao runbook doc.
observability_signals:
  - run_id
  - workspace_hash
  - task_kind
  - risk_level
  - intent_clarity_level
  - context_pack_hash
  - selected_tiers
  - selected_files
  - gates_activated
  - provider
  - model
  - provider_calls
  - repair_attempt_count
  - scope_guard_status
  - verification_status
  - completion_state
  - escalation_triggered
  - escalation_was_correct
  - prompt_projection_hash
  - contract_completeness_status
  - receipt_persisted
  - error_ledger_written
required_tests:
  - "php artisan test tests/Feature/AtlasCliDevCommandTest.php tests/Unit/AtlasCliDevWorkflowServiceTest.php tests/Unit/Ai/Programming/AtlasDevRuntimeServiceTest.php"
requires_evidence: true
risk_level: high
line_limit: 1300
---
# Atlas Dev Efficient Programming Flow v1

## Resumo

Contrato alto-nivel do Atlas Dev como fluxo especializado de desenvolvimento em workspace dentro do Atlas AI. Atlas AI e a superficie unica do programador; Atlas Dev e a fatia governada para patch, repair, refactor leve, code generation, review, frontend pontual e perguntas workspace-bound.

## Papel No Atlas

Atlas Dev nao substitui Atlas AI nem vira router global. Ele recebe um `OperationEnvelope` roteado pelo Atlas AI Router ou por entrada direta suportada, executa o fluxo Programming workspace-bound e retorna plano, patch, receipt, bloqueio, delegacao ou preview de Forge.

## Onde Se Encaixa

Fica abaixo do Atlas AI Router e ao lado de outros fluxos especializados como Research, Explain, Debug, Review, Conversation e Forge. Reusa Programming Governance, Code Intelligence, Open Brain, Tool Runtime, Evidence Store e Promotion Preview sem criar sistema paralelo.

## Contratos

Schemas detalhados, invariants, exemplos, hashes e DTO signatures vivem em `atlas-dev-efficient-programming-flow-contracts-v1.md`. A sequencia executavel de fatias vive em `atlas-dev-efficient-programming-flow-runbook-v1.md`.

## Fluxo

O fluxo vai de intake normalizado para contexto, discovery, CompactSDD, MiniProgrammingSpec, LightTaskContract, ProviderPromptProjection, execucao, scope guard, verificacao, repair, receipt, telemetria e possivel delegacao/escalada.

## Regras Para IA

IA deve obedecer mini-spec, task contract, scope guard, verification receipt, provider prompt projection e boundary surface-agnostic. Nao deve transformar Atlas Dev em Research, Explain, Conversation, Forge ou benchmark.

## Escopo De Implementacao

Este arquivo governa a arquitetura e as decisoes locked do fluxo. Schemas detalhados ficam no contracts doc; passos de implementacao ficam no runbook; medicao competitiva fica fora deste contrato.

## Dependencias

Depende de Atlas AI Router, Atlas Kernel Pipeline, Programming Governance, Code Intelligence, Open Brain, Tool Runtime, Evidence Store, Atlas Decide e Forge Promotion Preview.

## Evidencias

Evidencias esperadas incluem artefatos persistidos em `storage/atlas-dev/receipts/<run_id>/`, telemetry, error ledger, verification receipt, scope guard receipt e testes de schema/pipeline/gate/surface.

## Riscos

Riscos principais: Atlas Dev virar router geral, prompt artesanal substituir contratos, receipt mentir completion/risk, surface acoplar core, ou fast path tentar executar Obra sem Forge. Mitigacao: contracts fortes, validators, gates, telemetry e delegacao honesta.

## Exemplos

Exemplos canonicos validos e invalidos ficam no contracts doc. Cenários de implementacao e DoD ficam no runbook.

## Proximas Acoes

Manter docs e runtime alinhados nos novos campos `flow_id`, `flow_origin`, `command_intent`, `business_context`, `attachments` e `policy_profile`; fechar os P0/P1 de runtime antes de parity CLI/App/API.

## 1. Papel No Atlas

Atlas Dev e o **fluxo especializado de desenvolvimento em workspace dentro do Atlas AI**. Ele cobre a fatia de programacao governada: patch, repair, refactor leve, code generation em workspace, review de diff/codigo, frontend pontual, multi-file edit dentro de scope contract e perguntas workspace-bound como "onde esta X no repo?".

O produto que substitui Claude Code/Cursor/Codex para o operador e **Atlas AI**, nao Atlas Dev isoladamente. Atlas AI e a interface unica; Atlas AI Router escolhe o fluxo especializado. Atlas Dev recebe pedidos ja roteados para desenvolvimento em workspace e aplica por cima contexto real, spec compacta, contrato de tarefa, prompt projection deterministico, scope guard, verification, repair, receipts, memoria e continuidade.

Quando um engine melhora, Atlas AI herda essa melhora automaticamente. Atlas Dev herda por consequencia quando o Router/Atlas Decide escolhe esse engine para tarefas de desenvolvimento. O multiplicador proprio vem da governanca e da composicao operacional, nao de tentar congelar um modelo especifico.

Este modulo define seu **contrato canonico de alto nivel**.

Documentos irmaos:

- **Contracts**: `atlas-dev-efficient-programming-flow-contracts-v1.md` — schemas YAML detalhados, invariants, exemplos validos e invalidos, signatures PHP DTO, regras de hash e versionamento.
- **Runbook**: `atlas-dev-efficient-programming-flow-runbook-v1.md` — sequencia executavel de fatias (0 a 5), paths absolutos, signatures de service, fixtures e DoD operacional por PR.

Atlas Dev fica **dentro** do Atlas AI, ao lado de outros fluxos como Research, Explain, Debug, Review e Conversation. Forge entra quando o trabalho vira **Obra**: entrega longa, multiagente, persistente, auditavel, com necessidade de maximo poder de fogo.

### 1.1 Posicionamento No Atlas Kernel Pipeline

Atlas Dev nao e pipeline paralelo. Ele e **uma instancia governada do Atlas Kernel Pipeline canonico** para o dominio Programming, com fluxo Atlas Dev (workspace-bound). Mapeamento estagio a estagio:

| Estagio do Kernel | Componente Atlas Dev |
| ---: | --- |
| 1. Surface Plane | Atlas AI Desktop Mac (primeira surface); CLI/App/API entram como paridade |
| 2. Surface Adapter | `AtlasDesktopAiAdapter` (e demais 3) sob `AtlasDev/Surface/` |
| 3. Atlas Input | texto + screenshot/clipboard via `OperationEnvelope.attachments` |
| 4. Operation Envelope | `OperationEnvelope` (schema canonico Atlas Dev, nome identico ao Kernel) |
| 5. Intent / Routing | `IntakeNormalizer` + `TaskClassifier` + `RiskLevelScorer` |
| 6. Business Context | `business_context` em `OperationEnvelope` (organization, project, environment, customer) |
| 7. Domain / Profile / Flow | Domain=Programming, Profile=atlas_dev_fast_path, Flow=programming.dev_efficient |
| 8. Context Builder | `DocContextTierSelector` + `CodeDiscoveryEngine` + `OpenBrainProjectionAdapter` |
| 9. Policy / Profile | `LightTaskContract` com `policy_profile` (autonomy_level, privacy_class, cost_budget_usd, sandbox_required) |
| 10. Atlas Decide | Atlas Dev opera em `decision_mode=manual_override` (provider_lock) ate Atlas Decide ativar Programming; migra para `auto_best_allowed` quando ativar |
| 11. Decision Receipt v2 | `(envelope_hash, prompt_projection_hash, task_contract_hash)` co-validados antes do Run; nenhum runtime executa sem essa tripla |
| 12. Runtime / Executor | `SonnetClaudeCliAdapter` (provider driver Atlas Dev locked) + Programming Harness (capability) |
| 13. Quality Gates | `ScopeGuard` + `VerificationGate` + `CompletionStateGate` |
| 14. Repair / Escalation | `FailureCapsuleBuilder` + `RepairOrchestrator` + `EscalationDecisionEngine` |
| 15. Evidence Ledger | `ReceiptStorage` (FS local) com dual reference opcional para `atlas_engineering_evidence` (DB Governance) |
| 16. Learning / Proposals | `FastPathTelemetry` + `FastPathErrorLedgerEntry` alimentam Programming Curator via Proposal Inbox; nunca auto-aplicam |
| 17. Output Renderer | `SurfaceResponseFormatter` por surface; Desktop recebe `PatchResult`/`PlanOnlyResult` + `ui_hints` opcionais |

Os 14 principios canonicos do Kernel (Surface nao decide, Provider nao decide, Tool nao decide, Domain nao burla policy, Runtime nao executa sem Decision Receipt, Modelo manual e override auditado, Repair retorna via policy/receipt/Decide, Todos eventos relevantes viram Evidence, Learning nao altera comportamento critico sem proposal/review, AtlasVault e surface humana, etc.) sao **todos enforced** no Atlas Dev. Atlas Dev nao pode contradizer nenhum.

### 1.2 Posicionamento Dentro Do Atlas AI

```text
Atlas AI (produto / superficie unica do programador)
  -> Atlas AI Router
      -> Atlas Dev          (desenvolvimento em workspace)
      -> Atlas Research     (pesquisa conceitual / aprendizado)
      -> Atlas Explain      (explicacao de codigo/arquitetura sem patch)
      -> Atlas Debug        (logs/traces/erros sem patch obrigatorio)
      -> Atlas Review       (revisao profunda de diff/PR)
      -> Atlas Conversation (chat exploratorio)
      -> Atlas Forge        (Obra-driven, multiagente, semanas/mes)
      -> futuros fluxos     (QA, Security, DB, Design, ...)
```

Atlas Dev nao compete com esses fluxos e nao tenta virar "tudo que toca codigo". Se o pedido cair fora de desenvolvimento em workspace, Atlas Dev deve retornar `routing_decision=delegate_to_other_flow` com o fluxo sugerido, ou deixar o Atlas AI Router resolver antes de criar `OperationEnvelope`.

### 1.3 Escopo Canonico Do Fluxo

Tabela operacional que define o que Atlas Dev aceita executar e o que ele recusa/delega. Esta tabela e fronteira normativa: qualquer pedido fora da coluna "Dentro" exige delegacao via `routing_decision=delegate_to_other_flow` ou roteamento upstream pelo Atlas AI Router.

| Dentro do Atlas Dev (executa) | Fora do Atlas Dev (delega) | Fluxo destino |
| --- | --- | --- |
| patch em workspace (1-6 arquivos, scope contract) | pesquisa conceitual sem workspace | Atlas Research |
| repair de teste/gate falhando com workspace ativo | explicacao ampla sem patch alvo no repo | Atlas Explain |
| refactor leve com scope contract | debug standalone sem alvo de codigo concreto | Atlas Debug |
| code generation criando/alterando arquivos no workspace | chat exploratorio amplo / brainstorm | Atlas Conversation |
| review de diff/codigo existente ligado ao workspace | review profundo de PR/diff fora de workspace ativo | Atlas Review |
| frontend pontual com componente/arquivo identificavel | Obra longa, multiagente, semanas/mes | Atlas Forge |
| pergunta workspace-bound ("onde esta X no repo?") | redesenho arquitetural amplo sem patch imediato | Atlas Forge (preview) |
| multi-file edit dentro de scope contract (max 5-6 arquivos) | mudanca em auth/billing/migration/security/production | Atlas Forge (preview) |

Regras de fronteira:

- Atlas Dev executa apenas o que cabe na coluna "Dentro". Qualquer outra coisa vira `delegate_to_other_flow` ou `escalate_forge` (R4/R5).
- "Workspace-bound" significa que o pedido tem um workspace resolvido E uma intencao concreta sobre arquivos/simbolos/testes daquele workspace.
- Pergunta workspace-bound permanece no Atlas Dev mesmo sem patch porque depende de Code Discovery + repo real. Pergunta conceitual sem workspace vai para Atlas Research/Explain.
- Quando `flow_origin=atlas_ai_router` o pedido ja chegou pre-classificado; Atlas Dev confia no roteamento e valida apenas que o tipo cai em "Dentro".
- Quando `flow_origin=direct` (entrada legada CLI/API), Atlas Dev tambem precisa checar a tabela acima e delegar se nao for desenvolvimento em workspace.

## 2. Missao

```text
Construir Atlas Dev como fluxo de desenvolvimento em workspace
dentro do Atlas AI, usando o melhor engine escolhido pelo Router
e multiplicando sua entrega com contexto real, spec, contrato,
execucao, verificacao, repair, memoria, receipts e escalada para Obra.
```

Avaliacao competitiva, Rivals e Opus challenge **ficam fora desta fase**. Outro Codex/Claude pode montar essa avaliacao depois; este contrato governa apenas a **criacao** do Atlas Dev como fluxo operacional superior dentro do Atlas AI.

## 3. Tese

Dentro da fatia de desenvolvimento em workspace, Atlas Dev precisa entregar ao modelo e ao operador um sistema que o provider puro nao tem:

- contexto certo, nao dump grande;
- arquivos e simbolos provaveis antes da chamada;
- pesquisa workspace-bound ancorada no repo real;
- mini-spec antes de patch;
- contrato leve de tarefa;
- escopo permitido e proibido;
- testes focados;
- evidence e receipt;
- repair barato com erro real;
- memoria operacional;
- continuidade de sessao de desenvolvimento e aprendizado operacional;
- delegacao para outros fluxos quando nao for desenvolvimento em workspace;
- escalada para Forge quando o trabalho deixa de ser tarefa de desenvolvimento e vira Obra.

Formula:

```text
Atlas AI geral 25x =
  melhor_engine_disponivel
* router_e_fluxos_especializados

Atlas Dev workspace 10x =
  melhor_engine_disponivel
* (
  contexto_correto
+ escopo_correto
+ spec_leve
+ teste_focado
+ repair_barato
+ evidence_honesta
+ prompt_projetado_por_contrato
+ memoria_operacional
+ continuidade
+ delegate_to_other_flow
+ escalada_para_obra
)
```

## 4. Equipes

Este projeto tem duas equipes que **nao se contaminam**:

| Equipe | Escopo | Artefatos |
| --- | --- | --- |
| **Criacao** (dono deste contrato) | desenhar e construir o fluxo: pipeline, contratos, schemas, state machine, gates, scope guard, verification, repair, escalada, drivers, surfaces | este doc, contracts, runbook |
| **Medicao / Rivals** | desenhar e construir benchmark, oraculos, baterias, scoring, criterios de entrada no Rivals, validacao estatistica | docs proprios sob `atlas-forge-rivals-*` |

Decisoes sobre oraculos, difficulty, messy human prompts, scoring, cost-normalized score e regra de entrada no Rivals **nao pertencem a este doc**. Se aparecerem em PR ou edit, recusar.

## 5. Decisao De Foco Atual

Esta fase **nao cria**:

- Rivals arm;
- benchmark competitivo;
- Opus challenge;
- bateria de prompts baguncados;
- score custo-normalizado;
- oracle privado de avaliacao;
- claim de vitoria contra Sonnet/Opus.

Esta fase **cria**:

- runtime robusto;
- fluxo de desenvolvimento em workspace dentro do Atlas AI;
- modos workspace-bound: plano, patch, repair, code generation, review, frontend pontual, debug com alvo de codigo e continuidade;
- mini-spec e task contract reais;
- prompt/provider projection de alta qualidade;
- context tier selector;
- code discovery;
- scope guard;
- verification receipt;
- repair loop barato;
- telemetry operacional;
- error ledger;
- persistencia local de receipts;
- escalada honesta para Forge preview.

### 5.1 Surface Inicial Locked

A primeira implementacao completa de ponta a ponta sera a surface **Atlas AI Desktop Mac**, a aba `Atlas AI` do aplicativo desktop.

Identidade tecnica locked:

| Produto | Surface id no payload | Entrada desktop | Entrada backend |
| --- | --- | --- | --- |
| Atlas AI Desktop Mac | `atlas_desktop_ai` | `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx` + `contract.ts` | `app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php` + `AtlasDevRuntimeService` |

O CLI Dev continua sendo infraestrutura/facade reutilizada. A UX primaria para validar o fluxo de operador, plano, contexto, receipts e repair e o Atlas AI Desktop. CLI, App e API entram depois como surfaces de paridade, nao como produto inicial.

### 5.2 Principio Surface-Agnostic

Desktop-first e uma estrategia de entrega, nao acoplamento de arquitetura.

O core do Atlas Dev recebe apenas:

```text
OperationEnvelope
```

e retorna apenas:

```text
PlanOnlyResult | PatchResult
```

Regra dura: nenhum service em `app/Services/Ai/Programming/AtlasDev/{Schemas,Discovery,PromptProjection,Pipeline,Provider,Gate,Repair,Escalation,Persistence,Telemetry}/` pode conhecer Desktop, CLI, App ou API. Conhecimento de surface vive somente em `app/Services/Ai/Programming/AtlasDev/Surface/`.

Adapters de surface sao thin translators:

```text
payload nativo da surface -> OperationEnvelope
PlanOnlyResult|PatchResult -> resposta nativa da surface
```

`ui_hints` pode existir para facilitar render no Desktop, mas e sempre projecao derivada dos artefatos canonicos. Ele nunca decide rota, risco, provider, escopo, gate ou completion.

## 6. Nao Objetivos

- Nao recriar Forge no fast path.
- Nao usar council ou topology multi-provider por default.
- Nao criar Obra automaticamente.
- Nao carregar todos os docs enterprise em toda tarefa.
- Nao desenhar benchmark, Rivals, Opus challenge ou bateria de prompts nesta fase.
- Nao declarar sucesso sem receipt.

## 7. Arquitetura No Atlas AI

| Camada | Uso | Produto | Governanca |
| --- | --- | ---: | --- |
| Engines crus | Claude Code, Codex, Cursor, Sonnet, outros | motores internos | nenhuma ou propria do engine |
| Atlas AI Router | decide qual fluxo atende o pedido | orquestrador de produto | roteamento, composicao, memoria e policy |
| Atlas Dev | desenvolvimento em workspace: patch, repair, review, frontend pontual, code generation, perguntas repo-bound | fluxo especializado dentro do Atlas AI | compacta, adaptativa e verificavel |
| Outros fluxos Atlas AI | Research, Explain, Debug, Review, Conversation, QA, Security, DB, Design | fluxos especializados | propria por dominio |
| Atlas Forge | Obra definida: semanas/mes, multiagente, auditavel, persistente, maximo poder de fogo | Obra Production OS | forte, replay/evidence/topology/workspace persistente |

Forge **nao e Atlas Dev mais forte**. Forge e outro fluxo/produto operacional: Obra-driven, com evidence/replay/topology, workspace persistente, coordenacao multiagente e governanca pesada. Atlas Dev detecta e prepara a promocao quando o trabalho vira Obra; a criacao de Obra continua exigindo decisao humana.

### 7.1 Posicionamento De Produto

```text
Claude Code / Codex / Cursor / outros engines
  = motores internos que evoluem independentemente

Atlas AI
  = superficie unica do programador
  = Router + fluxos especializados + engines internos

Atlas Dev
  = fluxo de desenvolvimento em workspace
  = repo intelligence + spec + patch + repair + review + frontend pontual
    + verification + repair + receipts + memoria

Forge
  = sistema de producao de Obra
  = execucao longa, multiagente, governada, persistente, auditavel
```

O "10x" do Atlas Dev e tese estrutural para a fatia de desenvolvimento em workspace: cada run carrega contexto, escopo, spec, prompt projection, gates, evidence e memoria que engines crus nao possuem de forma integrada. O "25x" do Atlas AI vem da composicao de multiplos fluxos especializados por Router. O "20x" do Forge sobre o fluxo Dev vem quando existe Obra: decomposicao, paralelismo, workspace persistente, review gates, replay e execucao automatizada de longo prazo.

### 7.2 Principio Da Composicao Multiplicadora

Atlas AI nunca concorre contra a evolucao dos engines. Ele **embrulha e multiplica** o melhor engine disponivel por meio de fluxos especializados. Atlas Dev e o fluxo que aplica essa multiplicacao na fatia de desenvolvimento em workspace.

```text
resultado_atlas_ai =
  melhor_engine_atual
  x router_e_fluxos_especializados

resultado_atlas_dev =
  melhor_engine_atual
  x governance_atlas_dev_workspace
```

Implicacoes:

- se Codex, Claude, Cursor ou outro engine salta `N` vezes, Atlas AI herda esse salto automaticamente; Atlas Dev herda quando esse engine for escolhido para desenvolvimento;
- o multiplicador proprio do Atlas Dev vem de Code Intelligence, Open Brain, MiniSpec, TaskContract, ScopeGuard, Verification, Repair, Receipt, Telemetry e Plan-first na fatia workspace-bound;
- programador nao escolhe entre Atlas e engines: abre Atlas AI, e Atlas AI Router escolhe fluxo/engine por baixo;
- provider lock e fixo **por run** para impedir fallback escondido;
- Atlas Decide pode trocar o lock **entre runs** quando outro engine ficar melhor para uma categoria de tarefa;
- Forge aplica a mesma logica em escala Obra: `Forge = Atlas AI Router + Obra workspace + multiagente + replay/evidence pesado`.

## 8. Pipeline Completo

```text
Surface primaria: Atlas AI Desktop Mac (`surface_id=atlas_desktop_ai`)
Surfaces de paridade: CLI Dev | App | API
-> OperationEnvelope
-> Intake normalizado
-> Workspace + permission preflight
-> Classificacao de tarefa
-> Risk level R0-R5
-> Scope mode compact | structural
-> DocContextTierSelector
-> CodeDiscoveryManifest
-> OpenBrainProgrammingProjection
-> CompactSDD
-> MiniProgrammingSpec
-> LightTaskContract
-> ProviderPromptProjection
-> RoutingDecision
   -> read_only_answer
   -> atlas_dev_fast_path
   -> forge_promotion_preview
-> ProviderDecision (Sonnet locked, sem fallback)
-> ShortPlan
-> ScopedExecution (1 call principal)
-> PatchOrNoPatchReason
-> ScopeGuardReceipt
-> FocusedVerification
-> VerificationReceipt
-> CheapRepair (FailureCapsule -> mesma provider/model -> rerun do gate)
-> CompletionState
-> EscalationDecision (se aplicavel)
-> FastPathTelemetry
-> FastPathErrorLedgerEntry (se aplicavel)
-> Learning/Cartography proposal (se aplicavel)
```

## 9. State Machine

```text
idle
-> intake_received
-> preflight_ready | blocked_no_workspace | blocked_permissions
-> classified
-> context_budgeted
-> context_selected
-> code_discovery_ready
-> compact_sdd_ready | structural_spec_required
-> mini_spec_ready
-> task_contract_ready
-> prompt_projected
-> route_decided
   -> read_only_answering
   -> fast_path_planning
   -> forge_promotion_preview
-> provider_selected
-> executing
-> patch_projected | no_patch_needed
-> scope_guarding
-> verifying
   -> passed                 -> completed
   -> needs_review           -> completed_with_risk | repair_planned | forge_promotion_preview
   -> failed                 -> repair_planned | forge_promotion_preview | failed_closed
-> repair_executing
-> verifying (loop limitado)
-> escalate_forge
   -> promotion_preview_created
   -> awaiting_human_decision
-> telemetry_emitted
```

Estados finais:

- `completed`;
- `completed_with_risk`;
- `needs_human_review`;
- `failed_closed`;
- `promotion_preview_created`;
- `blocked`.

Estados de gate:

- `passed`;
- `failed`;
- `needs_review`;
- `skipped`;
- `waived`.

## 10. Classificacao De Tarefa

| `task_kind` | Exemplos | Modo inicial |
| --- | --- | --- |
| `question` | explicar fluxo, localizar arquivo, ler diff | read-only |
| `patch` | bug pequeno, ajuste local | fast path |
| `repair` | teste/gate falhando | fast path repair |
| `review` | revisar diff/codigo | read-only ou fast path |
| `frontend` | tela, screenshot, UI pontual | fast path com visual gate |
| `risky` | auth, billing, migration, prod, security | plan-only + Forge preview |

## 11. Risk Levels R0-R5

| Risco | Exemplos | Gates minimos | Tentativas | Estado maximo sem Forge |
| --- | --- | --- | --- | --- |
| R0 | pergunta/read-only | context refs, no-patch receipt | 1 call, 0 repair | `passed` ou `needs_review` |
| R1 | typo, docs, 1 arquivo reversivel | git status, scope guard, diff receipt | 1 call, 0-1 repair | `passed` |
| R2 | bug pequeno 1-2 arquivos | R1 + teste focado se codigo | 1 call + 1 repair | `passed` ou `failed` |
| R3 | 3-5 arquivos, frontend, refactor leve | R2 + lint/typecheck barato | 1 call + ate 2 repairs | `passed` ou `needs_review` |
| R4 | auth, billing, migration, db/API/UI, >5-6 arquivos | plan-only, risk receipt, rollback | 0 patch Dev por default | `escalate_forge` ou `needs_human_review` |
| R5 | multiagente, replay, auditoria, longa duracao | Forge/Obra | Forge policy | Forge-only |

R-level e **sinal tecnico de risco**, nao a unica fronteira de produto. Atlas Dev cobre o trabalho diario do programador, inclusive pesquisa, debug, review e refactors pontuais que podem parecer tecnicamente complexos. Forge e acionado primariamente por **Obra declarada** ou por sinais fortes de Obra.

Regras:

- R0-R3 sao territorio nativo do Atlas Dev.
- R4 sem Obra pode permanecer em Atlas Dev como plan/review/debug ou patch excepcional pequeno, reversivel e confirmado pelo operador.
- R4/R5 com Obra declarada, duracao longa, multiagente, auditoria pesada ou release gate viram Forge.
- R5 e Forge-only.
- Nenhum R-level permite burlar mini-spec, task contract, scope guard, verification e receipt.

## 12. Artefatos (Visao Geral)

Os artefatos canonicos operacionais sao **17**, agrupados em quatro camadas. Schemas detalhados, invariants, exemplos e signatures PHP DTO vivem em `atlas-dev-efficient-programming-flow-contracts-v1.md`.

### 12.1 Camada Plano (o que vamos fazer)

| Artefato | Funcao |
| --- | --- |
| `OperationEnvelope` | entrada normalizada do intake (surface, workspace, intent, constraints, preflight) |
| `CompactSDD` | classificacao + risco + scope mode + context budget + hashes |
| `MiniProgrammingSpec` | behavior contract (goal, assumptions, expected files, acceptance, rollback) — **obrigatorio para todo write** |
| `LightTaskContract` | execution contract (tools allowed, files allowed/forbidden, validation commands, repair policy, escalation triggers, provider lock) |

### 12.2 Camada Contexto (o que sabemos)

| Artefato | Funcao |
| --- | --- |
| `ContextRetrievalPlan` | tier selection + budget + missing sources (output do `DocContextTierSelector`) |
| `CodeDiscoveryManifest` | likely files/symbols/tests com confidence levels (`confirmed_fact`, `strong_inference`, `hypothesis`, `blocking_ambiguity`) |
| `OpenBrainProgrammingProjection` | projection compacta do Open Brain (schema `atlas.open_brain.programming_projection.v1`), refs preferidas a texto |
| `ProviderPromptProjection` | prompt deterministico projetado dos artefatos anteriores; nasce do contrato, nao improvisa |

### 12.3 Camada Receipt (o que aconteceu)

| Artefato | Funcao |
| --- | --- |
| `ProviderCallResult` | receipt seguro da chamada ao provider (provider/model, exit, duration, hashes e erros; sem stdout/stderr cru em surface) |
| `DiffParseResult` | resultado deterministico do parser de diff (`patch`, `no_patch_needed`, `blocked`, `invalid`) antes de aplicar patch |
| `PatchApplyResult` | receipt da aplicacao do patch no workspace antes de scope guard e verification |
| `ScopeGuardReceipt` | diff vs `LightTaskContract.allowed_files` + watched/forbidden |
| `VerificationReceipt` | gates + tests + cost + completion + escalation (schema `atlas.dev.verification_receipt.v1`) |
| `SeniorEngineerLoopAudit` | audit plan-time de ambiguidade, plano multi-step, architecture-aware editing, cockpit, learning handoff e hardening |
| `SeniorEngineerLoopExecution` | receipt operacional pós-Run/worker para execução, debug loop, verification e handoff para curator/error ledger |
| `FailureCapsule` | input determinístico para repair (gate, command, exit_code, primary_error_excerpt, failure_signature, decision) |
| `EscalationDecision` | quando vira Forge (target, reasons, signals, score) |

### 12.4 Camada Telemetria (como aconteceu)

| Artefato | Funcao |
| --- | --- |
| `FastPathTelemetry` | sinal operacional emitido uma vez por run, mesmo em failed/blocked |
| `FastPathErrorLedgerEntry` | registro append-only de falhas operacionais e missed escalations (alimenta tuning futuro) |

## 13. Contratos De Qualidade Do Runtime

Contratos adicionais obrigatorios para construir o Atlas Dev:

- `ProviderPromptProjectionContract`;
- `FastPathTelemetrySchema`;
- `FastPathErrorLedger`;
- `CertInvariantRegistry`.

Contratos de benchmark como `PrivateOracleContract`, `DifficultyClassifierContract`, `NeedsReviewScoringPolicy` e `CleanVsMessyClassifierContract` ficam explicitamente diferidos para outro fluxo. Eles nao bloqueiam a Fatia 0 de construcao do runtime.

## 14. Invariantes De Cert

Cada item novo do fast path precisa de cert antes de entrar no fluxo real:

| Item | Cert minimo |
| --- | --- |
| `fast_path_contract` | estados, transicoes, invariants e forbidden paths testados |
| `compact_sdd_schema` | schema exige acceptance, evidence e escalation triggers |
| `context_budget_policy` | budget, truncation e missing required source testados |
| `adaptive_gate_policy` | gates nao-negociaveis e gates por risco testados |
| `forge_escalation_thresholds` | thresholds derivados de sinais observaveis |
| `provider_prompt_projection` | prompt gerado de contratos, sem campos obrigatorios ausentes |
| `verification_receipt` | evidence minima, gates e completion state testados |
| `senior_engineer_loop` | audit e execução operational provam Plan -> Run -> gates -> learning handoff sem auto-aplicar |
| `fast_path_error_ledger` | falha, missed escalation e aprendizado operacional registrados |

Gates **nao-negociaveis** para qualquer write:

- `mini_spec_before_code_gate`;
- `light_task_contract_gate`;
- `scope_guard_light`;
- `verification_gate`;
- `receipt_gate`;
- `completion_state_gate`;
- `forge_escalation_gate`.

Adaptive gate pode adicionar ou endurecer gate. **Nao pode remover** esses gates para tarefa com write.

## 15. Context Strategy (Alto Nivel)

Contexto e **selecao deterministica, nao dump**.

Todo item de contexto carrega: `reason`, `source`, `hash`, `freshness`, `provider_safe`, `budget_cost`.

Refs sao preferidas a texto bruto. Excerpts entram apenas quando a confianca exige.

### 15.1 Doc Context Tiers

| Tier | Quando carregar | Forma no prompt |
| --- | --- | --- |
| `core` | sempre em programming estrutural | projecao compacta de Atlas Dev, Open Brain, retrieval, Governance |
| `code_intelligence` | sempre que houver workspace/codigo | `code_refs`, simbolos, rotas, comandos, testes |
| `sdd` | patch, ambiguidade, multi-arquivo, risco medio | `compact_sdd`, task contract, gates |
| `interface` | UI, Desktop/App, Atlas Code, screenshot | regras de surface e verificacao visual |
| `forge` | risco alto, >5-6 arquivos, security/migration/billing/auth | preview de escalada, nao full Forge |
| `obras` | sessao longa, handoff, Obra/Workspace | refs persistentes de workspace/evidence |

### 15.2 Context Budget (Chars)

| Modo | Budget Open Brain |
| --- | ---: |
| pergunta/read-only | 4k-6k |
| bug pequeno 1-2 arquivos | 8k-12k |
| patch medio/review/debug | 12k-20k |
| frontend visual | 16k-24k |
| Forge preview | 20k-32k |
| full Forge/Obra | fora do fast path |

Se exceder o budget:

- preservar `core + code_intelligence`;
- cortar refs de menor prioridade;
- marcar `truncated=true`;
- registrar `missing_sources`;
- escalar se fonte obrigatoria nao couber.

Unidade do budget e **chars** (decisao locked 2026-05-16).

### 15.3 Retrieval Barato

```text
intent
-> risk
-> tier selector
-> cheap lexical/code discovery
-> compact Open Brain
-> provider call
-> focused gates
-> repair capsule
```

Ordem operacional:

1. metadata da task, workspace, git root, thread/resume;
2. Knowledge DB por docs canonicos e tiers;
3. Code Intelligence por modulo/simbolo/rota/comando/teste/doc link;
4. `rg` lexical para confirmar paths reais;
5. AST/ctags/tree-sitter apenas quando custo compensa;
6. excerpts so para top candidates ou baixa confianca.

Vector search pode sugerir candidatos, mas **nao bypassa** ranking, freshness, privacidade, escopo ou contradicao.

## 16. Provider Policy

Default do fast path:

- provider: `claude_cli`;
- model: Sonnet configurado;
- **uma** chamada principal;
- ate **uma** tentativa de repair quando ha gate deterministico (mais por R-level);
- mesmo provider/model no repair (sem fallback escondido);
- sem council;
- sem fallback automatico para Forge;
- `gemini_cli` proibido em write;
- override manual respeitado mas registrado em `decision_mode=manual_override`.

Provider lock e enforced no `LightTaskContract`. Receipt registra provider/model utilizado.

Variantes futuras (fora do escopo desta fase):

- `atlas_dev_codex` pode reutilizar o mesmo contrato;
- Atlas Decide pode escolher Sonnet/Codex depois que o contrato estiver estavel;
- execucoes governadas devem registrar provider/model sem fallback oculto.

## 17. Gates Adaptativos

| Gate | Bloqueia | Funcao |
| --- | --- | --- |
| `intake_risk_gate` | sim | classifica kind, risco e permissao de write |
| `context_budget_gate` | sim | corta contexto ou escala se obrigatorio faltar |
| `mini_spec_before_code_gate` | sim para write | exige mini-spec antes de patch |
| `light_task_contract_gate` | sim para write | exige files, teste, rollback e evidence |
| `scope_guard_light` | sim | diff so pode tocar escopo permitido |
| `verification_gate` | sim | roda teste/comando proporcional ou no-test reason |
| `receipt_gate` | sim | completion exige evidence verificavel |
| `completion_state_gate` | sim | impede sucesso narrativo |
| `forge_escalation_gate` | sim | para quando virou Forge |

Adaptive significa **proporcional ao risco**, nao "skip por preguica". Cada gate define quando se aplica em funcao do R-level; nenhum gate e opcional dentro do seu R-level.

### 17.1 Mapeamento Programming Governance Para Fast Path

Atlas Dev nao cria um segundo sistema de governanca. Ele e a **fast lane governada** do Atlas Programming Governance System.

Cada gate do Atlas Dev e uma destas duas coisas:

1. projecao compacta de um gate universal de Programming Governance;
2. gate Dev-only necessario para operar o fast path.

Gates Dev-only existem porque o fast path precisa de contratos operacionais que o runner universal nao modela diretamente. Eles podem adicionar controle; nao podem relaxar ou contradizer uma lei do Governance.

Projecoes compactas:

| Atlas Dev gate | Projecao de Programming Governance | Regra |
| --- | --- | --- |
| `intake_risk_gate` | `ProgrammingPlacementGate` | decide se a tarefa pertence ao fast path, read-only ou Forge preview |
| `context_budget_gate` | `ProgrammingCodeIntelligenceGate` | seleciona contexto real, aplica budget e bloqueia se fonte obrigatoria faltar |
| `mini_spec_before_code_gate` | `ProgrammingSpecBeforeCodeGate` | exige spec compacta antes de qualquer write |
| `scope_guard_light` | `ProgrammingScopeGuardGate` | diff so passa dentro do contrato de escopo |
| `receipt_gate` | `ProgrammingEvidenceGate` | completion exige receipt persistido e evidence refs |
| `completion_state_gate` | `ProgrammingCompletionGate` | impede sucesso narrativo sem gates verdes |

Gates Dev-only justificados:

| Atlas Dev gate | Por que existe | Invariante |
| --- | --- | --- |
| `light_task_contract_gate` | O fast path precisa de allowed/forbidden files, commands, rollback, repair policy e provider lock antes da chamada | nao pode permitir write sem spec, escopo e evidence |
| `verification_gate` | O fast path precisa executar comandos focados e gerar status verificavel antes de completion | nao pode converter `unverified` em `passed` |
| `forge_escalation_gate` | O fast path precisa parar R4/R5 antes de patch e gerar preview humano | nao pode criar Obra automaticamente |

Invariant de revisao: todo gate Atlas Dev deve ser (a) projecao de Governance ou (b) Dev-only documentado aqui. Nenhum gate Dev pode remover placement, spec-before-code, scope guard, evidence ou completion.

## 18. Scope Guard

O contrato de escopo nasce **antes** da chamada ao provider.

Campos minimos (schema completo no contracts doc):

- `allowed_files`;
- `allowed_paths`;
- `watched_files`;
- `forbidden_files`;
- `expected_max_files`;
- baseline de `git status`;
- baseline de `git diff`.

Regras:

- tocar arquivo proibido bloqueia completion;
- tocar arquivo nao previsto mas defensavel vira `needs_review`;
- tocar mais de 5-6 arquivos ou 3+ camadas tende a `escalate_forge`;
- mudancas pre-existentes do usuario sao marcadas no receipt;
- scope expansion exige novo receipt/proposta, nao tag de "ajustei mais".

## 19. Verification E Repair

### 19.1 Completion States

| Estado | Significado | Sucesso cheio |
| --- | --- | --- |
| `passed` | gates verdes, scope ok, receipt presente, teste/motivo valido | sim |
| `needs_review` | patch plausivel, mas evidence incompleta ou risco residual | nao |
| `failed` | provider/comando/teste/gate falhou e repair nao resolveu | nao |
| `blocked` | workspace, permissao, ambiguidade ou decisao humana faltando | nao |
| `escalate_forge` | risco/complexidade/budget excedido | nao |
| `no_patch_needed` | read-only/review sem patch com refs suficientes | depende |

`unverified` **nunca** vira `passed`. Esta regra e enforced no `completion_state_gate`.

### 19.2 Repair Loop

```text
gate falha
-> FailureCapsule
-> mesma provider/model
-> menor patch possivel
-> rerun do gate/teste falho
-> receipt atualizado
-> passed | failed | needs_review | escalate_forge
```

Parar repair quando:

- mesma `failure_signature` falha duas vezes;
- diff cresce sem necessidade;
- surge escopo novo;
- teste falho pede arquitetura;
- contexto necessario excede budget;
- risco vira R4/R5.

Limites por R-level estao na tabela de Risk Levels (secao 11).

### 19.3 Verification Command Profiles

Tres perfis pre-cabeados (decisao locked 2026-05-16):

| Perfil | Lint | Test | Quando |
| --- | --- | --- | --- |
| `php_laravel` | `composer lint` ou `vendor/bin/pint --test` | `composer test -- --filter=<class>` | atlas-server |
| `ts_react` | `eslint <files>` + `tsc -b` | `pnpm test --filter=<glob>` | atlas-desktop, atlas-app |
| `generic_no_test` | `git diff --stat` | `no_test_reason` obrigatorio | docs, scripts, configs |

O `LightTaskContract` declara qual perfil aplicar. O `verification_gate` falha se o perfil exigir teste e nem teste nem `no_test_reason` aparecer.

## 20. Forge Escalation

Forge escalation tem dois gatilhos distintos.

### 20.1 Gatilho Primario: Obra Declarada

Se o operador declara Obra, ou se o trabalho claramente exige producao longa, multiagente, persistente e auditavel, Forge e o caminho primario. Isso inclui:

- implementacao prevista de semanas ou mes;
- multiplas frentes independentes;
- necessidade de handoff entre sessoes/operadores;
- review/replay/evidence pesada;
- release gate ou compliance forte;
- pedido explicito de Forge, Obra, RFC de arquitetura ou execucao automatizada longa.

Neste caso, Atlas Dev nao tenta "resolver grande". Ele monta contexto, plano, riscos, contrato inicial e **promotion preview** para Forge/Obra.

### 20.2 Gatilho Secundario: Complexidade Detectada

Escalar **antes** de patch quando houver:

- security, auth, billing, PII, compliance, production;
- migration, rollback, data loss;
- alteracao esperada >5-6 arquivos;
- 3+ camadas (db + API + UI, por exemplo);
- contexto >40k chars;
- thread >=24 mensagens;
- file breadth >=6;
- necessidade de replay, auditoria, review humano ou release gate.

Gerar `obra_candidate`/preview quando houver sinais medios:

- thread >=12 mensagens;
- contexto >=12k chars;
- file breadth >=3;
- falha recorrente >=2;
- escalation signal score >=4.

Escalation signal score >=7 recomenda `forge_obra`, mas **nao cria Obra automaticamente**. A criacao de Obra exige decisao humana, exceto quando a surface ja estiver dentro de uma Obra ativa e o operador tiver autorizado execucao Forge por contrato.

`EscalationDecision` registra: `target`, `reasons`, `signals`, `score`, `human_action_required`.

## 21. Evidence Minima Por Modo

| Modo | Evidence minima |
| --- | --- |
| read-only | docs/files lidos, resposta, limites de confianca, zero write |
| plan-only | `CompactSDD`, `MiniProgrammingSpec`, `LightTaskContract`, motivo de nao executar |
| patch | diff/hash, changed files, scope guard, teste/comando, output hash |
| repair | receipt da falha, `FailureCapsule`, patch pequeno, rerun do gate |
| frontend | patch + screenshot/verificacao visual ou `needs_review` |

## 22. Fora Do Escopo Desta Fase

Esta fase **nao** define nem implementa:

- Rivals;
- Opus challenge;
- benchmark competitivo;
- battery de prompts;
- oracle privado de avaliacao;
- score custo-normalizado;
- arms locais `sonnet_pure`/`opus_pure`;
- claim de vitoria.

Esses itens pertencem a outro Codex/Claude e a outro contrato. Aqui, o unico objetivo e construir o Atlas Dev robusto.

## 23. Quality Build Gates

Gates de qualidade para a **construcao** do Atlas Dev (engenharia, nao benchmark):

| Gate | Bloqueia | Evidencia |
| --- | --- | --- |
| `contract_schema_tests` | sim | schemas serializam, validam e rejeitam campos faltantes |
| `prompt_projection_tests` | sim | prompt contem objetivo, contrato, contexto, escopo, tests e stop conditions |
| `context_selection_tests` | sim | tiers corretos, paths existentes, missing refs honestos |
| `scope_guard_tests` | sim | diffs fora de escopo falham |
| `verification_receipt_tests` | sim | receipts persistem status, gates, testes e evidence |
| `repair_capsule_tests` | sim | repair usa erro real e nao amplia escopo |
| `escalation_policy_tests` | sim | R4/R5 nao fazem patch Dev |
| `no_rivals_leakage_tests` | sim | nenhum fluxo chama Rivals/arm/benchmark |
| `no_template_pollution_tests` | sim | doc principal nao pode ter template canonico duplicado antes das secoes numeradas |

Esses testes sao de **engenharia do runtime**, nao benchmark competitivo.

## 24. Implementacao (Sumario)

Sequencia operacional de fatias (detalhe em `atlas-dev-efficient-programming-flow-runbook-v1.md`):

| Fatia | Entrega | Valor user-facing |
| --- | --- | --- |
| 0 | Schemas (DTOs read-only dos 17 artefatos operacionais) + validators + testes de serializacao/hash | fundacao, sem efeito user-facing |
| 1 | `DocContextTierSelector` + `CodeDiscoveryEngine` + `OpenBrainProjectionAdapter` | Atlas passa a achar arquivos certos antes de chamar provider |
| 1.5 | `ProviderPromptBuilder` + `TelemetryEmitter` + `ErrorLedgerWriter` + `ReceiptStorage` | prompt nasce de contratos + persistencia + telemetria desde dia 1 |
| 2 | Plan-only pipeline end-to-end (Intake, Classifier, RiskScorer, SpecComposer, Orchestrator) + CLI `atlas:cli:dev --efficient` | operador ve plano curado antes de gastar token |
| 3 | One-call Sonnet via `claude_cli` + `ScopeGuard` + `VerificationGate` + `CompletionStateGate` + `VerificationReceipt` + comando `atlas:dev:run` | write real com evidence determinística |
| 4 | `FailureCapsuleBuilder` + `RepairOrchestrator` + limites por R-level | recovery loop completo |
| 5 | `EscalationDecisionEngine` + Atlas AI Desktop Mac first + Surface Adapters de paridade (CLI Dev, App, API) + evolucao de `AtlasCliDevWorkflowService` | fluxo disponivel primeiro na surface primaria e depois nas demais surfaces |

Empacotamento de entrega vertical:

| Marco | Entrega | Surface |
| --- | --- | --- |
| 1 | Foundation backend: schemas, discovery, prompt projection, persistence, telemetry | sem UI |
| 2 | Plan-only visivel: Contexto + Plano no Atlas AI Desktop | Desktop first |
| 3 | One-call visivel: diff, tests, receipt e stream de fases | Desktop first |
| 4 | Repair visivel: attempts, failure capsule, receipt acumulado | Desktop first |
| 5 | Surface parity: CLI Dev, App e API adapters thin + escalation preview | todas |

Apos Fatia 5, esta equipe **encerra**. Outra equipe (Medicao / Rivals) toma o fluxo pronto e desenha benchmark separadamente.

## 25. Decisoes Fechadas

| # | Decisao | Valor | Locked At |
| ---: | --- | --- | --- |
| 1 | Driver alvo | evoluir `AtlasCliDevWorkflowService` | 2026-05-16 |
| 2 | Persistencia de receipts | `atlas-server/storage/atlas-dev/receipts/<run_id>/*.json` | 2026-05-16 |
| 3 | Budget unit | chars | 2026-05-16 |
| 4 | Verification command profiles | `php_laravel`, `ts_react`, `generic_no_test` | 2026-05-16 |
| 5 | Code namespace | `app/Services/Ai/Programming/AtlasDev/{Schemas,Discovery,Gate,Persistence,...}` | 2026-05-16 |
| 6 | Provider lock | `claude_cli` + Sonnet, sem fallback | 2026-05-16 |
| 7 | Schema de receipt | `atlas.dev.verification_receipt.v1` | 2026-05-16 |
| 8 | Artefatos canonicos | Artefatos operacionais em 4 camadas (Plano/Contexto/Receipt/Telemetria), incluindo receipts Senior Engineer Loop | 2026-05-16 |
| 9 | Ordem de fatias | 0 -> 1 -> 1.5 -> 2 -> 3 -> 4 -> 5; nao pular | 2026-05-16 |
| 10 | Surface inicial | Atlas AI Desktop Mac via `surface_id=atlas_desktop_ai` | 2026-05-16 |
| 11 | Relacao com Governance | Atlas Dev gates sao projecoes de Governance ou Dev-only justificados que nao contradizem Governance | 2026-05-16 |
| 12 | Core surface-agnostic | Somente adapters em `AtlasDev/Surface/` conhecem surfaces | 2026-05-16 |
| 13 | Entrega | Marcos verticais Desktop-first, depois paridade CLI/App/API | 2026-05-16 |
| 14 | Rotas HTTP | `plan` e `run` separados; `run` exige `operator_confirmed=true` + `task_contract_hash` | 2026-05-16 |
| 15 | Realtime | SSE snapshot-replay-then-close em `/ai/interactions/atlas-dev/runs/{run_id}/stream` + fallback REST canonico em `/ai/interactions/atlas-dev/runs/{run_id}` | 2026-05-16 |
| 16 | Feature flags | `atlas_dev.efficient.plan_enabled` e `atlas_dev.efficient.run_enabled` em `config/atlas_dev.php` (env `ATLAS_DEV_EFFICIENT_PLAN_ENABLED` / `ATLAS_DEV_EFFICIENT_RUN_ENABLED`); workspace presente e surface suportada | 2026-05-16 |
| 17 | Run confirmation | `confirmation_token` single-use emitido pelo `plan`, persistido como hash HMAC-SHA256 em `atlas_dev_confirmation_tokens` (DB, não filesystem), expira em 5min, invalida apos uso, vinculado a `(run_id, task_contract_hash)` | 2026-05-16 |
| 18 | Evidence bridge | `VerificationReceipt.evidence_refs[]` carrega path local e `governance_ledger_ref` opcional | 2026-05-16 |
| 19 | Prompt rendering | `ProviderPromptBuilder` produz `rendered_prompt_text` obrigatorio via template Blade em Fatia 1.5 | 2026-05-16 |
| 20 | Stream lifecycle | snapshot-replay-then-close: backend replay artefatos persistidos + `stream_closed` final; sem long-lived keepalive; cliente continua via REST `/runs/{run_id}` | 2026-05-16 |
| 21 | Config canonica | `config/atlas_dev.php` é o root canônico do fluxo (flags, paths, timeouts, ttl token, redaction). `config/atlas.php` não é fonte | 2026-05-16 |
| 22 | APP_KEY obrigatorio | Plan/Run falham fechado com `ATLAS_DEV_KEY_MISSING` (500) se APP_KEY não for base64 com ≥32 bytes — pré-requisito do HMAC de confirmation_token | 2026-05-16 |
| 23 | Migrations obrigatorias | `atlas_dev_confirmation_tokens` (tokens DB+HMAC) e `atlas_dev_run_index` (cache de status REST) precisam estar migradas antes de habilitar plan/run | 2026-05-16 |
| 24 | Path redaction | Toda response HTTP usa `workspace_label`/`workspace_hash` e `persisted_artifact_refs` (refs `receipts/<run_id>/<file>`); paths absolutos so internos | 2026-05-16 |

Decisoes adiadas (nao bloqueiam Fatia 0):

- UI no Atlas Code sem duplicar SCOR-1 (Fatia 5 ou posterior);
- Migracao receipts JSON -> Postgres/Obra (apos Fatia 5);
- Suporte a Codex/outro modelo no mesmo contrato (futuro);
- Precificacao de chamadas CLI quando tokens/custo nao vierem do provider (futuro);

## 26. Regras Para IA Implementadora

Quando uma IA pegar este conjunto de docs para implementar:

1. Leia **este doc** primeiro (contrato).
2. Leia `atlas-dev-efficient-programming-flow-contracts-v1.md` (schemas).
3. Leia `atlas-dev-efficient-programming-flow-runbook-v1.md` (sequencia).
4. Comece pela Fatia 0, PR 0.1. **Nao pule**.
5. Nao discuta claim, vitoria, benchmark, Rivals ou Opus challenge como proxima acao deste fluxo.
6. Nao busque, crie, cure ou proponha prompts de teste.
7. Nao puxe council/topology/Obra automatica para dentro do fast path.
8. Nao introduza prompt artesanal; prompt vem de `ProviderPromptProjection`.
9. Cada fatia tem DoD operacional verificavel; **nao avance** sem DoD verde.
10. A primeira surface completa e Atlas AI Desktop Mac; CLI/App/API sao paridade.
11. Apos Fatia 5 verde, transfira para a equipe de medicao com cert local `available`.

## 26.1 Endpoints HTTP Canonicos (Locked 2026-05-16)

Toda surface entra no core via dois endpoints separados e um canal de streaming. Core e surface-agnostic; surfaces sao adapters thin sob `app/Services/Ai/Programming/AtlasDev/Surface/`.

### Plan

```text
POST /ai/interactions/atlas-dev/plan
Headers: X-Atlas-Token
```

Invariantes:

- Nunca chama provider. Custo de token = 0.
- Nunca aplica patch.
- Falha fechado com `ATLAS_DEV_KEY_MISSING` (500) se APP_KEY ausente ou não-base64 ≥32 bytes (pré-requisito do HMAC de token).
- Falha fechado com `ATLAS_DEV_PLAN_DISABLED` (503) se `atlas_dev.efficient.plan_enabled=false`.
- Para `surface_id=atlas_desktop_ai`, falha fechado com `ATLAS_DEV_DESKTOP_DISABLED` (503) se `atlas_dev.efficient.desktop_enabled=false`.
- Para Desktop, `workspace` pode ser slug de Projeto (`atlas`, `blackink`, etc.); o boundary HTTP resolve via `config/atlas_projects.php` para `workspace_path` existente antes de criar `OperationEnvelope`. O core persiste apenas o path resolvido. Slug sem path acessivel falha 422 antes de token/run.
- Produz `OperationEnvelope`, `CompactSDD`, `ContextRetrievalPlan`, `CodeDiscoveryManifest`, `OpenBrainProgrammingProjection`, `MiniProgrammingSpec`, `LightTaskContract`, `ProviderPromptProjection` e `routing_decision`.
- Persiste artefatos em `storage/atlas-dev/receipts/<run_id>/` (path interno absoluto). Response HTTP devolve `persisted_artifact_refs` relativos (`receipts/<run_id>/<file>`), `workspace_label` (basename) e `workspace_hash`; nunca path absoluto.
- Quando `routing_decision = atlas_dev_fast_path`, retorna `task_contract_hash` + `confirmation_token` em `confirmation.token` (single-use, expira em 5min, vinculado a `(run_id, task_contract_hash)`).

### Run

```text
POST /ai/interactions/atlas-dev/run
Headers: X-Atlas-Token
Body: { "run_id": "...", "task_contract_hash": "...", "confirmation_token": "...", "operator_confirmed": true }
```

Invariantes:

- Falha fechado com `ATLAS_DEV_RUN_DISABLED` (503) se `atlas_dev.efficient.run_enabled=false`.
- Para runs cujo `OperationEnvelope.surface_id=atlas_desktop_ai`, falha fechado com `ATLAS_DEV_DESKTOP_DISABLED` (503) se `atlas_dev.efficient.desktop_enabled=false`.
- Falha fechado com `ATLAS_DEV_KEY_MISSING` (500) se APP_KEY ausente/invalida.
- Exige `operator_confirmed = true` literal. Truthy strings, 1, "true" rejeitam 400 (`OPERATOR_NOT_CONFIRMED`).
- Exige `task_contract_hash` referenciando plan persistido. Mismatch rejeita 422 (`TASK_CONTRACT_HASH_MISMATCH`).
- Exige `confirmation_token` HMAC valido vinculado a `(run_id, task_contract_hash)`, single-use, dentro do TTL. Ausente, invalido, expirado, ja consumido ou contract-mismatch rejeitam 403 com error.code especifico (`CONFIRMATION_TOKEN_*`).
- Executa Execution + Repair + Receipt.
- Provider lock `claude_cli` + Sonnet; sem fallback.
- Persiste `ProviderCallResult`, `DiffParseResult`, `PatchApplyResult`, `ScopeGuardReceipt`, `VerificationReceipt`, `FailureCapsule` (se aplicavel), `EscalationDecision` (se aplicavel), `FastPathTelemetry`, `FastPathErrorLedgerEntry` (se aplicavel) e atualiza `atlas_dev_run_index`.
- Se provider responder fora do output contract, `DiffParseResult.mode=invalid` registra erros (`no_unified_diff_detected`, `no_no_patch_needed_marker`, `no_blocked_marker`, etc.) e `ProviderCallResult.raw_response_hash` preserva rastreabilidade sem repetir chamada paga.
- Response devolve `persisted_receipt_refs` relativos + resumo sem stdout/stderr bruto (`raw_response_hash`, byte counts, exit metadata); nunca path absoluto.

### Stream (SSE, canal primario)

```text
GET /ai/interactions/atlas-dev/runs/{run_id}/stream
Headers: X-Atlas-Token, Accept: text/event-stream
```

Modo locked: **snapshot-replay-then-close**. Backend escreve, em ordem deterministica, um evento `phase:` por artefato ja persistido + `receipt:` (se houver) + `stream_closed:` final, depois fecha a conexao. Sem long-lived keepalive nesta fase. Eventos canonicos: `phase`, `test_started`, `test_finished`, `repair_planned`, `repair_executing`, `repair_finished`, `escalation_triggered`, `receipt`, `stream_closed`.

Receipt sempre persiste no DB+filesystem antes do `stream_closed`. Live async (tail de log de execução) e evolucao futura — clientes ja devem assumir `stream_closed` como sinal canônico de fim, com fallback REST cobrindo qualquer estado intermediário. Falta de artefato no momento da conexao = 404 (`RUN_NOT_FOUND`).

### Status REST (fallback canonico)

```text
GET /ai/interactions/atlas-dev/runs/{run_id}
Headers: X-Atlas-Token
```

Fonte de verdade do estado atual: `completion_state`, `has_receipt`, `has_scope_guard_receipt`, `has_plan`, `routing`, `workspace_label` (sem absoluto), `workspace_hash`, `task_contract_hash`, `envelope_hash`, `*_receipt_hash`, `persisted_artifact_refs`. Cliente que nao suporta SSE ou perdeu o stream deve consultar este endpoint — é a base do contrato de retomada.

### Regra surface-agnostic

Esses 4 endpoints sao a UNICA fronteira HTTP do Atlas Dev. Toda surface (Desktop, CLI, App, API) consome esses endpoints via seu adapter thin. Core `AtlasDevFastPathOrchestrator` nunca conhece a surface chamadora.

## 26.2 Handoff Para Self-Improvement Curator

Atlas Dev **nunca auto-aplica** mudancas operacionais com base em seus proprios sinais. Telemetria e error ledger sao **dados**, nao decisao.

Fluxo de handoff canonico:

```text
FastPathTelemetry + FastPathErrorLedgerEntry
  -> Programming Curator (dono do dominio Self-Improvement para Programming)
  -> Proposal Inbox (Human Review queue)
  -> humano revisa, aprova/rejeita/refina
  -> patch governado aos thresholds/heuristicas via fluxo proprio
```

Atlas Dev escreve, Curator analisa, humano decide. Esse loop e enforced no estagio 16 do Kernel ("Learning nao altera comportamento critico sem proposal/review").

## 26.3 Atlas Dev Como Template Para Outras Verticais

Atlas Dev e o **primeiro fluxo** especializado do Atlas AI a ser implementado com governanca completa. O padrao operacional (Discovery -> CompactSDD -> MiniSpec -> TaskContract -> PromptProjection -> ScopeGuard -> Verification -> Receipt -> Repair -> Escalation -> Telemetry -> ErrorLedger) e **replicavel** em outras verticais do Atlas AI: Research, Explain, Debug, Review, Marketing, Finance, Cyber Security, etc.

Princípio canonico do Kernel: **"Tudo repetido vira Core"**. Quando outros fluxos replicarem este padrao, os componentes genericos sobem para `app/Services/Ai/Atlas/Kernel/...` e Atlas Dev passa a usar essa versao Core. Hoje, todos os componentes vivem em `app/Services/Ai/Programming/AtlasDev/...` porque ainda nao ha replicacao real.

Implicacoes para esta equipe:

- ao desenhar artefatos, separar mentalmente o que e **especifico de Programming** vs o que pode virar **Core compartilhado** com outras verticais;
- naming, schemas e contratos devem ser **suficientemente genericos** para futura promocao a Core sem rename forcado;
- evitar acoplar Atlas Dev a `programming.*` em nomes de schema quando o conceito for genérico (ex: `OperationEnvelope` e generico; `MiniProgrammingSpec` e Programming-specific).

## 26.4 Sumario Operacional (Estado Real Implementado)

Resumo curto do que **ja foi entregue** e do que ainda e follow-up. Detalhe operacional vive em `atlas-dev-efficient-programming-flow-runbook-v1.md` §15.1.

### Flags Atlas Dev Efficient

Tres niveis (todos `false` por padrao em production):

- `ATLAS_DEV_EFFICIENT_PLAN_ENABLED` → libera `POST /ai/interactions/atlas-dev/plan` (zero-provider, seguro ligar primeiro).
- `ATLAS_DEV_EFFICIENT_RUN_ENABLED` → libera `POST /ai/interactions/atlas-dev/run` (so apos Plan verde em prod).
- `ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED` → libera consumo via surface Desktop.

Desligar = flag `false` → controller responde 503 `ATLAS_DEV_{PLAN,RUN}_DISABLED`, sem efeito colateral.

### APP_KEY obrigatorio (fail-closed)

- `APP_KEY` precisa ser `base64:...` com ao menos 32 bytes decodificados (saida canonica de `php artisan key:generate`).
- Plan e Run **falham fechado** com `500 ATLAS_DEV_KEY_MISSING` quando a chave esta ausente ou curta. Nao ha fallback publico, nao ha "default key", e o operador sempre precisa rotacionar antes de habilitar runs.
- Detalhe canonico em `atlas-dev-efficient-programming-flow-runbook-v1.md` §15.1.2 e §15.1.4.

### Smoke CLI canonico

- Publico (igual ao que o Desktop emite): `php artisan atlas:cli:dev "..." --efficient --json`.
- Diagnostico interno (zero-provider, sem token): `php artisan atlas:dev:debug:smoke --intent="..." --workspace=/abs/path --json`. **Nao** e contrato publico de CLI; serve so para isolar regressao no pipeline plan-only.

### Stream snapshot-replay-then-close

- `GET /ai/interactions/atlas-dev/runs/{run_id}/stream` faz **snapshot-replay-then-close**: replay deterministico de eventos `phase:`, `receipt:` (se houver) e `stream_closed:` final, depois fecha a conexao.
- Nao ha long-lived keepalive nesta fase. Cliente trata `stream_closed` como fim canonico; qualquer estado intermediario ou retomada vem de `GET /runs/{run_id}` (REST, fonte de verdade).
- Live async (tail de log incremental) e evolucao futura — clientes ja devem ler o REST como source-of-truth.

### Path redaction nas respostas HTTP

- `HttpResponseRedactor` (camada surface) reescreve toda string que comece com o workspace absoluto:
  - `workspace_label = basename($workspace)` (sem `/Users/...`);
  - `workspace_hash` continua sendo o identifier provider-safe;
  - artefatos persistidos surgem como `persisted_artifact_refs` / `persisted_receipt_refs` no formato `receipts/<run_id>/<file>`, nao como path absoluto.
- Paths absolutos permanecem **so** internamente (storage, discovery, telemetria local).
- Smoke de verificacao: o body de `POST /plan` ou `POST /run` nunca pode conter `/Users/` nem o caminho de `storage/atlas-dev/receipts/`.

### Validacao operacional

```bash
# Suite canonica (core + endpoints + adapters)
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Programming/AtlasDev tests/Feature/Ai/Programming/AtlasDev

# Smoke HTTP end-to-end com PipelineRunExecutor real + fakes deterministicos
/opt/homebrew/bin/php artisan test tests/Feature/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHttpSmokeTest.php

# Health dos docs canonicos Atlas Dev
/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json
```

`atlas:engineering:knowledge docs-health --json` deve devolver `status: ok` com zero `violations`. Qualquer claim alterado neste doc passa por esse gate antes de virar canon.

### Follow-ups explicitos (nao entregues)

- **Bundle hash publico para o operador** — o Plan ja pina server-side dois hashes na linha HMAC-keyed do confirmation_token (`task_contract_hash` e `compact_sdd_hash`), e o `PipelineRunExecutor` valida ambos antes de chamar o provider (mismatch = 422 `TASK_CONTRACT_HASH_MISMATCH` / `COMPACT_SDD_TAMPERED`). MiniSpec ainda carrega `compact_sdd_hash` no disco como terceira camada. O que **ainda** nao existe e um hash unico do bundle completo `(envelope, compact_sdd, mini_spec, task_contract, prompt_projection)` publicado pelo Plan para o operador validar lado a lado, e o pin HMAC ainda nao cobre `envelope_hash` ou `prompt_projection_hash` (so `task_contract_hash` + `compact_sdd_hash`). Esperado em fatia futura.
- **Live async stream** (`phase:` em tempo real durante a execucao real) — ver §26.1 / `15.1.6`. Hoje so existe snapshot-replay-then-close + REST poll.
- **Outras surfaces** (App, API publica) — Desktop e canonico hoje; CLI tem paridade limitada via `atlas:cli:dev`. App/API completos sao trabalho futuro.

## 27. Regra Final

Atlas Dev + Sonnet ganha por **sistema**, nao por modelo:

```text
menos contexto inutil,
mais arquivo certo,
menos escopo errado,
mais teste focado,
mais evidence,
repair pequeno,
falha honesta,
Forge quando precisa.
```

Sem isso, e so provider com branding. Com isso, Atlas tem uma chance real de virar uma maquina de programacao diaria com performance e qualidade extrema.

Este doc e contrato canonico. Mudanca relevante neste fluxo passa por aqui antes de virar codigo, schema ou implementacao.
