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
  - Atlas owns packet truth, scope, gates, evidence and completion; providers are replaceable executors.
  - Provider adapters may translate instructions but must not widen authority.
  - Evidence must be normalized before Atlas accepts completion from any provider.
maintenance:
  - Update before adding provider-specific start packets, automated dispatch, provider routing for implementation or evidence normalization runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
  - docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 240
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
