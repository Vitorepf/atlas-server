---
id: atlas-ai-self-construction-reservation-ledger-contract
type: engineering_knowledge
title: Atlas Self-Construction Reservation Ledger Contract
status: active
category: architecture
priority: 100
summary: Contract for durable local packet reservations that prevent multiple AI sessions from claiming the same work.
tags:
  - atlas-ai
  - self-construction
  - reservation-ledger
  - multi-agent
capabilities:
  - self_construction_reservation_ledger_contract
  - packet_assignment
  - reservation_ledger
decisions:
  - Durable packet reservation requires an explicit ledger contract before any write implementation.
  - The current implementation uses a local append-only file ledger and projection.
  - A packet reservation must expire, be auditable, preserve disjoint write sets and block completed packets from being reclaimed.
maintenance:
  - Update before changing local ledger storage, lease renewal, claim release, completion or future Postgres promotion.
related_paths:
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-reservation-ledger-contract

graph_title: Atlas Self-Construction Reservation Ledger Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Reservation Ledger Contract
canonical_name: Atlas Self-Construction Reservation Ledger Contract
technical_name: atlas-ai-self-construction-reservation-ledger-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md

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
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
evidence_refs:
  - symbol: AtlasReservationLedgerContractService
  - command: atlas:aaeos:reservation-ledger-contract
  - test: AtlasReservationLedgerContractTest

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
# Atlas Self-Construction Reservation Ledger Contract

Reservation Ledger is the durable local record that prevents two AI sessions
from owning the same packet at the same time. It currently stores append-only
events and a projection under `storage/app/atlas/self-construction`.

## Purpose

It must track:

- packet id;
- owner/session id;
- claim state;
- lease expiration;
- allowed files;
- forbidden files;
- packet hash;
- split hash;
- release reason;
- completion reason;
- completion timestamp;
- evidence hash.

## Non Goals

- Do not implement persistence in the current read-only phase.
- Do not grant execution authority.
- Do not replace Decision Receipt.
- Do not allow packet overlap.
- Do not reserve hot external scopes.

## Ledger Row Schema

```json
{
  "reservation_id": "RES-YYYYMMDD-0001",
  "packet_id": "AIP-SPLIT-...",
  "owner_id": "session-or-agent-id",
  "state": "preview | claimed | released | expired | completed | blocked",
  "lease_expires_at": "datetime",
  "packet_hash": "sha256",
  "split_hash": "sha256",
  "allowed_files": [],
  "forbidden_files": [],
  "completion_gate_hash": "sha256|null",
  "created_at": "datetime",
  "updated_at": "datetime"
}
```

## State Rules

- `preview` means no durable claim exists.
- `claimed` means exactly one owner holds the packet.
- `released` means work was intentionally returned.
- `expired` means owner timed out.
- `completed` means the owner reported packet work complete and provided
  optional evidence hash; it does not approve code, merge changes or bypass
  quality gates.
- `blocked` means scope, evidence or hot files prevent work.

## Collision Rules

A reservation must be blocked when:

- packet is already claimed and lease is active;
- packet was already completed;
- allowed files overlap another active reservation;
- packet hash changed after assignment;
- split hash changed after assignment;
- hot external scope appears in allowed files;
- completion gate is blocked.

## Local Durable Commands

Current implementation supports:

```text
--reservation-status
--claim-packet
--claim-next-packet
--complete-packet
--release-packet
```

`--complete-packet` persists packet state only. It does not grant approval,
dispatch work, enable execution or mark the whole Self-Construction OS complete.

## Completion Criteria

This contract is complete when Atlas can claim, release, complete and inspect
local packet reservations while preserving scope isolation and keeping dispatch,
approval and auto-merge as separate governed steps.

## Resumo

Contract for durable local packet reservations that prevent multiple AI sessions from claiming the same work.

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
