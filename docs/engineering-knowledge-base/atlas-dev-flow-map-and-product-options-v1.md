---
id: atlas-dev-flow-map-and-product-options-v1
type: engineering_knowledge
title: Atlas Dev Flow Map And Product Options v1
status: active
category: programming
priority: 104
summary: Mapa completo e caderno de campanha do Atlas Dev (fluxo de desenvolvimento workspace-bound dentro do Atlas AI): fluxos atuais, casos de uso, opcoes de produto, contexto acumulado, hipoteses e plano para construir um Atlas Dev robusto, governado e de qualidade extrema dentro do Atlas AI. Atlas AI e o produto/superficie unica; Atlas Dev e UM fluxo entre varios (Research, Explain, Debug, Review, Conversation, Forge). Benchmark, Rivals e comparacao contra Opus ficam fora da fase atual (equipe Medicao). Para entrypoint canonico do Atlas Dev e ordem de leitura, ver `atlas-dev-index.md`.
tags:
  - atlas-dev
  - atlas-cli
  - programming
  - provider-routing
  - open-brain
  - dev-to-forge
capabilities:
  - atlas_dev_flow_map
  - atlas_dev_light_design
  - daily_programming_runtime
  - programming_repair_loop
  - dev_to_forge_promotion
decisions:
  - Atlas Dev e a camada diaria de programacao antes de Forge, nao um Forge menor com outro nome.
  - O Atlas Dev atual ja passa por CLI, chat, surface adapters, Domain Catalog, Atlas Decide, Open Brain, Kernel Pipeline, provider execution, quality gate e promocao Dev -> Forge.
  - Nao vamos mudar Rivals agora; a fase atual e exclusivamente construcao do Atlas Dev robusto.
  - Benchmark, Opus challenge, battery de prompts e scoring competitivo pertencem a outro Codex/Claude e outro contrato.
  - A meta atual e performance e qualidade extrema do Atlas Dev por contratos, contexto, prompt projection, escopo, verificacao, repair e escalada.
  - Forge continua sendo modo de governanca alta; Atlas Dev deve cobrir o cotidiano com custo e latencia proximos do provider puro.
  - decision_locked initial_surface atlas_ai_desktop_mac_via_surface_id_atlas_desktop_ai 2026-05-16
  - decision_locked governance_mapping atlas_dev_gates_are_compact_projections_of_programming_governance_gates 2026-05-16
maintenance:
  - Atualize este doc antes de alterar `atlas:cli:dev`, `atlas:ai:chat --dev`, AtlasDevRuntimeService, AtlasProgrammingOrchestrator, DevToForgePromotionService ou SurfaceAdapters.
  - Nao alterar Rivals nesta fase. Nao adicionar benchmark competitivo a este fluxo.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md
  - docs/engineering-knowledge-base/spec-operating-system/
  - app/Console/Commands/AtlasCliDevCommand.php
  - app/Console/Commands/AiChatCommand.php
  - app/Console/Commands/AtlasCliFixCommand.php
  - app/Console/Commands/AtlasCliContinueCommand.php
  - app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
  - app/Services/Ai/Cli/AtlasCliProviderStrategyService.php
  - app/Services/Ai/Programming/AtlasDevRuntimeService.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php
  - app/Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php
  - app/Services/Ai/Surface/Adapters/AtlasCliDevSurfaceAdapter.php
  - app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php
  - app/Services/Ai/Surface/Adapters/AtlasAppSurfaceAdapter.php
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/client.ts
  - app/Services/AtlasCode/DevToForgePromotionService.php
  - app/Services/AtlasCode/PromotionSignalDetector.php
  - tests/Feature/AtlasCliDevCommandTest.php
  - tests/Unit/AtlasCliDevWorkflowServiceTest.php
  - tests/Unit/Ai/Programming/AtlasDevRuntimeServiceTest.php
  - tests/Feature/AtlasCode/AtlasCodeDevToForgePromotionTest.php
  - tests/Unit/Ai/Surface/SurfaceAdaptersTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-01.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-02.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-03.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-04.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-05.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-06.md
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-07.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-flow-map-and-product-options-v1
graph_title: Atlas Dev Flow Map And Product Options v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-conversation-surface-and-atlas-dev-v1
graph_status: active
graph_source: repo
owner: programming
next_actions:
  - Normalizar o contrato de execucao diaria do Atlas Dev como fast lane do Programming Governance System.
  - Implementar a primeira surface completa no Atlas AI Desktop Mac (`surface_id=atlas_desktop_ai`).
  - Registrar receipts, telemetry, error ledger e criterio de escalada operacional antes de qualquer promocao para Forge.
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
allowed_changes:
  - Adicionar novos modos, casos de uso e contratos quando o Atlas Dev ganhar driver real ou nova UI.
  - Registrar contextos novos desta sessao, extrair valor deles e transformar em hipoteses/testes/backlog.
  - Refinar a fronteira Atlas Dev vs Atlas Forge com base em evidence operacional interna.
forbidden_changes:
  - Tratar provider como arquitetura.
  - Declarar que Atlas Dev executa com driver dedicado quando o caminho real ainda delega para `atlas:ai:chat` ou Engineering Harness.
  - Misturar promocao Dev -> Forge com auto-criacao irrestrita de Obra.
  - Mexer em Rivals como primeiro passo desta campanha.
  - Criar benchmark competitivo, Opus challenge, messy prompt battery ou score de Rivals nesta fase de construcao.
  - Chamar de vitoria contra Opus qualquer resultado sem tarefa reproduzivel, evidencia, custo, tempo e criterio de qualidade.
depends_on:
  - atlas-ai-conversation-surface-and-atlas-dev-v1
  - open-brain-context-injection
  - atlas-code-programming-obras-operating-system
flows_to:
  - atlas_cli_dev
  - atlas_ai_chat
  - atlas_desktop_ai
  - atlas_app
  - atlas_dev_light
  - atlas_forge
unlocks:
  - atlas_dev_efficient_programming_flow
  - daily_programming_product_matrix
  - dev_to_forge_routing_policy
governs:
  - atlas_dev.daily_programming
  - atlas_dev_light.product_shape
  - atlas_dev_to_forge.escalation
evidence:
  - app/Console/Commands/AtlasCliDevCommand.php
  - app/Console/Commands/AiChatCommand.php
  - app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - tests/Feature/AtlasCliDevCommandTest.php
  - tests/Unit/AtlasCliDevWorkflowServiceTest.php
required_tests:
  - "php artisan test tests/Feature/AtlasCliDevCommandTest.php tests/Unit/AtlasCliDevWorkflowServiceTest.php tests/Unit/Ai/Programming/AtlasDevRuntimeServiceTest.php"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este doc antes de implementar `atlas_dev_light`, mexer em Atlas Dev ou mudar a politica de escalada para Forge.
quality_gates:
  - atlas_dev_entrypoints_mapped
  - dev_runtime_workspace_required
  - provider_routing_auditable
  - quality_gate_contract_explicit
  - forge_escalation_honest
failure_modes:
  - Atlas Dev virar provider puro com branding.
  - Atlas Dev virar Forge caro por padrao.
  - Perder workspace/contexto ao sair de CLI para Desktop/App.
  - Deixar outra IA puxar medicao competitiva para dentro da construcao do runtime.
observability_signals:
  - plan_id
  - thread_id
  - workspace
  - surface_id
  - programming_profile
  - programming_flow
  - executor_decision.executor
  - selected_provider
  - selected_model
  - open_brain.context_pack_hash
  - kernel_pipeline.pipeline_id
  - quality_gate_policy.required_final_status
  - promotion_target
line_limit: 2100
---
# Atlas Dev Flow Map And Product Options v1

## Resumo

Este índice canônico organiza o mapa de fluxo e opções de produto do Atlas Dev.
Ele mostra a fronteira entre a camada diária de programação, as surfaces que a
acionam, os contratos de governança, os recortes de decisão e os detalhes
históricos que foram extraídos para documentos filhos.

## Escopo Ativo Inviolavel

Esta sessao pertence a equipe de criacao do Atlas Dev.

Foco unico:

- construir o Atlas Dev robusto;
- maximizar performance e qualidade do runtime;
- implementar contratos, contexto, prompt projection, scope guard, verification,
  repair, telemetry, error ledger e escalada limpa;
- preparar uma maquina de programacao diaria brutalmente competente.

Surface inicial locked: **Atlas AI Desktop Mac**, a aba `Atlas AI` do aplicativo desktop. O id tecnico do payload e `atlas_desktop_ai`; os arquivos de entrada sao `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx`, `contract.ts` e `client.ts`; no backend, a entrada passa por `AtlasDesktopAiSurfaceAdapter` e `AtlasDevRuntimeService`.

Fora de escopo nesta fase:

- Rivals;
- benchmark competitivo;
- Opus challenge;
- buscar, criar, curar ou sugerir prompts de teste;
- battery de prompts;
- score custo-normalizado;
- claim de vitoria contra Sonnet/Opus;
- arms ou alteracoes em codigo de arena/medicao.

Qualquer trecho historico deste documento que fale de benchmark/Rivals deve ser
lido apenas como contexto antigo. A instrucao ativa e esta: **criar Atlas Dev,
nao testar Atlas Dev contra modelos**.

## Papel no Atlas

Este modulo organiza a camada diaria de programacao do Atlas Dev. Ele define
como CLI, chat, surfaces, Open Brain, Kernel Pipeline, quality gate, repair e
promocao para Forge devem se encaixar para criar o runtime diario robusto.

## Onde Se Encaixa

O Atlas Dev fica entre provider puro e Atlas Forge. Ele deve ser leve o
suficiente para trabalho cotidiano, mas governado o bastante para preservar
contexto, escopo, evidencia, custo e criterio de escalada.

## Contratos

- Atlas Dev nao e provider com branding.
- Forge continua sendo o modo de governanca alta.
- Atlas Dev e fast lane do Programming Governance System; seus gates sao projecoes compactas, nao concorrentes.
- O core do Atlas Dev e surface-agnostic; Desktop-first e estrategia de entrega vertical, nao acoplamento.
- Benchmark/Rivals nao fazem parte da fase ativa.

## Fluxo

1. Entrada por CLI, chat ou surface.
2. Normalizacao de intencao e contexto.
3. Selecao de escopo e provider/modelo.
4. Execucao controlada com teste focado.
5. Reparo leve ou escalada para Forge.
6. Registro de evidencia para aprendizado operacional.

Entrega vertical locked:

```text
Marco 1: foundation backend, sem UI
Marco 2: plan-only visivel no Atlas AI Desktop
Marco 3: one-call visivel no Atlas AI Desktop
Marco 4: repair visivel no Atlas AI Desktop
Marco 5: paridade CLI/App/API
```

O orchestrator central recebe `OperationEnvelope` e retorna `PlanOnlyResult|PatchResult`. Somente adapters em `AtlasDev/Surface/` conhecem Desktop, CLI, App ou API.

## Regras para IA

- Leia este documento antes de alterar Atlas Dev, Dev Light, provider routing ou
  promocao Dev -> Forge.
- Nao proponha benchmark, prompts de teste, Opus challenge ou Rivals neste
  fluxo.
- Nao altere Rivals como substituto de construir o driver real do Atlas Dev.

## Escopo de Implementacao

Inclui mapeamento de produto, contratos operacionais, surfaces, comandos,
runtime de programacao, quality gates e politicas de escalada. Exclui
benchmarks, prompts de teste e mudancas diretas em Rivals.

## Dependencias

- Atlas Programming Governance System.
- Atlas CLI Dev.
- Atlas Programming Orchestrator.
- Surface adapters.
- Open Brain context injection.
- Dev to Forge promotion policy.

## Evidencias

As evidencias esperadas sao os caminhos em `related_paths`, os testes em
`required_tests`, hashes de contexto, resultados de quality gate, receipts,
work product e registros operacionais.

## Riscos

- Atlas Dev virar wrapper de provider puro.
- Atlas Dev virar Forge caro por padrao.
- A equipe desviar para benchmark/Rivals em vez de construir runtime.
- Promocao Dev -> Forge criar Obra sem governanca suficiente.

## Exemplos

- `atlas:cli:dev` para tarefa cotidiana com escopo pequeno.
- `atlas:ai:chat --dev` para superficie conversacional de programacao.
- Escalada para Forge quando risco, duracao ou evidencia exigirem governanca
  maior.

## Proximas Acoes

- Fechar contrato de execucao diaria do Atlas Dev.
- Implementar `ProviderPromptProjectionContract`.
- Implementar telemetry e error ledger.
- Revisar o design de `atlas_dev_light` somente depois do runtime real.

## Detalhes Extraidos

Este documento foi reduzido para funcionar como índice canônico de fluxo/produto. O detalhe vive nos recortes abaixo, para manter cartografia e modal humano legíveis sem perder informação.

- `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-01.md` — Resumo ate Contexto 2: Programming Governance, SCOR-1 E Spec Como Arma.
- `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-02.md` — Contexto 3: Pacote De Leitura Enterprise Para Nao Perder Pecas.
- `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-03.md` — Contexto 4: Opiniao Externa Sobre Atlas Dev Efficient Programming Flow ate O que ainda nao existe como driver proprio.
- `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-04.md` — Entrypoints ate Fair Claude.
- `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-05.md` — Open Brain E Contexto ate Review.
- `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-06.md` — Frontend/UI ate Fatia 6: promotion loop.
- `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-07.md` — Fatia 7: entrada no Rivals ate Regra Final.

### Regra De Manutencao

- Nao adicionar novas responsabilidades neste índice se um recorte filho for o lugar correto.
- Ao alterar um recorte, manter backlink para este índice e rodar `php artisan atlas:engineering:knowledge docs-health --json`.
- A cartografia deve tratar este índice como porta de entrada e os recortes como documentação detalhada.
