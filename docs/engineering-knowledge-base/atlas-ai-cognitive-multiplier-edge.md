---
id: atlas-ai-cognitive-multiplier-edge-redirect
type: engineering_knowledge
title: Atlas AI Cognitive Multiplier Edge (redirect)
status: active
category: redirect
priority: 96
implementation_state: active_redirect_to_cognitive_multiplier_edge
summary: Doc original do Cognitive Multiplier Edge foi movido para `cognitive/multiplier-edge.md` para passar no `line_limit` canonico do Doc-OS. Detalhe operacional por capability migrou para APs em `docs/ap/AP-COG-EDGE-*.md`.
tags:
  - atlas-ai
  - cognitive
  - multiplier-edge
  - redirect
  - split-required
capabilities:
  - cognitive_multiplier_edge_redirect
decisions:
  - Conteudo original (747 linhas) violava limite Doc-OS de 260 linhas.
  - Indice resumido das 7 capabilities cardinais foi para `cognitive/multiplier-edge.md`.
  - Detalhe operacional (schema, migration, services, gates, telas) de cada capability vai para AP dedicado em `docs/ap/`.
  - AP-163 (Dreyfus Dynamic Pedagogy) e o primeiro implementavel.
maintenance:
  - Nao expandir este arquivo. Toda atualizacao vai para `cognitive/multiplier-edge.md` ou AP correspondente.
  - Considerar arquivamento em `archive/` apos 60 dias.
related_paths:
  - docs/engineering-knowledge-base/cognitive/README.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/engineering-knowledge-base/cognitive/roadmap.md
  - docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md
owner: atlas-ai
layer: 2-and-3
line_limit: 80
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-multiplier-edge-redirect

graph_title: Atlas AI Cognitive Multiplier Edge (redirect)

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-multiplier-edge.md

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
  - docs/engineering-knowledge-base/atlas-ai-cognitive-multiplier-edge.md

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
# Atlas AI Cognitive Multiplier Edge — Redirect

> **Este doc foi dividido**. Indice resumido em `cognitive/multiplier-edge.md`; detalhe operacional em APs dedicados.

## Nova entrada

[`cognitive/README.md`](cognitive/README.md) — bootstrap.

## Mapa de migracao

| Conteudo original | Nova localizacao |
|---|---|
| Visao + 7 capabilities cardinais (resumo) | [`cognitive/multiplier-edge.md`](cognitive/multiplier-edge.md) |
| Roadmap dominante (Fases 1-6) | [`cognitive/roadmap.md`](cognitive/roadmap.md) |
| Capability 1 — Dreyfus Dynamic Pedagogy (implementado: schema, services, gates, surfaces, tests, SLOs) | [`docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md`](../ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md) |
| Capability 2 — Evidence-driven Self-Assessment | AP-COG-EDGE-02 (a criar quando entrar implementacao) |
| Capability 3 — Cross-Domain Latticework | AP-COG-EDGE-03 (a criar) |
| Capability 4 — Multi-Provider Discord Detector | AP-COG-EDGE-04 (a criar) |
| Capability 5 — Atlas-Vitor Socratic Tutor | AP-COG-EDGE-05 (a criar) |
| Capability 6 — Cross-Domain Evidence Routing | AP-COG-EDGE-06 (a criar) |
| Capability 7 — Temporal Compression Validation | AP-COG-EDGE-07 (a criar) |

## Continuidade

Atualizacoes futuras vao para `cognitive/multiplier-edge.md` (indice) ou AP dedicado (detalhe). Nunca para este redirector. Apos 60 dias de uso estavel da nova estrutura, considerar mover para `archive/`.

## Resumo

Doc original do Cognitive Multiplier Edge foi movido para `cognitive/multiplier-edge.md` para passar no `line_limit` canonico do Doc-OS. Detalhe operacional por capability migrou para APs em `docs/ap/AP-COG-EDGE-*.md`.

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
