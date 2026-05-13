---
id: surface-adapter
type: engineering_knowledge
title: Surface Adapter
status: active
category: kernel
priority: 91
summary: Normaliza input e output das superficies antes de entrar no Atlas Input.
tags: [atlas, kernel, adapter]
capabilities: [surface_adapter, input_normalization]
decisions:
  - Adapter traduz formatos de superficie, mas nao decide intencao final.
maintenance:
  - Atualizar quando novas superficies exigirem mapeamento de payload.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: surface-adapter
graph_title: Surface Adapter
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/surface-adapter.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
allowed_changes:
  - Adicionar contratos de normalizacao por surface.
forbidden_changes:
  - Executar ferramentas ou escolher modelo no adapter.
depends_on:
  - surface-plane
flows_to:
  - atlas-input
unlocks:
  - atlas-input
governs:
  - surface-plane
evidence:
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Definir schema comum para payload normalizado de surface.
visual_tags:
  - module
  - surface
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
# Surface Adapter

## Resumo

Surface Adapter converte entradas de UI, CLI, mobile, API e MCP para um formato comum antes do Kernel interpretar o pedido.

## Papel no Atlas

Ele reduz variacao de canais. O Kernel nao deve precisar saber se o pedido veio de textarea, voz, comando, paste ou integracao.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Depende de `surface-plane` e alimenta `atlas-input`.

## Contratos

Entrada: evento de surface. Saida: payload normalizado com origem, formato, anexos e metadados. Invariante: sem execucao, sem provider e sem policy.

## Fluxo

Recebe evento bruto, normaliza campos e preserva contexto de origem. Encaminha o pacote para `atlas-input`.

## Regras para IA

IA deve adicionar adapters como tradutores deterministas. Se houver ambiguidade semantica, ela deve ir para etapas posteriores.

## Escopo de Implementacao

Permitido: normalizacao de tipos, anexos e metadados. Proibido: inferir plano ou executar ferramenta.

## Dependencias

- `surface-plane`
- `atlas-ai-pipeline`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md`
- `docs/engineering-knowledge-base/atlas-ai-pipeline.md`

## Riscos

- Adapter enriquecer contexto demais e criar decisao invisivel.
- Perder origem do input e quebrar auditoria.

## Exemplos

Um paste de codigo e um upload de arquivo viram input normalizado com origem e conteudo preservados.

## Proximas Acoes

Criar exemplo canonico de payload normalizado usado por Atlas Code.
