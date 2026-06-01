---
id: atlas-ai-self-construction-packet-evidence-report-contract
type: engineering_knowledge
title: Atlas Self-Construction Packet Evidence Report Contract
status: active
category: architecture
priority: 100
summary: Contract for deciding whether a selected implementation packet has enough evidence to be completed.
tags:
  - atlas-ai
  - self-construction
  - evidence
  - packet-completion
capabilities:
  - self_construction_packet_evidence_report_contract
  - implementation_evidence
  - packet_completion_review
decisions:
  - Packet completion is blocked unless evidence, gates and scope validation agree.
  - External hot blockers must be reported separately from packet-owned failures.
  - A narrative "done" response is never sufficient evidence.
maintenance:
  - Update before implementing packet completion review, evidence ledger writes or multi-agent done states.
related_paths:
  - docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-packet-evidence-report-contract

graph_title: Atlas Self-Construction Packet Evidence Report Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Packet Evidence Report Contract
canonical_name: Atlas Self-Construction Packet Evidence Report Contract
technical_name: atlas-ai-self-construction-packet-evidence-report-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md

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
  - docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md
evidence_refs:
  - symbol: AtlasPacketEvidenceReportContractService
  - command: atlas:aaeos:packet-evidence-report-contract
  - test: AtlasPacketEvidenceReportContractTest

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
# Atlas Self-Construction Packet Evidence Report Contract

Packet Evidence Report decides whether a selected packet can be considered
complete.

## Purpose

It must compare:

- selected packet;
- assignment preview;
- runbook;
- scope validator;
- required gates;
- evidence items;
- external blockers.

## Non Goals

- Do not write to Evidence Ledger yet.
- Do not mark packet completed persistently.
- Do not override failed gates.
- Do not ignore hot external files.
- Do not create a durable claim.

## Report Schema

```json
{
  "schema_version": "atlas.self_construction_packet_evidence_report.v1",
  "report_id": "EVIDENCE-REPORT-YYYYMMDD-0001",
  "selected_packet_id": "AIP-SPLIT-...",
  "status": "completion_ready | blocked",
  "execution_allowed": false,
  "completion_allowed": false,
  "gate_results": [],
  "evidence_results": [],
  "blocking_reasons": [],
  "external_blockers": [],
  "required_next_action": "string"
}
```

## Completion Rules

Completion is allowed only when:

- selected packet exists;
- runbook exists;
- Scope Validator passes;
- required gates are present;
- required evidence is present;
- no forbidden, unknown or hot external file is owned by the packet;
- residual risk is low or accepted by review.

## Blocked States

The report must block completion when:

- Scope Validator is blocked;
- tests are missing or failed;
- architecture validation failed;
- docs-health failed;
- `git diff --check` failed;
- required evidence is missing;
- external hot files are mixed with packet-owned scope.

## Read-Only Phase

Current implementation may only emit:

```text
completion_allowed=false
evidence_ledger_write_allowed=false
status=blocked when current worktree contains hot external blockers
```

Durable completion requires a future packet reservation/evidence ledger AP.

## Completion Criteria

This contract is complete when another AI can inspect the report and know if its
packet is complete, blocked, or needs review without trusting free-form text.

## Resumo

Contract for deciding whether a selected implementation packet has enough evidence to be completed.

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
