---
id: atlas-ai-self-construction-assignment-and-claim-contract
type: engineering_knowledge
title: Atlas Self-Construction Assignment And Claim Contract
status: active
category: architecture
priority: 100
summary: Contract for assigning one safe implementation packet to one AI session without collision or hidden execution.
tags:
  - atlas-ai
  - self-construction
  - assignment
  - multi-agent
capabilities:
  - self_construction_assignment_and_claim_contract
  - ai_implementation_packet
  - work_splitter
  - packet_assignment
decisions:
  - An AI session may work on only one claimed packet at a time.
  - Claim preview is read-only until a durable reservation ledger exists.
  - Assignment must prefer the safest unblocked packet over maximum throughput.
maintenance:
  - Update before implementing packet reservation, claim persistence or multi-session assignment.
related_paths:
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-assignment-and-claim-contract

graph_title: Atlas Self-Construction Assignment And Claim Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Assignment And Claim Contract
canonical_name: Atlas Self-Construction Assignment And Claim Contract
technical_name: atlas-ai-self-construction-assignment-and-claim-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md

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
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
evidence_refs:
  - symbol: AtlasAssignmentAndClaimContractService
  - command: atlas:aaeos:assignment-and-claim-contract
  - test: AtlasAssignmentAndClaimContractTest

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
# Atlas Self-Construction Assignment And Claim Contract

Assignment and Claim is the rule that lets multiple AI sessions continue Atlas
implementation without taking the same work.

## Purpose

It must answer:

- which packet this AI should take;
- why this packet is safe now;
- whether the packet is already claimed;
- what scope the AI owns;
- when the AI must stop instead of implementing.

## Non Goals

- Do not persist claims until the reservation ledger exists.
- Do not grant write authority.
- Do not override Scope Validator.
- Do not assign hot Voice, Kernel, provider, route, migration or daemon work.
- Do not let one session own multiple packets.

## Claim Preview Schema

```json
{
  "schema_version": "atlas.self_construction_assignment_preview.v1",
  "assignment_id": "ASSIGN-YYYYMMDD-0001",
  "status": "claim_preview_ready | blocked",
  "session_owner": "read_only_preview",
  "selected_packet_id": "AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001",
  "claim_state": "preview_only_not_persisted",
  "execution_allowed": false,
  "packet_hash": "sha256",
  "split_hash": "sha256",
  "allowed_files": [],
  "forbidden_files": [],
  "required_first_commands": [],
  "stop_conditions": []
}
```

## Selection Policy

Atlas selects the first packet that satisfies:

```text
1. status=available
2. collision_risk is none or low
3. dependencies are empty or already complete
4. allowed_files are disjoint from other selected packets
5. forbidden_files include hot external scopes
6. required validator exists
```

If no packet qualifies, Atlas must return `blocked` with reasons.

## AI Protocol

After receiving a claim preview, the AI must:

- read the selected packet;
- confirm `execution_allowed=false`;
- run `git status --short`, `git diff --stat`, `git diff --name-only`;
- implement only after user/governed instruction allows work in the selected
  scope;
- run Scope Validator before completion;
- stop on stale packet hash, hot file, unknown file or failed gate.

## Collision Rules

Assignment blocks when:

- packet writes a file already changed by another hot scope;
- packet overlaps another claimed packet;
- packet depends on incomplete work;
- packet lacks required gates;
- packet does not list forbidden hot scopes;
- packet tries to authorize runtime execution without receipt.

## Read-Only Phase

Current implementation may emit only a claim preview:

```text
claim_preview_ready
execution_allowed=false
claim_state=preview_only_not_persisted
```

Durable claim persistence requires a future reservation ledger AP.

## Completion Criteria

This contract is complete when an AI can receive one selected packet, know the
owned scope, know what is forbidden, and know that no actual claim was persisted
or execution authorized.

## Resumo

Contract for assigning one safe implementation packet to one AI session without collision or hidden execution.

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
