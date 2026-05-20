---
id: atlas-forge-rivals-industrial-execution-suite-v1
type: engineering_knowledge
title: Atlas Forge Rivals Industrial Execution Suite v1
status: active
category: programming-forge
priority: 94
summary: Canon de readiness executavel local para industrial-50, com fixtures deterministicas, local_fake sem provider e claim gates fail-closed.
tags:
  - atlas
  - forge
  - rivals
  - industrial-execution
  - local-fake
  - atlas-decide
capabilities:
  - industrial_50_execution_readiness
  - deterministic_fixture_materialization
  - local_fake_evidence_replay_matrix_path
  - statistical_repeat_readiness_block
decisions:
  - industrial-50 deve ser executavel localmente antes de qualquer provider real.
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
  - industrial_50_local_fake_harness_proof
governs:
  - industrial_execution_readiness
  - industrial_local_fake_evidence_path
evidence:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsIndustrialExecutionSuiteTest.php
  - "php artisan atlas:forge:rivals industrial-execution --case-set=industrial-50 --json"
  - "php artisan atlas:forge:rivals run-battery --preset=industrial-50 --mode=local_fake --json"
required_tests:
  - "php artisan test --filter='AtlasForgeRivalsIndustrialExecutionSuiteTest'"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
next_actions:
  - Expandir fixtures executaveis para industrial-100 e industrial-200.
  - Rodar provider real somente com confirmacoes explicitas do operador.
---

# Atlas Forge Rivals Industrial Execution Suite v1

Status: canonical local execution readiness.

Canonical phrase: “Rivals emits measured evidence; Atlas Decide decides model routing.”

## Purpose

`atlas-forge-rivals-industrial-execution-suite-v1` turns the industrial benchmark specs into an executable local harness, starting with `industrial-50`.

It is not a provider run. It does not spend tokens. It does not unlock `external_rivals_certification`. It proves that fixtures, evidence paths, replay gates and matrix/report prerequisites can execute locally before an operator authorizes any real provider benchmark.

## Resumo

Execution Suite v1 torna `industrial-50` executavel localmente com fixtures reais, readiness JSON e caminho `local_fake` completo.

## Papel no Atlas

Rivals mede evidencia industrial local. Atlas Decide continua dono exclusivo de model routing.

## Onde Se Encaixa

Fica entre o corpus industrial e o executor Rivals: materializa fixtures, valida contaminacao e libera apenas execucao `local_fake` sem provider.

## Contratos

Readiness deve retornar 50 casos executaveis, sem seeds vazios, com testes, expected changed files, oracle metadata e evidence requirements.

## Fluxo

1. `industrial-execution` valida/materializa fixtures.
2. `run-battery --preset=industrial-50 --mode=local_fake` executa RunReal, collect-evidence, replay, adjudicator e report.
3. Claims fortes continuam bloqueados sem execucao real, matrix e confianca.

## Regras para IA

Nao iniciar provider real. Nao gastar tokens. Nao promover local_fake como benchmark real. Nao alterar Atlas Decide topology.

## Escopo de Implementacao

Escopo atual: `industrial-50`. Outros presets industriais permanecem specs/readiness de benchmark ate ganharem fixtures executaveis.

## Dependencias

- Provider Arena corpus.
- RunBattery/RunReal.
- Evidence/replay/adjudicator/report Rivals.

## Evidencias

- `AtlasForgeRivalsIndustrialExecutionSuiteTest`.
- `industrial-execution --case-set=industrial-50 --json`.
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

Expandir execution readiness para `industrial-100`/`industrial-200` e rodar provider real somente com confirmacoes explicitas.

## Scope

Implemented scope:

- `industrial-50` readiness with exactly 50 executable cases.
- Deterministic local fixtures under `storage/forge-rivals-corpus/<case_id>/seed`.
- Fixture payloads stage into `storage/forge-rivals-industrial/<case_id>/...` inside isolated worktrees.
- Per-case metadata remains canonical: `case_id`, category, difficulty L1-L5, task type, scope, acceptance criteria, evidence requirements, invalid-if rules, expected changed files, scoring dimensions, oracle metadata.
- `local_fake` execution remains provider-free and token-free.
- Strong benchmark claims remain blocked until real execution evidence, replay, scorecard, adjudicator, matrix and confidence gates are complete.

Out of scope:

- Starting Claude/Codex/Gemini or any real provider.
- Spending provider tokens.
- Promoting external marketing claims.
- Changing Atlas Decide model topology.

## Commands

Readiness:

```bash
php artisan atlas:forge:rivals industrial-execution --case-set=industrial-50 --json
```

Dry-run plan:

```bash
php artisan atlas:forge:rivals run-battery --preset=industrial-50 --mode=local_fake --dry-run --json
```

Local fake execution:

```bash
php artisan atlas:forge:rivals run-battery --preset=industrial-50 --mode=local_fake --json
```

Case listing:

```bash
php artisan atlas:forge:rivals cases --case-set=industrial-50 --json
```

## Readiness Contract

The readiness JSON must expose:

- `total_cases`
- `executable_cases`
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

## Statistical Repeat

`statistical-repeat` readiness is present, but confidence remains blocked until real repeated executions exist for the required repetition groups. The suite must report `confidence_ready=false` and `statistical_repetitions_missing` until then.

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

Rivals measures evidence. Atlas Decide owns model routing.
