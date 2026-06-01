---
id: atlas-ai-self-construction-multi-session-readiness-gate-contract
type: engineering_knowledge
title: Atlas Self-Construction Multi-Session Readiness Gate Contract
status: active
category: architecture
priority: 100
summary: Contract for deciding whether multiple AI sessions may safely proceed from queue, collision and unlock previews.
tags:
  - atlas-ai
  - self-construction
  - multi-session
  - readiness-gate
capabilities:
  - self_construction_multi_session_readiness_gate_contract
  - parallel_ai
  - governance_gate
decisions:
  - Multi-session work requires an explicit readiness gate before dispatch.
  - Multi-session means multi-agent and multi-provider, not only multiple Codex sessions.
  - The gate may recommend preview-only continuation but must not start sessions.
  - Durable parallel execution remains blocked until claims, reservations and evidence persistence exist.
maintenance:
  - Update before adding durable multi-session dispatch, auto-claim or execution.
related_paths:
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md
  - docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-multi-session-readiness-gate-contract

graph_title: Atlas Self-Construction Multi-Session Readiness Gate Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Multi-Session Readiness Gate Contract
canonical_name: Atlas Self-Construction Multi-Session Readiness Gate Contract
technical_name: atlas-ai-self-construction-multi-session-readiness-gate-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md

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
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
evidence_refs:
  - symbol: AtlasMultiSessionReadinessGateContractService
  - command: atlas:aaeos:multi-session-readiness-gate-contract
  - test: AtlasMultiSessionReadinessGateContractTest

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
# Atlas Self-Construction Multi-Session Readiness Gate Contract

Multi-Session Readiness Gate answers whether the current packet system is ready
for multiple AI sessions across one or more providers.

## Purpose

It must consolidate:

- packet queue;
- parallel session plan;
- collision matrix;
- dependency unlock plan;
- reservation preview state;
- blocking reasons;
- safe next instruction.

## Non Goals

- Do not start sessions.
- Do not dispatch packets.
- Do not persist claims.
- Do not write reservation ledger rows.
- Do not promote completion.

## Decision Values

- `preview_only_single_session`: one session can continue in read-only/scoped mode.
- `parallel_preview_ready_but_not_durable`: at least two disjoint packet-scoped
  sessions can proceed in preview mode, but durable reservation/dispatch is not
  enabled yet.
- `blocked_for_multi_session`: multiple sessions are unsafe.
- `ready_for_multi_session_preview`: multiple preview slots are safe, still without execution.
- `ready_for_durable_dispatch`: future value requiring durable claims and reservation ledger.

`ready_for_multi_session_preview` is the expected state after the local
reservation ledger exists and five cold-lane packets are available. It means
five provider sessions can be manually started with packet-scoped bootstrap
commands and durable claims, while automated dispatch remains off. Those
sessions may be Codex, Claude, Gemini, local agents or future AIs, as long as
each consumes the universal packet contract.

## Provider-Neutral Readiness

The gate is provider-neutral. It must check:

- every selected packet has a universal implementation contract;
- provider-specific instructions are projections, not source of truth;
- evidence normalization is required before completion;
- no provider receives broader scope because of long context or strong model
  capability;
- no provider can approve, merge, dispatch or mark another provider complete.

## Hot Work Policy

Hot external Voice/Kernel work must remain withheld and visible. It is a
non-blocking warning for cold-lane parallel preview when the five cold-lane
packets are disjoint, and a hard blocker only if a selected packet attempts to
own or edit the hot scope.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only gate that
tells the operator whether multi-session continuation is safe and why.

## Resumo

Contract for deciding whether multiple AI sessions may safely proceed from queue, collision and unlock previews.

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
