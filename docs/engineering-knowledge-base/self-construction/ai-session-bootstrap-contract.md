---
id: atlas-ai-self-construction-ai-session-bootstrap-contract
type: engineering_knowledge
title: Atlas Self-Construction AI Session Bootstrap Contract
status: active
category: architecture
priority: 100
summary: Contract for the one-command bootstrap packet that lets an AI session resume Self-Construction safely.
tags:
  - atlas-ai
  - self-construction
  - ai-bootstrap
  - parallel-ai
capabilities:
  - self_construction_os
  - ai_implementation_packet
  - packet_assignment
decisions:
  - A new AI session must receive one canonical bootstrap payload before implementation.
  - Bootstrap is provider-neutral; Codex-specific start packets are current adapters, not the architecture limit.
  - Bootstrap may bundle packet, assignment, reservation preview, runbook and gates.
  - `--claim-next-packet` may durably reserve one packet and return bootstrap instructions.
  - Bootstrap and claims do not grant dispatch, auto-merge or autonomous execution authority.
maintenance:
  - Update before allowing automated dispatch, completion writes or execution authority from this payload.
related_paths:
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-consumption-runbook-contract.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-ai-session-bootstrap-contract

graph_title: Atlas Self-Construction AI Session Bootstrap Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md

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
  - docs/engineering-knowledge-base/self-construction/ai-session-bootstrap-contract.md

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
# Atlas Self-Construction AI Session Bootstrap Contract

AI Session Bootstrap is the packet an AI should read immediately after a human
says "continue implementation".

## Purpose

It gives one session:

- selected packet;
- assignment preview;
- reservation or claim state;
- ordered runbook;
- current scope validator result;
- evidence and completion gate status;
- first commands;
- stop conditions;
- forbidden hot scopes.

## Bootstrap Modes

Read-only bootstrap:

```bash
php artisan atlas:ai:self-construction --ai-session-bootstrap --packet=AIP-SPLIT-... --json
```

Durable claim-and-bootstrap:

```bash
php artisan atlas:ai:self-construction --claim-next-packet --actor=codex-a --session=session-a --json
```

The second form selects the next available packet, writes a durable local claim,
and returns packet-scoped first commands. It still does not dispatch work or
grant execution authority.

Canonical Codex start packet:

```bash
php artisan atlas:ai:self-construction --codex-start-packet --actor=codex-a --session=session-a --json
```

This is the preferred entry point for a fresh Codex session. It claims the next
available packet and emits a self-contained contract with operator prompt,
bootstrap command, scope validator command, gates, evidence, release command and
final response fields. It must also include a completion command so the owning
session can durably mark its packet complete after gates and evidence are ready.

Equivalent future adapters may emit Claude, Gemini, local-agent or generic
start packets. They must preserve the same packet id, scope, gates, stop
conditions and final response contract.

## Non Goals

- Do not dispatch a session automatically.
- Do not grant execution authority.
- Do not mark completion.
- Do not hide hot external blockers.

## Payload Schema

```json
{
  "bootstrap_id": "BOOTSTRAP-SELF-CONSTRUCTION-0001",
  "session_mode": "read_only_ai_bootstrap",
  "selected_packet_id": "AIP-SPLIT-...",
  "assignment_hash": "sha256",
  "reservation_hash": "sha256",
  "runbook_hash": "sha256",
  "scope_validator_status": "pass|blocked",
  "completion_gate_status": "blocked|human_review_required",
  "execution_allowed": false,
  "claim_persisted": false,
  "ledger_write_allowed": false
}
```

## Provider Adapter Fields

A bootstrap payload may include:

```json
{
  "provider_profile": "codex | claude | gemini | local_agent | generic",
  "provider_adapter": "codex_cli | claude_cli | gemini_cli | local_runner",
  "provider_strengths": [],
  "provider_specific_prompt": "string",
  "universal_packet_hash": "sha256",
  "adapter_may_widen_scope": false
}
```

`adapter_may_widen_scope` must always be false.

## Required First Commands

Every AI session must start by running:

```text
git status --short
git diff --stat
git diff --name-only
php artisan atlas:ai:self-construction --ai-session-bootstrap --json
php artisan atlas:ai:self-construction --scope-validator --json
```

For a durable claimed packet, the packet-scoped commands must include
`--packet=AIP-SPLIT-...`.

## Stop Conditions

The session must stop when:

- selected packet is missing;
- packet, split, assignment or reservation hash changes unexpectedly;
- scope validator is blocked by files owned by another lane;
- hot Voice/Kernel files appear in its claimed scope;
- required tests or docs gates fail;
- operator evidence is missing;
- completion gate is blocked.

## Completion Criteria

This contract is complete when Atlas emits either a read-only bootstrap payload
or a durable claim-next start packet that a new AI session can consume without
chat history and without obtaining dispatch or execution authority.

## Resumo

Contract for the one-command bootstrap packet that lets an AI session resume Self-Construction safely.

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
