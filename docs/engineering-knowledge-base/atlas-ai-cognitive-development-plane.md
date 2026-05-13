---
id: atlas-ai-cognitive-development-plane
type: engineering_knowledge
title: Atlas AI Cognitive Development Plane (redirect)
status: split_required
category: redirect
priority: 95
summary: Doc original do Cognitive Development Plane foi fragmentado conforme `line_limit` canonico do Doc-OS. Conteudo migrou para a pasta `cognitive/` em 7 specs menores. Este arquivo permanece como redirector para preservar links externos.
tags:
  - atlas-ai
  - cognitive
  - redirect
  - split-required
capabilities:
  - cognitive_plane_redirect
decisions:
  - Conteudo original (624 linhas) violava limite Doc-OS de 260 linhas para contrato canonico.
  - Doc fragmentado em 7 specs ≤260 linhas em `cognitive/` mais AP executavel em `docs/ap/`.
  - Este arquivo NAO e mais a fonte canonica; segue `cognitive/README.md`.
maintenance:
  - Nao expandir este arquivo. Toda atualizacao vai para a spec correspondente em `cognitive/`.
  - Considerar arquivamento em `archive/` apos 60 dias de uso da nova estrutura.
related_paths:
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/overview.md
  - docs/engineering-knowledge-base/cognitive/principles.md
  - docs/engineering-knowledge-base/cognitive/capabilities-core.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
  - docs/engineering-knowledge-base/cognitive/roadmap.md
  - docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md
owner: atlas-ai
layer: 2-and-3
line_limit: 80
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-development-plane

graph_title: Atlas AI Cognitive Development Plane (redirect)

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-development-plane.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - redirect

evidence:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-development-plane.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
  - redirect

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

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Cognitive Development Plane — Redirect

> **Este doc foi dividido**. Conteudo migrou para `cognitive/` em 7 specs menores conforme `line_limit` canonico do `atlas-ai-documentation-operating-system.md`.

## Nova entrada

[`cognitive/README.md`](cognitive/README.md) — bootstrap com mapa de leitura.

## Mapa de migracao

| Conteudo original | Nova localizacao |
|---|---|
| Tese cognitiva, Pareto, 4 pilares, 5 movimentos, encaixe nos layers | [`cognitive/overview.md`](cognitive/overview.md) |
| 19 principios duros (C1-C19), hierarquia de evidencia, filtro de IA externa, anti-patterns | [`cognitive/principles.md`](cognitive/principles.md) |
| ~25 capabilities cognitivas Core | [`cognitive/capabilities-core.md`](cognitive/capabilities-core.md) |
| 7 capabilities cardinais Multiplier Edge (Dreyfus, Latticework, etc.) | [`cognitive/multiplier-edge.md`](cognitive/multiplier-edge.md) |
| 18 flows do `learning` v2, pipeline overlay, memory artifacts, ledger events, surfaces, loops | [`cognitive/pipeline-overlay.md`](cognitive/pipeline-overlay.md) |
| Roadmap C0-C12 + Fases 1-6 (Dreyfus first) | [`cognitive/roadmap.md`](cognitive/roadmap.md) |
| Spec executavel da Fase 1 (schema, services, gates, tests) | [`docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md`](../ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md) |

## Continuidade

Atualizacoes futuras vao para a spec correspondente em `cognitive/`, nunca neste redirector. Apos 60 dias de uso estavel da nova estrutura, este arquivo pode ser movido para `archive/`.

## Resumo

Doc original do Cognitive Development Plane foi fragmentado conforme `line_limit` canonico do Doc-OS. Conteudo migrou para a pasta `cognitive/` em 7 specs menores. Este arquivo permanece como redirector para preservar links externos.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
