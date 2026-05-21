---
id: atlas-ai-self-construction-durable-reservation-readiness-projection-contract
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Readiness Projection Contract
status: active
category: architecture
priority: 100
summary: Contract for feeding durable reservation projections into packet queue, collision matrix and multi-session readiness gates.
tags:
  - atlas-ai
  - self-construction
  - readiness-projection
  - durable-reservation
capabilities:
  - self_construction_durable_reservation_readiness_projection_contract
  - reservation_ledger
  - multi_session_readiness
decisions:
  - Multi-session readiness must consume durable reservation projection state before allowing parallel AI work.
  - Packet queue and collision matrix must explain blocks from active reservations, leases, dependencies and hot scopes.
  - Local reservation projection may mark packets as claimed, but it must not dispatch work or enable autonomous execution.
maintenance:
  - Update before changing readiness inputs, queue states, projection summaries or multi-session gate blockers.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-lease-lifecycle-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-implementation-preflight-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-durable-reservation-readiness-projection-contract

graph_title: Atlas Self-Construction Durable Reservation Readiness Projection Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Durable Reservation Readiness Projection Contract
canonical_name: Atlas Self-Construction Durable Reservation Readiness Projection Contract
technical_name: atlas-ai-self-construction-durable-reservation-readiness-projection-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md

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
  - docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md

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
# Atlas Self-Construction Durable Reservation Readiness Projection Contract

This contract defines how durable reservation state feeds packet queue and
readiness decisions. Atlas now has a local file-backed projection that can mark
packets as `claimed` after `--claim-packet`, while dispatch and autonomous
execution remain disabled.

## Projection Inputs

- active reservations;
- expired reservations;
- completed packets;
- blocked packets;
- packet dependencies;
- allowed file scopes;
- hot forbidden scopes;
- current changed files;
- completion gate status.

## Derived Queue States

- `available`: no active reservation, dependencies complete and no hot scope.
- `claimed`: active local lease exists for the packet.
- `blocked_by_collision`: allowed files overlap an active claim.
- `blocked_by_dependency`: dependency packet is incomplete.
- `blocked_by_hot_scope`: packet touches forbidden hot files.
- `blocked_by_stale_hash`: packet hash changed after assignment.
- `completed`: durable completion exists.

## Readiness Outputs

- queue summary;
- claimable packet ids;
- blocked packet ids and reasons;
- active reservation owners;
- dependency unlock hints;
- multi-session decision;
- safe single-session fallback instruction.

## Required Tests

- active reservation removes packet from claimable queue;
- completed dependency unlocks dependent packet;
- hot scope blocks packet before queue assignment;
- stale packet hash blocks claim;
- multi-session gate can report `ready_for_multi_session_preview` when five
  cold-lane packets are available and the local durable ledger exists;
- claimed packet is removed from available packet count;
- single-session fallback remains available when parallel dispatch is blocked;
- projection command does not persist claims or write storage.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only projection
packet that future queue and readiness gates can implement without guessing
durable reservation semantics.

## Resumo

Contract for feeding durable reservation projections into packet queue, collision matrix and multi-session readiness gates.

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
