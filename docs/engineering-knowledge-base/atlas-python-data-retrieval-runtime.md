---
id: atlas-python-data-retrieval-runtime
type: engineering_knowledge
title: Atlas Python Data Retrieval Runtime
status: planned
implementation_state: planned_child_architecture_not_current_runtime
blocker: APDR global ainda nao existe; ha ProgrammingPythonRuntime governado e voice runtime separados.
category: intelligence-runtime
priority: 97
summary: Doc filha AUCRI para runtime Python/data governado: graph analytics, clustering, reranking experimental, evals, embedding tooling local e analise de dados sem provider/network/shell livre.
tags: [atlas-ai, aucri, apdr, python, data-runtime, graph-analytics]
capabilities: [python_data_runtime, graph_analytics, local_eval, clustering, runtime_boundary]
decisions:
  - APDR executa analise local governada; Kernel decide, Python executa.
  - APDR nao chama provider, network ou shell arbitrario.
maintenance:
  - Atualizar quando runtime Python/data for promovido.
related_paths:
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeContract.php
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeExecutor.php
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeGraphProjector.php
  - runtimes/python/programming_intelligence/main.py
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Python Data Retrieval Runtime
runtime_acronym: APDR
internal_product_name: Atlas Data Intelligence Runtime
technical_runtime: AtlasPythonDataRetrievalRuntimeService
graph_id: atlas-python-data-retrieval-runtime
graph_title: Atlas Python Data Retrieval Runtime
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-python-data-retrieval-runtime.md
allowed_changes:
  - Definir runtime contracts, adapters e tests Python.
forbidden_changes:
  - Permitir provider/network/shell livre.
depends_on: [atlas-context-freshness-quality-gate, atlas-retrieval-feedback-loop]
flows_to: [atlas-graph-retrieval-network, atlas-context-ranking-system]
unlocks: [local_graph_analytics, retrieval_eval_runtime]
governs: [python_data_runtime, retrieval_analytics]
evidence:
  - docs/engineering-knowledge-base/atlas-python-data-retrieval-runtime.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Criar APDR-I1 sobre ProgrammingPythonRuntime sem quebrar boundary.
---

# Atlas Python Data Retrieval Runtime

## Resumo

APDR e o bloco 9 da AUCRI. Ele usa Python para analise local de dados,
embeddings, grafo, clustering, evals e experimentos de retrieval sob contrato
estrito.

## Papel no Atlas

Dar capacidade analitica local sem transformar Python em agente autonomo.

## Onde Se Encaixa

```text
Kernel decision receipt -> APDR manifest -> Python local -> execution receipt
```

## Contratos

- `atlas.aucri.python_data_request.v1`
- `atlas.aucri.python_data_execution_receipt.v1`
- `atlas.aucri.python_graph_fragment.v1`

## Fluxo

1. Kernel monta manifest.
2. Gate valida approval/receipt/boundary.
3. Python executa local.
4. Retorna JSON.
5. Projector gera fragmento/metricas.

## Regras para IA

- Nao executar Python sem decision receipt.
- Nao permitir network/provider.
- Nao persistir memoria diretamente.

## Escopo de Implementacao

Runtime global data, tests Python, adapters de graph analytics e evals.

## Dependencias

ProgrammingPythonRuntime, ACRS, AGRN, ARFL.

## Evidencias

Execution receipt, stdout/stderr hash, manifest hash e tests.

## Riscos

Execucao arbitraria, custo, output nao deterministico.

## Exemplos

Rodar clustering local de chunks e retornar grupos para reranking.

## Proximas Acoes

1. Reusar contract atual de Programming.
2. Criar runtime root dedicado apenas quando necessario.
