---
id: atlas-ai-kernel-contracts
type: engineering_knowledge
title: Atlas AI Kernel Contracts
status: active
category: architecture
priority: 100
summary: Focused contract map for Kernel executable objects: Envelope, Receipt, Ledger, SDKs, Policy, SLO, cost, identity and tenancy.
tags:
  - atlas-ai
  - kernel
  - contracts
capabilities:
  - operation_envelope_contract
  - decision_receipt_v2_contract
  - evidence_ledger_event_sourcing
  - surface_adapter_contract
  - provider_driver_contract
decisions:
  - Typed contracts beat prose in executable behavior.
  - Preview and dry-run use the same code path as execution with dry_run=true.
  - Operational tables are projections; Evidence Ledger is the replay source.
maintenance:
  - Add schema changes as additive versions.
  - Link concrete implementations from related_paths when promoted.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-kernel-contracts

graph_title: Atlas AI Kernel Contracts

graph_world: atlas

graph_layer: system

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Kernel Contracts
canonical_name: Atlas AI Kernel Contracts
technical_name: atlas-ai-kernel-contracts
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/kernel/contracts.md

owner: kernel

repo_paths:
  - docs/engineering-knowledge-base/kernel/contracts.md

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
  - kernel

evidence:
  - docs/engineering-knowledge-base/kernel/contracts.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - contract
  - kernel

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
# Kernel Contracts

## Core Objects

| Contract | Must contain |
|---|---|
| Operation Envelope | envelope id, operator/tenant, provenance, input refs, state, decision, execution, output, audit hash |
| Decision Receipt v2 | receipt id, schema, selected domain/flow/provider/model, budget, gates, repair policy, dry_run, signed_by, hashes |
| Ledger Event | event id, envelope id, type, payload hash, occurred_at, actor, schema version |
| Capability Manifest | capability id, owner, surfaces, gates, tests, authority group |
| Domain Manifest | domain id, flows, orchestrator, profiles, gates, memory projection |
| Surface Adapter | normalize input, declare capabilities, call Kernel, render output |
| Provider Driver | prepare call, inject identity, execute, normalize response, report telemetry |

## State Machines

Operation states: created, routed, decided, executing, gated, repairing,
completed, failed, blocked.

Decision receipt states: issued, attached, consumed, expired, superseded,
replayed.

Tool run states: planned, approved, invoked, returned, normalized, evidenced,
waived, failed.

## Policy/Profile

Policy/Profile compiles permissions, privacy, autonomy, cost, provider allowlist,
tool tier, repair limits, quality gates and redaction rules. No lower layer can
weaken hard Kernel invariants.

## Identity and Tenancy

Atlas identity persists across providers. OperatorId/TenantId are present from
day one so single-operator and multi-tenant deployments use the same shape.

## SLO and Cost

SLO targets are contracts, not dashboards. Cost is tracked per receipt and per
outcome so Atlas Decide can optimize quality per unit of compute.

## Resumo

Focused contract map for Kernel executable objects: Envelope, Receipt, Ledger, SDKs, Policy, SLO, cost, identity and tenancy.

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
