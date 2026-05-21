---
id: atlas-ai-strategic-decision-domain
type: engineering_knowledge
title: Atlas AI Strategic Decision Domain
status: active
category: architecture
priority: 95
summary: Spec canonica implemented/ready do dominio Strategic Decision para co-estrategia review-only, cool-down, valores, contraargumento, regret tracking e padroes longitudinais sem execucao autonoma.
capabilities:
  - strategic_decision_domain
  - co_strategist_review
  - rivals_strategy_validation
  - regret_tracking
  - operator_agency_gate
decisions:
  - Strategic Decision e dominio implemented/ready, mas estritamente review-only.
  - O dominio pode discordar, revisar, enquadrar e agendar revisitas, mas nao decide nem executa compromissos pelo operador.
  - Decisoes de alto impacto exigem cool-down, revisao humana, Rivals Strategy case e revisit schedule.
maintenance:
  - Atualize este documento quando flows, gates, runtime, Rivals Strategy ou qualitative level gates mudarem.
  - Rodar docs-health e architecture-validate depois de alterar o contrato do dominio.
related_paths:
  - app/Services/Ai/Domain/AtlasStrategicDecisionOrchestrator.php
  - app/Services/Ai/AtlasDomainProfileRegistry.php
  - database/migrations/2026_05_06_130000_seed_strategic_decision_domain_contract.php
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
owner: atlas-ai
layer: domain
line_limit: 220
tags:
  - atlas-ai
  - strategic-decision
  - co-strategist
related:
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-thesis-multiplier-channel.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-strategic-decision-domain

graph_title: Atlas AI Strategic Decision Domain

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Strategic Decision Domain
canonical_name: Atlas AI Strategic Decision Domain
technical_name: atlas-ai-strategic-decision-domain
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/strategic-decision.md

repo_paths:
  - docs/engineering-knowledge-base/domains/strategic-decision.md

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
  - domains

evidence:
  - docs/engineering-knowledge-base/domains/strategic-decision.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - domains

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
# Atlas AI Strategic Decision Domain

Strategic Decision is the domain for long-horizon choices where Atlas must act as
a co-strategist, not a command executor. It exists to help frame decisions,
disagree well, preserve Vitor's agency, schedule revisits, and turn outcomes
into Rivals Strategy evidence.

## Status

Current status: implemented/ready with plan-only review surface.

The domain is not allowed to execute decisions. It can generate a review packet;
with explicit audit it emits a dry-run Decision Receipt and Ledger evidence; with
explicit Rivals registration it creates internal follow-up cases.

## Scope

Included:

1. major personal, product, company, architecture, and market direction choices;
2. explicit disagreement and counterargument;
3. cool-down windows for high-impact decisions;
4. values alignment and tradeoff review;
5. regret tracking through Rivals Strategy cases;
6. longitudinal pattern discovery after reviewed evidence exists.

Excluded:

1. autonomous life, business, finance, or relationship commitments;
2. medical, legal, or financial execution;
3. replacing Vitor's judgment;
4. silently changing Atlas behavior based on unreviewed patterns.

## Flows

1. `strategic_decision.review`
2. `strategic_decision.cooldown`
3. `strategic_decision.values_alignment`
4. `strategic_decision.counterargument`
5. `strategic_decision.regret_tracking`
6. `strategic_decision.longitudinal_pattern`

## Required Gates

Every flow must preserve:

1. `cooldown_policy`
2. `multi_perspective_review`
3. `values_alignment`
4. `operator_agency`

High-impact decisions additionally require:

1. human review;
2. a Rivals Strategy case;
3. a scheduled revisit.

## Runtime Boundary

Runtime family: `strategic_decision`.

Execution mode: `review_only`.

The domain may produce plans, decision frames, counterarguments, revisit
schedules, and review packets. `atlas:ai:strategic-decision review` is packet
only by default. `--audit` writes dry-run evidence. `--register-rivals` creates
an internal Rivals Strategy case for later review. It must not execute the
decision itself.

Longitudinal review is explicit: `atlas:ai:rivals-strategy record-review`
records regret, alignment and agency scores. These scores feed qualitative
levels; agency below threshold blocks P4+ claims. `due-reviews` lists pending
revisits and record commands. Self-Improvement may emit an Inbox item with
`record_rivals_review`, but humans still provide scores; the Inbox action only
records, audits and closes the revisit when the human supplied evidence exists.

## Evidence Contract

Important outputs should become:

1. a Decision Receipt when routed through Atlas Decide;
2. an Evidence Ledger event when evaluated;
3. a Rivals Strategy case only when the operator explicitly registers it;
4. scheduled 30/90/180/365-day reviews when regret tracking is needed;
5. scored review evidence when revisits happen;
6. private memory only after review.

## Ready Boundary

Strategic Decision is ready because it has:

1. domain/profile/flow declarations;
2. implemented orchestrator SDK methods;
3. plan-only review runtime through `StrategicDecisionReviewService`;
4. dry-run Decision Receipt and Evidence Ledger audit path;
5. CLI/API surfaces;
6. Rivals Strategy registration and revisit tracking;
7. tests proving review-only behavior and no autonomous commitment.

Readiness does not mean autonomous authority. Strategic Decision can review,
frame, disagree, audit and schedule revisits. It still cannot execute life,
business, finance, legal, medical or relationship commitments for the operator.

## Resumo

Spec canonica implemented/ready do dominio Strategic Decision para co-estrategia review-only, cool-down, valores, contraargumento, regret tracking e padroes longitudinais sem execucao autonoma.

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
