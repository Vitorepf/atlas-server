---
id: atlas-ai-self-construction-codex-merge-post-execution-action-receipts
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Post-Execution Action Receipts
status: active
category: architecture
priority: 100
summary: Contract for read-only post-execution merge action receipt drafts and signature requests.
tags:
  - atlas-ai
  - self-construction
  - codex-review
  - merge-governance
capabilities:
  - self_construction_os
  - review_governance
  - merge_authorization
decisions:
  - Post-execution merge action receipt drafts may bind hashes and decisions, but must remain unsigned and non-authorizing.
  - Post-execution merge action signature requests may prepare signable payloads, but must not accept, validate, persist or authorize signatures.
  - Post-execution merge action post-signature runbooks may sequence external evidence checks, but must not accept, validate, persist or authorize signatures.
maintenance:
  - Update before adding any surface that accepts or validates a post-execution merge action signature.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-executor-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 200
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-codex-merge-post-execution-action-receipts

graph_title: Atlas Self-Construction Codex Merge Post-Execution Action Receipts

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-receipts.md

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
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-receipts.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - module
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
# Atlas Self-Construction Codex Merge Post-Execution Action Receipts

This contract governs read-only receipt and signature-request surfaces for a
future Codex merge action after execution evidence exists.

The action receipt draft command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-receipt-draft --json
```

The action signature request command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-signature-request --json
```

The action post-signature runbook command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-post-signature-runbook --json
```

## Boundary

These surfaces may prepare a receipt and a signable payload, but must keep:

- `execution_allowed=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `receipt_signed=false`;
- `signature_valid=false`;
- `receipt_persisted=false`.

They must not:

- accept signatures;
- validate signatures;
- approve code;
- persist receipts;
- merge;
- dispatch work.

## Action Receipt Draft

The action receipt draft binds the future merge action to hashes and decision
fields while remaining unsigned.

It may become ready only after the post-execution action template is ready.

It must default to:

- `do_not_merge`.

It must require signature before any future merge can proceed.

It must bind:

- post-execution action template hash;
- post-execution preflight hash;
- execution receipt template hash;
- executor contract template hash;
- final receipt hash;
- selected decision;
- merge candidate hash;
- persisted execution receipt hash;
- human post-execution confirmation hash.

It must require decision fields for:

- selected decision;
- decision rationale;
- merge candidate hash;
- persisted execution receipt hash;
- post-execution gate report hash;
- human post-execution confirmation hash;
- merge operator identity.

It must still forbid:

- signature acceptance;
- signature validation;
- approval;
- merge;
- receipt persistence;
- dispatch.

## Action Signature Request

The action signature request prepares the signable payload for the future
post-execution merge action receipt.

It may become pending only after the action receipt draft is ready.

It must include:

- action receipt hash;
- post-execution action template hash;
- post-execution preflight hash;
- execution receipt template hash;
- executor contract template hash;
- final receipt hash;
- required authority inputs;
- required action validations;
- required decision fields.

It must require external fields:

- final merge action signature value;
- signature validator identity;
- signature validation timestamp;
- append-only signed action receipt persistence proof.

It must still forbid:

- signature acceptance;
- signature validation;
- approval;
- merge;
- receipt persistence;
- dispatch.

## Action Post-Signature Runbook

The action post-signature runbook sequences the evidence checks a future
validator must perform after external signature evidence exists.

It may become ready only after the action signature request is pending.

It must require external evidence for:

- final merge action signature value;
- signature validator identity;
- signature validation timestamp;
- signed action receipt persistence event hash.

It must sequence:

- collect external signature evidence;
- verify signature request hash matches signable payload;
- verify action receipt hash matches signed payload;
- verify required authority inputs are present;
- verify required action validations are present;
- prepare signed action receipt persistence candidate;
- stop before signature acceptance or merge.

It must still forbid:

- signature acceptance;
- signature validation;
- decision recording;
- approval;
- merge;
- receipt persistence;
- dispatch.

## Principle

The signature-request surface is only a payload builder. A later, separate
surface must validate external signature evidence and persist any signed receipt.

## Resumo

Contract for read-only post-execution merge action receipt drafts and signature requests.

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
