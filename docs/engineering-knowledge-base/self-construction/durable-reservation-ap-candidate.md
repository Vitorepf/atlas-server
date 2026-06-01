---
id: atlas-ai-self-construction-durable-reservation-ap-candidate
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation AP Candidate
status: active
category: architecture
priority: 100
summary: Candidate AP for implementing durable Self-Construction packet reservations after the read-only plan is accepted.
tags:
  - atlas-ai
  - self-construction
  - ap-candidate
  - durable-reservation
capabilities:
  - self_construction_durable_reservation_ap_candidate
  - durable_claims
  - implementation_packet
decisions:
  - Durable reservation implementation must be split into storage, repository, gates and readiness integration.
  - Dispatch stays disabled after the ledger exists until a separate dispatch AP is approved.
  - The AP candidate must include rollback and evidence before any database migration is allowed.
maintenance:
  - Update when the durable reservation implementation scope, table names or promotion gates change.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-durable-reservation-ap-candidate

graph_title: Atlas Self-Construction Durable Reservation AP Candidate

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Durable Reservation AP Candidate
canonical_name: Atlas Self-Construction Durable Reservation AP Candidate
technical_name: atlas-ai-self-construction-durable-reservation-ap-candidate
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md

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
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
evidence_refs:
  - symbol: AtlasDurableReservationApCandidateService
  - command: atlas:aaeos:durable-reservation-ap-candidate
  - test: AtlasDurableReservationApCandidateTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - module
  - self-construction

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
# Atlas Self-Construction Durable Reservation AP Candidate

This is the candidate AP for the future implementation that removes
`durable_reservation_ledger_missing`.

## Mission

Implement durable packet reservation so multiple AI sessions can claim disjoint
Self-Construction work without duplicate ownership or scope collision.

## Scope

Allowed future work:

- create reservation storage migrations after explicit approval;
- implement append-only reservation event ledger;
- implement current reservation projection;
- implement atomic claim, renew, release and expire operations;
- integrate durable projection into the multi-session readiness gate;
- keep dispatch disabled until a separate dispatch AP exists.

Forbidden work:

- do not start AI sessions;
- do not auto-dispatch packets;
- do not touch Voice, Kernel, routes or config;
- do not bypass Decision Receipt, scope validator or completion gate.

## Implementation Packets

1. Storage AP: migrations and model contracts only.
2. Repository AP: transactional claim lock and event append.
3. Collision AP: overlap and hot-scope rejection.
4. Lease AP: renewal, release, expiry and stale-hash protection.
5. Readiness AP: feed durable state into multi-session gate.

## Required Tests

- duplicate packet claim is blocked;
- overlapping allowed files are blocked;
- hot forbidden scopes are blocked;
- stale packet or split hash is blocked;
- expired claim cannot complete;
- released claim can be reclaimed;
- dispatch remains disabled after ledger activation.

## Evidence

The future AP must return migration diff, repository tests, gate output,
scope-validator output, architecture validation, docs-health and rollback notes.

## Completion Criteria

This AP candidate is complete when Atlas can emit it as a deterministic
read-only packet with clear phases, allowed scope, forbidden scope, tests,
rollback, evidence and non-execution guarantees.

## Resumo

Candidate AP for implementing durable Self-Construction packet reservations after the read-only plan is accepted.

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
