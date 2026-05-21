---
id: atlas-ai-self-construction-packet-queue-contract
type: engineering_knowledge
title: Atlas Self-Construction Packet Queue Contract
status: active
category: architecture
priority: 100
summary: Contract for a queue of available, claimed, completed, blocked and withheld Self-Construction packets.
tags:
  - atlas-ai
  - self-construction
  - packet-queue
  - parallel-ai
capabilities:
  - self_construction_packet_queue_contract
  - work_splitter
  - packet_assignment
decisions:
  - Parallel AI sessions need a queue view backed by durable reservation projection.
  - Queue ranks available work and reflects claimed/completed packets, but must not dispatch, execute or complete packets itself.
  - Hot external work must appear as withheld, not assignable.
maintenance:
  - Update before adding automated packet dispatch, completion writes or Postgres projection storage.
related_paths:
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-packet-queue-contract

graph_title: Atlas Self-Construction Packet Queue Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Packet Queue Contract
canonical_name: Atlas Self-Construction Packet Queue Contract
technical_name: atlas-ai-self-construction-packet-queue-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/packet-queue-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md

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
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md

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
# Atlas Self-Construction Packet Queue Contract

Packet Queue is the work board a new AI session can inspect before choosing or
receiving a packet. It now consumes the local durable reservation projection so
claimed packets are removed from the available pool. Completed packets are also
removed from the claimable pool and can unlock dependent work when dependencies
exist.

## Purpose

It must show:

- assignable packets;
- claimed packets and lease owner/session;
- completed packets and completion owner/time;
- dependency-blocked packets;
- withheld hot work;
- selected recommended packet;
- disjoint allowed files;
- forbidden scopes;
- commands required before work;
- queue hash.

## Non Goals

- Do not dispatch work automatically.
- Do not execute work.
- Do not mark packet completion.
- Do not hide blocked or withheld work.

## Queue Entry Schema

```json
{
  "packet_id": "AIP-SPLIT-...",
  "lane": "docs|runtime_read_only|tests",
  "queue_state": "available|claimed|completed|blocked_by_dependency|withheld",
  "rank": 1,
  "active_reservation_id": "RES-...",
  "active_reservation_actor": "codex-a|claude-a|gemini-a|local-a",
  "active_reservation_session": "session-a",
  "lease_expires_at": "iso8601",
  "completed_reservation_id": "RES-...",
  "completed_at": "iso8601",
  "completion_actor": "codex-a|claude-a|gemini-a|local-a",
  "provider_profile": "codex|claude|gemini|local_agent|generic",
  "claim_policy": "single_owner",
  "allowed_files": [],
  "forbidden_files": [],
  "depends_on": [],
  "recommended": true
}
```

## Ranking Rules

Packets rank higher when:

- dependencies are empty;
- collision risk is low;
- allowed files are disjoint;
- packet can be validated with existing gates;
- packet does not touch hot Voice/Kernel scope.

## Required Guarantees

The queue preview must emit:

```text
execution_allowed=false
claim_persisted=false
ledger_write_allowed=false
queue_write_allowed=false
```

`claim_persisted=false` means the queue command itself did not claim. It may
still report packets claimed by `--claim-packet` or `--claim-next-packet`.
Completed packets may be written only by `--complete-packet`, which records
packet state but does not approve code, dispatch work, merge changes or bypass
quality gates.

## Completion Criteria

This contract is complete when Atlas can emit a deterministic queue with
available, claimed, blocked and withheld work while keeping dispatch and
completion disabled.

## Resumo

Contract for a queue of available, claimed, completed, blocked and withheld Self-Construction packets.

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
