---
id: atlas-ai-cognitive-readme
type: engineering_knowledge
title: Atlas AI Cognitive Plane - Bootstrap
status: active
category: documentation-bootstrap
priority: 99
summary: Porta de entrada do Cognitive Plane do Atlas. Mapa de leitura para humano novo e para Codex implementar. Aponta para overview, principles, capabilities, multiplier edge, pipeline overlay, roadmap e APs executaveis.
tags:
  - atlas-ai
  - cognitive
  - bootstrap
  - readme
capabilities:
  - cognitive_plane_bootstrap
  - reader_orientation
decisions:
  - Cognitive Plane vive em subpasta dedicada `cognitive/` para passar no `line_limit` canonico de 260 linhas por spec.
  - Specs originais `atlas-ai-cognitive-development-plane.md` e `atlas-ai-cognitive-multiplier-edge.md` viraram stubs redirectors para esta pasta.
  - APs executaveis (schema + migration + services + gates + tests) vivem em `docs/ap/AP-###-cognitive-*.md`, fora desta pasta.
  - `implementation-briefing.md` e a porta operacional para IA implementar sem confundir status, comandos ou APs.
maintenance:
  - Manter abaixo de 180 linhas (limite Doc-OS para bootstrap/index).
  - Atualizar quando spec nova for promovida ou quando AP cognitivo mudar de status.
related_paths:
  - docs/engineering-knowledge-base/cognitive/implementation-briefing.md
  - docs/engineering-knowledge-base/cognitive/overview.md
  - docs/engineering-knowledge-base/cognitive/principles.md
  - docs/engineering-knowledge-base/cognitive/capabilities-core.md
  - docs/engineering-knowledge-base/cognitive/multiplier-edge.md
  - docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
  - docs/engineering-knowledge-base/cognitive/roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/domains/learning.md
  - docs/ap/AP-163-cognitive-dreyfus-dynamic-pedagogy.md
  - docs/ap/AP-164-cognitive-worked-example-engine.md
  - docs/ap/AP-165-cognitive-process-pattern-catalog.md
  - docs/ap/AP-166-cognitive-failure-signature-tracker.md
  - docs/ap/AP-167-cognitive-self-regulated-learning-orchestrator.md
  - docs/ap/AP-168-cognitive-productive-failure-flow.md
  - docs/ap/AP-169-cognitive-personal-worked-examples-generator.md
  - docs/ap/AP-170-cognitive-predictive-failure-insertion.md
owner: atlas-ai
layer: 2-and-3
line_limit: 180
---

# Atlas AI Cognitive Plane — Bootstrap

Porta de entrada do **Cognitive Development Plane** do Atlas AI. Sub-arquitetura especializada que gerencia trajetoria cognitiva do operador: declarar dominancia, descobrir Pareto, treinar 4 pilares (teorico/pratico/cognitivo/transferencial), consolidar via Evidence, evitar ciclo de gurus, comprimir tempo ate maestria em 3-10x.

## Mapa de leitura — sequencia obrigatoria

| # | Doc | Funcao | Linhas |
|---|---|---|---|
| 1 | [`overview.md`](overview.md) | visao executiva Layer 2: tese, pareto, pilares, 5 movimentos, encaixe nos layers Atlas | ~190 |
| 2 | [`principles.md`](principles.md) | C1-C22 + hierarquia de evidencia cientifica + filtro de IA externa + anti-patterns | ~180 |
| 3 | [`capabilities-core.md`](capabilities-core.md) | ~32 capabilities cognitivas Core (FSRS, recall, generation, Feynman, interleaving, Sweller, flow trigger, DMN, NSDR, Hemingway, etc.) | ~140 |
| 4 | [`multiplier-edge.md`](multiplier-edge.md) | 10 capabilities cardinais Atlas-unicas (Dreyfus, Evidence-driven SA, Latticework, Multi-Provider Debate, Atlas-Vitor Socratic, Cross-Domain Routing, Compression, personal examples, predictive failure, process detector) | ~210 |
| 5 | [`pipeline-overlay.md`](pipeline-overlay.md) | catalogo de flows `learning`, hooks por etapa do pipeline canonico, memory artifacts, ledger events, surfaces | ~170 |
| 6 | [`roadmap.md`](roadmap.md) | C0-C13 (Cognitive Plane) + Fases 1-6 (Multiplier Edge) — Dreyfus first | ~160 |
| 7 | [`implementation-briefing.md`](implementation-briefing.md) | briefing operacional para IA implementar APs sem confundir status, comandos ou fronteiras | ~150 |

## Caminho rapido por papel

| Voce e... | Leia primeiro |
|---|---|
| Humano novo no projeto, querendo entender visao | `overview.md` -> `principles.md` -> `roadmap.md` |
| Codex / IA implementando capability | `implementation-briefing.md` -> `overview.md` -> `pipeline-overlay.md` -> AP especifico em `docs/ap/AP-###-cognitive-*.md` |
| Auditando filtro de contribuicoes externas (Gemini, ChatGPT, gurus) | `principles.md` (secao filtro) |
| Decidindo proxima capability | `roadmap.md` -> `multiplier-edge.md` |
| Procurando schema/migration/service/gate concreto | `implementation-briefing.md` -> `docs/ap/AP-###-cognitive-*.md` correspondente |

## Status atual

| Item | Estado |
|---|---|
| Design conceitual | active; sub-arquitetura canonica |
| APs operacionais | AP-163, AP-164, AP-165, AP-166, AP-167 em `implemented-operational-read-model` |
| APs scaffold | AP-168, AP-169, AP-170 |
| Schemas/migrations | Dreyfus, Worked Examples, Process Patterns, Failure Signatures e SRL implementados |
| Services Laravel | Read models + gates + CLI das ondas AP-163..167 implementados |
| Gaps conhecidos | AP-166 proposal emission, AP-167 hooks de surface, AP-168 runtime produtivo, AP-169/170 dependem de ledger/grafo maduros |

## Pre-requisitos de leitura

Antes desta pasta, leia (ordem):

1. `atlas-ai-thesis-multiplier-channel.md` (Layer -1, tese)
2. `atlas-ai-canonical-architecture-index.md` (autoridade)
3. `atlas-ai-pipeline.md` (pipeline canonico de 17 etapas)
4. `atlas-ai-core-vs-domain.md` (regra de onde capability vive)
5. `domains/learning.md` (domain implemented/ready que esta pasta expande)

## Autoridade

Em conflito, ordem vence:

1. Tese central (`atlas-ai-thesis-multiplier-channel.md`)
2. Kernel (`atlas-ai-kernel-architecture.md`)
3. Master (`atlas-ai-master-architecture.md`)
4. Specs desta pasta (`cognitive/`)
5. AP correspondente (`docs/ap/AP-###-cognitive-*.md`) — mais especifico vence em detalhe executavel
6. Domain spec (`domains/learning.md`) — vence em semantica de domain

## Pergunta-norte permanente

Toda decisao no Cognitive Plane e auditada contra:

> **Esta feature multiplica meu output cognitivo, ou compete com o ato de aprender? Mantem o Atlas como canal unico, ou cria fricca que me faz comprar curso/guru/ChatGPT direto?**

Multiplica + canal unico → constroi. Compete + escape → descarta.

## Compromisso de qualidade documental

Esta pasta segue o `atlas-ai-documentation-operating-system.md`:

- Cada spec abaixo de 260 linhas (contrato canonico)
- Frontmatter obrigatorio em todos
- Status declarado (active / scaffold / future)
- `evidence_level` declarado em cada capability
- Anti-duplicacao Core vs Domain enforcada
- Validation commands listadas em cada AP

Mudanca aqui exige `docs-health` + `architecture-validate` verdes.

## Versao e historico

| Versao | Data | Resumo |
|---|---|---|
| 0.1 | 2026-05-07 | Promocao inicial: design conceitual fragmentado em 7 specs + AP-163 Dreyfus |
| 0.2 | 2026-05-07 | Governanca operacional: briefing canonico, AP-163..167 status real, AP naming corrigido, C1-C22 e comando canonico `php artisan atlas:*` |

## Continuidade

Quando uma capability sair de `scaffold`, mover documentacao detalhada para o AP correspondente e marcar status exato da taxonomia definida em `implementation-briefing.md`.
