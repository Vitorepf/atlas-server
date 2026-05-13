---
id: atlas-ai-self-construction-collision-matrix-contract
type: engineering_knowledge
title: Atlas Self-Construction Collision Matrix Contract
status: active
category: architecture
priority: 100
summary: Contract for detecting packet scope overlap before parallel AI sessions claim or execute work.
tags:
  - atlas-ai
  - self-construction
  - collision-matrix
  - parallel-ai
capabilities:
  - self_construction_os
  - packet_queue
  - scope_validation
decisions:
  - Parallel work is unsafe until packet write scopes are proven disjoint.
  - Collision checks must include allowed files, forbidden scopes and withheld hot work.
  - The current phase may report collisions but must not mutate packet state.
maintenance:
  - Update before adding durable claims, automated dispatch or write-scope locking.
related_paths:
  - docs/engineering-knowledge-base/self-construction/packet-queue-contract.md
  - docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-collision-matrix-contract

graph_title: Atlas Self-Construction Collision Matrix Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md

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
  - docs/engineering-knowledge-base/self-construction/collision-matrix-contract.md

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
# Atlas Self-Construction Collision Matrix Contract

Collision Matrix is the read-only proof that parallel packets do or do not
overlap.

## Purpose

It must show:

- pairwise packet comparisons;
- allowed-file overlap;
- dependency relation;
- hot external scope exposure;
- collision decision;
- safe parallel groups;
- matrix hash.

## Non Goals

- Do not claim packets.
- Do not reserve packets.
- Do not rewrite packet scopes.
- Do not dispatch work.
- Do not override hot forbidden scopes.

## Pair Schema

```json
{
  "left_packet_id": "AIP-SPLIT-...",
  "right_packet_id": "AIP-SPLIT-...",
  "overlap": [],
  "dependency_related": false,
  "hot_scope_present": false,
  "collision": false,
  "decision": "parallel_safe|blocked"
}
```

## Collision Rules

A pair is blocked when:

- allowed files overlap;
- either side is withheld hot work;
- dependency relation requires ordering;
- either packet allows Voice/Kernel hot scope.

## Completion Criteria

This contract is complete when Atlas emits a deterministic read-only matrix that
proves which packets may be parallelized without claims, dispatch or execution.

## Resumo

Contract for detecting packet scope overlap before parallel AI sessions claim or execute work.

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
