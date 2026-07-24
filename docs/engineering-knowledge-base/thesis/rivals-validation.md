---
id: atlas-thesis-rivals-validation
type: engineering_knowledge
title: Atlas Thesis - Rivals Validation
status: active
category: constitutional
priority: 100
summary: Empirical validation contract for proving whether Atlas multiplies, matches or degrades direct provider output.
tags:
  - atlas-ai
  - thesis
  - rivals
  - benchmark
capabilities:
  - atlas_rivals
  - multiplier_measurement
  - stop_the_line
decisions:
  - Without Rivals, the thesis is philosophy; with Rivals, it is measurable.
  - Multiplicador negativo is stop-the-line.
maintenance:
  - Keep metrics aligned with current Rivals implementation.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  - docs/engineering-knowledge-base/atlas-rivals-product-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-structure-v1.md
  - docs/engineering-knowledge-base/atlas-rivals-claims-and-reporting-v1.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - app/Console/Commands/AtlasRivalsCommand.php
  - config/atlas_rivals.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-thesis-rivals-validation

graph_title: Atlas Thesis - Rivals Validation

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Thesis - Rivals Validation
canonical_name: Atlas Thesis - Rivals Validation
technical_name: atlas-thesis-rivals-validation
cartography_type: module
canonical_source: docs/engineering-knowledge-base/thesis/rivals-validation.md

owner: thesis

repo_paths:
  - docs/engineering-knowledge-base/thesis/rivals-validation.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-rivals-product-v1
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-rivals-claims-and-reporting-v1
  - atlas-cartography

unlocks:
  - rivals_uplift_measurement

governs:
  - thesis_rivals_multiplier

evidence:
  - docs/engineering-knowledge-base/atlas-rivals-product-v1.md
  - app/Services/Ai/Rivals/Core/AtlasUpliftRunner.php

evidence_refs:
  - command: atlas:rivals
  - symbol: AtlasUpliftRunner
required_tests:
  - "php artisan atlas:rivals doctor --json"

requires_evidence: true

risk_level: high

visual_tags:
  - module
  - module
  - thesis

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
  - Manter alinhado com atlas-rivals-product-v1 e AtlasUpliftRunner; runtime CLI atlas:rivals.
---

> Tese 2.0 (2026-07-09): produto e estrutura vivem em `atlas-rivals-product-v1` / `atlas-rivals-structure-v1`.
> Runtime: `atlas:rivals`. Kill-map do 1.0: `atlas-rivals2-rebuild-map-v1.md`.

# Atlas Thesis - Rivals Validation

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



## Purpose

Rivals (2.0) measures two things on the same ruler:

1. **Model vs model** (scoped by suite / task_type / budget) — quality, cost, cost-per-task, time, stability.
2. **Atlas uplift** — same model/case/budget; only variable is runtime (`bare` vs `atlas_dev` / forge / autonomous).

Uplift arms:

1. Atlas path: Kernel pipeline, memory, policy, provider selection, gates, repair, evidence (when runtime_commands are wired).
2. Direct path: same provider/model and workspace without the Atlas ecosystem.

It answers: did Atlas multiply, match or degrade the provider? (and, separately, which model wins which scoped task.)

## Outcomes

| Outcome | Meaning | Action |
|---|---|---|
| Positive multiplier | Atlas beats direct provider in quality/efficiency/gates | Continue and record evidence |
| Neutral multiplier | Atlas roughly equals direct provider | Investigate overhead and missed leverage |
| Negative multiplier | Atlas is worse than direct provider | Stop-the-line; isolate and fix/remove culprit |

Negative sources can include stale memory, bad skill prompt, wrong provider
selection, overactive constitutional filter, false-positive gate, divergent
repair loop or latency/UX overhead.

## Metrics

Track at minimum:

- score gap;
- gate pass/fail delta;
- iterations to success;
- time to acceptable output;
- cost;
- failure source attribution;
- user regret/acceptance when available.

## Enterprise Claim Gate

Atlas Rivals is important, but it is not the only active priority. Do not spend
the whole structure-mother cycle polishing Rivals while Memory/Open Brain,
Capture, Task Orchestration and Tool Runtime still need product consolidation.

Rivals can remain in a strong gated state until it is the right lane to finish.
When a public or internal enterprise claim is made, the report must be
audit-grade:

- no synthetic score;
- real baseline executed;
- same case/input contract for both arms;
- separate workspaces or verified isolation;
- provider/model lock for fair mode;
- fallback and Atlas Decide disabled for Fair Claude;
- minimum release comparable sample before claim;
- protocol validity, final gate and pass-without-human at 100%;
- replay artifact present, hash-verified and inside allowed storage root;
- export bundle verifiable by manifest/evidence hash;
- environment failures classified separately from model quality failures.

Runtime atual: o arm `atlas_dev` usa bridge nao-interativo sobre
`atlas:cli:dev`, com single-provider, Atlas Decide/fallback e fast-path
deterministico desligados. Cada receipt de uplift precisa carregar prova desse
bridge; arm_id sozinho nao prova que Atlas rodou.

`fase_a_100_percent_authorized` certifica o produto somente quando
`atlas:rivals closure --strict` encontra 10 bundles nativos + cinco uplifts
reais claim-ready + tests/docs/ledger/prerequisites verdes. Nao equivale a
`public_claim_allowed`; promocao publica continua sujeita a todos os itens
acima e a verificacao independente do bundle.

If any item fails, the correct status is `not_ready`, even when the current
sample says Atlas or the rival is leading.

## Cadence

- quick: before commits touching Kernel, Memory, Skills, Gates or Providers;
- medium: weekly health check;
- full: before significant release;
- on-demand: whenever Atlas feels worse than direct provider.

## P4 / strategy surface (1.0 — retired)

The old `atlas:ai:rivals-strategy` / Rivals Strategy P4 surface belonged to Rivals 1.0
and is **not** a live claim path. Uplift and model comparison use `atlas:rivals`
with scoped `claim_allowed` gates (`atlas-rivals-claims-and-reporting-v1`).

## Domain Expansion

Every major domain eventually needs its own suite:

- Rivals-Programming;
- Rivals-Finance;
- Rivals-Research;
- Rivals-Learning;
- Rivals-Voice;
- Rivals-Marketing.

The structure stays universal: Atlas path vs direct/provider/best-market path,
measured through evidence and fed back to Curator.

## Resumo

Empirical validation contract for proving whether Atlas multiplies, matches or degrades direct provider output.

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
