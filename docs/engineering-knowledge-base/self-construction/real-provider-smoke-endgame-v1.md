---
id: real-provider-smoke-endgame-v1
type: engineering_knowledge
title: Real Provider Smoke Endgame v1
status: active
category: architecture
priority: 86
summary: Read-only endgame coordinator for the end_to_end_real_provider_smoke_green blocker. Bundles offline harness, runbook, dossier, draft, certifier, ledger preflight, endgame verifier and operator runbook exporter into one staged corridor. Never calls a provider, never spends tokens, never persists evidence without explicit flag, never claims OS complete.
tags:
  - atlas-ai
  - self-construction
  - real-provider-smoke
  - endgame
  - blocker-corridor
capabilities:
  - real_provider_smoke_endgame
  - real_provider_smoke_endgame_verifier
  - real_provider_smoke_evidence_ledger_preflight
  - real_provider_smoke_operator_runbook_exporter
decisions:
  - The endgame is read-only and never substitutes the existing certifier.
  - Persistence happens only when the operator passes the explicit --persist-completion-evidence flag AND the endgame verifier returns passed.
  - Fixture or synthetic smokes never close the real blocker — only an operator-observed real provider run does.
maintenance:
  - Update when the canonical real provider smoke shape adds new required fields, hashes or forbidden flags.
  - Do not edit AtlasSelfConstructionReadinessService, AtlasAiSelfConstructionCommand or agent-control-plane-contract.md from this slice. Use the Integration Patch Pending section.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-completion-roadmap-v1.md
  - docs/engineering-knowledge-base/self-construction/atlas-self-construction-os-operator-runbook-v1.md
  - docs/engineering-knowledge-base/self-construction/real-provider-smoke-closure-execution-pack-v1.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 520
doc_schema: atlas_canonical_module_doc.v1
graph_id: real-provider-smoke-endgame-v1
graph_title: Real Provider Smoke Endgame v1
graph_world: atlas
graph_layer: gear
graph_kind: module
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Real Provider Smoke Endgame v1
canonical_name: Real Provider Smoke Endgame v1
technical_name: real-provider-smoke-endgame-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/real-provider-smoke-endgame-v1.md
repo_paths:
  - docs/engineering-knowledge-base/self-construction/real-provider-smoke-endgame-v1.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEndgameService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEndgameVerifierService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEndgameTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEndgameVerifierTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterTest.php
allowed_changes:
  - Atualizar a endgame quando os contratos canonicos do smoke mudarem.
  - Adicionar diagnostics novos no endgame verifier quando o certifier real ganhar novos campos.
forbidden_changes:
  - Persistir smoke real a partir da endgame sem flag explicito + verifier verde.
  - Chamar provider, gastar token, despachar ou habilitar runtime.
  - Promover completion a partir da endgame.
  - Substituir o certifier existente.
  - Tratar payload sintetico ou fixture como evidencia real.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-self-construction-os-completion-roadmap-v1
  - real-provider-smoke-closure-execution-pack-v1
flows_to:
  - atlas-self-construction-os-operator-runbook-v1
unlocks:
  - operator-observed-real-provider-smoke-blocker-closure
governs:
  - end-to-end-real-provider-smoke-green-corridor
evidence:
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEndgameTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEndgameVerifierTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterTest.php
evidence_refs:
  - test: AtlasSelfConstructionRealProviderSmokeEndgameTest
  - symbol: AtlasSelfConstructionRealProviderSmokeEndgameService
required_tests:
  - "php artisan test --filter='RealProviderSmokeEndgame|RealProviderSmokeEvidenceLedgerPreflight|RealProviderSmokeOperatorRunbookExporter'"
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - smoke
  - endgame
ai_entrypoints:
  - Leia Purpose, Scope, Non-Goals, Ordered Operator Steps e Integration Patch Pending antes de tocar em readiness ou CLI.
ai_usage_notes:
  - Use a endgame para coordenar todas as superfícies do blocker em um payload único. Use o endgame verifier antes do --persist-completion-evidence. Use o ledger preflight antes do verifier. Use o operator runbook exporter para gerar markdown.
quality_gates:
  - "php artisan test --filter='RealProviderSmokeEndgame|RealProviderSmokeEvidenceLedgerPreflight|RealProviderSmokeOperatorRunbookExporter'"
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
failure_modes:
  - Operador trata fixture ou test payload como smoke real.
  - Persistir smoke sem rodar o endgame verifier antes.
  - Declarar OS completo sem smoke real observado pelo operador.
observability_signals:
  - docs-health status ok
  - test suite verde
next_actions:
  - Integrar quartet (4 services) na ReadinessService e CLI quando os agentes paralelos liberarem os arquivos centrais.
  - Adicionar bullet canonico em agent-control-plane-contract.md.
---
## Purpose

The Real Provider Smoke Endgame v1 is the highest-level read-only coordinator for closing the completion audit blocker `end_to_end_real_provider_smoke_green`. It composes every existing read-only surface relevant to the blocker (offline harness, runbook, evidence dossier, draft, certification, blocker explainer, final evidence bundle) and adds three new services that complete the closure corridor:

1. `AtlasSelfConstructionRealProviderSmokeEndgameVerifierService` — single-purpose verifier returning concrete diagnostics for every failure mode.
2. `AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService` — pre-flight that validates the evidence ledger surface is complete before the certifier is invoked.
3. `AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService` — markdown + machine-JSON exporter; default non-persistent.

The endgame never calls a provider, never spends tokens, never dispatches, never enables runtime, never persists evidence without the explicit `--persist-completion-evidence` flag, never signs for an operator and never declares the Atlas Self-Construction OS complete.

## Scope

- Input: optional `real_provider_smoke`, optional `persist_completion_evidence` (bool), optional `completion_audit` payload.
- Output: single endgame envelope with 5 status states, 9 contracts, 16 ordered operator steps, exact commands, stop conditions, anti-cheat policy, non-execution guarantees, a read-only `operator_submission_envelope`, a `persistence_attempt` block (always honest about what actually happened) and a deterministic `real_provider_smoke_endgame_hash`.

## Non-Goals

- The endgame is not a provider client.
- The endgame is not a primary persistence path — persistence always flows through the existing certifier with the explicit flag.
- The endgame is not the certifier itself — it cannot independently promote the blocker green.
- The endgame is not the completion audit — it cannot declare the OS complete.
- The endgame is not a signer.

## Inputs

| Key                              | Type                | Required | Notes                                                                              |
| -------------------------------- | ------------------- | -------- | ---------------------------------------------------------------------------------- |
| `real_provider_smoke`            | array<string,mixed> | no       | If absent, status is `blocked_operator_real_provider_smoke_required`.              |
| `persist_completion_evidence`    | bool                | no       | Default false. When true, the certifier is invoked only if the verifier passes.    |
| `completion_audit`               | array<string,mixed> | no       | If absent, the embedded blocker_explainer reports `no_completion_audit_supplied`.  |

## Diagnostics

The endgame returns 5 status values:

- `blocked_operator_real_provider_smoke_required` — no payload was supplied.
- `ready_for_operator_smoke_execution` — payload supplied but more work needed.
- `ready_to_verify_smoke_payload` — payload partially filled.
- `verifier_passed_ready_for_explicit_persistence` — verifier green, no persistence requested.
- `smoke_persisted_completion_evidence_should_be_rerun` — verifier green AND certifier green AND persist flag passed.

The `persistence_attempt` block always reports `requested`, `attempted`, `persisted`, and the specific `blocker` (`persist_completion_evidence_flag_not_supplied`, `no_smoke_payload_supplied`, `endgame_verifier_blocked` or none on success). The endgame never lies about whether it persisted.

## Synthetic Fixture Policy

The endgame verifier explicitly rejects values containing `synthetic`, `fixture-only`, `fixture_only`, `test_only`, `test-only`, `fake`, `simulated`, `mock-`, `dummy` or `placeholder` substrings in identity fields. Test fixtures used by the suite are hash-coherent but always flagged `not_operator_real_until_observed=true`.

## Anti-Cheat Policy

```
no_synthetic_smoke_accepted
no_fixture_substitution_for_real_smoke
atlas_must_not_call_provider
atlas_must_not_spend_tokens
operator_must_observe_real_provider_run
persistence_requires_explicit_flag
no_os_complete_claim_from_endgame
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

## Ordered Operator Steps

1. `review_offline_harness`
2. `sign_operator_approval_for_single_packet`
3. `prepare_claim_to_completion_task_packet`
4. `run_real_provider_path_outside_read_only_surface`
5. `collect_provider_run_id`
6. `collect_provider_response_hash`
7. `collect_cost_event_hash`
8. `collect_work_product_manifest_hash`
9. `collect_continuation_summary_hash`
10. `collect_evidence_ledger_hash`
11. `build_smoke_preimage`
12. `compute_smoke_hash`
13. `verify_smoke_payload`
14. `persist_with_explicit_flag_only`
15. `rerun_completion_evidence_status`
16. `rerun_completion_audit`

## Verification Commands

```
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEndgameService.php
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEndgameVerifierService.php
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService.php
php -l app/Services/Ai/SelfConstruction/AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService.php
php artisan test --filter='RealProviderSmokeEndgame|RealProviderSmokeEvidenceLedgerPreflight|RealProviderSmokeOperatorRunbookExporter'
php -d memory_limit=512M ./vendor/bin/pint <touched files>
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
```

## Completion Boundaries

The endgame is necessary but not sufficient to close `end_to_end_real_provider_smoke_green`. The blocker only closes when ALL of the following are true:

1. The operator observes a live real provider claim-to-completion run.
2. The operator supplies real, non-synthetic evidence: provider_run_id, task_packet_id, observed_by, approval_reason, plus 7 hashes (smoke, operator_approval_receipt, evidence_ledger, work_product_manifest, cost_event, continuation_summary, provider_response).
3. The endgame verifier passes with zero diagnostics.
4. The certifier persists the payload via `--persist-completion-evidence`.
5. `completion-evidence-status` is re-run.
6. `completion-audit-status` is re-run and confirms the blocker is no longer in `failed_criteria`.

A fixture or test payload never closes the blocker. **Atlas Self-Construction OS is NOT complete after this slice.**

## Integration Patch Pending

`AtlasSelfConstructionReadinessService.php`, `AtlasAiSelfConstructionCommand.php` and `agent-control-plane-contract.md` were intentionally left untouched because parallel agents are modifying them concurrently in this session (they appear with the `M` flag in `git status` at the time of this slice). When those parallel slices land, apply the following:

### `AtlasSelfConstructionReadinessService.php`

Add a public method quartet for each new service:

1. `realProviderSmokeEndgameStatus(array $options = []): array` — calls `AtlasSelfConstructionRealProviderSmokeEndgameService::build($options)`.
2. `realProviderSmokeEndgameVerifierStatus(array $options = []): array` — calls `AtlasSelfConstructionRealProviderSmokeEndgameVerifierService::verify((array) ($options['real_provider_smoke'] ?? []))`.
3. `realProviderSmokeEvidenceLedgerPreflightStatus(array $options = []): array` — calls `AtlasSelfConstructionRealProviderSmokeEvidenceLedgerPreflightService::preflight((array) ($options['real_provider_smoke'] ?? []))`.
4. `realProviderSmokeOperatorRunbookExporterStatus(array $options = []): array` — calls `AtlasSelfConstructionRealProviderSmokeOperatorRunbookExporterService::build(['persist_export' => (bool) ($options['persist_export'] ?? false)])`.

Add to `capabilities()` these 20 new capability ids (5 per service):

```
atlas-self-construction-real-provider-smoke-endgame-status
atlas-self-construction-real-provider-smoke-endgame-blocker-corridor
atlas-self-construction-real-provider-smoke-endgame-ordered-operator-steps
atlas-self-construction-real-provider-smoke-endgame-non-execution-guarantees
atlas-self-construction-real-provider-smoke-endgame-anti-cheat-policy
atlas-self-construction-real-provider-smoke-endgame-verifier-status
atlas-self-construction-real-provider-smoke-endgame-verifier-diagnostic-codes
atlas-self-construction-real-provider-smoke-endgame-verifier-can-persist
atlas-self-construction-real-provider-smoke-endgame-verifier-not-operator-real-until-observed
atlas-self-construction-real-provider-smoke-endgame-verifier-hash
atlas-self-construction-real-provider-smoke-evidence-ledger-preflight-status
atlas-self-construction-real-provider-smoke-evidence-ledger-preflight-required-hashes
atlas-self-construction-real-provider-smoke-evidence-ledger-preflight-can-call-certifier
atlas-self-construction-real-provider-smoke-evidence-ledger-preflight-blocker-count
atlas-self-construction-real-provider-smoke-evidence-ledger-preflight-non-execution
atlas-self-construction-real-provider-smoke-operator-runbook-exporter-status
atlas-self-construction-real-provider-smoke-operator-runbook-exporter-markdown
atlas-self-construction-real-provider-smoke-operator-runbook-exporter-machine-json
atlas-self-construction-real-provider-smoke-operator-runbook-exporter-stop-conditions
atlas-self-construction-real-provider-smoke-operator-runbook-exporter-persist-export
```

### `AtlasAiSelfConstructionCommand.php`

Add four CLI flags routed to the quartet:

```
--atlas-self-construction-real-provider-smoke-endgame-status
--atlas-self-construction-real-provider-smoke-endgame-verifier-status
--atlas-self-construction-real-provider-smoke-evidence-ledger-preflight-status
--atlas-self-construction-real-provider-smoke-operator-runbook-exporter-status
```

Reuse `--real-provider-smoke-json=@path`, `--completion-audit-json=@path`. Add `--persist-export` for the runbook exporter (persists only the markdown artifact, never evidence). The existing `--persist-completion-evidence` flag stays as the sole route to persisting real provider smoke evidence.

### `agent-control-plane-contract.md`

Add a single canonical bullet under the read-only services section:

```
- Real Provider Smoke Endgame v1: top-level read-only coordinator for
  end_to_end_real_provider_smoke_green. Bundles offline harness, runbook,
  dossier, draft, certifier, ledger preflight, endgame verifier and
  operator runbook exporter. Never calls a provider, never persists
  without explicit flag, never claims completion.
```

### Why Pending

Per the established rule: "Se detectar que AtlasSelfConstructionReadinessService.php, AtlasAiSelfConstructionCommand.php ou agent-control-plane-contract.md já foram alterados por outro agente durante sua execução, não sobrescreva." All three central files showed `M` at slice start. Integration deferred to a separate small slice owned by the agent that lands those central files last.

## Change Log

- 2026-05-15 — Initial entry. 4 services + 4 tests + this doc created as an isolated macro-slice. Readiness/CLI/contract integration deferred and documented above.

## Resumo

Endgame coordinator read-only para o blocker `end_to_end_real_provider_smoke_green`. Une offline harness, runbook, dossier, draft, certifier, ledger preflight, endgame verifier e operator runbook exporter em um payload unico. Nunca chama provider, nunca gasta token, nunca persiste sem flag explicito + verifier verde, nunca declara OS completo.

## Papel no Atlas

Superficie de fechamento ordenado para o blocker mais sensivel da completion audit. Da ao operador o corredor completo: aprovacao -> execucao manual -> coleta -> hash -> verifier -> persistencia explicita -> rerun audit.

## Onde Se Encaixa

Sub-modulo final do Atlas Self-Construction OS para o blocker do smoke real. Acima da Closure Execution Pack (que ja foi entregue em macro-slice anterior). Filho conceitual do agent-control-plane-contract.

## Contratos

Nove contratos canonicos (required_evidence, operator_approval, single_packet_scope, provider_observation, token_cost, work_product, continuation_summary, evidence_ledger, provider_response). Cada um traz regra + stop_condition. Persistencia exige o flag explicito `--persist-completion-evidence` + endgame verifier verde.

## Fluxo

Operador roda offline harness -> aprova escopo de packet unico -> roda smoke real fora do read-only -> coleta provider_run_id e cinco hashes -> monta preimage -> calcula smoke_hash -> roda endgame verifier -> roda ledger preflight -> persiste com flag explicito -> rerun completion evidence status -> rerun completion audit.

## Regras para IA

Nao chamar provider. Nao gastar token. Nao despachar. Nao habilitar runtime. Nao promover completion. Nao tratar test payload como smoke real. Nao integrar em readiness/CLI/contract sem que outros agentes liberem.

## Escopo de Implementacao

Quatro services em `app/Services/Ai/SelfConstruction/` (endgame, endgame verifier, evidence ledger preflight, operator runbook exporter), quatro tests em `tests/Feature/Ai/SelfConstruction/` e este doc.

## Dependencias

AtlasSelfConstructionRealProviderSmokeOfflineHarnessService, AtlasSelfConstructionRealProviderSmokeRunbookService, AtlasSelfConstructionRealProviderSmokeEvidenceDossierService, AtlasSelfConstructionRealProviderSmokeDraftService, AtlasSelfConstructionRealProviderSmokeCertificationService, AtlasSelfConstructionCompletionAuditBlockerExplainerService, AtlasSelfConstructionFinalEvidenceBundleService (resolvido via container), AtlasSelfConstructionCompletionEvidenceHashService.

## Evidencias

Tests focados verdes para cada um dos 4 services, pint verde, php -l verde, RealProviderSmoke regression verde, real_provider_smoke_endgame_hash determinista, no operator evidence path tocado em Storage::fake (exceto o runbook exporter quando `persist_export=true`, e mesmo nesse caso so escreve o markdown do runbook, nao a evidencia).

## Riscos

Operador interpretar endgame como certifier. Persistir smoke sem endgame verifier verde. Outros agentes mexerem em readiness/CLI/contract antes da integration patch pending ser feita.

## Exemplos

Operador chama `build(['real_provider_smoke' => $candidate, 'persist_completion_evidence' => false])`, ve `endgame_verifier_result.status=blocked` com diagnostics `missing_provider_run_id` e `invalid_cost_event_hash`. Operador corrige, rerun, ve `verifier_passed_ready_for_explicit_persistence`. Operador adiciona `persist_completion_evidence=true`, endgame chama certifier e marca `persistence_attempt.persisted=true`. Operador roda completion audit rerun e ve o blocker fora de failed_criteria.

O `operator_submission_envelope` acompanha esse fluxo sem escrever nada: quando o smoke payload passa pelo endgame verifier e pelo certifier, ele expõe o payload exato sob revisão, `smoke_json_sha256`, `smoke_hash`, comando de persistência com `--persist-completion-evidence`, checks pré-persistência e comandos de rerun. Quando o payload está ausente ou inválido, o envelope fica bloqueado e aponta o estágio exato (`blocked_until_operator_smoke_payload_exists`, `blocked_until_endgame_verifier_passes` ou `blocked_until_smoke_certification_passes`).

## Proximas Acoes

Aguardar parallel agents terminarem suas slices centrais. Integrar o quartet de cada service em ReadinessService + CLI + bullet em agent-control-plane-contract.md em macro-slice proprio. Manter a endgame sincronizada quando o certifier ganhar campos novos.
