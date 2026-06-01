---
id: runtime-executor
type: engineering_knowledge
title: Runtime Executor
status: active
category: kernel
priority: 97
summary: Engrenagem que executa o contrato assinado usando drivers, ferramentas, harnesses e runtimes governados.
tags:
  - atlas
  - kernel
  - runtime
  - executor
capabilities:
  - runtime_executor
  - tool_runtime
  - governed_execution
decisions:
  - Runtime executa; Kernel decide.
  - Runtime nao pode ampliar escopo definido pelo receipt.
maintenance:
  - Atualizar quando drivers, harnesses, tool tiers ou boundaries de linguagem mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: runtime-executor
graph_title: Runtime Executor
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
human_name: Runtime Executor
canonical_name: Runtime Executor
technical_name: runtime-executor
cartography_type: step
canonical_source: docs/engineering-knowledge-base/system-graph/runtime-executor.md
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/runtime-executor.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
allowed_changes:
  - Atualizar boundaries, drivers e tool families com evidencia.
forbidden_changes:
  - Fazer runtime decidir provider, politica ou escopo.
  - Executar fora do allowed scope do receipt.
depends_on:
  - decision-receipt
flows_to:
  - quality-gates
unlocks:
  - evidence-ledger
governs:
  - tool-runtime
  - provider-drivers
evidence:
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
evidence_refs:
  - symbol: AtlasRuntimeExecutorService
  - command: atlas:aaeos:runtime-executor
  - test: AtlasRuntimeExecutorTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
next_actions:
  - Mapear no Atlas Code quais execucoes sao desktop-native e quais pertencem ao atlas-server.
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
# Runtime Executor

## Resumo

Runtime Executor aplica o Decision Receipt usando drivers, harnesses e ferramentas governadas. Ele nao decide o que fazer; ele executa o contrato recebido.

## Papel no Atlas

Ele e a ponte entre decisao e trabalho real: comandos, tools, providers, shells, harnesses, gates e ambientes.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Recebe `decision-receipt` e alimenta `quality-gates`.

## Contratos

Entrada: receipt assinado e contexto autorizado. Saida: execucao, artefatos, logs, status e referencia de evidencia.

## Fluxo

Receipt autoriza. Runtime executa dentro do escopo. Quality Gates verificam. Evidence Ledger registra.

## Regras para IA

IA nao pode usar runtime como atalho para burlar politica. Se precisa tocar arquivo fora do escopo, deve pedir novo receipt.

## Escopo de Implementacao

Permitido: drivers, PTY, tool runtime, harnesses e integração com evidence. Proibido: decisao de provider ou permissao fora do Kernel.

## Dependencias

- `decision-receipt`
- `atlas-ai-runtime-language-boundaries`
- `super-tool-runtime-core`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md`
- `docs/engineering-knowledge-base/super-tool-runtime-core.md`

## Riscos

- Runtime virar segundo Kernel.
- Comando manual escapar do receipt.
- Terminal parecer real mas nao registrar evidence.

## Exemplos

Atlas Code pode hospedar PTY local, mas a execucao governada precisa voltar como run/evidence no Kernel.

## Proximas Acoes

Definir contrato entre PTY do Desktop e evidence do `atlas-server`.
