---
id: atlas-ai-self-construction-codex-merge-authorizing-action-contract
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Authorizing Action Contract
status: active
category: architecture
priority: 100
summary: Contract for the read-only template, final receipt draft, signature request and post-signature runbook for a future governed merge authorizing action.
tags:
  - atlas-ai
  - self-construction
  - codex-review
  - merge-governance
capabilities:
  - self_construction_codex_merge_authorizing_action_contract
  - review_governance
  - merge_authorization
decisions:
  - Merge authorizing action templates may define future inputs, but must not accept evidence or record decisions.
  - Final merge receipt drafts may bind receipt fields, but must remain unsigned and non-authorizing.
  - Final merge signature requests may prepare a signable payload, but must not accept or validate signatures.
  - Final post-signature runbooks may sequence future receipt signing, but must not sign receipts or execute merge.
  - The future authorizing action may emit a final merge receipt only after external signature validation and fresh gates.
  - The future authorizing action must not apply patches or merge directly.
maintenance:
  - Update before adding any command that accepts authorization evidence, records a decision, signs a receipt or executes merge.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-action-draft-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-signed-final-receipt-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-review-chain-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 300
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-codex-merge-authorizing-action-contract

graph_title: Atlas Self-Construction Codex Merge Authorizing Action Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Codex Merge Authorizing Action Contract
canonical_name: Atlas Self-Construction Codex Merge Authorizing Action Contract
technical_name: atlas-ai-self-construction-codex-merge-authorizing-action-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/codex-merge-authorizing-action-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorizing-action-contract.md

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
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorizing-action-contract.md
evidence_refs:
  - symbol: AtlasCodexMergeAuthorizingActionContractRtService
  - command: atlas:aaeos:codex-merge-authorizing-action-contract-rt
  - test: AtlasCodexMergeAuthorizingActionContractRtTest

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
# Atlas Self-Construction Codex Merge Authorizing Action Contract

This contract governs the read-only template for a future separate authorizing
action. It starts after final authorization preflight is ready.

The command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-authorizing-action-template --json
```

The final receipt draft command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-final-receipt-draft --json
```

The final signature request command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-final-signature-request --json
```

The final post-signature runbook command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-final-post-signature-runbook --json
```

## Read-Only Boundary

The template must keep:

- `authorization_ready=false`;
- `signature_present=false`;
- `signature_valid=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `execution_allowed=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`.

It must not:

- accept authorization evidence;
- validate a signature;
- infer approval from completed packets;
- record a decision;
- approve code;
- apply a patch;
- merge;
- dispatch work.

## Required Future Inputs

The future authorizing action must require:

- external authorization signature value;
- validator identity;
- validation timestamp;
- validated authorization signable payload hash;
- validated authorization receipt hash;
- selected decision;
- decision rationale;
- fresh merge preflight hash;
- fresh test output hash;
- fresh docs-health output hash;
- fresh architecture validation output hash;
- fresh diff-check output hash;
- scope integrity statement;
- hot-scope exclusion statement;
- completed packet evidence integrity statement;
- rollback plan;
- human final merge confirmation.

The default decision is always `request_changes`.

## Required Future Validations

The future authorizing action must validate:

- selected decision is exactly `merge`;
- signature matches the source authorization signable payload hash;
- authorization receipt hash matches the source chain;
- fresh merge preflight hash is bound;
- tests pass;
- docs-health passes;
- architecture validation passes;
- diff-check passes;
- scope excludes hot Voice/Kernel files;
- completed packet evidence matches completed packet hashes;
- rollback plan is present;
- human final merge confirmation is explicit.

Any missing or mismatched item must produce `request_changes` or `abort`, never
implicit approval.

## Future Receipt Fields

The future authorizing action must persist an append-only receipt containing:

- authorizing action id;
- source final authorization preflight hash;
- selected decision;
- decision rationale;
- validated signature hash;
- validated authorization receipt hash;
- fresh gate hashes;
- scope integrity result;
- evidence integrity result;
- rollback plan hash;
- human confirmation hash;
- authorizer identity;
- authorization timestamp.

## Execution Boundary

Even after successful authorization, the future authorizing action must not
apply patches or merge directly.

The correct chain is:

```text
authorizing action
→ append-only final merge receipt
→ separate merge executor
→ last-minute diff and scope checks
→ controlled merge action
```

This separation prevents one command from becoming judge, signer and executor.

## Final Merge Receipt Draft

`--codex-review-merge-final-receipt-draft` prepares an unsigned shell for the
future final merge receipt.

It may become ready only after the authorizing action template is ready.

The receipt draft must bind:

- authorizing action template hash;
- final authorization preflight hash;
- authorization signature request hash;
- authorization signable payload hash;
- allowed decisions;
- default decision;
- future authorization fields;
- requirements before signing;
- future executor contract.

It must keep:

- `receipt_signed=false`;
- `authorization_ready=false`;
- `signature_present=false`;
- `signature_valid=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `execution_allowed=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`.

The draft must not:

- accept a signature;
- validate a signature;
- record a merge decision;
- persist an authorization receipt;
- approve code;
- execute merge;
- dispatch an executor.

The future executor may only run after a separate signed final merge receipt
exists and has been verified.

## Final Merge Signature Request

`--codex-review-merge-final-signature-request` prepares the signable payload for
the final merge receipt draft.

It may become pending only after the final receipt draft is ready.

The signable payload must include:

- signature request id;
- final receipt id and hash;
- authorizing action template hash;
- final authorization preflight hash;
- authorization signature request hash;
- authorization signable payload hash;
- requested signature type;
- allowed decisions;
- default decision;
- drafted authorization fields;
- requirements before the final receipt can be signed;
- future executor contract;
- explicit forbidden actions after the request.

It must keep:

- `signature_required=true`;
- `signature_present=false`;
- `signature_valid=false`;
- `receipt_signed=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `execution_allowed=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`.

The signature request is not a signature. It must not accept signature evidence,
validate a signature, sign a receipt, record approval, merge or dispatch work.

## Final Post-Signature Runbook

`--codex-review-merge-final-post-signature-runbook` defines the checklist for a
future moment after external final receipt signature evidence exists.

It may become ready only after the final signature request is pending.

The runbook must require:

- external final merge receipt signature value;
- validator identity and validation timestamp;
- validated final signable payload hash;
- validated final receipt hash;
- selected decision and rationale;
- fresh merge preflight, test, docs-health, architecture and diff-check hashes;
- scope integrity and hot-scope exclusion statements;
- packet evidence integrity statement;
- rollback plan hash;
- human confirmation hash.

It must sequence, but not execute:

- external final signature verification;
- final signable payload and receipt hash matching;
- selected decision check;
- fresh gate hash verification;
- scope and packet evidence checks;
- preparation of a separate signed final receipt surface.

It must keep `signature_valid=false`, `receipt_signed=false`,
`decision_recorded=false`, `approval_granted=false` and `merge_allowed=false`.

The runbook must never sign the receipt. Signing the receipt requires a separate
surface that validates external evidence and persists append-only state.

The signed final receipt template is governed by
`codex-merge-signed-final-receipt-contract.md`.

## Resumo

Contract for the read-only template, final receipt draft, signature request and post-signature runbook for a future governed merge authorizing action.

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
