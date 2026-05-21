---
id: atlas-ai-self-construction-single-session-instruction-packet-contract
type: engineering_knowledge
title: Atlas Self-Construction Single Session Instruction Packet Contract
status: active
category: architecture
priority: 100
summary: Contract for canonical instruction/start packets a single AI session consumes when continuing Self-Construction work.
tags:
  - atlas-ai
  - self-construction
  - single-session
  - ai-instruction
capabilities:
  - self_construction_single_session_instruction_packet_contract
  - ai_session_bootstrap
  - governance_gate
decisions:
  - When automated dispatch is blocked, Atlas must still guide one safe session.
  - The instruction packet must be self-contained and executable by an AI without chat history.
  - The instruction packet is provider-neutral even when the current operational start surface is Codex-specific.
  - `--codex-start-packet` may durably claim one packet and return a start contract.
  - `--codex-launch-plan` may generate multiple start commands, but each session must still claim through its own start packet.
  - The packet must not dispatch, auto-merge, enable execution authority or complete work.
maintenance:
  - Update before allowing automated dispatch, completion writes or execution authority.
related_paths:
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-session-readiness-gate-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-single-session-instruction-packet-contract

graph_title: Atlas Self-Construction Single Session Instruction Packet Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Single Session Instruction Packet Contract
canonical_name: Atlas Self-Construction Single Session Instruction Packet Contract
technical_name: atlas-ai-self-construction-single-session-instruction-packet-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md

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
  - docs/engineering-knowledge-base/self-construction/single-session-instruction-packet-contract.md

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
# Atlas Self-Construction Single Session Instruction Packet Contract

Single Session Instruction Packet is the canonical packet an AI session can
consume when broader automated dispatch is blocked.

Codex Start Packet is the stronger operational entry point for parallel Codex
work:

```bash
php artisan atlas:ai:self-construction --codex-start-packet --actor=codex-a --session=session-a --json
```

It durably claims the next available packet, then returns the scope, gates,
evidence expectations and final response contract for that session.

For Claude, Gemini, local agents or future providers, Atlas must emit an
equivalent provider adapter packet that preserves the same universal scope and
evidence contract.

## Purpose

It must include:

- selected packet id;
- one-line operator instruction;
- required first commands;
- allowed files;
- forbidden hot scopes;
- required gates;
- evidence expected;
- stop conditions;
- bootstrap and readiness hashes.
- durable claim state when using Codex Start Packet.

## Non Goals

- Do not dispatch work.
- Do not auto-start another process.
- Do not auto-merge.
- Do not grant execution authority beyond the user's active implementation
  request.
- Do not mark completion.
- Do not hide current blockers.

## Codex Start Contract

The start contract must include:

- claimed packet id;
- actor and session id;
- one-line user prompt;
- operator prompt for a Codex session with no chat history;
- mission;
- reservation id and lease expiry;
- claim hash;
- allowed files and hot forbidden scopes;
- explicit bootstrap command;
- explicit scope validator command;
- required first commands;
- required gates;
- evidence required in the final answer;
- implementation rules;
- final response contract;
- completion command;
- release command.

## Completion Criteria

This contract is complete when Atlas can emit either a deterministic read-only
instruction packet or a durable Codex Start Packet that lets one AI continue
safely without chat history, while dispatch, auto-merge and completion remain
separate governed steps.

## Resumo

Contract for canonical instruction/start packets a single AI session consumes when continuing Self-Construction work.

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
