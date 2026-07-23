---
id: atlas-ai-self-construction-codex-merge-signed-final-receipt-contract
type: engineering_knowledge
title: Atlas Self-Construction Codex Merge Signed Final Receipt Contract
status: active
category: architecture
priority: 100
summary: Contract for the read-only template and preflight that define future signed final merge receipt persistence.
tags:
  - atlas-ai
  - self-construction
  - codex-review
  - merge-governance
capabilities:
  - self_construction_codex_merge_signed_final_receipt_contract
  - review_governance
  - merge_authorization
decisions:
  - Signed final receipt templates may define receipt fields, but must not persist receipts or release execution.
  - Signed final receipt preflights may check future persistence inputs, but must not persist receipts or release execution.
  - Signed final receipt persistence templates may define append-only storage requirements, but must not persist receipts or release execution.
  - Executor release preflights may define executor prerequisites, but must not accept persisted receipt evidence or release execution.
  - Signed final receipts must be append-only and must not apply patches directly.
  - Executor release requires a separate persisted signed receipt plus last-minute scope checks.
maintenance:
  - Update before adding any command that persists a signed final receipt or dispatches a merge executor.
related_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorizing-action-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md
  - docs/engineering-knowledge-base/self-construction/codex-merge-action-draft-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 260
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-codex-merge-signed-final-receipt-contract

graph_title: Atlas Self-Construction Codex Merge Signed Final Receipt Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Codex Merge Signed Final Receipt Contract
canonical_name: Atlas Self-Construction Codex Merge Signed Final Receipt Contract
technical_name: atlas-ai-self-construction-codex-merge-signed-final-receipt-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/self-construction/codex-merge-signed-final-receipt-contract.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/codex-merge-signed-final-receipt-contract.md

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
  - docs/engineering-knowledge-base/self-construction/codex-merge-signed-final-receipt-contract.md
evidence_refs:
  - symbol: AtlasSelfConstructionReadinessService
  - command: atlas:review:codex-chain-contract
  - test: AtlasCodexReviewChainContractTest

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
# Atlas Self-Construction Codex Merge Signed Final Receipt Contract

This contract governs the read-only template for a future signed final merge
receipt. It starts after the final post-signature runbook is ready.

The command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-signed-final-receipt-template --json
```

The preflight command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-signed-final-receipt-preflight --json
```

The persistence template command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-signed-final-receipt-persistence-template --json
```

The executor release preflight command is:

```bash
php artisan atlas:ai:self-construction --codex-review-merge-executor-release-preflight --json
```

## Boundary

These surfaces may describe a future signed final receipt and append-only
persistence contract, but they must keep:

- `signature_present=false`;
- `signature_valid=false`;
- `receipt_signed=false`;
- `receipt_persisted=false`;
- `decision_recorded=false`;
- `approval_granted=false`;
- `merge_allowed=false`;
- `executor_allowed=false`;
- `execution_allowed=false`;
- `ledger_write_allowed=false`;
- `dispatch_allowed=false`.

It must not:

- accept signature evidence;
- validate a signature;
- persist a receipt;
- record a decision;
- approve code;
- release an executor;
- merge;
- dispatch work.

## Future Evidence

The future signed receipt surface must require:

- external final merge receipt signature value;
- validator identity;
- validation timestamp;
- validated final signable payload hash;
- validated final receipt hash;
- validated selected decision;
- validated gate hashes;
- validated scope integrity result;
- validated packet evidence integrity result;
- validated rollback plan hash;
- validated human confirmation hash.

## Future Signed Receipt Fields

The future signed final receipt must persist:

- signed final receipt id;
- source final receipt hash;
- source final signable payload hash;
- source final post-signature runbook hash;
- signature hash;
- signature validator identity;
- signature validation timestamp;
- selected decision;
- decision rationale;
- gate hashes;
- scope integrity result;
- packet evidence integrity result;
- rollback plan hash;
- human confirmation hash;
- executor contract hash;
- signer identity;
- signature timestamp.

## Executor Release

The future executor may only become available when:

- signed final receipt was persisted append-only;
- signed final receipt hash was verified;
- executor consumes only the signed final receipt;
- executor reruns last-minute diff check;
- executor reruns hot-scope check;
- executor emits execution evidence.

This template is not the executor. It exists so the future executor cannot invent
its own authority model.

## Signed Final Receipt Preflight

`--codex-review-merge-signed-final-receipt-preflight` checks the future inputs
required before a signed final receipt can be persisted.

It may become ready only after the signed final receipt template is ready.

It must check for:

- external signature evidence;
- validator identity;
- validation timestamp;
- final signable payload hash match;
- final receipt hash match;
- selected decision equal to `merge`;
- fresh gate hashes;
- scope integrity;
- packet evidence integrity;
- rollback plan hash;
- human confirmation hash.

It must keep `signature_valid=false`, `receipt_signed=false`,
`decision_recorded=false`, `approval_granted=false`, `merge_allowed=false` and
`executor_allowed=false`.

It must not accept signatures, validate signatures, persist receipts, record
decisions, approve code, release executors, merge or dispatch work.

## Signed Final Receipt Persistence Template

`--codex-review-merge-signed-final-receipt-persistence-template` defines how a
future surface would persist a validated signed final receipt append-only.

It may become ready only after signed final receipt preflight is ready.

It must define:

- source preflight, template, receipt and signable payload hashes;
- required external signature and validator inputs;
- append-only persistence fields;
- persistence validations;
- future executor release requirements;
- forbidden operations that remain forbidden by this template.

The persistence template must require:

- all preflight checks passed;
- signature hash matches external evidence;
- selected decision equals `merge`;
- source hashes match the preflight;
- append-only store available;
- unique receipt id;
- executor contract hash present;
- no patch execution in the persistence surface.

It must keep `signature_valid=false`, `receipt_signed=false`,
`receipt_persisted=false`, `decision_recorded=false`, `approval_granted=false`,
`merge_allowed=false` and `executor_allowed=false`.

It must not accept signatures, validate signatures, persist receipts, record
decisions, release executors, merge or dispatch work.

## Executor Release Preflight

`--codex-review-merge-executor-release-preflight` defines the checks required
before any future merge executor may become available.

It may become ready only after the signed final receipt persistence template is
ready.

It must require:

- persisted signed final receipt id and hash;
- append-only receipt event hash;
- executor contract hash;
- final diff check hash;
- hot-scope check hash;
- docs health hash;
- architecture validation hash;
- focused test matrix hash;
- human executor release confirmation hash.

It must block on:

- missing persisted signed final receipt;
- missing append-only receipt event;
- receipt source hash mismatch;
- executor contract hash mismatch;
- final diff failure;
- hot Voice or Kernel scope touch;
- docs health failure;
- architecture validation failure;
- focused test matrix failure;
- missing human executor release confirmation.

It must keep `receipt_persisted=false`, `approval_granted=false`,
`executor_allowed=false` and `merge_allowed=false`.

It must not accept persisted receipt evidence, persist receipts, record
decisions, release an executor, execute patches, merge or dispatch work.

## Resumo

Contract for the read-only template and preflight that define future signed final merge receipt persistence.

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
