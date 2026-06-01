---
id: atlas-ai-self-construction-durable-reservation-approval-decision-template
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Approval Decision Template
status: active
category: architecture
priority: 100
summary: Decision template for approving or rejecting durable reservation implementation without confusing template generation with approval.
tags:
  - atlas-ai
  - self-construction
  - approval-decision
  - durable-reservation
capabilities:
  - self_construction_durable_reservation_approval_decision_template
  - approval_gate
  - durable_reservation_decision_receipt
decisions:
  - Approval decisions must be explicit, signed and hash-bound to the request, AP candidate and plan.
  - A decision template is not an approval and must keep execution, migration, storage and dispatch disabled.
  - Approval may authorize implementation planning only; dispatch requires a separate future AP.
maintenance:
  - Update when approval states, signer rules or durable reservation post-approval limits change.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-durable-reservation-approval-decision-template

graph_title: Atlas Self-Construction Durable Reservation Approval Decision Template

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Durable Reservation Approval Decision Template
canonical_name: Atlas Self-Construction Durable Reservation Approval Decision Template
technical_name: atlas-ai-self-construction-durable-reservation-approval-decision-template
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md

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
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md
evidence_refs:
  - symbol: AtlasDurableReservationApprovalDecisionTemplateService
  - command: atlas:aaeos:durable-reservation-approval-decision-template
  - test: AtlasDurableReservationApprovalDecisionTemplateTest

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
# Atlas Self-Construction Durable Reservation Approval Decision Template

This template defines how a future operator records approval or rejection for
durable reservation implementation.

## Decision Values

- `approved_for_scoped_implementation`: implementation may proceed only inside
  the approved AP scope.
- `rejected`: implementation remains blocked.
- `needs_revision`: the AP candidate or approval request must be updated.
- `expired`: hashes or evidence changed after review.

## Required Bindings

Every decision must bind to:

- approval request hash;
- AP candidate hash;
- durable ledger plan hash;
- multi-session readiness gate hash;
- signer identities;
- approved scopes;
- forbidden scopes;
- rollback strategy.

## Explicit Non Approval

This template does not approve anything by itself. A valid decision requires
human/operator signer data and an accepted decision value.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only decision
template with states, signer slots, scope limits, rollback, expiry checks and
non-execution guarantees.

## Resumo

Decision template for approving or rejecting durable reservation implementation without confusing template generation with approval.

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
