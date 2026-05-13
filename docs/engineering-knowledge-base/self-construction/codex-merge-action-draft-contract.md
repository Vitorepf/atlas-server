---
id: atlas-ai-self-construction-codex-merge-action-draft-contract
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Action Draft Contract
status: active
category: architecture
priority: 100
summary: Contract for non-executing Codex merge action and receipt drafts after review-chain preflight.
tags:
  - atlas-ai
  - self-construction
  - codex-review
  - merge-governance
capabilities:
  - self_construction_os
  - review_governance
  - merge_preflight
decisions:
  - Merge action drafts may prepare a future governed action, but must not execute it.
  - Merge receipt drafts may bind the action draft to an unsigned receipt, but must not authorize it.
  - Merge signature requests may prepare a signable payload, but must not present or validate a signature.
  - Merge post-signature runbooks may sequence future work, but must not validate signature or merge.
  - Missing external evidence must default to request changes.
  - Approval and merge require a separate explicit human or governed action.
maintenance:
  - Update before adding any command that records a merge decision or changes repository state.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-review-chain-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md
  - docs/engineering-knowledge-base/self-construction/parallel-session-plan-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 240
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-codex-merge-action-draft-contract

graph_title: Atlas Self-Construction Codex Merge Action Draft Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-action-draft-contract.md

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
  - docs/engineering-knowledge-base/self-construction/codex-merge-action-draft-contract.md

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
# Atlas Self-Construction Codex Merge Action Draft Contract

`--codex-review-merge-action-draft` is the read-only draft for a later explicit
governed merge action:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-action-draft --json
```

It may become ready only after `--codex-review-merge-preflight` is ready.

## Required Content

The draft must include:

- source preflight hash;
- source hashes from the review chain;
- allowed decisions;
- conservative default decision;
- required external evidence;
- required fields for the future action;
- guardrails for signature, scope, gates and evidence;
- prepared command sequence.

## Required Flags

The draft must always keep:

- `signature_valid=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `dispatch_allowed=false`;
- `execution_allowed=false`.

## Required Fields

The future explicit action must require:

- operator name;
- review timestamp;
- selected decision;
- decision rationale;
- signed receipt hash;
- signable payload hash;
- fresh gate outputs;
- scope integrity result;
- evidence integrity result;
- files to merge;
- human merge confirmation;
- rollback plan;
- remaining risks.

## Guardrails

The draft must encode:

- selected decision must be `merge`;
- signature must be validated by an external governed actor;
- all preflight checks must still pass;
- fresh gates must pass after signature;
- scope integrity must be clean;
- evidence integrity must be verified;
- hot Voice/Kernel scope must remain untouched.

## Non-Authority

The draft is not the action. It prepares the exact fields a human or governed
executor must fill later, and it defaults to `request_changes` so missing
evidence never becomes accidental approval.

It must not:

- validate signature;
- record decision;
- grant approval;
- merge;
- dispatch work.

## Merge Receipt Draft

`--codex-review-merge-receipt-draft` turns the action draft into an unsigned,
hash-bound receipt draft:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-receipt-draft --json
```

It may become ready only after the merge action draft is ready.

The receipt draft must bind:

- merge action draft hash;
- preflight hash;
- review-chain source hashes;
- required signer roles;
- required receipt inputs;
- approval preconditions;
- verification commands;
- non-authorizing invariants.

It must keep:

- `signature_required=true`;
- `signature_present=false`;
- `signature_valid=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`.

The receipt draft is not a signed receipt. It is the audit shell a later
external governed action may sign after validating evidence and gates.

## Merge Signature Request

`--codex-review-merge-signature-request` prepares a signable payload for the
merge receipt draft:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-signature-request --json
```

It may become pending only after the merge receipt draft is ready.

The signable payload must include:

- receipt id;
- receipt hash;
- merge action draft hash;
- preflight hash;
- review-chain source hashes;
- requested signature type;
- allowed decisions;
- default decision;
- signer roles;
- required receipt inputs;
- approval preconditions;
- verification commands;
- actions still forbidden after signature request.

It must keep:

- `signature_required=true`;
- `signature_present=false`;
- `signature_valid=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`.

The signature request is not a signature. It must not present, accept, infer or
validate a signature, and it must never authorize merge by itself.

## Merge Post-Signature Runbook

`--codex-review-merge-post-signature-runbook` defines the checklist for a
future moment after external signature evidence exists:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-signature-runbook --json
```

It may become ready only after the merge signature request is pending.

The runbook must require external inputs for:

- signature value;
- validator identity;
- validation timestamp;
- validated signable payload hash;
- validated receipt hash;
- selected decision and rationale;
- fresh test, docs-health, architecture and diff-check output;
- scope and evidence integrity statements;
- rollback plan;
- remaining risks.

It must sequence, but not execute:

- verifying external signature evidence;
- matching validated hashes to source hashes;
- rerunning merge preflight;
- rerunning fresh tests and quality gates;
- checking hot Voice/Kernel exclusions;
- preparing a separate explicit merge action.

It must keep `signature_valid=false`, `approval_granted=false` and
`merge_allowed=false`. The runbook is not an authorizer; it is the bridge from
future external signature evidence to a later explicit governed merge action.

The later execution checklist, authorization template, authorization receipt
draft, authorization signature request, authorization post-signature runbook and
final authorization preflight live in the dedicated merge authorization contract
so this pre-action contract remains focused.

## Resumo

Contract for non-executing Codex merge action and receipt drafts after review-chain preflight.

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
