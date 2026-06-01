---
id: atlas-ai-self-construction-durable-reservation-storage-schema
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Storage Schema
status: active
category: architecture
priority: 100
summary: Storage contract for durable packet reservation events, projections, future migrations, indexes and invariants.
tags:
  - atlas-ai
  - self-construction
  - storage-schema
  - durable-reservation
capabilities:
  - self_construction_durable_reservation_storage_schema
  - reservation_ledger
  - durable_claims
decisions:
  - Durable reservation storage must be event-backed, append-only and projection-readable.
  - Current storage is local file-backed under storage/app; future migrations must preserve the same invariants in Postgres.
  - Exactly one active reservation per packet and overlapping active file-scope blocks are mandatory.
  - Storage claims never grant dispatch, execution, auto-merge or hot-scope authority.
maintenance:
  - Update before changing durable reservation table names, states, indexes, invariants or migration gates.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-packet.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-durable-reservation-storage-schema

graph_title: Atlas Self-Construction Durable Reservation Storage Schema

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Durable Reservation Storage Schema
canonical_name: Atlas Self-Construction Durable Reservation Storage Schema
technical_name: atlas-ai-self-construction-durable-reservation-storage-schema
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md

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
  - docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md
evidence_refs:
  - symbol: AtlasDurableReservationStorageSchemaService
  - command: atlas:aaeos:durable-reservation-storage-schema
  - test: AtlasDurableReservationStorageSchemaTest

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
# Atlas Self-Construction Durable Reservation Storage Schema

This contract defines the storage shape for durable reservation claims. The
current runtime uses:

- `events.jsonl`: append-only hash-chained reservation events;
- `projection.json`: current reservation projection;
- `ledger.lock`: local file lock for atomic claim/release operations.

The Postgres table shape below remains the promotion target for a later
enterprise backend migration.

## Tables

`atlas_self_construction_reservation_events`

- append-only event source for reservation lifecycle;
- stores reservation id, packet id, event type, actor, session, packet hash,
  allowed files hash, previous event hash, event hash and payload;
- supports audit, replay, tamper detection and rollback reasoning.

`atlas_self_construction_reservations`

- current projection rebuilt from events;
- stores packet id, owner/session, state, packet hash, allowed files hash, lease
  expiry, release/completion timestamps and blocker reason;
- supports fast collision checks and multi-session readiness.

## Required Invariants

- Events are append-only.
- Each event references the previous event hash when one exists.
- Exactly one active claimed reservation may exist per packet.
- Active reservations with overlapping allowed files block new claims.
- Expired leases cannot mark completion.
- Hot Voice/Kernel scopes are never claimable.
- Dispatch remains disabled by this schema.

## Required Tests

- migration contains hash, actor, state, lease and payload fields;
- duplicate active packet claim is blocked;
- overlapping active file scope is blocked;
- expired reservation cannot complete;
- released reservation can be reclaimed;
- projection can be rebuilt from events;
- schema command does not create migrations or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only schema
packet that future migrations can implement without guessing table shape,
indexes, states, invariants or tests.

## Resumo

Storage contract for durable packet reservation events, projections, future migrations, indexes and invariants.

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
