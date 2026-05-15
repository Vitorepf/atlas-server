---
id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Perfect Battery & Adjudicator v1
status: active
category: programming-forge
priority: 88
summary: Bateria única e adjudicator determinístico local para `atlas:forge:rivals`. Encadeia doctor→setup→preflight→dry-run→plan-real→run-real→collect-evidence→replay→adjudicate→report em um comando auditável, com winner/tie/invalid honesto. Nunca destrava external_rivals_certification.
tags:
  - atlas
  - forge
  - rivals
  - run-battery
  - adjudicator
capabilities:
  - forge_rivals_run_battery_v1
  - forge_rivals_local_deterministic_adjudicator
  - forge_rivals_perfect_battery_certification
decisions:
  - Bateria é um único entrypoint humano; aliases não escondem o canon `run-battery`.
  - Adjudicator é local determinístico; nunca delega para provider externo.
  - `external_rivals_certification` permanece BLOCKED por construção independentemente do veredito.
maintenance:
  - Atualizar quando `AtlasForgeRivalsRunBatteryService`, `AtlasForgeRivalsAdjudicatorService` ou cert v1 mudarem de invariantes.
  - Não introduzir alias novo sem aliasing list no command.
related_paths:
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
---

# Atlas Forge Rivals · Perfect Battery & Adjudicator v1

**Schema:** `atlas.forge.rivals.run_battery.v1`
**Adjudicator schema:** `atlas.forge.rivals.adjudication.v1`
**Report schema:** `atlas.forge.rivals.report.v2`
**Certification:** `atlas_forge_rivals_perfect_battery_certification` (v1)
**Entrypoint:** `php artisan atlas:forge:rivals`
**Status:** Slice 7 — Perfect Battery & Adjudicator delivered (2026-05-15)

This doc is the canonical contract for running Atlas Forge vs a rival in a
single auditable command, with a deterministic local adjudicator that
declares an honest winner / tie / invalid verdict. It supersedes nothing —
it builds on top of `atlas-forge-rivals-operator-battery-v2.md` and
`atlas-forge-rivals-real-battery-operator-harness-v1.md`. Read those first.

> **External rivals canon.** This battery NEVER unlocks
> `external_rivals_certification`. That cert remains operator-approval-gated
> and separately tracked. Every layer (run-battery, adjudicator, report,
> certification, this doc) restates the separation explicitly.

---

## 1. The single button

```bash
php artisan atlas:forge:rivals run-battery \
  --mode=fair --atlas-model=sonnet --rival=claude_sonnet \
  --preset=release \
  --confirm-runbook-reviewed \
  --confirm-provider-cost \
  --confirm-real-provider-call \
  --json --strict
```

This one command chains the entire pipeline:

```
doctor → setup → preflight → dry-run → plan-real → run-real →
collect-evidence → replay → adjudicate → report
```

Stops on the first phase whose status is not `ok`. Returns the partial
pipeline so the operator can see exactly where it broke. Evidence is
preserved at `runs/<run_id>/` regardless.

**Aliases:** `battery`, `run-battery-real`, `battery-run` all resolve to
`run-battery`. `score`, `adjudicator`, `adjudication` all resolve to
`adjudicate`.

---

## 2. Modes, models, presets

### Modes (canonical + alias)

| Mode (canon) | Alias | Requires provider | Allows topology | Notes |
| --- | --- | --- | --- | --- |
| `fair` | — | yes | no | Same model on both arms. Canonical claim mode. |
| `full_power` | `power` | yes | yes | Atlas may declare topology; rival may use codex. |
| `local_fake` | — | no | no | In-process fake; no provider call; for CI smoke. |

### Models

| Arm | Allowed |
| --- | --- |
| `--atlas-model` | `sonnet` · `opus` · `claude_sonnet` · `claude_opus` · `codex` (only in `full_power` after driver wired) · `auto` (only in `full_power`) |
| `--rival` | `claude_sonnet` · `claude_opus` · `codex` (honest blocker if binary missing) · `auto` (only in `full_power`) |

`sonnet` is shorthand for `claude_sonnet`; `opus` is shorthand for
`claude_opus`. `fair` mode rejects mismatched models on the two arms.
`auto` is rejected outside `full_power`.

### Presets

`smoke` · `quick` · `release` · `full`. A preset that resolves to zero
cases is a fatal harness bug (`EmptyPresetIsFatalHarnessBug`).

---

## 3. Three operator confirmations (real-provider only)

For `fair` and `full_power`, the battery refuses to invoke the provider
without **all three** flags:

- `--confirm-runbook-reviewed`
- `--confirm-provider-cost`
- `--confirm-real-provider-call`

Missing any one ⇒ `blocked`, `missing_confirmation:<flag>` blocker,
`external_provider_call=false`, `provider_tokens_spent=false`.
`local_fake` ignores the flags — it never invokes the provider.

The same gate is enforced by `run-real` and by `run-battery` independently
(defense in depth). `AtlasForgeRivalsPerfectBatteryCertification` invariant
`real_provider_requires_three_confirmations` proves both layers reference
all three flags + the missing-confirmation blocker.

---

## 4. Adjudicator (deterministic, local)

`AtlasForgeRivalsAdjudicatorService` produces `evidence/scorecard.json` for a
run. Schema: `atlas.forge.rivals.adjudication.v1`. **No LLM is ever asked to
judge.** Every signal is computed from local artifacts.

### Hard gates (each fail-closed ⇒ winner=null, scores=null)

`verdict_comparable` · `provider_exit_zero_{atlas,rival}` ·
`tests_passed_{atlas,rival}` · `replay_passes` · `evidence_complete` ·
`no_out_of_scope_files_{atlas,rival}` · `no_bytecode_artifacts_{atlas,rival}` ·
`dirty_after_run_false` · `patch_diff_present_{atlas,rival}`.

### Quality dimensions (only when every hard gate green)

| Dimension | Weight | Measures |
| --- | ---: | --- |
| `objective_alignment` | 15% | Provider exit zero + tests pass, −20 if killed |
| `patch_focus` | 12% | Smaller substantive diff wins (curve) |
| `implementation_complexity` | 8% | Touched-files + big-single-file penalty |
| `test_quality` | 12% | Assertion count + touched-test bonus |
| `maintainability` | 10% | Average diff bytes per touched file |
| `risk_surface` | 10% | Production-touched vs tests-touched balance |
| `scope_discipline` | 15% | Zero out-of-scope + zero bytecode |
| `evidence_quality` | 10% | Required artifacts present + hashed |
| `cost_time_efficiency` | 8% | Wall time + stdout volume proxies |

Weights sum to 1.0. Final score per arm = weighted sum in [0, 100].
**Cost/time can never overturn a non-tie quality outcome** — it only
surfaces as a tiebreaker hint in `winner_reason`.

### Winner / tie / invalid

- Hard fail ⇒ `winner=null`, scores null, gates explain failure.
- `|atlas-rival| < threshold` (default 5.0) ⇒ `winner=human_review_required_tie`.
- Otherwise ⇒ `winner=atlas|rival`, `claim_ready=true`, structured `winner_reason`.

Adjudicator NEVER calls a provider; fully unit-testable against synthetic receipts.

---

## 5. Premium report (`report.md` + JSON)

`AtlasForgeRivalsReportService` (schema `atlas.forge.rivals.report.v2`)
reads manifest + replay + scorecard and renders:

- **Executive summary** with run id, mode, models, preset, score line,
  replay_passes, claim_ready, declared_why.
- **Why this outcome** — bullets from `scorecard.winner_reason`.
- **Hard gates** table (one row per gate).
- **Quality dimensions** table (atlas vs rival, weighted contribution).
- **Patch comparison** (bytes, changed files, out-of-scope, bytecode,
  diff hashes).
- **Test comparison** (command, exit codes, log paths, log hashes).
- **Cost / time / provider usage** (wall time, stdout/stderr bytes,
  tokens_used, token_cost).
- **Evidence integrity** (manifest, receipts, workspace hashes,
  scorecard).
- **Replay status** (passes, mismatches, event_count).
- **Human review checklist** — adapts to outcome (invalid / replay-failed /
  hard-fail / tie / clear winner). Tie always triggers a 5-item checklist.
- **Artifacts list** — `events.jsonl`, `manifest.json`, receipts, patches,
  test logs, scorecard, report.
- **Canon footer** — restates separation from `external_rivals_certification`.

### Report JSON envelope

```json
{
  "status": "ok",
  "schema_version": "atlas.forge.rivals.report.v2",
  "run_id": "...",
  "verdict": "comparable|invalid_*",
  "winner": "atlas|rival|human_review_required_tie|null",
  "winner_reason": [],
  "atlas_score": 86.5,
  "rival_score": 79.2,
  "threshold": 5.0,
  "hard_failures": [],
  "human_review_required": false,
  "claim_ready": true,
  "replay_passes": true,
  "declared_why": "quality_winner:atlas",
  "quality_dimensions": { },
  "hard_gates": [],
  "report_path": "runs/<id>/evidence/report.md",
  "scorecard_path": "runs/<id>/evidence/scorecard.json",
  "artifacts": [],
  "external_provider_call": false,
  "separated_from_external_rivals_certification": true,
  "unlocks_external_rivals_certification": false
}
```

---

## 6. Codex blocker (honest)

Asking for `--rival=codex` (or `--atlas-model=codex` in `full_power`)
without the `codex` binary installed yields:

```
blocker: rival_driver_not_configured:codex
hint:    install codex CLI (which codex) or pick a different --rival
```

Both `run-battery` (via doctor probe) and `run-real` (via direct
`which codex` check) emit this blocker. No silent "pretend support".
`AtlasForgeRivalsPerfectBatteryCertification` invariant
`codex_blocker_honest_or_supported` proves both layers emit the blocker.

---

## 7. Worktree isolation

Every run lives in `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/`
with `atlas/` and `rival/` worktrees provisioned by `setup`. `run-real`
refuses to start when those worktrees are missing (`worktrees_missing`
blocker) and never operates on the source repo. The source repo can be
dirty without affecting any run.

---

## 8. Certification (audit read-model)

`php artisan atlas:forge:rivals audit --json` evaluates two certifications
side by side:

- `atlas_forge_rivals_operator_battery_certification` (the 18-invariant
  v2 contract — backwards compatibility)
- `atlas_forge_rivals_perfect_battery_certification` (this v1 — 12
  invariants)

### v1 invariants

1. `one_button_battery_available` — `run-battery` is in `ACTIONS`, dispatcher
   routes it, service class exists.
2. `adjudicator_available` — `adjudicate` action wired, service exists,
   schema constant present.
3. `replay_required_before_winner` — adjudicator and report both reference
   `replay_passes` in their gate logic.
4. `evidence_required_before_winner` — adjudicator references
   `evidence_complete` and `missing_evidence`.
5. `hard_fail_score_null` — adjudicator emits null scores and `WINNER_NONE`
   on the hard-fail branch.
6. `no_external_rivals_unlock` — every layer asserts separation from
   `external_rivals_certification`.
7. `real_provider_requires_three_confirmations` — battery and run-real
   both reference all three flags + the missing-confirmation blocker.
8. `worktree_isolation_required` — run-real references `worktrees_missing`
   and `.git` checks.
9. `sonnet_supported` — `MODEL_CLAUDE_SONNET` in `MODELS`.
10. `opus_supported` — `MODEL_CLAUDE_OPUS` in `MODELS`.
11. `codex_blocker_honest_or_supported` — battery and run-real both emit
    `rival_driver_not_configured:codex`.
12. `report_has_winner_reason` — report JSON and adjudicator both expose
    `winner_reason`; adjudicator has `buildWinnerReason`.

Status semantics:

- `available` — every invariant green, every artifact present.
- `missing_artifacts` — an artifact required to evaluate is missing.
- `blocked` — at least one invariant evaluates to false.

---

## 9. CLI ergonomics & interpretation

Every action returns the canonical envelope
(`atlas.forge.rivals.action_response.v1`). `--strict` causes
non-`ok`/`completed` statuses to exit non-zero. Headline commands:
`doctor --json`, `run-battery ... --json`, `report --run-id=<id> --json`,
`replay --run-id=<id> --json`, `adjudicate --run-id=<id> --json`,
`audit --json` (dual certification read-model).

| Outcome | Meaning | Operator action |
| --- | --- | --- |
| `winner: atlas` / `rival` | All hard gates green, diff ≥ threshold | Inspect diff; legitimate win — does NOT unlock external rivals |
| `winner: human_review_required_tie` | Diff < threshold | Read checklist; do NOT force a winner |
| `winner: null` + `hard_failures` | A hard gate failed | Fix gates and re-run |
| `verdict: invalid_*` | Run invalidated (dirty, timeout, no diff) | ZERO claim — investigate cause |
| `replay_passes: false` | Hash mismatch | Evidence untrustworthy — re-run |

## 10. Safety rules (non-negotiable)

- NEVER unlocks `external_rivals_certification`.
- Real provider call requires three operator confirmations simultaneously.
- `local_fake` mode never invokes a provider, even with confirmations.
- `rm` is never used against worktree roots; reset is git-driven.
- Workspace dirty after run / tracked .pyc / out-of-scope files ⇒ hard fail, ZERO claim.
- Empty preset ⇒ `EmptyPresetIsFatalHarnessBug` (not a silent no-op).

## 11. Related docs

- `atlas-forge-rivals-operator-battery-v2.md`
- `atlas-forge-rivals-real-battery-operator-harness-v1.md`
- `atlas-forge-rivals-reliability-lockdown-v1.md`
- `atlas-rivals-evidence-pack-replay-manifest-v1.md`
- `atlas-rivals-one-shot-enterprise-evaluation-v1.md`
- `atlas-forge-native-rivals-protocol-v1.md`

## Resumo

Slice 7 do Forge Rivals: bateria única `atlas:forge:rivals run-battery` que orquestra todo o pipeline de comparação Atlas vs rival com adjudicator determinístico local e cert v1. Aliases consolidados (`battery`, `run-battery-real`, `score`, `adjudicator`). `external_rivals_certification` continua BLOCKED.

## Papel no Atlas

Cabine humana do Forge Rivals. Substitui a sequência manual de 9 comandos por uma única chamada com `--strict`, retornando o pipeline parcial até o ponto de falha e preservando evidência em `runs/<run_id>/`.

## Onde Se Encaixa

Acima de `atlas-forge-rivals-operator-battery-v2.md` e `atlas-forge-rivals-real-battery-operator-harness-v1.md`. Companheiro direto de `atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md`.

## Fluxo

`doctor → setup → preflight → dry-run → plan-real → run-real → collect-evidence → replay → adjudicate → report`, parando na primeira fase com status diferente de `ok`.

## Regras para IA

Não esconder safety strip. Não pular as três confirmações em modos `fair`/`full_power`. Não promover `external_rivals_certification` a partir do veredito.

## Escopo de Implementacao

`AtlasForgeRivalsCommand`, `AtlasForgeRivalsRunBatteryService`, `AtlasForgeRivalsAdjudicatorService`, `AtlasForgeRivalsReportService`, `AtlasForgeRivalsCollectEvidenceService`, `AtlasForgeRivalsReplayService`.

## Dependencias

Operator battery v2, real battery operator harness v1, evidence pack v2 hardening, perfect battery certification v1.

## Evidencias

Cert v1 `atlas_forge_rivals_perfect_battery_certification` e 186 testes Forge Rivals verdes (Slice 7 delivered 2026-05-15).

## Riscos

Operador interpretar `winner` como completion claim. Alias novo escapar do controle do command. Promoção indevida de `external_rivals_certification`.

## Exemplos

`php artisan atlas:forge:rivals run-battery --mode=fair --atlas-model=sonnet --rival=claude_sonnet --preset=release --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json --strict`.

## Proximas Acoes

Acompanhar futuros polish em adjudicator e report. Mantersuit de testes sincronizada com mudanças de invariantes.

