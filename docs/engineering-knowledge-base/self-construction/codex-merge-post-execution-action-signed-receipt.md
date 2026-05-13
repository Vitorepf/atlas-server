---
id: atlas-ai-self-construction-codex-merge-post-execution-action-signed-receipt
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Post-Execution Action Signed Receipt
status: active
category: architecture
priority: 100
summary: Contract for read-only signed receipt templates after a post-execution merge action signature request.
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
  - Signed action receipt templates may define fields and validations, but must not accept signatures, validate signatures, persist receipts or merge.
  - Signed action receipt preflights may list persistence blockers, but must not accept evidence, persist receipts or merge.
  - Signed action receipt persistence templates may define append-only events, but must not write the ledger or persist receipts.
maintenance:
  - Update before adding any surface that persists a signed post-execution action receipt.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-receipts.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 200
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-codex-merge-post-execution-action-signed-receipt

graph_title: Atlas Self-Construction Codex Merge Post-Execution Action Signed Receipt

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo

repo_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-signed-receipt.md

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
  - docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-signed-receipt.md

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
# Atlas Self-Construction Codex Merge Post-Execution Action Signed Receipt

This contract governs the read-only signed receipt template for a future
post-execution Codex merge action.

The command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-signed-receipt-template --json
```

The preflight command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-signed-receipt-preflight --json
```

The persistence template command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-post-execution-action-signed-receipt-persistence-template --json
```

## Boundary

The template may describe future signed receipt fields and validations, but must
keep:

- `execution_allowed=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `signature_valid=false`;
- `receipt_persisted=false`.

It must not:

- accept signatures;
- validate signatures;
- record decisions;
- persist receipts;
- approve code;
- merge;
- dispatch work.

## Required Sources

The signed action receipt template must be bound to:

- action post-signature runbook hash;
- action signature request hash;
- action signable payload hash;
- action receipt hash.

## Future External Evidence

A future persisting surface must provide:

- final merge action signature value;
- signature validator identity;
- signature validation timestamp;
- validated action signable payload hash;
- validated action receipt hash;
- validated selected decision;
- validated authority inputs;
- validated action validations;
- signed action receipt persistence event hash.

## Future Persisted Fields

A future signed action receipt must persist:

- signed action receipt id;
- source action receipt hash;
- source action signable payload hash;
- source post-signature runbook hash;
- signature hash;
- signature validator identity;
- signature validation timestamp;
- selected decision and rationale;
- merge candidate hash;
- persisted execution receipt hash;
- post-execution gate report hash;
- human post-execution confirmation hash;
- merge operator identity.

## Release Conditions

A later merge surface may only proceed after:

- signed action receipt was persisted append-only;
- signed action receipt hash was verified;
- the merge surface consumes only that signed action receipt;
- last-minute diff and hot-scope checks pass;
- final merge evidence can be emitted.

## Persistence Preflight

The persistence preflight checks whether a future signed action receipt
persistence surface may be approached.

It must remain read-only and must block on:

- missing external final merge action signature value;
- missing signature validator identity;
- missing signature validation timestamp;
- missing validated action signable payload hash;
- missing validated action receipt hash;
- missing selected decision;
- missing authority input validation;
- missing action validation proof;
- missing signed receipt persistence event hash;
- selected decision not equal to merge;
- action receipt hash mismatch;
- action signable payload hash mismatch;
- hot-scope drift since action signature request;
- unreviewed diff since action signature request.

It must still forbid signature acceptance, signature validation, receipt
persistence, decision recording, approval, merge and dispatch.

## Persistence Template

The persistence template defines the future append-only event without writing it.

The future event type is:

- `CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED`.

The future event must include:

- event id and event type;
- signed action receipt id and hash;
- source signed receipt preflight hash;
- source signed receipt template hash;
- source action signature request hash;
- source action signable payload hash;
- source action receipt hash;
- signature hash and validator identity;
- selected decision;
- merge candidate hash;
- persisted execution receipt hash;
- human post-execution confirmation hash;
- persistence timestamp.

The template must still forbid ledger writes, signature acceptance, signature
validation, receipt persistence, decision recording, approval, merge and
dispatch.

## Principle

This template is still not the signed receipt. It is only the contract for a
future, separate, explicitly governed persistence surface.

## Resumo

Contract for read-only signed receipt templates after a post-execution merge action signature request.

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
