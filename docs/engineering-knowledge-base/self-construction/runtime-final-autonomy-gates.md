---
id: atlas-ai-self-construction-runtime-final-autonomy-gates
type: engineering_knowledge
title: Atlas Self-Construction Runtime Final Autonomy Gates
status: active
category: architecture
priority: 96
summary: Compact reference of the gates that final Self-Construction autonomy must satisfy before any 24/7 promotion.
tags:
  - atlas-ai
  - self-construction
  - gates
  - autonomy
capabilities:
  - self_construction_runtime_final_autonomy_gates
decisions:
  - Final 24/7 autonomy is only granted when EVERY gate below has machine-verifiable evidence.
  - Evidence is classified ready / hold / blocked - no scalar scoring is used.
  - Workers and final completion checks consume this doc; the roadmap parent links here instead of duplicating gate detail.
maintenance:
  - Update when a gate changes; roadmap parent should keep its link in sync.
related_paths:
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap-waves.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 320
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-runtime-final-autonomy-gates

graph_title: Atlas Self-Construction Runtime Final Autonomy Gates

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-runtime-implementation-roadmap

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Runtime Final Autonomy Gates
canonical_name: Atlas Self-Construction Runtime Final Autonomy Gates
technical_name: atlas-ai-self-construction-runtime-final-autonomy-gates
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/runtime-final-autonomy-gates.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/runtime-final-autonomy-gates.md

allowed_changes:
  - Atualizar este doc quando um gate, evidencia ou contrato canonico mudar.

forbidden_changes:
  - Declarar autonomia 24/7 sem evidencia verificavel em todos os gates listados.

depends_on:
  - atlas-ai-self-construction-runtime-implementation-roadmap

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/runtime-final-autonomy-gates.md
evidence_refs:
  - command: atlas:engineering:knowledge

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - module
  - self-construction
  - gates

ai_entrypoints:
  - Use este doc como referencia unica de gates de fechamento ao validar promocao para 24/7.

ai_usage_notes:
  - Cada gate carrega evidencia ready / hold / blocked; nenhum gate pode ser "passado" sem evidencia maquina-verificavel.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Gate declarado verde sem evidencia ou com evidencia stale.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com a implementacao real e com o roadmap pai.
---
# Atlas Self-Construction Runtime Final Autonomy Gates

This reference holds the **gates** every Self-Construction slice must satisfy
before being promoted toward 24/7 autonomy. The roadmap parent
[runtime-implementation-roadmap.md](runtime-implementation-roadmap.md) links here
instead of duplicating the detailed gate list.

Each gate emits one of three evidence classes:

- **ready** - machine-verifiable proof present, all signals green.
- **hold** - refreshable proof missing or stale; safe to retry after refresh.
- **blocked** - unsafe failure, contract violation, or missing non-refreshable evidence; promotion forbidden until resolved.

No scalar scoring is allowed.

## Gate - Server-Side Verification

- ready: independent verifier run on the server confirms tests / gates / acceptance and emits a signed verdict.
- hold: verifier facts missing because the verifier was not run on this revision (re-run available).
- blocked: verifier reported fail / contradictory, or the verifier itself failed to start.

## Gate - Rollback

- ready: rollback plan exists, was validated dry-run, and a recent rollback execution receipt is on file.
- hold: rollback plan present but unexecuted on this revision (replay available).
- blocked: rollback execution previously failed, or no rollback plan exists for the touched scope.

## Gate - Kill Switch

- ready: master switch path proven OFF-by-default, with an integration test confirming OFF byte-identical no-op behaviour.
- hold: switch facts not refreshed on the current revision.
- blocked: any path can self-flip the master switch, or OFF state still runs work.

## Gate - Backlog Depth

- ready: queue health report shows sustainable depth, malformed_count = 0, and replenisher recent success.
- hold: queue depth below floor but replenisher is allowed and not jammed.
- blocked: replenisher jammed, malformed_count > 0 beyond repair budget, or scope-repair-doomed packets present.

## Gate - Structured Give-Back Learning

- ready: give-back ledger writes a structured outcome row for every give-back, with diagnostic class and counter.
- hold: ledger present but no recent rows on this revision (no give-backs to record).
- blocked: give-backs occur without ledger writes, or ledger writes lack diagnostic class.

## Gate - Code Intelligence

- ready: code status ready, index symbol count above floor, no staleness, and schema drift auditor passed.
- hold: index empty or stale (refreshable by re-running engineering knowledge index-code).
- blocked: code status reports failure, or schema drift auditor reports stamped-but-tables-missing or column drift.

## Gate - Knowledge Sync

- ready: docs-health report status ok, KB sync recent, code-intelligence sync recent.
- hold: docs-health flags refreshable warnings only; KB / code sync stale but allowed.
- blocked: docs-health reports blocking violations, or KB / code sync repeatedly fails.

## Gate - Multi-Project Isolation

- ready: project lane registry shows isolated workspaces, isolated queues, isolated docs roots, no cross-project leak.
- hold: lane registry present but unverified on the current revision.
- blocked: any cross-project leak detected, or lane registry missing for a scope tagged external.

## Resumo

Referencia compacta dos gates que toda promocao final para 24/7 deve satisfazer.

## Papel no Atlas

Da aos workers e ao Final Completion uma unica fonte para evidencias de fechamento.

## Onde Se Encaixa

Filho conceitual do runtime-implementation-roadmap.md; consumido por scripts de validacao e por workers.

## Contratos

Cada gate declara classes de evidencia ready / hold / blocked, sem scalar scoring; promocao 24/7 exige todos ready.

## Fluxo

Worker / Final Completion coleta evidencia maquina-verificavel para cada gate, classifica, e so promove quando todos saem ready.

## Regras para IA

Agentes nao podem declarar autonomia 24/7 sem evidencia em cada gate; hold ou blocked impede a promocao.

## Escopo de Implementacao

Edicoes ficam limitadas a este arquivo e ao roadmap pai quando o link precisar mudar.

## Dependencias

Depende do roadmap pai e dos contratos canonicos Self-Construction.

## Evidencias

Aceitas: docs-health verde, lint-file verde, receipts e relatorios maquina-verificaveis por gate.

## Riscos

Gate declarado verde sem evidencia, ou gate stale aceito como ready; mitigado por docs-health e lint-file.

## Exemplos

Cada gate acima descreve explicitamente o que conta como ready, hold e blocked.

## Proximas Acoes

Manter sincronizado com a implementacao real e com o roadmap pai.
