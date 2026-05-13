---
id: evidence-loop
type: engineering_knowledge
title: Evidence Loop
status: active
category: kernel
priority: 89
summary: Plano lateral que transforma execucao, telemetria, gates e outcomes em sinais para Decide e Learning.
tags: [atlas, kernel, evidence, telemetry]
capabilities: [evidence_loop, telemetry_feedback]
decisions:
  - Todos os eventos relevantes precisam virar evidencia antes de influenciar decisao futura.
maintenance:
  - Atualizar quando sinais, gates ou metricas mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: evidence-loop
graph_title: Evidence Loop
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/evidence-loop.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
allowed_changes:
  - Adicionar sinais de evidencia e telemetria.
forbidden_changes:
  - Usar metricas nao registradas para governar Decide.
depends_on:
  - evidence-ledger
flows_to:
  - atlas-decide
  - learning-proposals
unlocks:
  - atlas-decide
  - learning-proposals
governs:
  - evidence-ledger
evidence:
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Conectar Evidence Loop aos cards de gate e receipt no Atlas Code.
visual_tags:
  - module
  - module
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
# Evidence Loop

## Resumo

Evidence Loop fecha o ciclo entre execucao, telemetria, gates, aprendizado e decisoes futuras.

## Papel no Atlas

Ele garante que o Kernel melhore por evidencia registrada, nao por impressao ou memoria solta.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe de `evidence-ledger` e alimenta `atlas-decide` e `learning-proposals`.

## Contratos

Entrada: eventos, traces, gates e outcomes. Saida: sinais calibrados. Invariante: sinal sem evidencia nao governa decisao.

## Fluxo

Execucao gera ledger. Evidence Loop agrega sinais. Decide e Learning usam os sinais no proximo ciclo.

## Regras para IA

IA deve tratar evidencia como requisito para promover aprendizado, policy ou mudanca de default.

## Escopo de Implementacao

Permitido: agregacao de sinais e metricas. Proibido: inferir sucesso sem gate ou outcome.

## Dependencias

- `evidence-ledger`
- `atlas-ai-telemetry-evidence-performance`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md`
- `docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md`

## Riscos

- Metricas contaminadas reforcarem decisao ruim.
- Evidence incompleta parecer sucesso.

## Exemplos

AP-99, custo, latencia, falhas de gate e repair attempts voltam para Decide.

## Proximas Acoes

Definir read model para Evidence Loop exibido na Cartografia.
