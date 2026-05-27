---
id: atlas-dev-efficient-programming-flow-v1
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow v1
status: active
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
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-02.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-03.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-04.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-v1
graph_title: Atlas Dev Efficient Programming Flow v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-dev-flow-map-and-product-options-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow v1
canonical_name: Atlas Dev Efficient Programming Flow v1
technical_name: atlas-dev-efficient-programming-flow-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
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
  - tests/Feature/AtlasCliDevCommandTest.php
  - tests/Unit/AtlasCliDevWorkflowServiceTest.php
  - tests/Unit/Ai/Programming/AtlasDevRuntimeServiceTest.php
requires_evidence: true
risk_level: high
line_limit: 520
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

## Detalhes Extraidos

Este contrato foi reduzido para funcionar como mapa canônico legível do Atlas Dev Efficient Programming Flow. O detalhe normativo continua vivo nos recortes abaixo, todos filhos deste módulo na cartografia.

- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md` — 1. Papel No Atlas ate 8. Pipeline Completo.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-02.md` — 9. State Machine ate 18. Scope Guard.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-03.md` — 19. Verification E Repair ate 26.3 Atlas Dev Como Template Para Outras Verticais.
- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-04.md` — 26.4 Sumario Operacional (Estado Real Implementado) ate 27. Regra Final.

### Regra De Manutencao

- Nao adicionar schema detalhado ou passo-a-passo neste índice; use contracts, runbook ou recorte filho.
- Ao alterar um recorte, manter backlink para este índice e rodar `php artisan atlas:engineering:knowledge docs-health --json`.
- A cartografia deve tratar este índice como porta de entrada e os recortes como documentação detalhada.
