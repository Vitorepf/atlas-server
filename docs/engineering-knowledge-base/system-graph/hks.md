---
id: hks
type: engineering_knowledge
title: Human Knowledge Surface
status: active
category: kernel
priority: 82
summary: Plano lateral do AtlasVault, Obsidian e workspace humano como contexto curado, nao fonte operacional crua.
tags: [atlas, kernel, hks, vault, obsidian]
capabilities: [human_knowledge_surface, atlas_vault]
decisions:
  - AtlasVault pode alimentar contexto curado; docs repo seguem como verdade tecnica oficial.
maintenance:
  - Atualizar quando regras de Vault e Cartografia mudarem.
related_paths:
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/vault/README.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: hks
graph_title: Human Knowledge Surface
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-ai-kernel-pipeline
graph_status: active
graph_source: repo
human_name: Human Knowledge Surface
canonical_name: Human Knowledge Surface
technical_name: hks
cartography_type: module
canonical_source: docs/engineering-knowledge-base/system-graph/hks.md
owner: atlas-kernel
repo_paths:
  - docs/engineering-knowledge-base/system-graph/hks.md
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/vault/README.md
allowed_changes:
  - Atualizar regras de leitura do Vault e contexto humano.
forbidden_changes:
  - Tratar nota humana como verdade tecnica sem canon.
depends_on:
  - context-builder
flows_to:
  - context-builder
unlocks:
  - atlas-semantic-graph
governs:
  - atlas-vault
evidence:
  - docs/engineering-knowledge-base/obsidian-atlas-vault.md
  - docs/engineering-knowledge-base/vault/README.md
evidence_refs:
  - symbol: AtlasHumanKnowledgeSurfaceService
  - command: atlas:aaeos:human-knowledge-surface
  - test: AtlasHumanKnowledgeSurfaceTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Exibir source repo/vault sem parecer dois produtos separados na Cartografia.
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
# Human Knowledge Surface

## Resumo

Human Knowledge Surface e o plano onde AtlasVault, Obsidian e workspace humano entram como contexto curado.

## Papel no Atlas

Ele permite usar livros, filosofia, historias, notas e memoria humana sem confundir isso com documentacao tecnica oficial.

## Onde Se Encaixa

Pai: `atlas-ai-kernel-pipeline`. Alimenta `context-builder` e a Cartografia Semantica.

## Contratos

Entrada: notas e links humanos. Saida: contexto curado com source. Invariante: fonte humana nao substitui canon tecnico do repo.

## Fluxo

Vault fornece memoria humana. Context Builder decide o que pode entrar no context pack.

## Regras para IA

IA deve preservar diferenca de autoridade mesmo quando a UI apresentar tudo como mapa unico.

## Escopo de Implementacao

Permitido: leitura, indexacao e links. Proibido: sobrescrever docs oficiais a partir de nota sem decisao.

## Dependencias

- `context-builder`
- `vault/README`
- `obsidian-atlas-vault`

## Evidencias

- `docs/engineering-knowledge-base/obsidian-atlas-vault.md`
- `docs/engineering-knowledge-base/vault/README.md`

## Riscos

- Drift entre nota humana e canon tecnico.
- Usuario interpretar reflexao como contrato executavel.

## Exemplos

Um livro no Vault pode inspirar uma Obra; a mudanca tecnica resultante deve virar doc canonico no repo.

## Proximas Acoes

Implementar badge de autoridade no inspector da Cartografia.
