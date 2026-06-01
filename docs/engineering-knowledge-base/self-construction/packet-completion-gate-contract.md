---
id: atlas-ai-self-construction-packet-completion-gate-contract
type: engineering_knowledge
title: Atlas Self-Construction Packet Completion Gate Contract
status: active
category: architecture
priority: 100
summary: Contract for deciding whether a packet may be marked complete, remain blocked, or require human review.
tags:
  - atlas-ai
  - self-construction
  - completion-gate
  - evidence
capabilities:
  - self_construction_packet_completion_gate_contract
  - packet_completion_review
  - implementation_evidence
decisions:
  - Completion decisions must be derived from evidence report, not agent confidence.
  - Read-only completion gate may recommend, but never mark durable completion.
  - External blockers must prevent automated completion claims.
maintenance:
  - Update before implementing packet done states, completion persistence or evidence ledger writes.
related_paths:
  - docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-packet-completion-gate-contract

graph_title: Atlas Self-Construction Packet Completion Gate Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Packet Completion Gate Contract
canonical_name: Atlas Self-Construction Packet Completion Gate Contract
technical_name: atlas-ai-self-construction-packet-completion-gate-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md

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
  - docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md
evidence_refs:
  - symbol: AtlasPacketCompletionGateContractService
  - command: atlas:aaeos:packet-completion-gate-contract
  - test: AtlasPacketCompletionGateContractTest

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
# Atlas Self-Construction Packet Completion Gate Contract

Packet Completion Gate converts packet evidence into a controlled completion
decision.

## Purpose

It must answer:

- can this packet be completed;
- what blocks completion;
- which evidence is missing;
- whether human review is required;
- what the next action must be.

## Non Goals

- Do not persist packet completion.
- Do not write evidence ledger events.
- Do not override packet evidence report.
- Do not authorize execution.
- Do not merge or clean up external hot changes.

## Gate Schema

```json
{
  "schema_version": "atlas.self_construction_packet_completion_gate.v1",
  "gate_id": "COMPLETION-GATE-YYYYMMDD-0001",
  "selected_packet_id": "AIP-SPLIT-...",
  "status": "blocked | human_review_required | completion_candidate",
  "execution_allowed": false,
  "completion_allowed": false,
  "durable_completion_written": false,
  "decision": "block | request_human_review | candidate_only",
  "blocking_reasons": [],
  "required_next_action": "string"
}
```

## Decision Rules

- If evidence report is blocked, status must be `blocked`.
- If evidence report is clean but no human review exists, status must be
  `human_review_required`.
- If evidence report is clean and human review exists, read-only status may be
  `completion_candidate`.
- `completion_allowed` remains false until durable completion persistence is
  implemented by a future AP.

## Required Evidence

The gate must inspect:

- selected packet id;
- evidence report hash;
- scope validator status;
- blocking reasons;
- external blockers;
- required gate statuses;
- required evidence statuses.

## Completion Criteria

This contract is complete when the gate can block false completion, request
human review for clean evidence, and keep durable completion disabled until a
future persistence layer exists.

## Resumo

Contract for deciding whether a packet may be marked complete, remain blocked, or require human review.

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
