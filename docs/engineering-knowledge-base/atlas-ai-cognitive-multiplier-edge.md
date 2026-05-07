---
id: atlas-ai-cognitive-multiplier-edge-redirect
type: engineering_knowledge
title: Atlas AI Cognitive Multiplier Edge (redirect)
status: split_required
category: redirect
priority: 96
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
