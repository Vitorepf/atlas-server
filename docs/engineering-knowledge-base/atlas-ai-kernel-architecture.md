---
id: atlas-ai-kernel-architecture
type: engineering_knowledge
title: Atlas AI Kernel Architecture
status: active
category: architecture
priority: 100
summary: Compact authority index for the executable Atlas AI Kernel: Operation Envelope, Decision Receipt, Evidence Ledger, SDK contracts, failure domains, SLOs and architectural tests.
tags:
  - atlas-ai
  - kernel
  - architecture
  - typed-contracts
  - event-sourcing
capabilities:
  - kernel_specification
  - operation_envelope_contract
  - decision_receipt_v2_contract
  - evidence_ledger_event_sourcing
  - capability_registry_enforcement
  - domain_manifest_sdk
  - surface_adapter_contract
  - provider_driver_contract
  - architectural_test_doctrine
  - kernel_slo_governance
decisions:
  - Kernel is the executable architecture layer; product docs cannot bypass it.
  - Decision Receipt, Evidence Ledger, Policy/Profile and Provider/Surface/Domain contracts are mandatory for real execution.
  - The full original specification is archived as source material; focused child docs own active implementation detail.
maintenance:
  - Keep this file compact; add details to kernel/* child docs.
  - Run architecture validation after changing Kernel contracts.
  - Update child docs and canonical index when contracts move or mature.
related_paths:
  - docs/engineering-knowledge-base/kernel/contracts.md
  - docs/engineering-knowledge-base/kernel/static-scans.md
  - docs/engineering-knowledge-base/kernel/roadmap-ap-index.md
  - docs/engineering-knowledge-base/kernel/failure-domain-taxonomy.md
  - docs/engineering-knowledge-base/archive/source-material/kernel/atlas-ai-kernel-architecture-full-2026-05-08.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-kernel-architecture

graph_title: Atlas AI Kernel Architecture

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Kernel Architecture

This is the compact executable authority for Atlas AI Kernel. The long mother
spec is preserved at
`archive/source-material/kernel/atlas-ai-kernel-architecture-full-2026-05-08.md`.

## Kernel Law

Every meaningful Atlas operation must pass through:

```text
Input -> Operation Envelope -> Intent/Routing -> Decide -> Decision Receipt
-> Domain/Profile/Flow -> Context -> Policy -> Runtime -> Gates -> Repair
-> Evidence Ledger -> Learning/Proposals -> Output
```

No Surface, Provider, Tool, Domain, Runtime, Curator or generated agent may
skip this chain for production behavior.

## Child Authorities

| Topic | Read |
|---|---|
| Envelope, Receipt, Ledger, SDKs, Policy, SLOs | `kernel/contracts.md` |
| Architecture tests and bypass prevention | `kernel/static-scans.md` |
| AP roadmap and implementation phases | `kernel/roadmap-ap-index.md` |
| Failure taxonomy and handler expectations | `kernel/failure-domain-taxonomy.md` |

## Hard Invariants

1. Surface does not decide.
2. Provider does not decide.
3. Tool does not decide.
4. Domain does not bypass Policy/Profile.
5. Runtime does not execute without Decision Receipt.
6. Repair returns through Policy, Receipt and Decide.
7. Everything important becomes Evidence.
8. Curator proposes critical changes; it does not silently self-apply them.
9. Repeated capability moves to Core.
10. Manual model/provider choice is an audited override.

## Contract Families

| Family | Purpose |
|---|---|
| Operation Envelope | canonical unit of work, trace, tenant/operator and attachments |
| Decision Receipt v2 | signed decision, provider/model, budget, gates, repair limits |
| Evidence Ledger | append-only operational truth and replay source |
| Capability Registry | prevents surface-only capabilities and duplicate ownership |
| Domain Manifest/SDK | lets domains plug in without new pipelines |
| Surface Adapter | collects input and renders output without deciding |
| Provider Driver | routes provider calls without owning Atlas identity |
| Failure Domain | closed taxonomy with handlers |
| SLO Targets | latency, quality, cost and reliability contract |

AP-73 runtime budget window contract centralizes budget windows in settings and
adds `atlas.runtime_budget.governance_contract.v1`: `autonomy_escalation_allowed=false`,
no auto budget raise, human review + Decision Receipt for limit changes, and
forbidden actions for bypassing budget blocks or changing provider policy from
budget signals.

AP-133 Architecture Operations Filter Contract uses canonical `id/kind/section/surface`
filters so CLI, API and MCP can query the same operations catalog without local
aliases or client-side string parsing.

## Validation

```bash
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge docs-health --json
php artisan atlas:ai:docs-split-plan --json
```

## Resumo

Compact authority index for the executable Atlas AI Kernel: Operation Envelope, Decision Receipt, Evidence Ledger, SDK contracts, failure domains, SLOs and architectural tests.

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
