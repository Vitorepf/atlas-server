---
id: atlas-ai-self-construction-durable-reservation-post-approval-preflight
type: engineering_knowledge
title: Atlas Self-Construction Durable Reservation Post-Approval Preflight
status: active
category: architecture
priority: 100
summary: Preflight contract for checking a signed durable reservation approval before any implementation, migration or storage write.
tags:
  - atlas-ai
  - self-construction
  - preflight
  - durable-reservation
capabilities:
  - self_construction_durable_reservation_post_approval_preflight
  - approval_gate
  - implementation_preflight
decisions:
  - A signed approval must pass preflight before any durable reservation implementation begins.
  - Preflight must verify hashes, signer slots, evidence, forbidden scopes and dispatch disablement.
  - Preflight generation is read-only and cannot create migrations, storage, claims or dispatch.
maintenance:
  - Update when post-approval checks, signer requirements or durable reservation evidence rules change.
related_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-approval-request.md
  - docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-durable-reservation-post-approval-preflight

graph_title: Atlas Self-Construction Durable Reservation Post-Approval Preflight

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Durable Reservation Post-Approval Preflight
canonical_name: Atlas Self-Construction Durable Reservation Post-Approval Preflight
technical_name: atlas-ai-self-construction-durable-reservation-post-approval-preflight
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md

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
  - docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md
evidence_refs:
  - symbol: AtlasDurableReservationPostApprovalPreflightService
  - command: atlas:aaeos:durable-reservation-post-approval-preflight
  - test: AtlasDurableReservationPostApprovalPreflightTest

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
# Atlas Self-Construction Durable Reservation Post-Approval Preflight

This contract defines the final read-only check that must pass after a future
approval decision is signed and before implementation begins.

## Required Checks

- approval decision is signed by all required roles;
- decision value is `approved_for_scoped_implementation`;
- approval request hash still matches;
- AP candidate hash still matches;
- durable ledger plan hash still matches;
- multi-session readiness gate hash still matches;
- docs-health and architecture-validate are passing;
- hot Voice/Kernel scopes are absent from allowed files;
- dispatch remains disabled.

## Blockers

Preflight blocks implementation when:

- any signer slot is missing;
- any hash drifted;
- any required evidence is absent;
- migration/storage scope is broader than approved;
- dispatch would be enabled;
- rollback strategy is missing.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only preflight
with checks, blockers, required evidence, implementation limits, rollback and
non-execution guarantees.

## Resumo

Preflight contract for checking a signed durable reservation approval before any implementation, migration or storage write.

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
