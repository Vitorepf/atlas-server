---
id: atlas-ai-kernel-pipeline
type: engineering_knowledge
title: Atlas AI Kernel Pipeline
status: active
category: kernel
priority: 99
summary: Fluxo central do Kernel Atlas, da entrada ao output, passando por decisao, receipt, runtime, gates, evidencia e aprendizado.
tags:
  - atlas
  - kernel
  - pipeline
  - cartography
capabilities:
  - atlas_ai_kernel_pipeline
  - atlas_semantic_graph
  - kernel_flow
decisions:
  - O Kernel Pipeline e o fluxo central que nenhuma surface, provider, tool ou dominio pode burlar.
  - As engrenagens do pipeline devem existir como nodes navegaveis e verificaveis na Cartografia.
maintenance:
  - Atualizar quando a ordem, as engrenagens ou as dependencias do pipeline mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/system-graph/decision-receipt.md
  - docs/engineering-knowledge-base/system-graph/runtime-executor.md
  - docs/engineering-knowledge-base/system-graph/quality-gates.md
  - docs/engineering-knowledge-base/system-graph/evidence-ledger.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-kernel-pipeline
graph_title: Atlas AI Kernel Pipeline
graph_world: atlas
graph_layer: flow
graph_kind: flow
graph_parent: atlas
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/atlas-ai-kernel-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
allowed_changes:
  - Ajustar ordem e relacoes do pipeline quando houver contrato canonico atualizado.
forbidden_changes:
  - Permitir bypass de Decide, Receipt, Runtime, Gates ou Evidence.
depends_on:
  - atlas-ai-kernel-architecture
flows_to:
  - atlas-decide
unlocks:
  - atlas-code
  - atlas-semantic-graph
governs:
  - atlas-kernel
  - atlas-code
evidence:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Completar nodes dedicados para as 17 etapas do pipeline.
visual_tags:
  - flow
  - flow
  - system-graph

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok
---
# Atlas AI Kernel Pipeline

## Resumo

Atlas AI Kernel Pipeline e o fluxo operacional que conecta entrada, contexto, decisao, contrato, execucao, verificacao, evidencia, aprendizado e resposta.

## Papel no Atlas

Ele e a espinha dorsal executavel. Toda surface solicita; o pipeline governa.

## Onde Se Encaixa

Pai: `atlas`. Filhos: engrenagens como `atlas-decide`, `decision-receipt`, `runtime-executor`, `quality-gates` e `evidence-ledger`.

## Contratos

Nenhuma execucao relevante pode pular Decide, Receipt, Runtime, Gates ou Evidence.

## Fluxo

Entrada vira envelope, contexto, policy, decide, receipt, runtime, gates, repair, evidence, learning e output.

## Regras para IA

IA deve localizar a etapa do pipeline antes de alterar codigo. Mudanca sem etapa dona tende a virar duplicacao.

## Escopo de Implementacao

Permitido: docs de grafo, contratos das etapas e relacoes. Proibido: alterar ordem do Kernel sem evidencias e docs donos.

## Dependencias

- `atlas-ai-kernel-architecture`
- `atlas-ai-pipeline`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md`
- `docs/engineering-knowledge-base/atlas-ai-pipeline.md`

## Riscos

- Pipeline virar desenho sem enforcement.
- Etapas sem doc proprio gerarem interpretacao divergente.
- Surface tentar executar fora do fluxo.

## Exemplos

Atlas Code deve mostrar o pipeline como contrato operacional, nao como barra decorativa.

## Proximas Acoes

Completar os docs dedicados para todas as 17 etapas.
