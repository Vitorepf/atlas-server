---
id: atlas-ai-self-construction-multi-provider-agent-orchestration-contract
type: engineering_knowledge
title: Atlas Self-Construction Multi-Provider Agent Orchestration Contract
status: active
category: architecture
priority: 100
summary: Contract for coordinating Codex, Claude, Gemini, local agents and future AIs through one universal implementation packet.
tags:
  - atlas-ai
  - self-construction
  - multi-provider
  - agent-orchestration
capabilities:
  - self_construction_os
  - multi_provider_orchestration
  - governed_implementation
decisions:
  - "Five Codex" is an operator shorthand; the architecture target is multiple providers consuming the same contract.
  - Multi-provider work should coordinate through Obras Shared Workspace; provider chats are adapters, not shared state.
  - Atlas owns packet truth, scope, gates, evidence and completion; providers are replaceable executors.
  - Provider adapters may translate instructions but must not widen authority.
  - Evidence must be normalized before Atlas accepts completion from any provider.
maintenance:
  - Update before adding provider-specific start packets, automated dispatch, provider routing for implementation or evidence normalization runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
  - docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-multi-provider-agent-orchestration-contract

graph_title: Atlas Self-Construction Multi-Provider Agent Orchestration Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md

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
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md

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
# Atlas Self-Construction Multi-Provider Agent Orchestration Contract

Atlas must be able to coordinate multiple AI implementers at the same time:
Codex, Claude, Gemini, local agents and future providers.

The goal is not vendor parallelism. The goal is governed construction where any
capable AI can receive one packet, implement safely, produce evidence and let
Atlas validate the result.

## Core Thesis

```text
Atlas is the orchestrator.
Packets are the source of truth.
Providers are replaceable executors.
Evidence is normalized before completion.
```

Codex-specific surfaces are current operational adapters. They do not define the
architecture boundary.

## Shared Workspace Boundary

The canonical shared office for multi-provider construction is Obras Shared
Workspace. For Programming and Atlas Forge, call the specialization Forge
Workspace.

Providers should not pass long context to each other as the source of truth.
They consume provider-specific packets and return normalized artifacts to the
workspace. Atlas validates the workspace artifacts, not chat momentum.

## Universal Agent Contract

Every provider must receive:

- packet id;
- objective;
- rationale;
- allowed files;
- forbidden files and hot scopes;
- dependencies;
- required first commands;
- required gates;
- expected evidence;
- stop conditions;
- final response contract;
- completion or release command when claims are durable.

No provider may infer extra authority from model capability, context length,
tool access or confidence.

## Provider Profiles

| Profile | Best use | Routing signal |
|---|---|---|
| `codex` | code edits, tests, repo navigation, local validation | implementation-heavy packet |
| `claude` | architecture critique, policy/docs review, long-form consistency | review or documentation packet |
| `gemini` | long context, multimodal checks, alternative synthesis | broad context or visual/source packet |
| `local_agent` | deterministic scripts, lint, static checks, formatting | mechanical validation packet |
| `generic` | conservative docs/tests packet | fallback when capability is unknown |

Profiles guide assignment. They do not change the packet.

## Adapter Rule

Each provider adapter may:

- rephrase the operator prompt;
- include provider-specific tool instructions;
- compress context for the provider;
- choose a safer subset of the packet.

It must not:

- widen `allowed_files`;
- remove `forbidden_files`;
- skip required gates;
- hide stop conditions;
- mark completion;
- approve, merge or dispatch;
- alter packet hash.

## Evidence Normalization

Every provider final response must be normalized to:

```json
{
  "packet_id": "AIP-SPLIT-...",
  "provider": "codex|claude|gemini|local_agent|generic",
  "files_changed": [],
  "commands_run": [],
  "gates": [],
  "evidence_hash": "sha256|null",
  "scope_deviations": [],
  "residual_risks": [],
  "completion_claim": "complete|partial|blocked"
}
```

Atlas should reject completion if the response cannot be normalized.

## Task Lifecycle Surface

Open Brain MCP task tools are lifecycle surfaces, not provider dispatchers. They
may create a task and append `atlas.task_orchestration.event.v1` events for
`started`, `milestone` and `completed`; they must fail closed when the task event
table is unavailable. These events are audit breadcrumbs for orchestration state.
Events must be hash-chained with sequence, previous event id/hash and current
event hash so orchestration progress has replayable lineage. They do not
authorize provider execution, runtime execution, merge, approval, dispatch,
policy mutation or memory mutation.

## Splitter Behavior

Work Splitter must prefer:

1. disjoint write sets;
2. dependency-free packets;
3. clear provider profile fit;
4. small completion scope;
5. evidence that can be independently verified.

It must emit fewer packets rather than assign ambiguous work.

## Readiness Levels

| Level | Meaning |
|---|---|
| L1 single provider | one AI can consume one packet safely |
| L2 multi-session same provider | multiple Codex-like sessions can claim disjoint packets |
| L3 multi-provider manual | Codex, Claude, Gemini or local agents can receive adapter packets manually |
| L4 multi-provider governed | Atlas chooses provider profile and normalizes evidence |
| L5 auto-orchestrated | Atlas dispatches provider work only after signed authority, reservations and gates |

Current work targets L3/L4 documentation and read-only runtime surfaces. L5 is
future and must remain blocked without explicit receipts.

## Hard Stops

Stop immediately if:

- provider asks to broaden scope;
- provider edits a forbidden file;
- provider cannot run or report required gates;
- evidence is free-form only;
- two providers touch the same write set;
- a provider claims approval, merge or dispatch authority.

## Human Meaning

The user should be able to say:

```text
continue a implementação
```

to several different AIs. Each AI should receive a different governed packet,
work safely, and return evidence in a shape Atlas can validate.

That is the bridge from multi-Codex parallelism to Atlas Self-Programming OS.

## Resumo

Contract for coordinating Codex, Claude, Gemini, local agents and future AIs through one universal implementation packet.

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
