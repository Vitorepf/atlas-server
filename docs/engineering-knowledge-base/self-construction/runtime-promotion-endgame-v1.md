---
id: atlas-ai-self-construction-runtime-promotion-endgame-v1
type: engineering_knowledge
title: Atlas Self-Construction Runtime Promotion Endgame v1
status: active
category: architecture
priority: 95
summary: Read-only operator corridor for closing the runtime_gap_matrix_all_runtime_y completion-audit blocker without autopromotion or fake evidence.
tags:
  - atlas-ai
  - self-construction
  - runtime-promotion
  - endgame
capabilities:
  - runtime_promotion_endgame
  - runtime_promotion_endgame_verifier
  - runtime_promotion_operator_runbook_exporter
decisions:
  - Runtime promotion receipt persistence remains gated by --persist-runtime-promotion-receipt and the canonical AtlasSelfConstructionRuntimePromotionReceiptService.
  - The endgame never autopromotes runtime, never signs for the operator, never calls a provider, never spends tokens.
  - Operator runbook export is in-memory by default; persistence requires explicit --persist-export.
maintenance:
  - Update when the runtime gap matrix shape, closure pack hash inputs or persistence path change.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionEndgameService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionEndgameVerifierService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService.php
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 320
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-runtime-promotion-endgame-v1

graph_title: Atlas Self-Construction Runtime Promotion Endgame v1

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Runtime Promotion Endgame v1
canonical_name: Atlas Self-Construction Runtime Promotion Endgame v1
technical_name: atlas-ai-self-construction-runtime-promotion-endgame-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/runtime-promotion-endgame-v1.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/runtime-promotion-endgame-v1.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionEndgameService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionEndgameVerifierService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionEndgameTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionEndgameVerifierTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterTest.php

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-self-construction-os

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - runtime_promotion_receipt_persistence_corridor

governs:
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/runtime-promotion-endgame-v1.md

evidence_refs:
  - symbol: AtlasSelfConstructionRuntimePromotionEndgameService
  - command: atlas:self-construction:runtime-promotion-endgame
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionEndgameTest.php"
  - "php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionEndgameVerifierTest.php"
  - "php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterTest.php"

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
  - "php artisan atlas:ai:architecture-validate --json"

failure_modes:
  - Receipt forjado, hash stale ou flags de runtime ligadas sem operador.

observability_signals:
  - docs-health status ok
  - completion audit status

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Self-Construction Runtime Promotion Endgame v1

Read-only macro corridor that walks the Atlas Self-Construction OS operator
from "runtime promotion receipt missing" all the way to "runtime promotion
receipt persisted, ready to rerun the runtime gap matrix and completion audit"
without ever autopromoting runtime, signing for the operator, calling a
provider, spending tokens, dispatching, or persisting a receipt without an
explicit `--persist-runtime-promotion-receipt` flag plus a green pre-submission
verifier.

This slice is the maximum-leverage focused corridor on the
`runtime_gap_matrix_all_runtime_y` completion-audit blocker. It does **not**
close any of the three real OS blockers
(`runtime_gap_matrix_all_runtime_y`,
`human_signed_os_complete_receipt_present`,
`end_to_end_real_provider_smoke_green`) by itself — the operator still has to
produce a real, hash-canonical, signed runtime promotion receipt against the
current matrix.

## Services

### AtlasSelfConstructionRuntimePromotionEndgameService

Schema `atlas.self_construction.runtime_promotion_endgame.v1`, mode
`read_only_runtime_promotion_endgame`. Composes runtime gap matrix, evidence
dossier, closure pack + closure pack verifier, receipt runbook, receipt draft
(only when `signed_by`/`reason` ≥ 32 chars are supplied), endgame verifier,
completion evidence submission preflight, completion audit, and operator action
packet. Surfaces an `operator_decision_checklist` with eight canonical ids and
a 9-rule anti-cheat policy.

### AtlasSelfConstructionRuntimePromotionEndgameVerifierService

Schema `atlas.self_construction.runtime_promotion_endgame_verifier.v1`. Rich
per-rule diagnostic verifier. Emits 14 structured violation codes:
`required_field_missing`, `invalid_64_hex_field`, `placeholder_field`,
`placeholder_signer`, `reason_too_short_or_placeholder`,
`receipt_hash_mismatch`, `forbidden_flag_true`,
`required_acknowledgement_missing`, `stale_runtime_gap_matrix_hash`,
`stale_runtime_promotion_basis_hash`,
`stale_runtime_promotion_closure_basis_hash`, `promoted_gap_id_drift`,
`missing_graduation_hash`, `graduation_hash_mismatch`.

### AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService

Schema `atlas.self_construction.runtime_promotion_operator_runbook_exporter.v1`.
Generates an operator-legible Markdown runbook plus a machine-friendly JSON
summary. Defaults to in-memory only; persistence to local storage is gated by
the explicit `persist_export=true` option (CLI `--persist-export`) and writes
under the sandbox prefix
`atlas/self-construction/runtime-promotion/operator-runbook-exports`.

## Anti-cheat policy

- `reject_runtime_autopromotion`
- `reject_stale_gap_matrix_hash`
- `reject_stale_closure_basis_hash`
- `reject_gap_id_drift`
- `reject_graduation_hash_mismatch`
- `reject_placeholder_operator`
- `reject_hash_mismatch`
- `reject_runtime_enabled_flags_true`
- `reject_completion_claim_from_runtime_receipt_alone`

## Non-execution guarantees

The endgame never starts Codex, never calls a provider HTTP API, never spends
tokens, never dispatches, never starts a process, never enables runtime, never
enables self-programming, never signs for the operator, never persists a
receipt without `--persist-runtime-promotion-receipt` and verifier green, and
never declares the Atlas Self-Construction OS complete.

## Operator commands

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-status --json
php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-status \
  --signed-by="<real-operator>" --reason="<≥32 chars explanation>" --json
php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-endgame-verifier-status \
  --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --json
php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status \
  --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json
php artisan atlas:ai:self-construction --atlas-self-construction-runtime-gap-matrix --json
php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json
php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-operator-runbook-exporter-status --json
php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-operator-runbook-exporter-status --persist-export --json
```

## Resumo

Corredor read-only para fechar o blocker `runtime_gap_matrix_all_runtime_y`
sem autopromocao, sem fake evidence, sem provider call e sem dispatch.

## Papel no Atlas

Implementa o endgame focado de runtime promotion dentro do Atlas
Self-Construction OS sem alterar o caminho canonico de persistencia.

## Onde Se Encaixa

Vive abaixo de `atlas-ai-self-construction-os` ao lado do Agent Control Plane
Contract e dos demais corredores de runtime promotion.

## Contratos

Persistencia exige `--persist-runtime-promotion-receipt` AND verifier verde.
Runtime nunca eh marcado como `runtime_y` sem receipt real persistido.

## Fluxo

`blocked → draft_ready → verifier_passed → persisted → rerun_matrix → rerun_audit`,
com o operador como unico autor da assinatura e dos hashes.

## Regras para IA

A IA agente nunca deve gerar `signed_by`, nunca recomputar hash sem o servico
canonico, nunca chamar provider, nunca habilitar runtime, nunca declarar OS
completo.

## Escopo de Implementacao

Mudancas ficam restritas aos servicos, testes e docs declarados em
`repo_paths`. Outras areas (Forge, Rivals, AtlasCode, atlas-desktop, Voice,
Cartografia, Programming, SelfImprovement) sao intocaveis.

## Dependencias

Depende de `RuntimeGapMatrixService`, `RuntimePromotionEvidenceDossierService`,
`RuntimePromotionClosurePackService`, `RuntimePromotionClosurePackVerifierService`,
`RuntimePromotionReceiptDraftService`, `RuntimePromotionReceiptRunbookService`,
`RuntimePromotionReceiptService` (canonical persistence), `OsCompletionAuditService`,
`CompletionEvidenceSubmissionPreflightService`.

## Evidencias

Tres testes feature, status JSON do CLI, completion audit, docs-health,
architecture-validate, git diff --check.

## Riscos

Receipt forjado, hash stale, runtime-enabling flags ligadas sem operador,
declaracao prematura de runtime_y, persistencia sem verifier verde.

## Exemplos

Veja "Operator commands" acima para sequencia exata de fechamento.

## Proximas Acoes

Operador deve gerar receipt real assinado contra a matrix atual, rodar o
verifier verde, persistir com flag explicita, rerodar a matrix e o completion
audit. Sem isso, o blocker permanece aberto.
