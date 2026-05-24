---
id: atlas-forge-rivals-industrial-execution-suite-v1
type: engineering_knowledge
title: Atlas Forge Rivals Industrial Execution Suite v1
status: active
category: programming-forge
priority: 94
summary: Canon de readiness executavel local para case-sets industriais, incluindo industrial-50 e baterias extremas, com fixtures deterministicas, local_fake sem provider e claim gates fail-closed.
tags:
  - atlas
  - forge
  - rivals
  - industrial-execution
  - local-fake
  - atlas-decide
capabilities:
  - industrial_execution_readiness
  - extreme_differentiator_execution_readiness
  - meta_provider_stress_execution_readiness
  - deterministic_fixture_materialization
  - local_fake_evidence_replay_matrix_path
  - statistical_repeat_readiness_block
decisions:
  - industrial-50 e os case-sets industriais extremos devem ser executaveis localmente antes de qualquer provider real.
  - local_fake prova harness, nao claim real.
  - external_rivals_certification permanece bloqueado ate aprovacao humana.
  - Rivals emits measured evidence; Atlas Decide decides model routing.
maintenance:
  - Atualizar quando novos presets industriais ganharem fixtures executaveis.
  - Nao iniciar provider real a partir de readiness.
  - Manter invariantes advisory-only para Atlas Decide.
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsIndustrialExecutionSuiteService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsIndustrialExecutionSuiteCertification.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsIndustrialExecutionSuiteTest.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-industrial-execution-suite-v1
graph_title: Atlas Forge Rivals Industrial Execution Suite v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-industrial-benchmark-suite-v1
graph_status: active
graph_source: repo
human_name: Atlas Forge Rivals Industrial Execution Suite v1
canonical_name: Atlas Forge Rivals Industrial Execution Suite v1
technical_name: atlas-forge-rivals-industrial-execution-suite-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-industrial-execution-suite-v1.md
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsIndustrialExecutionSuiteService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php
allowed_changes:
  - Adicionar fixtures executaveis locais por case industrial.
  - Expandir execution readiness para outros presets industriais sem provider call.
forbidden_changes:
  - Iniciar provider real a partir de readiness.
  - Promover local_fake como claim real.
  - Desbloquear external_rivals_certification sem aprovacao humana.
  - Alterar topology do Atlas Decide.
depends_on:
  - atlas-forge-rivals-industrial-benchmark-suite-v1
  - atlas-forge-rivals-provider-arena-v2
flows_to:
  - atlas-forge-rivals-intelligence-ledger-v1
  - atlas-decide
unlocks:
  - industrial_local_fake_harness_proof
governs:
  - industrial_execution_readiness
  - industrial_local_fake_evidence_path
evidence:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsIndustrialExecutionSuiteTest.php
  - "php artisan atlas:forge:rivals industrial-execution --case-set=industrial-50 --json"
  - "php artisan atlas:forge:rivals industrial-execution --case-set=extreme-differentiator --json"
  - "php artisan atlas:forge:rivals industrial-execution --case-set=meta-provider-stress --json"
  - "php artisan atlas:forge:rivals run-battery --preset=industrial-50 --mode=local_fake --json"
required_tests:
  - "php artisan test --filter='AtlasForgeRivalsIndustrialExecutionSuiteTest'"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
next_actions:
  - Rodar provider real somente com confirmacoes explicitas do operador e worktree disk gate verde.
  - Completar repeticoes reais antes de qualquer confidence estatistica.
---
# Atlas Forge Rivals Industrial Execution Suite v1

Status: canonical local execution readiness.

Canonical phrase: “Rivals emits measured evidence; Atlas Decide decides model routing.”

## Purpose

`atlas-forge-rivals-industrial-execution-suite-v1` turns the industrial benchmark specs into an executable local harness for `industrial-50`, broader industrial presets and the high-difficulty `meta-provider-stress` / `extreme-differentiator` batteries.

It is not a provider run. It does not spend tokens. It does not unlock `external_rivals_certification`. It proves that fixtures, evidence paths, replay gates and matrix/report prerequisites can execute locally before an operator authorizes any real provider benchmark.

## Resumo

Execution Suite v1 torna case-sets industriais executaveis localmente com fixtures reais, readiness JSON e caminho `local_fake` completo. Isso inclui `industrial-50`, `industrial-100`, `industrial-200`, presets tematicos industriais, `meta-provider-stress` e `extreme-differentiator`.

## Papel no Atlas

Rivals mede evidencia industrial local. Atlas Decide continua dono exclusivo de model routing.

## Onde Se Encaixa

Fica entre o corpus industrial e o executor Rivals: materializa fixtures, valida contaminacao e libera apenas execucao `local_fake` sem provider.

## Contratos

Readiness deve retornar o minimo canonico de casos executaveis para o case-set solicitado, sem seeds vazios, com testes, expected changed files, oracle metadata e evidence requirements. Exemplos: `industrial-50=50`, `meta-provider-stress=50`, `extreme-differentiator=80`.

## Fluxo

1. `industrial-execution` valida/materializa fixtures.
2. `run-battery --preset=<case-set-industrial> --mode=local_fake` executa RunReal, collect-evidence, replay, adjudicator e report.
3. Claims fortes continuam bloqueados sem execucao real, matrix e confianca.

## Regras para IA

Nao iniciar provider real. Nao gastar tokens. Nao promover local_fake como benchmark real. Nao alterar Atlas Decide topology.

## Escopo de Implementacao

Escopo atual: case-sets industriais executaveis, exceto `statistical-repeat`, que permanece bloqueado para confidence ate existirem repeticoes reais. `extreme-differentiator` e `meta-provider-stress` sao readiness executavel para diagnosticar empates, separar capacidades e elevar a pressao quando 40 casos ou L5 valido ainda terminam em empate. O report deve expor `difficulty_pressure.status` para deixar claro se o teto pratico de dificuldade foi atingido ou se a proxima bateria precisa subir para presets extremos.

## Dependencias

- Provider Arena corpus.
- RunBattery/RunReal.
- Evidence/replay/adjudicator/report Rivals.

## Evidencias

- `AtlasForgeRivalsIndustrialExecutionSuiteTest`.
- `industrial-execution --case-set=industrial-50 --json`.
- `industrial-execution --case-set=extreme-differentiator --json`.
- `industrial-execution --case-set=meta-provider-stress --json`.
- `run-battery --preset=industrial-50 --mode=local_fake --json`.

## Riscos

- Confundir `local_fake` com claim real.
- Permitir fixture contaminada.
- Emitir confidence estatistica sem repeticoes reais.

## Exemplos

```bash
php artisan atlas:forge:rivals industrial-execution --case-set=industrial-50 --json
```

## Proximas Acoes

Rodar provider real somente com confirmacoes explicitas e worktree disk gate verde. Completar `statistical-repeat` com repeticoes reais antes de confidence estatistica.

## Scope

Implemented scope:

- `industrial-50` readiness with exactly 50 executable cases.
- `meta-provider-stress` readiness with at least 50 executable cases.
- `extreme-differentiator` readiness with at least 80 executable cases.
- Broader industrial case-set readiness uses each case-set minimum from the canonical corpus registry.
- Deterministic local fixtures under `storage/forge-rivals-corpus/<case_id>/seed`.
- Fixture payloads stage into `storage/forge-rivals-industrial/<case_id>/...` inside isolated worktrees.
- Per-case metadata remains canonical: `case_id`, category, difficulty L1-L5, task type, scope, acceptance criteria, evidence requirements, invalid-if rules, expected changed files, scoring dimensions, oracle metadata.
- `local_fake` execution remains provider-free and token-free.
- Strong benchmark claims remain blocked until real execution evidence, replay, scorecard, adjudicator, matrix and confidence gates are complete.

Runtime hardening:

- Industrial and explicit corpus cases may use `minimal_no_checkout` worktrees to avoid disk-heavy full checkouts.
- Minimal worktrees must materialize only Composer runtime contract files plus staged fixture files; missing repo files from `--no-checkout` are not provider changes.
- Runtime noise such as `vendor/`, `.env` and `.env.testing` is excluded from scope scoring; staged fixture deletions remain blockers.
- Generated industrial variants must keep `setup_fixture.seed_dir`, `fixture_seed_path`, `test_command`, `quick_test_command` and `expected_changed_files` retargeted to the same case id.
- Evidence collection must tolerate large real-run JSON/diff artifacts without depending on the operator to pass a custom PHP memory limit.
- A valid tie is diagnostic evidence, not a claim. Escalate to `extreme-differentiator`, category slices and repeated real runs before promoting confidence.
- Matrix reports must aggregate `measured_capabilities` from industrial cases so an aggregate tie still exposes what was actually measured: security, rollback, ambiguity handling, replay quality, scope discipline, long-context retention, performance tradeoffs and other 360 axes.
- `arena-readiness` must expose the same pre-provider evidence disk guard used by real runs. Dry-run planning remains available, but real-run readiness becomes `plan_ready_evidence_disk_blocked` when the configured evidence floor is not met.

Out of scope:

- Starting Claude/Codex/Gemini or any real provider.
- Spending provider tokens.
- Promoting external marketing claims.
- Changing Atlas Decide model topology.

## Commands

Readiness:

```bash
php artisan atlas:forge:rivals industrial-execution --case-set=industrial-50 --json
php artisan atlas:forge:rivals industrial-execution --case-set=meta-provider-stress --json
php artisan atlas:forge:rivals industrial-execution --case-set=extreme-differentiator --json
php artisan atlas:forge:rivals arena-readiness --json
```

Dry-run plan:

```bash
php artisan atlas:forge:rivals run-battery --preset=extreme-differentiator --mode=local_fake --dry-run --json
```

Local fake execution:

```bash
php artisan atlas:forge:rivals run-battery --preset=extreme-differentiator --mode=local_fake --json
```

Case listing:

```bash
php artisan atlas:forge:rivals cases --case-set=extreme-differentiator --json
```

## Readiness Contract

The readiness JSON must expose:

- `total_cases`
- `executable_cases`
- `required_cases`
- `execution_case_sets`
- `missing_fixtures`
- `empty_seed_cases`
- `missing_tests`
- `missing_expected_changed_files`
- `oracle_metadata_status`
- `evidence_requirements_status`
- `claim_status`
- `external_provider_call=false`
- `provider_tokens_spent=false`

Any fixture contamination blocks execution with explicit blockers instead of ambiguous failures.

## Claim Policy

`local_fake` is a harness proof, not a real benchmark claim. A strong claim requires all of:

- minimum 50 valid executable cases;
- complete evidence pack;
- green replay;
- scorecard per case;
- green adjudicator;
- green matrix report;
- statistical repetitions when required;
- explicit confidence;
- human approval for external certification.

`external_rivals_certification` remains `blocked_requires_human_approval`.

## 360 Capability Diagnosis

When release-sized or extreme batteries tie, the next step is not to lower the
threshold or promote a claim. The next step is to inspect the matrix capability
ranking and run harder slices for under-sampled axes.

`matrix-report` emits `capability_ranking` with:

- capability rows expanded from `measured_capabilities`,
  `extreme_differentiator.capability_axes`, measurement tags and context profile
  dimensions;
- per-capability Atlas/Rival/tie counts and average scores;
- per-capability `separation_state`, so a full-floor tie is not confused with a
  real runner-strength signal;
- `missing_required_capabilities` and `under_sampled_required_capabilities`;
- `next_measurement_plan` with concrete dry-run commands and case ids for the
  next extreme samples;
- `differentiation` diagnosis with `low_differentiation` when required
  capabilities are covered but still tied, and `differentiated` only when
  repeated L5 evidence separates enough required axes;
- a dry-run-only battle matrix per missing capability, covering Atlas Forge vs
  Claude Sonnet, Atlas Dev vs Atlas Forge, Composer 2.5 vs Codex GPT-5.5,
  Cursor default vs Claude Sonnet, Claude Sonnet vs Codex GPT-5.5, Codex
  GPT-5.5 vs Gemini Pro, Claude Sonnet vs Claude Opus and Atlas Forge
  full_power vs Claude Opus where configured;
- advisory-only invariants for Atlas Decide.

This makes an L5 tie useful: it can say "tied on rollback safety with one
sample", "covered the 360 floor but separated nothing", or "not enough security
fail-closed probes", instead of collapsing everything into a flat global empate.
Rivals emits measured evidence; Atlas Decide decides model routing.

Capability aliases are part of the evidence contract. `scope_boundary_probe`
counts as `scope_boundary_discipline`, while `assumption_quality`,
`ambiguity_resolution` and `ambiguity_handling` count as
`ambiguous_human_prompt_handling`. This keeps "what the runner is good at"
separate from broad prompt ambiguity.

## Statistical Repeat

`statistical-repeat` readiness is present, but confidence remains blocked until real repeated executions exist for the required repetition groups. The suite must report `confidence_ready=false` and `statistical_repetitions_missing` until then.

## Extreme 360 Follow-up

A 40-case tie is a valid diagnostic, not a conclusion. Battery reports must emit `extreme_measurement_plan.v1` when separation is weak, including:

- category slices for the eight canonical categories;
- capability slices for long context, multi-step reasoning, rollback safety, scope discipline, replay evidence, honest blockers, and ambiguous human prompts;
- `extreme-differentiator` restricted to L5 cases only, with 80 generated variants and no L4 escape hatch;
- extreme cases hardened for differentiation, not throughput: every case is L5, high ambiguity and at least high risk, so ties are less likely to hide capability gaps;
- per-case `measured_capabilities` axes, including rollback safety, security fail-closed, performance tradeoff quality, data safety, flake isolation, observability, scope-boundary probing, and assumption quality;
- explicit single industrial cases materialized through industrial execution readiness with `required_cases=1`, while full case-set claims still require the case-set floor;
- a shared pre-provider evidence disk guard in RunBattery and Provider Arena v2; if the run directory cannot preserve evidence, the pipeline blocks before `RunRealService` and reports `provider_evidence_disk_space_insufficient`;
- dry-run commands for Atlas Forge vs Claude, Claude vs Codex, Composer 2.5 vs Codex, Cursor vs Claude, Atlas Dev vs Atlas Forge, and Codex vs Gemini;
- `external_provider_call=false` and `provider_tokens_spent=false` for the plan itself;
- explicit confirmations before any real provider run.

The plan answers "what should we run next to know who is actually good at what" without promoting a winner or changing routing.

## Atlas Decide Boundary

All machine-readable outputs remain advisory-only:

```json
{
  "advisory_only": true,
  "should_update_provider_topology": false,
  "never_changes_atlas_decide_topology": true,
  "owner_of_model_routing": "atlas_decide",
  "routing_effect": "none"
}
```

Rivals emits measured evidence; Atlas Decide decides model routing.
