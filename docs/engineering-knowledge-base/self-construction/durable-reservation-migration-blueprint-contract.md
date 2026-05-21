---
id: atlas-ai-self-construction-durable-reservation-migration-blueprint-contract
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Migration Blueprint Contract
status: active
category: architecture
priority: 100
summary: Read-only migration blueprint for future durable reservation ledger tables, columns, indexes, constraints and rollback rules.
tags:
  - atlas-ai
  - self-construction
  - migration-blueprint
  - durable-reservation
capabilities:
  - self_construction_durable_reservation_migration_blueprint_contract
  - reservation_ledger
  - migration_blueprint
decisions:
  - Durable reservation migrations must be generated from a deterministic blueprint, not guessed during implementation.
  - The blueprint must define tables, columns, indexes, uniqueness rules, rollback and tests before migration files exist.
  - Blueprint generation is read-only and cannot create migrations, write storage, persist claims or dispatch work.
maintenance:
  - Update before changing durable reservation migration names, table columns, indexes, constraints or rollback rules.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-durable-reservation-migration-blueprint-contract

graph_title: Atlas Self-Construction Durable Reservation Migration Blueprint Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Durable Reservation Migration Blueprint Contract
canonical_name: Atlas Self-Construction Durable Reservation Migration Blueprint Contract
technical_name: atlas-ai-self-construction-durable-reservation-migration-blueprint-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md

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
  - docs/engineering-knowledge-base/self-construction/durable-reservation-migration-blueprint-contract.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - contract
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
# Atlas Self-Construction Durable Reservation Migration Blueprint Contract

This contract defines the exact future migration blueprint for durable packet
reservations. It is not a migration and must not touch `database/migrations`.

## Future Migration Files

- `create_atlas_self_construction_reservation_events_table`;
- `create_atlas_self_construction_reservations_table`;
- optional backfill/rebuild command only after repository and projection tests exist.

## Reservation Events Table

Required columns:

- `id`;
- `reservation_id`;
- `packet_id`;
- `event_type`;
- `actor_id`;
- `session_id`;
- `packet_hash`;
- `allowed_files_hash`;
- `previous_event_hash`;
- `event_hash`;
- `payload`;
- `created_at`.

Required indexes:

- `reservation_id`;
- `packet_id`;
- `event_type`;
- `session_id`;
- `event_hash` unique;
- `created_at`.

## Reservations Projection Table

Required columns:

- `id`;
- `reservation_id`;
- `packet_id`;
- `owner_id`;
- `session_id`;
- `state`;
- `packet_hash`;
- `allowed_files_hash`;
- `lease_expires_at`;
- `completed_at`;
- `released_at`;
- `blocker_reason`;
- timestamps.

Required indexes:

- unique active packet claim guard;
- packet id;
- owner/session;
- state;
- lease expiry;
- allowed files hash.

## Required Tests

- migrations create both tables with required columns;
- event hash is unique;
- active packet claim uniqueness is enforced by repository transaction tests;
- lease expiry is queryable;
- projection can be rebuilt from events;
- rollback drops projection before events;
- blueprint command does not create migrations or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only migration
blueprint that future implementation can convert into Laravel migrations
without changing scope, naming or invariants.

## Resumo

Read-only migration blueprint for future durable reservation ledger tables, columns, indexes, constraints and rollback rules.

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
