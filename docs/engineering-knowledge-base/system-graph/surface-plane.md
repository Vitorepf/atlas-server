---
id: surface-plane
type: engineering_knowledge
title: Surface Plane
status: active
category: kernel
priority: 92
summary: Plano de entrada onde usuarios, apps, CLI, mobile, API e MCP encontram o Atlas sem decidir pelo Kernel.
tags: [atlas, kernel, surface, cartography]
capabilities: [surface_plane, atlas_input]
decisions:
  - Superficies apresentam entrada e saida; elas nao escolhem provider, politica ou autonomia.
maintenance:
  - Atualizar quando uma nova superficie oficial entrar no Kernel.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: surface-plane
graph_title: Surface Plane
graph_world: atlas
graph_layer: gear
graph_kind: step
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/surface-plane.md
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
allowed_changes:
  - Adicionar superficies oficiais e seus limites.
forbidden_changes:
  - Permitir que a superficie decida provider, politica ou execucao.
depends_on:
  - atlas-ai-kernel-pipeline
flows_to:
  - surface-adapter
unlocks:
  - surface-adapter
governs:
  - atlas-input
evidence:
  - docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md
  - docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Inventariar todas as superficies oficiais consumidas pelo Atlas Code e Cartografia.
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
# Surface Plane

## Resumo

Surface Plane e o plano onde humanos, apps, CLI, mobile, API e MCP entram no Atlas. Ele existe para receber interacao sem misturar interface com decisao operacional.

## Papel no Atlas

O papel e separar contato humano ou externo da logica do Kernel. A surface pode coletar input, mostrar output e preservar contexto de UX, mas nao decide sozinha.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Filho operacional seguinte: `surface-adapter`. Irmaos: demais etapas do pipeline do Kernel.

## Contratos

Entrada: interacao do usuario ou sistema externo. Saida: evento bruto encaminhado ao adapter. Invariante: a surface nao escolhe provider, budget, autonomia ou policy.

## Fluxo

Usuario ou sistema usa uma surface. A surface preserva origem, canal e affordances. O evento segue para `surface-adapter`.

## Regras para IA

IA deve tratar surface como fronteira de experiencia, nao como autoridade de decisao. Qualquer regra de provider ou autonomia pertence ao Kernel.

## Escopo de Implementacao

Permitido: documentar canais e limites. Proibido: implementar roteamento de modelo na camada de UI.

## Dependencias

- `atlas-ai-kernel-pipeline`
- `atlas-ai-mobile-surface-gateway`
- `atlas-ai-cli-multimodal`

## Evidencias

- `docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md`
- `docs/engineering-knowledge-base/atlas-ai-cli-multimodal.md`

## Riscos

- UI virar decisor oculto.
- Canais diferentes gerarem semantica divergente para o mesmo pedido.

## Exemplos

Atlas Code, CLI e mobile podem iniciar a mesma Obra; a diferenca e de superficie, nao de regra de decisao.

## Proximas Acoes

Mapear cada surface oficial para seu adapter e eventos suportados.
