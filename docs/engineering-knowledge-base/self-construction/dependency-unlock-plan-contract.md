---
id: atlas-ai-self-construction-dependency-unlock-plan-contract
type: engineering_knowledge
title: Atlas Self-Construction Dependency Unlock Plan Contract
status: active
category: architecture
priority: 100
summary: Contract for showing which packet completions unlock later packets in the read-only queue.
tags:
  - atlas-ai
  - self-construction
  - dependency-unlock
  - packet-queue
capabilities:
  - self_construction_os
  - packet_queue
  - parallel_ai
decisions:
  - Parallel acceleration requires knowing which completed packet unlocks which next packet.
  - Unlock plans must remain read-only until durable completion and reservation exist.
  - Hot withheld work must not be unlocked by Self-Construction cold-lane packets.
maintenance:
  - Update before adding durable completion, automatic unlocks or queue state mutation.
related_paths:
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-completion-gate-contract.md
  - docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-dependency-unlock-plan-contract

graph_title: Atlas Self-Construction Dependency Unlock Plan Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md

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
  - docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md

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
# Atlas Self-Construction Dependency Unlock Plan Contract

Dependency Unlock Plan shows how completing one packet changes future work
availability, without mutating queue state.

## Purpose

It must show:

- blocked packets;
- dependencies for each blocked packet;
- which dependencies are already available;
- what would become assignable after completion;
- which work stays withheld;
- unlock plan hash.

## Non Goals

- Do not mark dependencies complete.
- Do not mutate queue state.
- Do not persist completion.
- Do not dispatch newly unlocked work.
- Do not unlock hot external work.

## Unlock Rule

A packet may become preview-assignable only when all dependencies have durable
completion evidence in a future phase. In the current phase, Atlas may only
preview the unlock relationship.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only unlock
plan with blocked packets, unlock candidates and withheld hot work.

## Resumo

Contract for showing which packet completions unlock later packets in the read-only queue.

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
