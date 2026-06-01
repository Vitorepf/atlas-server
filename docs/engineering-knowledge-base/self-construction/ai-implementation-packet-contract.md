---
id: atlas-ai-self-construction-ai-implementation-packet-contract
type: engineering_knowledge
title: Atlas Self-Construction AI Implementation Packet Contract
status: active
category: architecture
priority: 100
summary: Contract for the packet that lets another AI continue Atlas implementation from one short instruction.
tags:
  - atlas-ai
  - self-construction
  - ai-implementation-packet
  - multi-agent
capabilities:
  - self_construction_ai_implementation_packet_contract
  - ai_implementation_packet
  - governed_implementation
decisions:
  - A packet is a bounded work contract, not a suggestion.
  - A packet must be executable by an AI that only receives "continue implementation".
  - The packet is provider-neutral: Codex, Claude, Gemini, local agents and future AIs must all be able to consume it through adapters.
  - A packet never grants authority beyond its allowed files, gates and evidence requirements.
maintenance:
  - Update before changing implementation-packet runtime, packet schema or multi-agent handoff behavior.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/structural-contract-gate.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-ai-implementation-packet-contract

graph_title: Atlas Self-Construction AI Implementation Packet Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction AI Implementation Packet Contract
canonical_name: Atlas Self-Construction AI Implementation Packet Contract
technical_name: atlas-ai-self-construction-ai-implementation-packet-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md

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
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
evidence_refs:
  - symbol: AtlasAiImplementationPacketContractService
  - command: atlas:aaeos:ai-implementation-packet-contract
  - test: AtlasAiImplementationPacketContractTest

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
# Atlas Self-Construction AI Implementation Packet Contract

AI Implementation Packet is the artifact Atlas emits so another AI can safely
continue Atlas construction from one short user instruction.

Target user instruction:

```text
continue a implementação
```

The AI must read the packet, implement only that packet, run the required gates
and return evidence.

This is not a Codex-only artifact. Codex is the first strong implementation
provider, but the packet is the universal contract for any capable AI executor.
Provider adapters may rephrase instructions, but they must not change scope,
allowed files, forbidden files, gates or evidence requirements.

## Purpose

The packet must answer:

- what to build;
- why this is the next correct block;
- which files may be changed;
- which files must not be touched;
- which gates prove the work;
- what evidence is required;
- when to stop and ask for review.

## Non Goals

- Do not authorize autonomous merge.
- Do not sign receipts by itself.
- Do not override AP law.
- Do not touch hot Voice, Kernel, provider, daemon or runtime scopes unless
  explicitly listed in the packet and receipt.
- Do not allow an AI to choose a different task because it seems more useful.

## Packet Schema

```json
{
  "schema_version": "1.0",
  "packet_id": "AIP-YYYYMMDD-0001",
  "operation_id": "OP-YYYYMMDD-0001",
  "status": "available | claimed | blocked | completed | stale",
  "lane": "self_construction | docs | tests | runtime_read_only | runtime_scoped",
  "objective": "string",
  "rationale": "string",
  "priority": 0,
  "risk_level": "low | medium | high | critical",
  "execution_allowed": false,
  "requires_human_signature": true,
  "context_docs": [],
  "allowed_files": [],
  "forbidden_files": [],
  "forbidden_actions": [],
  "acceptance_criteria": [],
  "required_gates": [],
  "required_evidence": [],
  "provider_contract": {
    "contract_type": "universal_agent_implementation_packet",
    "provider_profile": "codex | claude | gemini | local_agent | generic",
    "adapter_instructions": [],
    "normalized_final_response_required": true
  },
  "rollback_policy": "string",
  "dependencies": [],
  "stop_conditions": [],
  "scope_validator": {
    "required": true,
    "command": "php artisan atlas:ai:self-construction --scope-validator --json"
  },
  "packet_hash": "sha256"
}
```

## Hard Invariants

- `execution_allowed=false` until a governed receipt permits execution.
- `allowed_files` is the maximum write set.
- `forbidden_files` always wins over `allowed_files`.
- Every acceptance criterion must map to at least one evidence item.
- A packet must be small enough for one AI session to finish without owning
  unrelated architecture.
- One AI claims one packet at a time.
- A packet cannot assign work to files already marked hot by external work.
- Provider-specific strengths may influence assignment, never authority.
- A provider adapter may narrow execution instructions but may not widen scope.

## Required Context

Every packet must include these docs when relevant:

- Self-Construction OS;
- Constitution;
- Structural Contract Gate;
- the specific subsystem contract;
- AP governing the work;
- ownership boundary or hot-file report;
- runtime roadmap when code is involved.

## AI Consumption Protocol

When an AI receives only "continue a implementação", it must:

```text
1. Run git status/diff inspection.
2. Read Self-Construction OS and this packet.
3. Confirm packet status is available or claimed by this session.
4. Confirm allowed and forbidden files.
5. Implement only the objective.
6. Run required gates.
7. Run Scope Validator.
8. Produce evidence and residual risk.
9. Stop if any forbidden file changes.
```

## Provider-Neutral Contract

Every implementation packet must be understandable by:

- Codex-like coding agents;
- Claude-like architecture/review agents;
- Gemini-like long-context or multimodal agents;
- local deterministic agents;
- future providers with equivalent capability.

The universal packet owns the truth. Provider prompts are projections. If a
provider prompt conflicts with the packet, the packet wins.

## Normalized Final Response

Every AI must return:

- packet id;
- files changed;
- commands run;
- tests/gates result;
- evidence hash when available;
- scope deviations, if any;
- residual risks;
- next recommended packet, if discovered.

Free-form provider output is not enough for completion.

## Safe Packet Example

```json
{
  "packet_id": "AIP-20260510-0001",
  "lane": "self_construction",
  "objective": "Document Scope Validator contract and link it from AP-691.",
  "risk_level": "low",
  "execution_allowed": false,
  "allowed_files": [
    "docs/engineering-knowledge-base/self-construction/scope-validator-contract.md",
    "docs/ap/AP-691-atlas-self-construction-os-contract.md"
  ],
  "forbidden_files": [
    "runtimes/python/voice_realtime/**",
    "app/Services/Ai/Voice/**"
  ],
  "required_gates": [
    "docs-health",
    "architecture-validate",
    "git diff --check"
  ]
}
```

## Rejected Packet Example

Reject or block any packet that:

- has no `allowed_files`;
- permits broad directories such as `app/**` without sub-scope;
- omits gates;
- asks for implementation before its structural contract exists;
- overlaps hot files owned by another session;
- changes security, payment, provider, daemon, memory or runtime policy without
  explicit critical AP and receipt.

## Completion Criteria

A packet is complete only when:

- all acceptance criteria are satisfied;
- required gates passed or failures are explicitly external;
- Scope Validator reports no blocking violation;
- evidence is attached;
- residual risk is documented;
- no unrelated file was changed by the packet owner.

## Resumo

Contract for the packet that lets another AI continue Atlas implementation from one short instruction.

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
