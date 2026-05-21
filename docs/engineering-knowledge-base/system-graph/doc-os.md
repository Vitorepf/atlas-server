---
id: doc-os
type: engineering_knowledge
title: Documentation Operating System
status: active
category: kernel
priority: 86
summary: Plano lateral que orienta humanos e IAs para START_HERE, Canonical Index, KB e Code Intelligence.
tags: [atlas, kernel, documentation, docs]
capabilities: [documentation_os]
decisions:
  - Documentacao oficial tecnica e fonte de verdade para arquitetura do Atlas.
maintenance:
  - Atualizar quando schema canonico ou regras de docs mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: doc-os
graph_title: Documentation Operating System
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
human_name: Documentation Operating System
canonical_name: Documentation Operating System
technical_name: doc-os
cartography_type: module
canonical_source: docs/engineering-knowledge-base/system-graph/doc-os.md
owner: atlas-documentation
repo_paths:
  - docs/engineering-knowledge-base/system-graph/doc-os.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
allowed_changes:
  - Evoluir regras de doc canonica com docs-health.
forbidden_changes:
  - Criar cartografia paralela que nao leia a documentacao oficial.
depends_on:
  - atlas-canonical-module-doc-v1
flows_to:
  - context-builder
  - output-renderer
unlocks:
  - atlas-semantic-graph
governs:
  - engineering-knowledge-base
evidence:
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Fazer Cartografia destacar quando um node nao possui doc canonica v1.
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
# Documentation Operating System

## Resumo

Documentation Operating System e o plano que governa como humanos e IAs leem, escrevem e validam a documentacao oficial.

## Papel no Atlas

Ele torna docs uma parte operacional do Atlas, nao apenas texto explicativo.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Alimenta `context-builder`, `output-renderer` e a Cartografia Semantica.

## Contratos

Entrada: docs canonicas, schemas, indices e KB. Saida: contexto verificavel e mapa navegavel. Invariante: doc oficial e repo-first para tecnica.

## Fluxo

Docs oficiais sao validadas por schema. Cartografia le a fonte real. IAs usam docs para implementar e auditar.

## Regras para IA

IA deve atualizar docs canonicas quando mudar comportamento tecnico relevante e rodar docs-health.

## Escopo de Implementacao

Permitido: schemas, templates, validadores e indices. Proibido: depender de resumo de chat como canon.

## Dependencias

- `atlas-canonical-module-doc-v1`
- `atlas-ai-documentation-operating-system`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md`
- `docs/engineering-knowledge-base/atlas-canonical-module-doc-v1.md`

## Riscos

- Docs envelhecerem e Cartografia mostrar verdade falsa.
- Agente implementar sem ler canon.

## Exemplos

Uma etapa do Kernel so entra na Cartografia como repo source quando tem frontmatter canonico validado.

## Proximas Acoes

Adicionar gate visual de schema na Cartografia.
