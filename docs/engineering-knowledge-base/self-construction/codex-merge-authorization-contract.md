---
id: atlas-ai-self-construction-codex-merge-authorization-contract
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Authorization Contract
status: active
category: architecture
priority: 100
summary: Contract for non-authorizing merge execution checklist, authorization receipt drafts and final authorization preflight.
tags:
  - atlas-ai
  - self-construction
  - codex-review
  - merge-governance
capabilities:
  - self_construction_codex_merge_authorization_contract
  - review_governance
  - merge_authorization
decisions:
  - Merge execution checklists may bind final prerequisites, but must not become the authorizing action.
  - Merge authorization templates may define authorizing fields, but must not accept signatures or approve.
  - Merge authorization receipt drafts may bind authorization fields, but must stay unsigned and inert.
  - Merge authorization signature requests may prepare a signable payload, but must not accept or validate signatures.
  - Merge authorization post-signature runbooks may sequence future work, but must not validate signatures or approve.
  - Merge final authorization preflight may bind evidence requirements, but must not validate signatures or authorize merge.
  - The required default is request_changes; hash mismatch, evidence failure or hot-scope changes must abort.
maintenance:
  - Update before adding any command that validates signatures, records merge decisions or changes repository state.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-action-draft-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorizing-action-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-review-chain-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 300
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-codex-merge-authorization-contract

graph_title: Atlas Self-Construction Codex Merge Authorization Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Codex Merge Authorization Contract
canonical_name: Atlas Self-Construction Codex Merge Authorization Contract
technical_name: atlas-ai-self-construction-codex-merge-authorization-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md

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
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md
evidence_refs:
  - symbol: AtlasCodexMergeAuthorizationContractService
  - command: atlas:aaeos:codex-merge-authorization-contract
  - test: AtlasCodexMergeAuthorizationContractTest

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
# Atlas Self-Construction Codex Merge Authorization Contract

This contract starts after the post-signature merge runbook. It defines the
final pre-authorizing surfaces only. None of these commands may validate a
signature, approve, record a decision, dispatch work or merge.

## Merge Execution Checklist

`--codex-review-merge-execution-checklist` binds the final prerequisites a
future authorizing surface must satisfy:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-execution-checklist --json
```

It may become ready only after the post-signature runbook is ready.

The checklist must require:

- external authorization;
- valid external signature evidence;
- selected decision equal to merge;
- fresh gate outputs;
- scope and evidence integrity;
- hot Voice/Kernel exclusion;
- rollback plan;
- human final confirmation.

The future authorizing surface must record:

- signature reference;
- selected decision and rationale;
- gate output hashes;
- scope and evidence hashes;
- merge operator and timestamp;
- rollback and post-merge verification hashes.

The checklist must keep `signature_valid=false`, `approval_granted=false` and
`merge_allowed=false`. It is a final pre-action contract, not the action.

## Merge Authorization Template

`--codex-review-merge-authorization-template` defines the fields a future
authorizing surface must collect:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-authorization-template --json
```

It may become ready only after the merge execution checklist is ready.

The template must include:

- source checklist, runbook, signature-request and signable-payload hashes;
- allowed decisions;
- conservative default decision;
- required authorization fields;
- required preconditions;
- rejection defaults;
- what the future authorizing surface must record.

The template may describe a future surface that validates external signature,
records decision, grants approval and merges, but this template itself must keep
`signature_valid=false`, `approval_granted=false` and `merge_allowed=false`.

The required default is `request_changes`; hash mismatch, evidence failure or
hot-scope changes must default to `abort`.

## Merge Authorization Receipt Draft

`--codex-review-merge-authorization-receipt-draft` binds the authorization
template into an unsigned receipt draft:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-authorization-receipt-draft --json
```

It may become ready only after the authorization template is ready.

The receipt draft must bind:

- authorization template hash;
- execution checklist hash;
- post-signature runbook hash;
- signature request hash;
- signable payload hash;
- required signers;
- required authorization fields;
- required preconditions;
- rejection defaults.

It must keep:

- `signature_present=false`;
- `signature_valid=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`.

The receipt draft is an audit shell for a future governed action. It must not:

- accept a signature;
- validate a signature;
- record a decision;
- approve;
- merge;
- dispatch work.

## Merge Authorization Signature Request

`--codex-review-merge-authorization-signature-request` prepares the signable
payload for the unsigned authorization receipt draft:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-authorization-signature-request --json
```

It may become pending only after the authorization receipt draft is ready.

The signable payload must include:

- authorization receipt id and hash;
- authorization template hash;
- execution checklist hash;
- post-signature runbook hash;
- prior signature request hash;
- prior signable payload hash;
- requested signature type;
- allowed decisions and default decision;
- required authorization fields;
- required preconditions;
- required receipt signers;
- fields that must be recorded by the future authorizing surface;
- rejection defaults.

It must keep:

- `signature_present=false`;
- `signature_valid=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`.

The signature request is not a signature. It must not accept, infer, validate or
apply a signature, and it must never authorize merge by itself.

## Merge Authorization Post-Signature Runbook

`--codex-review-merge-authorization-post-signature-runbook` defines the checklist
for a future moment after external authorization signature evidence exists:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-authorization-post-signature-runbook --json
```

It may become ready only after the authorization signature request is pending.

The runbook must require:

- external authorization signature value;
- validator identity and timestamp;
- validated authorization signable payload hash;
- validated authorization receipt hash;
- selected decision and rationale;
- fresh test, docs-health, architecture and diff-check output;
- scope and evidence integrity statements;
- rollback plan;
- human final merge confirmation.

It must sequence, but not execute:

- external authorization signature verification;
- source hash matching;
- merge preflight rerun;
- fresh gates;
- hot Voice/Kernel exclusion checks;
- preparation of a separate authorizing merge surface.

It must keep `signature_valid=false`, `decision_recorded=false`,
`approval_granted=false` and `merge_allowed=false`.

## Merge Final Authorization Preflight

`--codex-review-merge-final-authorization-preflight` evaluates the final
non-authorizing evidence contract before any future merge executor:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-final-authorization-preflight --json
```

It may become ready only after the authorization post-signature runbook is
ready.

The preflight must require:

- validated external authorization signature evidence;
- validated authorization signable payload and receipt hashes;
- explicit selected decision equal to `merge`;
- decision rationale;
- fresh merge preflight payload and hash;
- fresh test, docs-health, architecture and diff-check output;
- scope integrity and hot scope exclusion statements;
- completed packet evidence integrity statement;
- rollback plan;
- human final merge confirmation.

It must verify that the future authorizing surface will be separate, will
validate the signature again, will persist an append-only authorization receipt,
will reference fresh gates, will require final human confirmation and will emit
a final merge receipt before any execution.

It must keep:

- `authorization_ready=false`;
- `signature_present=false`;
- `signature_valid=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`.

The final authorization preflight is still not the authorizing action. It must
not accept signatures, validate signatures, record decisions, approve, merge or
dispatch work.

## Future Boundary

The next surface after this contract is governed by
`codex-merge-authorizing-action-contract.md`. It may only become an authorizing
surface if it is explicitly designed as a separate governed action with external
signature validation, fresh gates, rollback plan and human confirmation. This
contract is not that surface.

## Resumo

Contract for non-authorizing merge execution checklist, authorization receipt drafts and final authorization preflight.

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
