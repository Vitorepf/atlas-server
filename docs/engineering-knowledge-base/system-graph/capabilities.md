---
id: capabilities
type: engineering_knowledge
title: Capabilities
status: active
category: kernel
priority: 83
summary: Plano lateral de harnesses e capacidades que podem ser acionadas pelo Runtime, sem virar dominio.
tags: [atlas, kernel, capabilities, harnesses]
capabilities: [runtime_capabilities, harnesses]
decisions:
  - Capability e ferramenta/harness; dominio e perfil cognitivo. Eles nao sao a mesma coisa.
maintenance:
  - Atualizar quando novos harnesses forem promovidos.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: capabilities
graph_title: Capabilities
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
human_name: Capabilities
canonical_name: Capabilities
technical_name: capabilities
cartography_type: module
canonical_source: docs/engineering-knowledge-base/system-graph/capabilities.md
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/capabilities.md
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md
allowed_changes:
  - Adicionar capacidades com contrato e evidence.
forbidden_changes:
  - Deixar capability decidir por conta propria.
depends_on:
  - policy-profile
flows_to:
  - runtime-executor
unlocks:
  - runtime-executor
governs:
  - runtime-capabilities
evidence:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md
  - docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Ligar capabilities reais ao painel Runtime do Atlas Code.
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
# Capabilities

## Resumo

Capabilities representa harnesses, ferramentas e capacidades de runtime que o Atlas pode acionar.

## Papel no Atlas

Ele separa "o que pode executar" de "qual dominio pensa" e "quem decide".

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Depende de `policy-profile` e alimenta `runtime-executor`.

## Contratos

Entrada: catalogo de capacidades e policy. Saida: capabilities autorizadas. Invariante: capability nao decide sozinha.

## Fluxo

Policy autoriza capacidades. Runtime Executor aciona apenas o que foi permitido pelo receipt.

## Regras para IA

IA deve tratar capability como meio de execucao, nao como autoridade operacional.

## Escopo de Implementacao

Permitido: catalogo, limits e harnesses. Proibido: executar fora de policy.

## Dependencias

- `policy-profile`
- `runtime-executor`
- `tool-runtime/catalog-roadmap`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md`
- `docs/engineering-knowledge-base/tool-runtime/catalog-roadmap.md`

## Riscos

- Ferramenta poderosa sem gate.
- Confundir harness com dominio.

## Exemplos

Programming Harness executa checks de codigo; Frontend Design Harness valida UI; ambos dependem de policy.

## Proximas Acoes

Classificar capabilities por risco e evidence obrigatoria.
