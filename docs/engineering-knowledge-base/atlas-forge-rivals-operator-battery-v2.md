---
id: atlas-forge-rivals-operator-battery-v2
type: engineering_knowledge
title: Atlas Forge Rivals · Operator Battery v2
status: active
category: programming
priority: 100
summary: Canon v2 do fluxo operador Rivals. Único entrypoint atlas:forge:rivals com 13 ações (doctor, setup, preflight, dry-run, plan-real, run-real, status, collect-evidence, replay, report, reset, full-smoke, audit), 5 modos (fair, full_power, diagnostic, replay_only, local_fake), 3 modelos (claude_sonnet, claude_opus, codex), worktrees isolados via git worktree add, streaming JSONL com heartbeat/stall/timeout, provider receipts hash-validados, evidence pack obrigatório, replay determinístico. Substitui operacionalmente os fluxos legacy benchmark:rivals*, programming:rivals-*; eles permanecem como wrappers deprecated. NUNCA desbloqueia external_rivals_certification.
tags:
  - atlas
  - forge
  - rivals
  - benchmark
  - operator
  - runbook
  - canonical
  - v2
capabilities:
  - forge_rivals_canonical_entrypoint_v2
  - forge_rivals_worktree_isolation
  - forge_rivals_streaming_jsonl_with_heartbeat
  - forge_rivals_provider_receipt_hash_validated
  - forge_rivals_evidence_pack_required
  - forge_rivals_replay_required_for_claim
  - forge_rivals_fair_mode_same_model
  - forge_rivals_full_power_topology_declaration
  - forge_rivals_local_fake_offline_smoke
  - forge_rivals_sonnet_opus_codex_matrix
decisions:
  - Único entrypoint operador é `php artisan atlas:forge:rivals`; legacy commands viram deprecated wrappers.
  - Atlas arm SEMPRE roda via Forge (runtime=atlas_forge); claude-cli raw como Atlas arm é proibido.
  - Real run (modes fair/full_power) exige TRÊS confirmações simultâneas; sem elas o provider NUNCA é invocado.
  - Worktrees são `git worktree add` sob /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/{atlas,rival}; source dirty NÃO contamina.
  - reset deleta apenas runs/<run_id>; jamais toca source.
  - dirty_after_run ⇒ verdict=invalid_dirty_after_run, score=null, claim=false.
  - invalid result ⇒ ZERO claim, score=null. NUNCA promove vitória.
  - report NUNCA declara winner sem replay passes + verdict=comparable + claim_ready=true.
  - Codex é rival-only até Forge codex adapter ser construído; auto só é admissível em full_power.
  - Esta certificação NUNCA desbloqueia external_rivals_certification.
maintenance:
  - Atualize esta doc quando uma das 13 ações mudar de shape ou um novo modo/modelo for adicionado.
  - Schema atlas.forge.rivals.action_response.v1 é additive-only: novas chaves são permitidas, renomes não.
  - Versionar pra .v2 só em breaking change (nunca em Slices 1-6).
related_paths:
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCasesRegistry.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModeRegistry.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsModelMatrix.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEventStream.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsOperatorBatteryCertification.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-operator-battery-v2
graph_title: Atlas Forge Rivals · Operator Battery v2
graph_world: programming
graph_layer: flow
graph_kind: runbook
graph_parent: atlas-forge-rivals-real-battery-operator-harness-v1
graph_status: active
graph_source: repo
owner: programming_rivals
repo_paths:
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - app/Services/Ai/Programming/ForgeRivals/
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsOperatorBatteryCertification.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md
allowed_changes:
  - additive flags on `atlas:forge:rivals` signature (never rename)
  - additive fields on action_response.v1 envelope (never rename)
  - new modes / models registered behind the canonical entrypoint
  - cert evaluators tightened (more sources, never weaker checks)
forbidden_changes:
  - chamando provider real a partir de testes
  - desbloquear external_rivals_certification
  - apagar source repo
  - mascarar dirty_after_run
  - declarar winner sem replay passes + verdict=comparable
  - permitir atlas arm com runtime != atlas_forge
depends_on:
  - atlas-forge-native-rivals-protocol-v1
  - atlas-rivals-evidence-pack-replay-manifest-v1
  - atlas-forge-rivals-reliability-lockdown-v1
flows_to:
  - external_rivals_certification (gate; remains blocked until operator approval)
unlocks:
  - operator_runbook_for_real_provider_battery_sonnet_opus_codex
governs:
  - todos os fluxos Rivals operador (legacy commands wrappam para cá)
evidence:
  - /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/evidence/manifest.json
  - /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/events.jsonl
  - /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/evidence/atlas_receipt.json
  - /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/evidence/rival_receipt.json
required_tests:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsCommandTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsCasesRegistryTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsModelMatrixTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsOperatorBatteryCertificationTest.php
  - tests/Feature/Ai/Programming/AtlasRivalsCommandDeprecationTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsReliabilityLockdownIntegrationTest.php
requires_evidence: true
risk_level: high
next_actions:
  - php artisan atlas:forge:rivals full-smoke --json
  - php artisan atlas:forge:rivals audit --json
  - php artisan atlas:programming:completion-audit --json
---

# Atlas Forge Rivals · Operator Battery v2

> Single canonical operator surface for Atlas vs. external rivals (Claude Sonnet, Claude Opus, Codex).
> Supersedes the v1 harness operationally; the v1 cert (`atlas_forge_rivals_real_battery_operator_harness_certification`) is preserved as a peer for historical traceability.

## Resumo

Fluxo canônico único `php artisan atlas:forge:rivals` com 13 ações, 5 modos, 3 modelos. Worktrees isolados via `git worktree add` sob `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/{atlas,rival}`. Streaming JSONL com heartbeat/stall/timeout, provider receipts hash-validados, evidence pack obrigatório, replay determinístico. Substitui operacionalmente os fluxos `atlas:engineering:benchmark:rivals*` e `atlas:programming:rivals-*` (preservados como wrappers deprecated). NUNCA desbloqueia `external_rivals_certification`.

## Papel no Atlas

Camada de bateria operacional sobre o protocolo canon Forge-Native Rivals. Não é feature de produto: é a única superfície que o operador toca para provocar a comparação Atlas-Forge vs rivais. Tudo abaixo (protocolo, case manifest, evidence pack, log stream, workspace hygiene) continua sendo o building block; v2 é a montagem operável.

## Onde Se Encaixa

```
docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md    (lockdown parent)
└── docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md   (v1 harness, peer)
    └── docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md            (este doc, v2 canônico)
        └── app/Console/Commands/AtlasForgeRivalsCommand.php
            └── app/Services/Ai/Programming/ForgeRivals/*
```

## Contratos

- **Envelope estável**: `atlas.forge.rivals.action_response.v1` em toda ação. Schema é additive-only.
- **Cert v2**: `atlas_forge_rivals_operator_battery_certification` (18 invariantes, status `available`).
- **Separação canon**: `separated_from = 'external_rivals_certification'`. Esta cert NUNCA promove claim externo.
- **Run-real gating**: 3 confirmações obrigatórias para modes `fair`/`full_power`; `local_fake` é livre mas nunca produz claim.
- **Atlas arm**: SEMPRE `runtime=atlas_forge`. Claude raw / codex raw como Atlas arm é proibido.

## Fluxo

```
doctor → setup --source-ref=HEAD → preflight → dry-run → plan-real
                                                              ↓
            ┌───────────── run-real (--confirm-* x3 se mode=fair|full_power) ─────────────┐
            ↓                                                                              ↓
        status (heartbeat tail)                                              [streaming JSONL]
            ↓
       collect-evidence (manifest + receipts + hashes + events)
            ↓
       replay (hash-validate, decisão determinística)
            ↓
       report (report.md; nunca declara winner sem replay passes + comparable)
            ↓
       reset (rm runs/<run_id>; NUNCA toca source)
```

`full-smoke` encadeia tudo offline com `--mode=local_fake`, sem provider real.

## Regras para IA

- **NUNCA** invoque provider real em testes; use `--mode=local_fake`.
- **NUNCA** desbloqueie `external_rivals_certification`.
- **NUNCA** declare winner sem replay passes E verdict=comparable E claim_ready=true.
- **NUNCA** apague source repo. `reset` é path-confined sob `runs/<run_id>`.
- **SEMPRE** injete `PYTHONDONTWRITEBYTECODE=1` em subprocessos.
- **SEMPRE** registre invalid ⇒ `score=null`, `claim=false`, ZERO claim.
- **SEMPRE** rode `audit` no fim de qualquer slice que afete o flow.

## Escopo de Implementacao

Modificável dentro deste módulo:
- 13 actions handlers
- registries (modes, models, cases)
- run path resolver
- event stream
- run-real service (subprocess + streaming + receipts)
- replay determinismo

Fora de escopo (preservar):
- atlas-server canon protocol services (`AtlasForgeNativeRivalsProtocolService`, etc.)
- v1 harness command (continua funcional)
- Voice/Cartografia/UI Atlas Code

## Dependencias

- atlas-forge-native-rivals-protocol-v1 (case shape, runtime contract)
- atlas-rivals-evidence-pack-replay-manifest-v1 (pack canon)
- atlas-forge-rivals-reliability-lockdown-v1 (parent lockdown spec)
- git (worktree add/remove)
- claude / codex CLI binaries (real-mode runs)

## Evidencias

- `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/events.jsonl`
- `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/evidence/manifest.json`
- `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/evidence/atlas_receipt.json`
- `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/evidence/rival_receipt.json`
- `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/evidence/workspace_hashes.json`
- `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/evidence/evidence_pack.json`
- `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/evidence/report.md`

## Riscos

- Real provider call gasta tokens; mitigado por 3 confirmações + cost estimate em plan-real.
- Source repo dirty bloqueia setup; mitigado por `git worktree add <source-ref>` que checa out um commit limpo.
- Stalled subprocess: heartbeat threshold 30s detecta; kill-tree on idle_timeout.
- Tracked python bytecode: bloqueado por WorkspaceHygieneService antes do run; doctor reporta.
- Replay non-determinismo: hash-validate de TODOS os arquivos do evidence pack; mismatch invalida.

## Exemplos

### Smoke offline (zero tokens)

```bash
php artisan atlas:forge:rivals full-smoke --json
```

Roda 11 fases (doctor → setup → preflight → dry-run → plan-real → run-real local_fake → status → collect-evidence → replay → report → reset) sem provider real.

### Run real (operador)

```bash
php artisan atlas:forge:rivals doctor --json
php artisan atlas:forge:rivals setup --source-ref=HEAD --json
php artisan atlas:forge:rivals preflight --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke --json
php artisan atlas:forge:rivals dry-run --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke --json
php artisan atlas:forge:rivals plan-real --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke --json
php artisan atlas:forge:rivals run-real --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke \
    --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json
php artisan atlas:forge:rivals status --run-id=<id> --json
php artisan atlas:forge:rivals collect-evidence --run-id=<id> --json
php artisan atlas:forge:rivals replay --run-id=<id> --json
php artisan atlas:forge:rivals report --run-id=<id> --json
```

Trocar `claude_sonnet` por `claude_opus` ou `codex` (rival-only) conforme matriz.

## Proximas Acoes

- `php artisan atlas:forge:rivals full-smoke --json` (validação offline end-to-end)
- `php artisan atlas:forge:rivals audit --json` (18 invariantes)
- `php artisan atlas:programming:completion-audit --json` (cert atlas_forge_rivals_operator_battery_certification = available; external_rivals_certification permanece blocked_requires_operator_approval)


**Schema:** `atlas.forge_rivals_operator_battery_certification.v1`
**Status policy:** This certification keeps `external_rivals_certification` BLOCKED. It NEVER unlocks paid/external claims by itself. Local evidence, replay, scorecards live here; external promotion requires explicit operator approval and is tracked separately.

---

## 1. Context

The old Rivals surface accumulated multiple parallel flows: `atlas:engineering:benchmark:rivals`, `:rivals-harness`, `atlas:programming:rivals-forge-{preflight,dry-run}`, `atlas:programming:rivals-evidence-pack`, plus an early `atlas:forge:rivals` forwarder. The combined effect on the operator: confused entrypoints, divergent `case_id` / `case_code`, silent baseline timeouts, no streaming, setup that broke when the main workspace was dirty, and comparisons of debatable validity.

v2 lockdown collapses this into a single canonical CLI — `php artisan atlas:forge:rivals` — with thirteen named actions and a stable JSON envelope. Legacy commands continue to exist as deprecated wrappers that reference this entrypoint; in Slice 6 their forward path is enabled and they become pure aliases.

**Atlas arm is always Forge.** Never claude-cli raw. Provider topology, runtime dispatch, invocation, evidence are all declared fields of the run. The legacy "atlas_not_forge" failure mode is impossible by construction.

---

## 2. Canonical Commands

The ONLY operator entrypoint:

```bash
php artisan atlas:forge:rivals <action> [flags]
```

### Thirteen actions

| Action | Purpose | Real impl in |
|---|---|---|
| `doctor` | Environment readiness (Forge, git, bytecode hygiene, provider configs) | Slice 1 |
| `setup` | Provision isolated worktrees via `git worktree add` from `--source-ref` | Slice 1 |
| `preflight` | Diagnostic-only protocol / case / mode / model validation | Slice 2 |
| `dry-run` | Plan replay manifest without dispatching providers | Slice 2 |
| `plan-real` | Print the final runbook + cost estimate + confirmations required | Slice 2 |
| `run-real` | Dispatch real provider (gated by THREE confirmations) | Slice 3 |
| `status` | Read events.jsonl, report heartbeat_age, detect stall | Slice 3 |
| `collect-evidence` | Assemble manifest + receipts + hashes + logs + scorecard | Slice 4 |
| `replay` | Hash-validate the evidence pack; reproduce decision deterministically | Slice 4 |
| `report` | Generate report.md and emit decision (winner / inconclusive / invalid) | Slice 4 |
| `reset` | Clean only `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>` (never source) | Slice 1 |
| `full-smoke` | Chain doctor→setup→preflight→dry-run→plan-real→status→replay offline | Slice 5 |
| `audit` | Evaluate the 18-invariant Operator Battery v2 certification | Slice 0 |

### Shared envelope (every action)

```json
{
  "schema_version": "atlas.forge.rivals.action_response.v1",
  "action": "doctor",
  "status": "pending_slice_1",
  "generated_at": "2026-05-14T...",
  "blockers": [],
  "evidence_paths": [],
  "next_command": "php artisan atlas:forge:rivals doctor --json  # awaiting Slice 1 implementation",
  "external_provider_call": false,
  "provider_tokens_spent": false,
  "separated_from_external_rivals_certification": true
}
```

### Status codes

| Status | Exit code | Operator meaning |
|---|---|---|
| `ok` | 0 | Action completed successfully |
| `pending_slice_N` | **2** | Action is wired but its real handler ships in Slice N — fail-closed by design |
| `blocked` | `--strict`?1:0 | Action's invariants/preconditions failed; consult `blockers[]` and `next_command` |
| `error` | 1 | Bad input (unknown action, invalid flag, invalid mode) |

`pending_slice_N` returning exit 2 is intentional: CI that conflates "non-zero" with "broken" will refuse to silently advance during the slice rollout — exactly the desired behavior.

### Canonical sequence (operator runbook target — full version in Slice 6)

```bash
php artisan atlas:forge:rivals doctor --json
php artisan atlas:forge:rivals setup --source-ref=HEAD --json
php artisan atlas:forge:rivals preflight --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke --json
php artisan atlas:forge:rivals dry-run --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke --json
php artisan atlas:forge:rivals plan-real --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke --json
php artisan atlas:forge:rivals run-real --mode=fair --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke \
    --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json
php artisan atlas:forge:rivals status --run-id=<id> --json
php artisan atlas:forge:rivals collect-evidence --run-id=<id> --json
php artisan atlas:forge:rivals replay --run-id=<id> --json
php artisan atlas:forge:rivals report --run-id=<id> --json
```

---

## 3. Modes

Five modes, declared via `--mode=`:

| Mode | Provider | Atlas Decide | Topology | Allowed models | Claim eligible |
|---|---|---|---|---|---|
| `fair` | yes | no | no | sonnet, opus, codex | YES (canonical comparison) |
| `full_power` | yes | yes | yes | sonnet, opus, codex, auto | YES (with topology declared) |
| `diagnostic` | NO | no | no | sonnet, opus, codex | no (local validation only) |
| `replay_only` | NO | no | no | — | no (re-executes evidence) |
| `local_fake` | NO (fake) | no | yes | sonnet, opus, codex | no (CI smoke, never claims) |

**Fair vs full_power:** Fair is the canonical comparison shape — same model on both arms, no Atlas tricks, no topology games. Full power is "Atlas at its strongest" — Atlas Decide and topology allowed, but every provider invocation must be declared in the receipt; nothing is silent.

**Diagnostic and replay_only never call providers.** They are safe to chain in CI.

**Local fake** generates synthetic provider responses for CI smoke. It is INCAPABLE of producing a claim: the cert flags it explicitly via `claim_eligible=false`.

---

## 4. Model Matrix

Models, declared via `--atlas-model=` and `--rival=`:

| Model | Atlas arm | Rival arm |
|---|---|---|
| `claude_sonnet` | yes | yes |
| `claude_opus` | yes | yes |
| `codex` | **rival-only in Slice 0–5** | yes |
| `auto` | only in `full_power` | only in `full_power` |

### Validation rules (`AtlasForgeRivalsModelMatrix::validate(mode, atlas, rival)`):

- `fair_mode_requires_same_model_on_both_arms` — fair vs different models is rejected.
- `auto_only_valid_in_full_power_mode` — `auto` outside `full_power` is rejected.
- `atlas_arm_cannot_use_rival_only_model` — `codex` as Atlas arm is rejected at this stage (the Forge codex dispatcher arrives in a later slice).
- Model not in the mode's `allowed_models` is rejected.

A run that fails matrix validation never reaches the provider — preflight blocks it deterministically.

---

## 5. Worktree Isolation

> Filled in Slice 1.

In short: `setup` will provision two isolated worktrees via
`git worktree add /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/atlas <source-ref>` and equivalent for `/rival`. Default `--source-ref=HEAD`. Source repo state (dirty or not) does NOT contaminate worktrees because `git worktree add` checks out the ref into a fresh working tree. `reset` only ever deletes under `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>` — the source repo is untouchable from this command.

---

## 6. Streaming (events.jsonl + heartbeat)

> Filled in Slice 3.

Every real run writes `events.jsonl` under `runs/<run_id>/`. Canonical events: `run_started, heartbeat, step_started, provider_started, provider_stdout_chunk, provider_stderr_chunk, provider_timeout_warning, provider_finished, after_clean_check, evidence_pack, final_report`. `status --run-id` tails this file and reports `heartbeat_age_seconds`. If `heartbeat_age_seconds > HEARTBEAT_STALL_SECONDS`, status becomes `stalled_runner_no_heartbeat`. Provider timeouts kill-tree the subprocess and close the evidence as `invalid`, never silent.

---

## 7. Evidence + Replay

> Filled in Slice 4.

Every real run produces under `runs/<run_id>/evidence/`:

- `manifest.json` — case_id, mode, atlas_model, rival_model, preset, hashes, started_at, finished_at
- `atlas_receipt.json` / `rival_receipt.json` — per-arm provider receipts (command hash, prompt hash, stdout/stderr hashes, exit code, timeout, token/cost)
- `before/` `after/` — workspace hashes (detect dirty_after_run)
- `*.patch` — per-arm diff
- `tests.log`, `quality.log` — log captures
- `scorecard.json` — diagnostic_score, comparable_score, claim_ready (boolean), winner (atlas|rival|tie|null)
- `report.md` — human-readable summary

`replay` re-executes the evidence pack hash-by-hash. If replay fails OR the pack is incomplete, `claim_ready=false` and `report` refuses to declare a winner.

---

## 8. Interpreting Invalid / Inconclusive / Comparable

Three result tiers; each carries explicit scoring rules:

| Tier | When | Scoring | Claim allowed? |
|---|---|---|---|
| `invalid` | Any of: missing provider_receipt, dirty_after_run, baseline_failed_timeout, replay_pack_incomplete, atlas_not_forge | `score=null`, `winner=null` | **ZERO claim. Never.** |
| `inconclusive` | Both arms valid but quality/test signals don't differentiate | `comparable_score` computed, `winner=null` | no claim of victory; comparison is recorded |
| `comparable` | Both arms valid, signals differentiate | `comparable_score`, `winner ∈ {atlas, rival, tie}` with justification | claim_ready=true after replay passes |

`diagnostic_score` (computed offline, never from real run) and `comparable_score` are NEVER conflated. Synthetic / `local_fake` runs may compute `diagnostic_score` but `claim_ready` is always false.

`external_rivals_certification` is upstream of all of this and remains blocked until explicit operator approval.

---

## 9. Runbook (copy-safe)

> Filled in Slice 6.

The Slice 6 runbook gives the full operator script with cost estimates, expected durations per preset, and the human-side checklist (`pyenv` ok, internet, providers authed, runbook ack'd, cost ack'd).

---

## 10. Legacy Mapping

The five legacy commands continue to exist as deprecated wrappers. Each emits a `[DEPRECATED]` banner (Slice 0) and references this entrypoint in its source. In Slice 6 the forward path becomes active and they delegate to `atlas:forge:rivals`.

| Legacy command | Legacy action | Canonical equivalent |
|---|---|---|
| `atlas:engineering:benchmark:rivals` | `readiness` | `atlas:forge:rivals doctor` |
| ditto | `preflight` | `atlas:forge:rivals preflight --mode=diagnostic` |
| ditto | `dry-run` / `dryrun` / `plan` | `atlas:forge:rivals dry-run --mode=diagnostic` |
| ditto | `run` / `quick` / `medium` / `full` | `atlas:forge:rivals run-real --mode=fair` + 3 confirms |
| ditto | `replay` / `replay-latest` | `atlas:forge:rivals replay --run-id=...` |
| ditto | `report` | `atlas:forge:rivals report --run-id=...` |
| ditto | `runbook` | `atlas:forge:rivals plan-real --mode=fair` |
| ditto | `verify` | `atlas:forge:rivals collect-evidence --run-id=...` |
| ditto | `triage-invalid-battery` | (no v2 equivalent yet; legacy logic runs) |
| `atlas:engineering:benchmark:rivals-harness` | `setup-worktrees` | `atlas:forge:rivals setup --source-ref=...` |
| ditto | `doctor` | `atlas:forge:rivals doctor` |
| ditto | `preflight` | `atlas:forge:rivals preflight --mode=diagnostic` |
| ditto | `dry-run` | `atlas:forge:rivals dry-run --mode=diagnostic` |
| ditto | `quick-real-plan` | `atlas:forge:rivals plan-real --mode=fair` |
| ditto | `quick-real` / `run-quick-real` | `atlas:forge:rivals run-real --mode=fair` + 3 confirms |
| ditto | `collect-evidence` | `atlas:forge:rivals collect-evidence --run-id=...` |
| ditto | `replay` | `atlas:forge:rivals replay --run-id=...` |
| ditto | `report` | `atlas:forge:rivals report --run-id=...` |
| ditto | `reset-test-worktrees` | `atlas:forge:rivals reset --reason=...` |
| ditto | `full-smoke` | `atlas:forge:rivals full-smoke` |
| ditto | `audit` | `atlas:forge:rivals audit` |
| `atlas:programming:rivals-forge-dry-run` | (default) | `atlas:forge:rivals dry-run --mode=diagnostic` |
| `atlas:programming:rivals-forge-preflight` | (default) | `atlas:forge:rivals preflight --mode=diagnostic` |
| `atlas:programming:rivals-evidence-pack` | (default) | `atlas:forge:rivals collect-evidence` |

**Preset translation:** legacy `quick` → v2 `smoke`, legacy `medium` → v2 `quick`, legacy `full` → v2 `release`. The new `full` preset is wider than legacy `full` and arrives in Slice 6.

**Model translation:** legacy `opus` → `claude_opus`, legacy `sonnet` → `claude_sonnet`.

---

## 11. Invariants (18 — canonical)

The `audit` action evaluates `atlas_forge_rivals_operator_battery_certification`. Aggregate status is `pending_implementation` until every invariant is green. ZERO claim, score=null while pending.

1. `canonical_forge_only_entrypoint` — Single canonical command exists and references no legacy command. *(Slice 0)*
2. `old_rivals_paths_deprecated_or_wrapped` — Each legacy command references the deprecation notifier and the canonical entrypoint. *(Slice 0)*
3. `worktrees_isolated_from_dirty_source` — `setup` provisions worktrees independent of source dirty state. *(Slice 1)*
4. `no_provider_without_three_confirmations` — `run-real` blocks without all three `--confirm-*`. *(Slice 3)*
5. `real_run_has_streaming_jsonl` — Real run writes `events.jsonl`. *(Slice 3)*
6. `heartbeat_and_stall_detection` — `status` reports `heartbeat_age_seconds` and detects stall. *(Slice 3)*
7. `provider_receipt_required_for_real_run` — Missing provider_receipt invalidates the run. *(Slice 3)*
8. `after_clean_check_required` — Run finalizer compares before/after workspace hashes. *(Slice 4)*
9. `dirty_after_run_invalidates` — `dirty_after_run` ⇒ `verdict=invalid_dirty_after_run`, `score=null`. *(Slice 4)*
10. `tracked_python_bytecode_blocked` — Tracked `.pyc`/`__pycache__` blocks the run. *(Slice 0)*
11. `sonnet_opus_codex_supported` — Model matrix includes the three. *(Slice 0)*
12. `fair_mode_same_model_enforced` — Fair mode rejects different models. *(Slice 0)*
13. `full_power_mode_declares_topology` — Full power runs declare topology in receipt. *(Slice 2)*
14. `zero_case_preset_blocks` — Preset with zero cases throws `EmptyPresetIsFatalHarnessBug`. *(Slice 0)*
15. `evidence_pack_required` — Replay/report require evidence pack present. *(Slice 4)*
16. `replay_required_for_claim` — Report refuses to declare winner without replay success. *(Slice 4)*
17. `invalid_never_claims` — invalid result ⇒ `ZERO claim`, `score=null`. *(Slice 0)*
18. `external_rivals_remains_blocked` — This cert never unblocks `external_rivals_certification`. *(Slice 0)*

---

## 12. Cross-references

- Peer cert: `app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsRealBatteryOperatorHarnessCertification.php` (v1 harness, preserved untouched).
- Underlying canon: `docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md`.
- Case manifest: `app/Services/Ai/Programming/AtlasForgeNativeRivalsCaseManifestService.php`.
- Streaming primitive: `app/Services/Ai/Programming/RivalsForgeRunLogStreamService.php`.
- Workspace hygiene: `app/Services/Ai/Programming/WorkspaceHygieneService.php`.
- Evidence pack: `app/Services/Ai/Programming/AtlasRivalsEvidencePackService.php`.
- Lockdown parent: `docs/engineering-knowledge-base/atlas-forge-rivals-reliability-lockdown-v1.md`.
