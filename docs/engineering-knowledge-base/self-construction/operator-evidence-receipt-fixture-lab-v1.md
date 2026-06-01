---
id: operator-evidence-receipt-fixture-lab-v1
type: engineering_knowledge
title: Operator Evidence Receipt Fixture Lab v1
status: active
category: architecture
priority: 80
summary: Read-only diagnostic lab that explains why an operator evidence payload (runtime_promotion_receipt, real_provider_smoke, human_completion_receipt) is failing before the operator attempts to persist. Never persists, never calls a provider, never claims completion.
tags:
  - atlas-ai
  - self-construction
  - operator-evidence
  - fixture-lab
  - diagnostics
capabilities:
  - operator_evidence_diagnostics
  - fixture_synthesis_test_only
  - non_execution_lab
decisions:
  - The fixture lab is a diagnostic surface, not an evidence source.
  - Synthetic fixtures exist only for unit tests and are explicitly marked as not_operator_evidence.
  - The lab must never persist, never call a provider, never enable runtime and never close completion.
maintenance:
  - Update when verifier services add new required fields, forbidden flags or hash fields.
  - Do not edit AtlasSelfConstructionReadinessService, AtlasAiSelfConstructionCommand or agent-control-plane-contract.md from this lab.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: operator-evidence-receipt-fixture-lab-v1
graph_title: Operator Evidence Receipt Fixture Lab v1
graph_world: atlas
graph_layer: gear
graph_kind: module
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Operator Evidence Receipt Fixture Lab v1
canonical_name: Operator Evidence Receipt Fixture Lab v1
technical_name: operator-evidence-receipt-fixture-lab-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/operator-evidence-receipt-fixture-lab-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/operator-evidence-receipt-fixture-lab-v1.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest.php
allowed_changes:
  - Adicionar campos novos de diagnostico quando os verifiers reais mudarem.
  - Atualizar fixtures sinteticas inválidas para refletir novos forbidden flags.
forbidden_changes:
  - Persistir qualquer payload a partir do lab.
  - Tratar fixture sintetica valida como evidencia operadora real.
  - Chamar provider, gastar token, despachar ou habilitar runtime.
  - Promover completion a partir do lab.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-self-construction-os-completion-roadmap-v1
flows_to:
  - atlas-self-construction-os-operator-runbook-v1
unlocks:
  - operator-evidence-self-service-diagnostics
governs:
  - operator-evidence-diagnostics
evidence:
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest.php
evidence_refs:
  - test: AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest
  - symbol: AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService
required_tests:
  - "php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - gear
  - lab
  - self-construction
ai_entrypoints:
  - Leia Purpose, Scope, Non-Goals e Diagnostics antes de interpretar a saida do lab.
ai_usage_notes:
  - Use o lab para descobrir por que um payload falha, nunca para fechar evidencia operadora.
quality_gates:
  - "php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - Operador trata fixture sintetica como evidencia real.
  - Operador interpreta verifier_passed do lab como completion claim.
  - Lab persistindo, chamando provider, gastando token ou habilitando runtime.
observability_signals:
  - docs-health status ok
  - test suite verde
next_actions:
  - Manter o lab sincronizado com mudancas nos verifiers reais e hash services.
  - Nao integrar no readiness/CLI sem macro-slice proprio com gates verdes.
---
## Purpose

The Operator Evidence Receipt Fixture Lab v1 is a read-only diagnostic surface that explains, in plain language, why an operator evidence payload (runtime promotion receipt, real provider smoke, or human completion receipt) is failing the existing verifiers. It exists so the operator can iterate on a payload locally, see exactly which fields are missing, malformed or carrying forbidden runtime flags, and arrive at the real verifier with a clean payload — without persisting anything, without calling a provider and without claiming completion.

The lab is the antonym of an authority surface. It produces no evidence. It produces only a diagnostic.

## Scope

- Input: an optional payload for each of the three final operator artifacts:
  - `runtime_promotion_receipt`
  - `real_provider_smoke`
  - `completion_receipt`
- Output: an `available` envelope with three diagnostics, a synthetic fixture catalog, anti-cheat policy, non-execution guarantees and a deterministic `fixture_lab_hash`.
- Used only by operators and unit tests in the diagnostic phase, before submitting evidence to the real Final Operator Evidence Closure Corridor.

## Non-Goals

- The lab is not a persistence path. It cannot store a receipt, a smoke or a completion receipt.
- The lab is not a provider client. It cannot call a model provider or spend tokens.
- The lab is not a dispatcher. It cannot start a process, dispatch a job, enable runtime or promote completion.
- The lab is not a signer. It cannot sign on behalf of the operator.
- The lab is not the real verifier. A `verifier_passed` diagnostic in the lab is necessary but not sufficient — the real verifier still owns the final decision against live matrix rows, the real evidence ledger and the real operator context.

## Inputs

| Key                          | Type                | Required | Notes                                                                 |
| ---------------------------- | ------------------- | -------- | --------------------------------------------------------------------- |
| `runtime_promotion_receipt`  | array<string,mixed> | no       | If absent, the runtime diagnostic returns status `no_input`.          |
| `real_provider_smoke`        | array<string,mixed> | no       | If absent, the smoke diagnostic returns status `no_input`.            |
| `completion_receipt`         | array<string,mixed> | no       | If absent, the completion diagnostic returns status `no_input`.       |

The lab never auto-loads payloads from storage. If a payload is missing, the corresponding diagnostic explicitly reports `no_input` instead of silently using the latest persisted artifact. This is intentional: the lab must never observe real operator evidence implicitly.

## Diagnostics

Each receipt diagnostic exposes the following fields:

| Field                       | Type                  | Meaning                                                                                                |
| --------------------------- | --------------------- | ------------------------------------------------------------------------------------------------------ |
| `fixture_kind`              | string                | `runtime_promotion_receipt`, `real_provider_smoke` or `human_completion_receipt`.                      |
| `input_present`             | bool                  | Whether the operator supplied a payload for this kind.                                                 |
| `status`                    | string                | `no_input`, `invalid` or `verifier_passed`.                                                            |
| `computed_hash`             | string                | Hash the lab computed from the supplied payload using the canonical hash service.                      |
| `submitted_hash`            | string                | Hash field present in the payload (`receipt_hash` or `smoke_hash`).                                    |
| `hash_matches`              | bool                  | True iff `submitted_hash` equals `computed_hash` and `submitted_hash` is non-empty.                    |
| `missing_fields`            | list<string>          | Required fields that are absent or empty.                                                              |
| `invalid_hash_fields`       | list<string>          | Hash-shaped fields that fail the 64-hex regex.                                                         |
| `placeholder_fields`        | list<string>          | Identity fields filled with `<operator>`, `codex`, `<...>`, etc.                                       |
| `forbidden_flags_true`      | list<string>          | Runtime-enabling flags set to `true` that must always be `false` in operator evidence.                 |
| `verifier_status`           | string                | Raw status returned by the underlying verifier.                                                        |
| `verifier_violation_count`  | int                   | Number of verifier violations.                                                                         |
| `verifier_violations`       | list<array>           | Raw verifier violation entries.                                                                        |
| `safe_next_command`         | string                | Plain-language suggestion for the operator's next step.                                                |
| `persistence_allowed_here`  | bool                  | Always `false`; the lab never persists.                                                                |

A `verifier_passed` status in the lab means the supplied payload would survive the verifier's structural checks. It does not mean the operator may persist it from the lab. The next legitimate step is always the real verifier in the Final Operator Evidence Closure Corridor.

## Synthetic Fixture Policy

The lab exposes a small `synthetic_fixture_catalog` with one invalid example per kind and one hash-coherent valid example only for the runtime promotion receipt (the only kind where a synthetic test-only example is unambiguous). The catalog declares, in machine-readable form:

- `valid_fixture_is_test_only` — synthetic fixtures exist only to drive the lab's own unit tests.
- `not_operator_evidence` — synthetic fixtures are not operator evidence under any circumstance.
- `cannot_be_used_for_completion_claim` — synthetic fixtures never close a completion claim.
- `cannot_be_persisted_as_real_provider_smoke` — synthetic fixtures never close a real provider smoke blocker.

No synthetic valid `real_provider_smoke` is offered. A real provider smoke requires a real provider call observed by a real operator; there is no way to synthesize this without lying. Similarly, no synthetic valid `human_completion_receipt` is offered: only a real human operator signature can close completion.

## Anti-Cheat Policy

The payload declares the following invariants:

- `test_fixtures_are_not_operator_evidence`
- `synthetic_smoke_cannot_close_real_provider_blocker`
- `computed_hash_does_not_imply_operator_approval`
- `verifier_passed_in_test_storage_does_not_equal_real_completion`
- `no_completion_claim_from_fixture_lab`

These are not configurable. The lab is structurally incapable of violating them: it has no persistence path, no provider client and no completion promotion code path.

## Non-Execution Guarantees

The payload also declares:

- `does_not_persist`
- `does_not_call_provider`
- `does_not_spend_tokens`
- `does_not_dispatch`
- `does_not_start_process`
- `does_not_enable_runtime`
- `does_not_promote_completion`
- `does_not_sign_for_operator`

These map one-to-one to absent code paths. The lab uses the existing verifiers in non-loading mode and the hash service in pure-function mode. It does not call `Storage::put`, does not instantiate any provider client and does not touch the readiness or completion services.

## Operator Workflow

1. Operator drafts a candidate payload for one of the three artifacts.
2. Operator invokes the lab service with the candidate payload.
3. Operator reads the diagnostic for that kind:
   - If `missing_fields` is non-empty, fill the missing fields and rerun.
   - If `invalid_hash_fields` is non-empty, regenerate the hash fields with the real upstream evidence.
   - If `placeholder_fields` contains `signed_by`, replace the placeholder with the real operator identity.
   - If `forbidden_flags_true` is non-empty, set those flags to `false` and rerun.
   - If `hash_matches` is `false`, recompute `receipt_hash` / `smoke_hash` using `AtlasSelfConstructionCompletionEvidenceHashService` and rerun.
4. When the diagnostic reports `verifier_passed`, route the payload to the real Final Operator Evidence Closure Corridor — the lab does not persist.

## Verification Commands

```
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService.php
php -l tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest.php
php artisan test tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest.php
php -d memory_limit=512M ./vendor/bin/pint app/Services/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService.php tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest.php
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
```

## Completion Boundaries

- The lab MAY be added as a read-only assistance surface for operators preparing evidence.
- The lab MUST NOT be wired into the readiness service, the CLI command or the agent-control-plane contract as a source of completion-relevant evidence. If integration becomes desirable, propose it as a separate macro-slice with its own gates — never inline from this lab.
- A `fixture_lab_hash` is deterministic across runs ignoring `generated_at`; it identifies the diagnostic content, not an operator signature.

## Change Log

- 2026-05-15 — Initial entry. Service, tests and doc created as an isolated macro-slice that does not touch readiness, CLI or the agent control plane contract.

## Resumo

Lab read-only que diagnostica payloads candidatos de runtime_promotion_receipt, real_provider_smoke e human_completion_receipt. Nunca persiste, nunca chama provider, nunca habilita runtime, nunca fecha completion.

## Papel no Atlas

Superficie de assistencia diagnostica para o operador iterar payloads antes da Final Operator Evidence Closure Corridor. Nao e fonte de evidencia operadora.

## Onde Se Encaixa

Sub-superficie de apoio do Atlas Self-Construction OS, irma das superficies finais (FinalOperatorEvidenceClosureCorridor, OperatorEvidenceArtifactTemplatePack, OperatorEvidenceSubmissionReadiness). Filha conceitual do agent-control-plane-contract sem modificar contrato.

## Contratos

Honra estritamente: read_only_operator_evidence_receipt_fixture_lab, persistence_allowed_here=false, anti-cheat policy e non-execution guarantees declarados no payload. Usa verifiers reais em modo nao-loading.

## Fluxo

Operador entrega payload candidato -> lab roda hash service e verifier por kind -> retorna diagnostic com missing/invalid/placeholder/forbidden + verifier_status + safe_next_command -> operador corrige -> repete -> rota para verifier real fora do lab.

## Regras para IA

Nao usar fixture sintetica como evidencia real. Nao tratar verifier_passed do lab como completion claim. Nao instanciar provider client. Nao tocar readiness service nem CLI a partir do lab. Nao persistir.

## Escopo de Implementacao

Apenas estes tres arquivos:
- app/Services/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabService.php
- tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionOperatorEvidenceReceiptFixtureLabTest.php
- docs/engineering-knowledge-base/self-construction/operator-evidence-receipt-fixture-lab-v1.md

## Dependencias

AtlasSelfConstructionCompletionEvidenceHashService, AtlasSelfConstructionRuntimePromotionReceiptService, AtlasSelfConstructionRealProviderSmokeCertificationService, AtlasSelfConstructionHumanCompletionReceiptVerifierService.

## Evidencias

10 testes feature verdes (114 assertions), pint verde, php -l verde nos dois arquivos PHP, fixture_lab_hash determinista, lab nao escreve nenhum arquivo em Storage::fake.

## Riscos

Operador interpretar fixture sintetica como evidencia real. Operador interpretar verifier_passed do lab como completion claim. Acoplar lab a readiness/CLI sem macro-slice proprio.

## Exemplos

Operador chama `build(['runtime_promotion_receipt' => $candidate])`, le `receipt_diagnostics[0]`, ve `missing_fields=['reason']` e `invalid_hash_fields=['runtime_gap_matrix_hash']`, corrige, repete, recebe `verifier_status=passed` e roteia para verifier real.

## Proximas Acoes

Manter sincronia com mudancas nos verifiers reais. Avaliar integracao opcional via macro-slice separado apenas apos outros agentes terminarem suas superficies centrais.
