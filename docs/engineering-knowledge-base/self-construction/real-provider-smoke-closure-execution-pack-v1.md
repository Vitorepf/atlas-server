---
id: real-provider-smoke-closure-execution-pack-v1
type: engineering_knowledge
title: Real Provider Smoke Closure Execution Pack v1
status: active
category: architecture
priority: 84
summary: Read-only closure execution pack that bundles runbook, offline harness, draft, dossier, completion evidence status, blocker explainer, pre-submission verifier and operator checklist for the end_to_end_real_provider_smoke_green blocker. Never calls a provider, never spends tokens, never persists evidence and never declares OS complete.
tags:
  - atlas-ai
  - self-construction
  - real-provider-smoke
  - closure-execution-pack
  - blocker-corridor
capabilities:
  - real_provider_smoke_closure_execution_pack
  - real_provider_smoke_pre_submission_verification
  - real_provider_smoke_operator_checklist
decisions:
  - The closure execution pack is read-only and never substitutes the existing certifier.
  - Pre-submission verification is mandatory before --persist-completion-evidence.
  - Persistence of the smoke remains gated by the existing certifier with the explicit flag.
maintenance:
  - Update when the canonical real provider smoke shape adds new required fields, hashes or forbidden flags.
  - Do not edit AtlasSelfConstructionReadinessService, AtlasAiSelfConstructionCommand or agent-control-plane-contract.md from this pack.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: real-provider-smoke-closure-execution-pack-v1
graph_title: Real Provider Smoke Closure Execution Pack v1
graph_world: atlas
graph_layer: gear
graph_kind: module
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Real Provider Smoke Closure Execution Pack v1
canonical_name: Real Provider Smoke Closure Execution Pack v1
technical_name: real-provider-smoke-closure-execution-pack-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/real-provider-smoke-closure-execution-pack-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/real-provider-smoke-closure-execution-pack-v1.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeOperatorChecklistService.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeClosureExecutionPackTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeOperatorChecklistTest.php
allowed_changes:
  - Atualizar a pack quando os contratos canonicos do smoke mudarem.
  - Adicionar diagnostics novos no pre-submission verifier quando o certificador real ganhar novos campos.
forbidden_changes:
  - Persistir smoke ou evidencia operador a partir da pack.
  - Chamar provider, gastar token, despachar ou habilitar runtime.
  - Promover completion a partir da pack.
  - Substituir o certificador existente.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-self-construction-os-completion-roadmap-v1
flows_to:
  - atlas-self-construction-os-operator-runbook-v1
unlocks:
  - operator-observed-real-provider-smoke-closure-path
governs:
  - end-to-end-real-provider-smoke-green-corridor
evidence:
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeClosureExecutionPackTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeOperatorChecklistTest.php
required_tests:
  - "php artisan test --filter='RealProviderSmokeClosureExecutionPack|RealProviderSmokePreSubmissionVerifier|RealProviderSmokeOperatorChecklist'"
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - smoke
  - closure-pack
ai_entrypoints:
  - Leia Purpose, Scope, Non-Goals, Ordered Operator Steps e Integration Patch Pending antes de propor mudancas em readiness ou CLI.
ai_usage_notes:
  - Use a pack para iterar o smoke. Use o pre-submission verifier antes do --persist-completion-evidence. Use o checklist para nao pular fases.
quality_gates:
  - "php artisan test --filter='RealProviderSmokeClosureExecutionPack|RealProviderSmokePreSubmissionVerifier|RealProviderSmokeOperatorChecklist'"
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - Operador trata fixture sintetica ou test payload como smoke real.
  - Persistir smoke sem rodar o pre-submission verifier antes.
  - Declarar OS completo sem smoke real observado pelo operador.
observability_signals:
  - docs-health status ok
  - test suite verde
next_actions:
  - Integrar quartet de cada service na ReadinessService e CLI quando os outros agentes terminarem suas slices centrais.
  - Adicionar bullet canonico em agent-control-plane-contract.md depois que ele estiver liberado.
---
## Purpose

The Real Provider Smoke Closure Execution Pack v1 is the read-only closure surface for the completion audit blocker `end_to_end_real_provider_smoke_green`. It composes every existing read-only artifact relevant to the blocker (runbook, offline harness, draft, evidence dossier, completion evidence status, blocker explainer, final evidence bundle and certifier) into a single payload, and adds two new closure services — a pre-submission verifier and an operator checklist — that the operator can run before attempting to persist a real provider smoke.

The pack never calls a provider, never spends tokens, never persists evidence, never promotes completion and never declares the OS complete. It exists to make the operator's path to closing the blocker explicit, ordered and safe.

## Scope

- Input: optional `real_provider_smoke` payload and optional `completion_audit` payload.
- Output: an `available` envelope with full closure context, ordered operator steps, exact commands, anti-cheat policy, non-execution guarantees and a deterministic `closure_pack_hash`.
- Used only as an offline planning and pre-submission surface. Real persistence still happens through the existing certifier with the explicit `--persist-completion-evidence` flag.

## Non-Goals

- The pack is not a provider client.
- The pack is not a persistence path.
- The pack is not the certifier — it cannot promote the blocker green.
- The pack is not the completion audit — it cannot mark OS complete.
- The pack is not a signer.

## Inputs

| Key                       | Type                | Required | Notes                                                                |
| ------------------------- | ------------------- | -------- | -------------------------------------------------------------------- |
| `real_provider_smoke`     | array<string,mixed> | no       | If absent, status is `blocked_operator_real_provider_smoke_required`.|
| `completion_audit`        | array<string,mixed> | no       | If absent, `blocker_explainer` reports `no_completion_audit_supplied`.|

## Diagnostics

The pack returns:

- `provider_smoke_template` and the canonical lists `required_evidence_fields`, `required_hash_fields`, `required_observation_flags`, `forbidden_flags`.
- Seven contracts: `operator_approval_contract`, `single_packet_scope_contract`, `work_product_collection_contract`, `cost_event_contract`, `continuation_summary_contract`, `evidence_ledger_contract`, `provider_response_contract` — each with a `rule` and a `stop_condition`.
- Sub-component payloads: `runbook`, `offline_harness`, `draft_payload`, `evidence_dossier`, `completion_evidence_status`, `blocker_explainer`, `final_evidence_bundle`, `certification_result`, `pre_submission_verifier_result`, `operator_checklist`.
- Top-level status: `blocked_operator_real_provider_smoke_required`, `ready_to_verify_operator_smoke_payload`, `ready_to_persist_real_provider_smoke` or `real_provider_smoke_verified`.

## Synthetic Fixture Policy

The pack never generates synthetic smokes. The pre-submission verifier explicitly rejects values containing `synthetic`, `fake`, `mock-`, `simulated`, `test_only` or `fixture-only` fragments in the operator-supplied identity fields. Test fixtures used by the suite are coherent only for unit tests and carry the flag `not_operator_real_until_observed=true`.

## Anti-Cheat Policy

```
no_synthetic_smoke_accepted
atlas_must_not_call_provider
atlas_must_not_spend_tokens
operator_must_observe_real_provider_run
persistence_requires_explicit_flag
no_os_complete_claim_from_pack
```

## Non-Execution Guarantees

```
does_not_call_provider
does_not_spend_tokens
does_not_dispatch
does_not_start_process
does_not_persist_smoke
does_not_promote_completion
does_not_enable_runtime
does_not_sign_for_operator
```

## Operator Workflow

1. `review_offline_harness`
2. `approve_single_packet_scope`
3. `run_real_provider_smoke_outside_read_only_surface`
4. `collect_provider_response_hash`
5. `collect_cost_event_hash`
6. `collect_work_product_manifest_hash`
7. `collect_continuation_summary_hash`
8. `collect_evidence_ledger_hash`
9. `build_smoke_preimage`
10. `compute_smoke_hash`
11. `verify_smoke_payload`
12. `persist_with_explicit_flag_only`
13. `rerun_completion_evidence_status`
14. `rerun_completion_audit`

## Verification Commands

```
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService.php
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierService.php
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeOperatorChecklistService.php
php artisan test --filter='RealProviderSmokeClosureExecutionPack|RealProviderSmokePreSubmissionVerifier|RealProviderSmokeOperatorChecklist'
php -d memory_limit=512M ./vendor/bin/pint <touched files>
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
```

## Completion Boundaries

- The pack is necessary but not sufficient to close `end_to_end_real_provider_smoke_green`. The blocker only closes when the operator observes a real provider run, supplies real evidence, the existing certifier passes and the completion audit re-runs.
- The pack and its sub-services do not declare the OS complete.

## Integration Patch Pending

Readiness, CLI and `agent-control-plane-contract.md` were intentionally left untouched because parallel agents may be modifying those files in this session. When those slices land, the following patch is required to wire the three new services into the orchestration:

### `AtlasSelfConstructionReadinessService.php`

Add a quartet for each of the three new services (status, draft, json export, capability):

1. `realProviderSmokeClosureExecutionPackStatus(array $options = []): array` — calls `AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService::build($options)` and stamps `readiness_at`.
2. `realProviderSmokePreSubmissionVerifierStatus(array $options = []): array` — calls `AtlasSelfConstructionRealProviderSmokePreSubmissionVerifierService::verify((array) ($options['real_provider_smoke'] ?? []))`.
3. `realProviderSmokeOperatorChecklistStatus(): array` — calls `AtlasSelfConstructionRealProviderSmokeOperatorChecklistService::build()`.
4. Add to `capabilities()` (the canonical capability catalog) these 15 new capability ids (5 per service):
   - `atlas-self-construction-real-provider-smoke-closure-execution-pack-status`
   - `atlas-self-construction-real-provider-smoke-closure-execution-pack-blocker-corridor`
   - `atlas-self-construction-real-provider-smoke-closure-execution-pack-ordered-operator-steps`
   - `atlas-self-construction-real-provider-smoke-closure-execution-pack-non-execution-guarantees`
   - `atlas-self-construction-real-provider-smoke-closure-execution-pack-anti-cheat-policy`
   - `atlas-self-construction-real-provider-smoke-pre-submission-verifier-status`
   - `atlas-self-construction-real-provider-smoke-pre-submission-verifier-diagnostic-codes`
   - `atlas-self-construction-real-provider-smoke-pre-submission-verifier-can-persist`
   - `atlas-self-construction-real-provider-smoke-pre-submission-verifier-not-operator-real-until-observed`
   - `atlas-self-construction-real-provider-smoke-pre-submission-verifier-non-execution`
   - `atlas-self-construction-real-provider-smoke-operator-checklist-status`
   - `atlas-self-construction-real-provider-smoke-operator-checklist-phases`
   - `atlas-self-construction-real-provider-smoke-operator-checklist-stop-conditions`
   - `atlas-self-construction-real-provider-smoke-operator-checklist-commands`
   - `atlas-self-construction-real-provider-smoke-operator-checklist-non-execution`

### `AtlasAiSelfConstructionCommand.php`

Add three CLI flags routed to the corresponding readiness methods:

```
--atlas-self-construction-real-provider-smoke-closure-execution-pack-status
--atlas-self-construction-real-provider-smoke-pre-submission-verifier-status
--atlas-self-construction-real-provider-smoke-operator-checklist-status
```

Reuse the existing `--real-provider-smoke-json=@path` and `--completion-audit-json=@path` payload options. Output JSON when `--json` is provided. No new persistence flag is required — persistence remains routed through `--persist-completion-evidence`.

### `agent-control-plane-contract.md`

Add a single canonical bullet under the read-only services section:

```
- Real Provider Smoke Closure Execution Pack v1: composes runbook, offline harness, draft, dossier,
  completion evidence status, blocker explainer, final evidence bundle, pre-submission verifier and
  operator checklist into a single read-only closure surface for end_to_end_real_provider_smoke_green.
  Never calls a provider, never persists, never claims completion.
```

### Why Pending

The previous safety rule says: "Se detectar que `AtlasSelfConstructionReadinessService.php`, `AtlasAiSelfConstructionCommand.php` ou `agent-control-plane-contract.md` já foram alterados por outro agente durante sua execução, não sobrescreva." The three central files were clean at the start of this slice but parallel agents are likely to modify them. To avoid clobbering work-in-flight, the three integration patches are deferred to a small follow-up slice owned by whoever lands those central files next.

## Change Log

- 2026-05-15 — Initial entry. Closure execution pack, pre-submission verifier, operator checklist, tests and doc created as an isolated macro-slice. Readiness/CLI/contract integration deferred and documented above.

## Resumo

Pack read-only que fecha profissionalmente o corredor do blocker `end_to_end_real_provider_smoke_green`. Une runbook, offline harness, draft, dossier, completion evidence status, blocker explainer, final evidence bundle, pre-submission verifier e operator checklist em um payload unico. Nunca chama provider, nunca gasta token, nunca persiste, nunca declara OS completo.

## Papel no Atlas

Superficie de execucao para o blocker mais sensivel da completion audit. Da ao operador o caminho ordenado, comandos exatos e checklists para fechar o smoke real sem atalho automatizado.

## Onde Se Encaixa

Sub-modulo do Atlas Self-Construction OS, irmao das demais superficies de evidencia do corridor (FinalOperatorEvidenceClosureCorridor, OperatorEvidenceArtifactTemplatePack, OperatorEvidenceSubmissionReadiness, OperatorEvidenceReceiptFixtureLab). Filho conceitual do agent-control-plane-contract.

## Contratos

Sete contratos canonicos (operator_approval, single_packet_scope, work_product_collection, cost_event, continuation_summary, evidence_ledger, provider_response). Cada contrato traz uma regra e uma stop_condition. Persistencia exige o flag explicito `--persist-completion-evidence` no certificador existente.

## Fluxo

Operador roda offline harness -> aprova escopo de packet unico -> roda smoke real fora do read-only -> coleta hashes -> monta preimage -> calcula smoke_hash -> roda pre-submission verifier -> persiste com flag explicito -> rerun completion evidence status -> rerun completion audit.

## Regras para IA

Nao chamar provider. Nao gastar token. Nao despachar. Nao habilitar runtime. Nao promover completion. Nao tratar test payload como smoke real. Nao integrar em readiness/CLI/contract sem que outros agentes liberem.

## Escopo de Implementacao

Tres services em `app/Services/Ai/SelfConstruction/` (closure execution pack, pre-submission verifier, operator checklist), tres tests em `tests/Feature/Ai/SelfConstruction/` e este doc.

## Dependencias

AtlasSelfConstructionRealProviderSmokeRunbookService, AtlasSelfConstructionRealProviderSmokeOfflineHarnessService, AtlasSelfConstructionRealProviderSmokeDraftService, AtlasSelfConstructionRealProviderSmokeEvidenceDossierService, AtlasSelfConstructionFinalEvidenceBundleService (resolvido via container), AtlasSelfConstructionRealProviderSmokeCertificationService, AtlasSelfConstructionCompletionAuditBlockerExplainerService, AtlasSelfConstructionCompletionEvidenceHashService.

## Evidencias

22 testes feature verdes nos 3 services novos, pint verde, php -l verde, RealProviderSmoke* regression suite verde, closure_pack_hash determinista, no operator evidence path tocado em Storage::fake.

## Riscos

Operador interpretar pack como certificador. Persistir smoke sem pre-submission verifier verde. Outros agentes mexerem em readiness/CLI/contract antes da integracao patch pending ser feita.

## Exemplos

Operador chama `build(['real_provider_smoke' => $candidate])`, ve `pre_submission_verifier_result.status=blocked` com diagnostics `missing_provider_run_id` e `invalid_cost_event_hash`. Operador corrige, rerun, ve `ready_to_persist_real_provider_smoke`, e so entao roda `--persist-completion-evidence` no certificador existente.

## Proximas Acoes

Aguardar parallel agents terminarem suas slices centrais. Integrar o quartet de cada service em ReadinessService + CLI + bullet em agent-control-plane-contract.md em macro-slice proprio. Manter a pack sincronizada quando o certificador real ganhar campos novos.
