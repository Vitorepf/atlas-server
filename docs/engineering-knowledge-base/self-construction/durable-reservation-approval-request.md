---
id: atlas-ai-self-construction-durable-reservation-approval-request
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Approval Request
status: active
category: architecture
priority: 100
summary: Approval request contract for promoting durable reservation work from AP candidate to explicitly authorized implementation.
tags:
  - atlas-ai
  - self-construction
  - approval
  - durable-reservation
capabilities:
  - self_construction_durable_reservation_approval_request
  - approval_gate
  - durable_claims
decisions:
  - Durable reservation implementation requires an explicit approval request before migrations or storage writes.
  - Approval must name signer roles, accepted risk, rollback, evidence and forbidden scopes.
  - Approval request generation remains read-only and cannot be treated as approval.
maintenance:
  - Update when signer roles, approval criteria or durable reservation promotion rules change.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-durable-reservation-approval-request

graph_title: Atlas Self-Construction Durable Reservation Approval Request

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Durable Reservation Approval Request
canonical_name: Atlas Self-Construction Durable Reservation Approval Request
technical_name: atlas-ai-self-construction-durable-reservation-approval-request
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md

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
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md
evidence_refs:
  - symbol: AtlasDurableReservationApprovalRequestService
  - command: atlas:aaeos:durable-reservation-approval-request
  - test: AtlasDurableReservationApprovalRequestTest

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
# Atlas Self-Construction Durable Reservation Approval Request

This contract defines the approval payload required before durable reservation
implementation can touch migrations, storage, repository code or claim state.

## Approval Is Not Execution

Generating this request does not approve work. It only creates a deterministic
packet for a human/operator to review.

## Required Signers

- product governor;
- architecture governor;
- safety/governance reviewer;
- implementation operator.

## Approval Must Decide

- whether migrations/storage are allowed;
- whether the AP candidate is accepted as scoped;
- whether dispatch remains disabled after ledger activation;
- whether rollback evidence is sufficient;
- whether hot Voice/Kernel scopes remain forbidden.

## Required Evidence

- AP candidate hash;
- durable ledger plan hash;
- multi-session readiness gate hash;
- docs-health output;
- architecture-validate output;
- rollback strategy;
- explicit forbidden scopes.

## Blocking Conditions

Approval must be blocked when:

- candidate hash changed after review;
- plan hash changed after review;
- docs or architecture validation fail;
- hot scopes appear in allowed files;
- dispatch would be enabled in the same AP;
- rollback is missing.

## Completion Criteria

This contract is complete when Atlas emits a read-only approval request with
signers, decisions, blockers, evidence, rollback and non-execution guarantees.

## Resumo

Approval request contract for promoting durable reservation work from AP candidate to explicitly authorized implementation.

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
