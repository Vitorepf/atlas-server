---
id: atlas-ai-self-construction-work-splitter-contract
type: engineering_knowledge
title: Atlas Self-Construction Work Splitter Contract
status: active
category: architecture
priority: 100
summary: Contract for splitting Atlas construction into safe disjoint packets for parallel AI sessions.
tags:
  - atlas-ai
  - self-construction
  - work-splitter
  - multi-agent
capabilities:
  - self_construction_os
  - work_splitter
  - parallel_implementation
decisions:
  - Parallel AI work is allowed only through disjoint write sets.
  - Parallel work is provider-neutral: Atlas may assign Codex, Claude, Gemini, local agents or future AIs by capability profile.
  - Hot external files block assignment, not validation.
  - Work Splitter must prefer fewer safe packets over many risky packets.
maintenance:
  - Update before changing packet assignment, reservation or parallel work policies.
related_paths:
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-work-splitter-contract

graph_title: Atlas Self-Construction Work Splitter Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md

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
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md

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
# Atlas Self-Construction Work Splitter Contract

Work Splitter converts Atlas construction backlog into packets that multiple AI
sessions can implement without colliding.

## Purpose

It must let the user run several sessions with a short command while Atlas keeps
ownership, order and scope safe.

```text
user -> continue a implementação
AI -> asks Atlas for packet
Atlas -> emits one safe disjoint packet
AI -> implements only that packet
```

## Non Goals

- Do not maximize concurrency at the cost of safety.
- Do not split work across the same file unless explicitly read-only.
- Do not assign hot Voice/Kernel/provider/daemon files to Self-Construction
  packets.
- Do not create autonomous merge authority.

## Splitter Input

```json
{
  "backlog": [],
  "current_git_status": [],
  "hot_scopes": [],
  "dependency_graph": [],
  "available_lanes": [],
  "max_packets": 5,
  "risk_policy": "conservative"
}
```

## Packet Output

```json
{
  "split_id": "SPLIT-YYYYMMDD-0001",
  "packets": [
    {
      "packet_id": "AIP-YYYYMMDD-0001",
      "lane": "self_construction",
      "objective": "string",
      "allowed_files": [],
      "forbidden_files": [],
      "depends_on": [],
      "collision_risk": "none | low | medium | high",
      "claim_policy": "single_owner",
      "status": "available"
    }
  ],
  "withheld_work": [],
  "blocking_reasons": []
}
```

## Lane Model

| Lane | Purpose | Default risk |
|---|---|---|
| `docs` | Documentation contracts, indexes, AP updates | low |
| `packet_contracts` | Packet, splitter, validator, runbook and completion contracts | low |
| `command_surface` | CLI option surface for read-only Self-Construction commands | low |
| `readiness_service` | Readiness service payloads and packet orchestration logic | low/medium |
| `tests` | Focused test additions for existing read-only surfaces | low |
| `runtime_read_only` | Commands/services that only inspect and report | medium |
| `runtime_scoped` | Narrow execution behind receipt and gates | high |
| `hot_external` | Files owned by another active front | blocked |

## Multi-Provider Cold-Lane Split

The operational split for parallel continuation must expose up to five
dependency-free cold-lane packets before any durable dispatch exists. "Five
Codex" is an operator shorthand; the real contract is five independent AI
executors, potentially from different providers.

1. `AIP-SPLIT-SELF-CONSTRUCTION-DOCS-0001`: root Self-Construction docs,
   constitution, structural gate and AP-691.
2. `AIP-SPLIT-SELF-CONSTRUCTION-PACKET-CONTRACTS-0002`: packet, splitter,
   validator, runbook, evidence and completion contracts.
3. `AIP-SPLIT-SELF-CONSTRUCTION-COMMAND-0003`: CLI command surface only.
4. `AIP-SPLIT-SELF-CONSTRUCTION-SERVICE-0004`: readiness service logic only.
5. `AIP-SPLIT-SELF-CONSTRUCTION-EVIDENCE-0005`: focused feature tests only.

These five packets are preview-assignable in parallel because their write sets
are disjoint. Hot Voice/Kernel work stays withheld and visible, but it does not
block cold-lane preview planning.

## Provider Capability Assignment

The splitter may recommend a provider profile per packet:

| Profile | Best fit | Must not do |
|---|---|---|
| `codex` | code edits, tests, repo navigation | widen scope or own review authority |
| `claude` | architecture critique, docs, consistency review | merge or override packet law |
| `gemini` | long-context synthesis, multimodal/context cross-checks | treat broad context as broad write authority |
| `local_agent` | deterministic checks, lint, scripts, formatting | infer product decisions |
| `generic` | safe docs/tests packet with explicit instructions | touch hot or ambiguous scopes |

Capability profile affects routing only. It never changes `allowed_files`,
`forbidden_files`, gates, evidence or completion policy.

## Disjoint Write-Set Rules

- Two packets may not write the same file.
- Two packets may not write parent/child ownership scopes that imply the same
  generated index unless the index update is assigned to one packet.
- Docs and tests may run in parallel only when their related_paths and test
  fixtures do not overlap.
- Runtime packets must own service, command and tests together unless the split
  explicitly assigns one side as read-only documentation.
- Hot external files must be listed in every packet as forbidden.

## Assignment Policy

The splitter chooses packets in this order:

```text
1. documentation contracts blocking future runtime;
2. schema and example completion;
3. read-only command and service surfaces;
4. focused tests for read-only surfaces;
5. scoped execution only after signatures and receipts.
```

## Claim Policy

A packet may be:

- `available`: no owner yet;
- `claimed`: one AI owns it for the current session;
- `blocked`: dependency, hot file or missing contract;
- `completed`: evidence submitted and validator clean;
- `stale`: status changed since packet hash was emitted.

If a packet is stale, the AI must stop and request a fresh packet.

## Collision Detection

Collision risk is `high` when:

- any write file is already modified by another lane;
- any write file is inside a hot scope;
- migrations, routes, providers, daemons or runtime entrypoints are involved;
- packet lacks a scope validator command.

Collision risk `high` blocks assignment.

## Failure Modes

- Too many packets: emit fewer, safer packets.
- Shared file needed: assign that file to exactly one packet.
- Hot scope detected: withhold the affected packet.
- Missing structural contract: emit documentation packet first.
- Unknown generated file: mark unknown and require human review.

## Completion Criteria

Work Splitter is ready when it can emit up to five non-overlapping packets, each
with objective, allowed files, forbidden files, dependencies, gates and evidence
requirements, while withholding unsafe or hot work.

## Resumo

Contract for splitting Atlas construction into safe disjoint packets for parallel AI sessions.

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
