---
id: atlas-forge-rivals-provider-arena-core-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Provider Arena Core v1
status: active
category: programming-forge
priority: 88
summary: Slice 8 do Forge Rivals. Camada Provider Arena Core (registry de runners + arm contract + run envelope + cert v1) que permite operador rodar `arm_a vs arm_b` para uma categoria de tarefa via `atlas:forge:rivals run-arena`. Nunca destrava external_rivals_certification.
tags:
  - atlas
  - forge
  - rivals
  - provider-arena
  - registry
capabilities:
  - forge_rivals_provider_arena_core
  - forge_rivals_arm_registry
  - forge_rivals_arm_contract
decisions:
  - Sete arms canônicos vivem em `AtlasForgeRivalsArmRegistryService`; dropdown livre é proibido.
  - Categorias de tarefa formam um conjunto fechado de nove valores; categorias desconhecidas são bloqueadas honestamente.
  - `external_rivals_certification` permanece BLOCKED por construção em todas as camadas do Provider Arena.
maintenance:
  - Atualizar quando o registry ganhar arm novo, runner novo ou flag de safety nova.
  - Não desbloquear arm `placeholder` ou arm `not_yet_executable` fora de `local_fake`.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModeRegistry.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCasesRegistry.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderArenaCoreCertification.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-code-provider-arena-ui-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-provider-arena-core-v1
graph_title: Atlas Forge Rivals · Provider Arena Core v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
human_name: "Atlas Forge Rivals · Provider Arena Core v1"
canonical_name: "Atlas Forge Rivals · Provider Arena Core v1"
technical_name: atlas-forge-rivals-provider-arena-core-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/Arms/AtlasForgeRivalsArmRegistryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsArenaRunService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModeRegistry.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCasesRegistry.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderArenaCoreCertification.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-arena-core-v1.md
allowed_changes:
  - Adicionar arm novo ao registry com runner declarado e safety contract completo.
  - Adicionar categoria nova ao cases registry sob o conjunto fechado.
forbidden_changes:
  - Listar arms fora do registry.
  - Rodar arm `placeholder` em modo real.
  - Tratar categoria não declarada como válida.
  - Promover `external_rivals_certification` a partir do veredito da arena.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2
flows_to:
  - atlas-code-provider-arena-ui-v1
unlocks:
  - operator_runs_arm_a_vs_arm_b_with_canonical_arms_and_categories
governs:
  - forge_rivals_provider_arena_core
evidence:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderArenaCoreTest.php
evidence_refs:
  - test: AtlasForgeRivalsProviderArenaCoreTest
  - symbol: AtlasForgeRivalsArmRegistryService
required_tests:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderArenaCoreTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Liberar drivers scripted/manual/gemini conforme amadurecerem.
  - Manter doc sincronizado com novos arms/categorias.
---
# Atlas Forge Rivals · Provider Arena Core v1

**Strategy canon:** `atlas-forge-rivals-benchmark-strategy-v1.md`
**Run schema:** `atlas.forge.rivals.provider_arena_run.v1`
**Registry schema:** `atlas.forge.rivals.runner_registry.v1`
**Contract schema:** `atlas.forge.rivals.arm_contract.v1`
**Certification:** `atlas_forge_rivals_provider_arena_core_certification` (v1)
**Status:** Slice 8 delivered 2026-05-15

This doc is the canonical contract for the Provider Arena Core layer that
sits on top of the Perfect Battery & Adjudicator v1. It lets the operator
run any declared `arm_a` vs `arm_b` for a given task category, without
breaking the legacy `run-battery` command and without weakening any safety
gate.

Read first: `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`.

> **External rivals canon.** Provider Arena Core v1 NEVER unlocks
> `external_rivals_certification`. That cert stays operator-approval-gated
> and separately tracked. Every layer (registry, contract, arena run, cert,
> this doc) restates the rule.

---

## 1. The arms

Eight canonical arms live in `AtlasForgeRivalsArmRegistryService`. Every
arm declares: `arm_id`, `runner_type`, `provider`, `model_options`,
`execution_mode`, `requires_external_provider_call`,
`requires_cost_confirmation`, `supports_streaming`, `supports_replay`,
`supports_patch_diff`, `supports_test_log`, `allowed_task_categories`,
`safety_contract`, `status`, `human_label`, `human_description`.

| arm_id | runner_type | provider | status |
| --- | --- | --- | --- |
| `atlas_forge` | forge | claude | available |
| `atlas_dev` | atlas_dev | claude | available |
| `claude_code` | cli_provider | claude | available |
| `codex_cli` | cli_provider | codex | available |
| `gemini_cli` | cli_provider | gemini | available |
| `scripted_runner` | scripted | — | not_yet_executable |
| `manual_runner` | manual | — | not_yet_executable |
| `future_runner` | placeholder | — | placeholder |

**Not-yet-executable arms** block honestly outside `local_fake` mode with
`arm_runner_not_yet_executable:<arm_id>`. **Placeholder arms** always block
with `future_runner_is_placeholder_only`. UIs and audits can still render
them because they exist in the registry snapshot.

`atlas_dev` is the daily-use middle layer between raw provider
and Forge: Sonnet-only, low call budget, scoped context, short plan, patch,
focused tests, simple verification, and escalation to Forge on high risk or
failure. It is executable through the same governed Claude command builder as
Forge, with a distinct Atlas Dev role prompt and the same three-confirmation
provider-spend gate.

`atlas_dev_light` remains accepted as a legacy alias, but `atlas_dev` is the
canonical arm id for new Provider Arena v2 runs.

`safety_contract` for every arm carries: `never_promotes_completion_claim`,
`never_unlocks_external_rivals_certification`,
`requires_three_confirmations_for_real_provider`,
`max_score_without_evidence=0`, `fails_closed_on_missing_driver`,
`audit_trail_required`, `replay_required_before_winner`,
`evidence_required_before_winner`, `scripted_or_manual_cannot_forge_score`,
`placeholder_blocks_real_run`.

## 2. Task categories

Nine canonical task categories: `frontend`, `backend`, `bugfix`, `tests`,
`refactor`, `architecture`, `docs`, `performance`, `security`. Arena run
rejects any category outside this list with `task_category_unknown:<x>`.
Each arm can restrict its supported categories — running a scripted runner
on `frontend` produces `arm_b_task_category_not_supported_by_arm:...`.

## 3. The single arena command

```bash
php artisan atlas:forge:rivals run-arena \
  --arm-a=atlas_forge   --arm-a-model=sonnet \
  --arm-b=claude_code   --arm-b-model=sonnet \
  --task-category=bugfix \
  --mode=fair --preset=release \
  --confirm-runbook-reviewed \
  --confirm-provider-cost \
  --confirm-real-provider-call \
  --json --strict
```

The service:

1. Validates mode (`fair|full_power|provider_arena|provider_pure|local_fake`; `power` is an alias).
2. Resolves both arm contracts via `AtlasForgeRivalsArmContractService`.
3. Validates the task category against the registry + each arm's allowed list.
4. Requires the three operator confirmations whenever any arm requires a
   real provider (skipped for `local_fake`).
5. In `fair` mode: enforces same provider + same model across both arms.
6. For `provider_arena` / `provider_pure` / explicit-arm `full_power`, passes
   explicit `arena_contracts` into `AtlasForgeRivalsRunRealService`, then runs
   setup → run-real → collect-evidence → replay → adjudicate → report.
7. For legacy `fair/local_fake`, preserves the RunBatteryService bridge unless
   an arena-specific mode requires the v2 executor.
8. Decorates the response with arena context (`arena_schema_version`,
   `arm_a`, `arm_b`, `task_category`, `safety_promises`) so UIs can render
   the result without re-walking the legacy fields.

**Aliases:** `arena`, `run-arena-real`, `arena-run`, `provider-arena` →
`run-arena`. `list-arms`, `registry`, `runners` → `arms`.

## 4. The `arms` introspection action

```bash
php artisan atlas:forge:rivals arms --json
```

Returns the registry snapshot (schema `atlas.forge.rivals.runner_registry.v1`):
all eight arms, their flags, the canonical task category list, generation
timestamp, separation note. Read-only. No provider call.

## 5. Three operator confirmations (real provider)

Provider Arena Core enforces the same three flags as Perfect Battery:
`--confirm-runbook-reviewed`, `--confirm-provider-cost`,
`--confirm-real-provider-call`. The check runs at the arena layer **before**
delegating to RunBatteryService, and RunBatteryService re-checks
independently (defense in depth). `local_fake` mode skips the flags
entirely — no provider is ever invoked.

## 6. local_fake end-to-end

`--mode=local_fake` is the canonical safe smoke. Both arms run as the
in-process fake provider; no `claude` / `codex` / `gemini` binary is ever
invoked. The adjudicator still hard-fails because `patch_diff_present_*`
will be zero for the fake — that is the correct behaviour: even arena
fakes cannot forge a score.

## 7. Safety rules (non-negotiable)

- The arena layer NEVER unlocks `external_rivals_certification`.
- Real-provider arms require all three confirmations simultaneously.
- Scripted/manual runners cannot forge a score: `max_score_without_evidence=0`.
- Cross-provider runs are forbidden in `fair` mode (same provider + same model).
- `not_yet_executable` arms block honestly outside `local_fake`; executable arms still block before provider spend when driver/policy/confirmations are missing.
- `placeholder` arms block in every mode with `future_runner_is_placeholder_only`.
- All arm execution still flows through Perfect Battery's hard gates
  (verdict_comparable, replay_passes, evidence_complete,
  no_out_of_scope_files, no_bytecode_artifacts, dirty_after_run_false,
  patch_diff_present).

## 8. Audit / certification

`audit --json` returns three certifications side by side:

- `atlas_forge_rivals_operator_battery_certification` (v2, 18 invariants)
- `atlas_forge_rivals_perfect_battery_certification` (v1, 12 invariants)
- `atlas_forge_rivals_provider_arena_core_certification` (v1 schema, Provider Arena v2 coverage, 18 invariants)

Worst status across the three is propagated to the action envelope.

### Provider Arena Core / v2 invariants

1. `arm_registry_available`
2. `arm_contract_service_available`
3. `arena_run_service_available`
4. `run_arena_action_wired`
5. `arms_action_exposes_registry`
6. `all_canonical_arms_declared`
7. `task_category_gate_enforced`
8. `declared_non_executable_blockers_honest`
9. `scripted_or_manual_cannot_forge_score`
10. `arena_real_provider_requires_three_confirmations`
11. `arena_never_unlocks_external_rivals`
12. `arena_doc_canonical`
13. `provider_model_registry_available`
14. `models_action_exposes_registry`
15. `arm_command_builder_centralized`
16. `provider_arena_modes_declared`
17. `provider_arena_real_executor_wired`
18. `arena_contracts_flow_to_manifest_report_signal`

## 9. Read-model contract (for the future Provider Arena UI)

The arena run response carries every field a premium enterprise Atlas Code
UI will need. UI work is out of scope for v1, but the read-model is
already UI-ready:

- `status` — operator-friendly state (`ok`, `blocked`)
- `arm_a` / `arm_b` — `arm_id`, `runner_type`, `provider`, `model`,
  `legacy_model_id`, `status`, `safety_contract`, `human_label`
- `task_category` — selected category
- `winner` — `atlas | rival | human_review_required_tie | null`
- `scorecard` — adjudicator output (atlas_score, rival_score, hard_failures, etc.)
- `phases` — pipeline phase trace with `ok` flags
- `external_provider_call` / `provider_tokens_spent` — honest booleans
- `evidence_paths` — concrete artifact paths
- `report_path` — `runs/<id>/evidence/report.md`
- `next_command` — copy-safe next action for the operator
- `note` — human-friendly summary

Field names use snake_case at the data plane; an adapter layer is expected
to convert to camelCase for Atlas Code UI consumption in a later slice.

## 10. Related docs

- `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`
- `atlas-forge-rivals-operator-battery-v2.md`
- `atlas-forge-rivals-real-battery-operator-harness-v1.md`
- `atlas-forge-rivals-reliability-lockdown-v1.md`

## Resumo

Slice 8 do Forge Rivals: registry canônico de sete arms (`atlas_forge`, `claude_code`, `codex_cli`, `gemini_cli`, `scripted_runner`, `manual_runner`, `future_runner`), arm contract com nove categorias de tarefa, run envelope e cert v1. Entrypoint `atlas:forge:rivals run-arena` parea `arm_a vs arm_b` por categoria.

## Papel no Atlas

Camada Provider Arena Core que sobe acima do Perfect Battery & Adjudicator. Define quem pode competir (registry), em quais categorias (cases) e sob quais invariantes de safety (arm contract).

## Onde Se Encaixa

Dependência direta de `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`. Consumido pela UI em `atlas-code-provider-arena-ui-v1.md`. Compartilha o adjudicator local determinístico com o `run-battery`.

## Contratos

Schemas: `atlas.forge.rivals.provider_arena_run.v1` (run envelope), `atlas.forge.rivals.runner_registry.v1` (registry), `atlas.forge.rivals.arm_contract.v1` (contrato por arm). Cert: `atlas_forge_rivals_provider_arena_core_certification` (v1). Cada arm carrega `safety_contract` com `never_promotes_completion_claim`, `never_unlocks_external_rivals_certification`, `requires_three_confirmations_for_real_provider`, `max_score_without_evidence=0`, `fails_closed_on_missing_driver`, `audit_trail_required`, `replay_required_before_winner`, `evidence_required_before_winner`, `scripted_or_manual_cannot_forge_score`, `placeholder_blocks_real_run`.

## Fluxo

Operador escolhe arm_a e arm_b do registry → escolhe categoria do cases → define modo (`local_fake` default; `fair`/`full_power` exigem 3 confirmações) → `run-arena` aciona evidence → replay → adjudicate → report.

## Regras para IA

Nunca expor arm fora do registry. Nunca rodar `placeholder` em modo real. Nunca tratar categoria não declarada como válida. Nunca promover `external_rivals_certification` a partir do veredito da arena.

## Escopo de Implementacao

`AtlasForgeRivalsArmRegistryService`, `AtlasForgeRivalsArenaRunService`, `AtlasForgeRivalsModeRegistry`, `AtlasForgeRivalsCasesRegistry`, cert kernel `AtlasForgeRivalsProviderArenaCoreCertification`.

## Dependencias

`atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`, `atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md`, `atlas-forge-rivals-operator-battery-v2.md`.

## Evidencias

Cert v1 `atlas_forge_rivals_provider_arena_core_certification` e suite de testes Forge Rivals (Slice 8 delivered 2026-05-15).

## Riscos

Adicionar arm via dropdown livre, esconder runners `not_yet_executable` ou tratar `future_runner` como executável. Promoção indevida de `external_rivals_certification`.

## Exemplos

`php artisan atlas:forge:rivals run-arena --arm-a=atlas_forge --arm-a-model=sonnet --arm-b=claude_code --arm-b-model=sonnet --category=backend --mode=local_fake --json`.

## Proximas Acoes

Rodar bateria real cross-provider com confirmacoes explicitas do operador, validar Gemini real em ambiente com driver configurado e manter doc sincronizado com mudanças no registry e nos cases.
