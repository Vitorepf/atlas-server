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
