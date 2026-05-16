---
id: atlas-forge-rivals-evidence-pack-replay-multi-case-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Evidence Pack + Replay Multi-Case v1
status: active
category: programming-forge
priority: 90
summary: Hardening multi-case do Evidence Pack + Replay do Forge Rivals. Define agregado por bateria (battery_evidence_pack.v1) + verifier multi-case (battery_replay_verification.v1) com category_summary, difficulty_summary L1-L5, replay case-by-case e fail-closed. NÃO destrava external_rivals_certification.
tags:
  - atlas
  - forge
  - rivals
  - evidence
  - replay
  - multi-case
  - difficulty-l5
capabilities:
  - forge_rivals_battery_evidence_pack_v1
  - forge_rivals_battery_replay_verifier_v1
  - forge_rivals_difficulty_l5_propagation
  - forge_rivals_multi_case_aggregation
decisions:
  - Toda bateria precisa ser replayable case-by-case; falha de qualquer case bloqueia claim final.
  - difficulty propagada do corpus pela ladder L1-L5; missing difficulty bloqueia bateria.
  - Multi-case usa subdirs `evidence/cases/<case_subdir>/` com receipts/patches/test logs por case.
  - battery_evidence_pack.json é sidecar agregado por run, não substitui per-run evidence_pack.json.
  - aggregate_claim_ready=false sempre; verifier nunca promove claim.
  - external_rivals_certification permanece BLOCKED por construção.
maintenance:
  - Atualizar quando `AtlasForgeRivalsRunRealService.perCaseSummary`, `AtlasForgeRivalsCollectEvidenceService.buildMultiCaseSummary`, `AtlasForgeRivalsBatteryEvidenceService.aggregate` ou `AtlasForgeRivalsBatteryReplayVerifierService.verify` mudarem.
  - Manter aliases CLI (`battery-evidence`, `battery-verify-evidence`) sincronizados com command signature.
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunRealService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCollectEvidenceService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryReplayVerifierService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceReplayMultiCaseTest.php
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-evidence-pack-replay-multi-case-v1
graph_title: Atlas Forge Rivals · Evidence Pack + Replay Multi-Case v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-evidence-pack-replay-hardening-v2
graph_status: active
graph_source: repo
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryReplayVerifierService.php
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-evidence-pack-replay-multi-case-v1.md
allowed_changes:
  - Adicionar campos additive ao battery pack v1 e ao battery verification v1.
  - Endurecer regras case-by-case do verifier.
  - Adicionar aliases CLI sem renomear actions.
forbidden_changes:
  - Aceitar bateria com missing provider receipt, missing patch diff, missing test log, missing difficulty ou hash mismatch em qualquer case.
  - Promover `aggregate_claim_ready=true` a partir do verifier.
  - Destravar `external_rivals_certification` a partir deste contrato.
  - Aceitar fallback silencioso de difficulty (sem origin auto_mapped_from_legacy/corpus/missing).
depends_on:
  - atlas-forge-rivals-evidence-pack-replay-hardening-v2
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-provider-arena-corpus-v1
flows_to:
  - atlas-forge-rivals-evidence-pack-replay-hardening-v2
unlocks:
  - forge_rivals_multi_case_battery_evidence_chain_auditable
governs:
  - forge_rivals_battery_evidence_verification_contract
evidence:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceReplayMultiCaseTest.php
required_tests:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceReplayMultiCaseTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Monitorar pipeline `run-battery` em release/full quanto a regressões em coleta multi-case.
  - Sincronizar este doc se novos artifact keys per-case entrarem na chain.
---

# Atlas Forge Rivals · Evidence Pack + Replay Multi-Case v1

**Status:** Delivered 2026-05-15 (Claude D · Multi-Case slice)
**Battery pack schema:** `atlas.forge.rivals.battery_evidence_pack.v1`
**Battery verifier schema:** `atlas.forge.rivals.battery_replay_verification.v1`
**Companion per-run schema:** `atlas.forge.rivals.evidence_pack.v2`
**Entrypoint:** `php artisan atlas:forge:rivals`

This doc is the canonical contract for the multi-case slice of the Forge
Rivals evidence/replay pipeline. It tightens the v2 hardening
(`atlas-forge-rivals-evidence-pack-replay-hardening-v2.md`) so a battery
that runs N cases under a single run_id (or across N run_ids) is auditable
case-by-case and impossible to turn into a claim if any case fails.

> **External rivals canon.** This slice NEVER unlocks
> `external_rivals_certification`. That cert remains operator-approval-gated
> and separately tracked. Every layer (collect-evidence, replay,
> verify-evidence, battery-evidence, battery-verify-evidence, this doc)
> restates the separation explicitly.

---

## 1. Why multi-case

A real Rivals battery measures Atlas Forge vs a baseline across multiple
cases (12 in `release`, 3 in `quick`, etc.). The v2 single-case hardening
already locks down one case's evidence — but a battery is only as honest as
its weakest case. The multi-case slice closes three gaps:

1. **Per-case audit:** every case has its own provider receipts, patch diff,
   test log, workspace hashes, and difficulty. Missing any of these in any
   case is a battery-level blocker.
2. **Aggregate sanity:** category and difficulty summaries make it obvious
   whether the battery actually exercised the surface the operator claimed
   (e.g. "release" battery without any L5 case → not a release-grade run).
3. **Replay case-by-case:** the verifier rehashes every per-case artifact;
   if a single sha256 drifts, the battery is rejected.

---

## 2. Artifact layout (multi-case)

For a multi-case run_id, the run-real service writes:

```
runs/<run_id>/
  evidence/
    manifest.json                 # carries cases[] with per-case digest
    events.jsonl
    intent.json
    atlas_receipt.json            # legacy worst-of-aggregate (single-case compat)
    rival_receipt.json
    workspace_hashes.json
    atlas_patch.diff              # legacy worst-of-aggregate
    rival_patch.diff
    atlas_test.log
    rival_test.log
    evidence_pack.json            # per-run v2 pack (now with cases[])
    artifact_index.json           # per-run v2 sidecar
    battery_evidence_pack.json    # NEW: battery-level aggregate (this slice)
    cases/
      <case_subdir_1>/
        atlas_receipt.json
        rival_receipt.json
        atlas_patch.diff
        rival_patch.diff
        atlas_test.log
        rival_test.log
        workspace_hashes.json
      <case_subdir_2>/
        ...
```

For batteries that span multiple run_ids (operator ran N separate
`run-real` invocations), the battery aggregator accepts a `--run-ids`
list and writes the aggregate pack inside the first run's `evidence/`
directory by default.

---

## 3. L1-L5 difficulty canon propagation

The corpus already declares the ladder (see
`AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVELS = [L1,L2,L3,L4,L5]`).
This slice propagates it everywhere downstream:

| Layer                                    | Field surfaced                                                  |
| ---------------------------------------- | --------------------------------------------------------------- |
| Corpus case                              | `difficulty`, `difficulty_level`, `difficulty_weight`           |
| Adapter (RunRealService)                 | same, copied into the in-memory case for the arm's prompt+tag   |
| Per-case manifest entry                  | `difficulty`, `difficulty_level`, `difficulty_weight`            |
| Per-run evidence pack (`cases[]`)        | same + `difficulty_level_origin` (`corpus`/`auto_mapped_from_legacy`/`missing`) |
| Per-run difficulty_summary               | counts by L1-L5 + missing_difficulty_count + ladder + weights   |
| Battery evidence pack (`cases[]`)        | same + per-case run_id reference                                |
| Battery difficulty_summary               | counts by L1-L5 across all cases + total_weight                  |
| Battery verifier per-case status         | difficulty_level included in `per_case[]` and `missing_evidence` |

Mapping is authoritative:

| Legacy bucket | Canonical level |
| ------------- | --------------- |
| `easy`        | `L1`            |
| `medium`      | `L3`            |
| `hard`        | `L5`            |

L2 and L4 are reserved for future corpus expansion; the schemas accept
them today even though no case uses them.

`difficulty_level_origin` exposes whether a case's level was declared
explicitly by the corpus (`corpus`), inferred from the legacy bucket
(`auto_mapped_from_legacy`), or absent (`missing`). The verifier rejects
the battery when any case has origin `missing` (or a level outside the
ladder).

---

## 4. Battery evidence pack v1 — top-level fields

Schema: `atlas.forge.rivals.battery_evidence_pack.v1`. Written to
`<first_run>/evidence/battery_evidence_pack.json` (or `--output-path`):

| Field                                          | Type             | Notes                                                   |
| ---------------------------------------------- | ---------------- | ------------------------------------------------------- |
| `schema_version`                               | string           | Pinned.                                                 |
| `battery_id`                                   | string           | Explicit `--battery-id`, else `battery-<sha8>` of sorted run_ids. |
| `evidence_stage`                               | enum             | `pre_adjudication` \| `final`.                          |
| `generated_at`                                 | ISO8601          |                                                         |
| `run_ids`                                      | list<string>     | In operator order.                                      |
| `runs`                                         | list<object>     | Per-run pack digest (path, sha256, mode, claim_ready). |
| `cases`                                        | list<object>     | Per-case entry (see §5).                                |
| `case_count`                                   | int              |                                                         |
| `category_summary.counts`                      | map<category,int>| Distribution across present cases.                      |
| `category_summary.present_categories`          | list<string>     |                                                         |
| `difficulty_summary.counts`                    | map<L1-L5,int>   |                                                         |
| `difficulty_summary.missing_difficulty_count`  | int              | Non-zero blocks the battery.                            |
| `difficulty_summary.missing_difficulty_cases`  | list<string>     | Sampled list `run_id/case_id`.                          |
| `difficulty_summary.ladder`                    | list<string>     | Canonical L1..L5.                                       |
| `difficulty_summary.weights`                   | map<L1-L5,float> | 1.0..3.0 ladder.                                        |
| `difficulty_summary.total_weight`              | float            | Sum across present cases.                               |
| `replay_status.total_cases`                    | int              |                                                         |
| `replay_status.replayable_count`               | int              |                                                         |
| `replay_status.blocked_count`                  | int              |                                                         |
| `replay_status.blockers`                       | list<string>     | `case_not_replayable:run_id/case_id` per blocked case.  |
| `mode_distribution`                            | map<mode,int>    | Counts of mode_for_evidence values across runs.         |
| `blockers`                                     | list<string>     |                                                         |
| `invalid_reasons`                              | list<string>     |                                                         |
| `aggregate_claim_ready`                        | bool             | **Always `false`.** Verifier never promotes claim.      |
| `external_provider_call`                       | bool             | OR of per-run flags.                                    |
| `provider_tokens_may_have_been_spent`          | bool             | OR of per-run flags.                                    |
| `external_rivals_certification_status`         | string           | Constant `blocked`.                                     |
| `separated_from_external_rivals_certification` | bool             | Constant `true`.                                        |

---

## 5. Per-case entry (battery pack)

Each `cases[]` entry carries the audit trail for replay:

```jsonc
{
  "run_id": "...",
  "case_id": "...",
  "case_index": 0,
  "task_category": "backend_logic",
  "difficulty": "medium",
  "difficulty_level": "L3",
  "difficulty_level_origin": "auto_mapped_from_legacy",
  "difficulty_weight": 2.0,
  "verdict": "comparable",
  "evidence_subdir": "cases/case-1",
  "evidence_path": "/.../evidence/cases/case-1",
  "workspace_hash_before": { "atlas": "...", "rival": "..." },
  "workspace_hash_after":  { "atlas": "...", "rival": "..." },
  "workspace_blockers": [],
  "replay_ready": true,
  "arms": {
    "atlas": {
      "present": true,
      "patch_diff_path": "/.../atlas_patch.diff",
      "patch_diff_sha256": "<sha256>",
      "patch_diff_bytes": 3000,
      "test_log_path": "/.../atlas_test.log",
      "test_log_sha256": "<sha256>",
      "exit_code": 0,
      "test_exit_code": 0,
      "killed": false
    },
    "rival": { ... }
  }
}
```

`arms.*.present=false` when the per-case receipt file is missing on disk —
the collector reads the case subdir, not just the manifest snapshot.
`reason_missing` is mandatory in that case.

---

## 6. Battery replay verifier v1

Schema: `atlas.forge.rivals.battery_replay_verification.v1`. The verifier:

1. Aggregates the battery pack (re-runs collect on each run).
2. Reads the **prior** `battery_evidence_pack.json` before it overwrites it.
   The prior pack's per-case sha256 is the integrity baseline.
3. Verifies each per-run pack via the v2 single-run verifier
   (`AtlasForgeRivalsEvidencePackVerifierService`).
4. Walks each case and:
   - rehashes the patch diff + test log from disk,
   - compares against both the freshly-recorded `sha256` and the prior
     pack's recorded `sha256` (drift between prior and disk ⇒ tamper),
   - asserts `difficulty_level` is in `L1..L5`,
   - asserts workspace_blockers is empty,
   - asserts both arms' receipts are present on disk.
5. Aggregates blockers / invalid_reasons / missing_evidence and emits a
   single battery-level verdict.

### Status values

- `passed`: every case re-hashed clean, every per-run pack verified, no
  difficulty missing, no workspace dirty, no tracked bytecode.
- `invalid_missing_evidence`: at least one case has a missing artifact.
- `invalid_hash_mismatch`: at least one case has a hash mismatch (current or
  prior-pack drift).
- `blocked`: structural blockers (no `aggregate_claim_ready=false`,
  schema drift, run dir not found).

### Always-on invariants

- `aggregate_claim_ready=false`.
- `external_rivals_certification_status='blocked'`.
- `no_provider_call=true`.

---

## 7. CLI surface

```bash
# Aggregate the battery pack for N run_ids (writes battery_evidence_pack.json).
php artisan atlas:forge:rivals battery-evidence \
  --run-ids=run-a,run-b,run-c --stage=pre_adjudication --json

# Or use repeated --run-id; or a single multi-case run_id.
php artisan atlas:forge:rivals battery-evidence \
  --run-id=multi-case-battery --json

# Strict verifier: re-aggregate, re-hash, compare prior pack, block on drift.
php artisan atlas:forge:rivals battery-verify-evidence \
  --run-ids=run-a,run-b,run-c \
  --verify-mode=real_run --stage=pre_adjudication \
  --json --strict
```

Aliases: `battery-pack`, `aggregate-evidence`, `battery-collect-evidence` →
`battery-evidence`; `battery-verify`, `battery-replay`, `verify-battery`,
`multi-case-verify` → `battery-verify-evidence`.

`--strict` makes `invalid_missing_evidence` / `invalid_hash_mismatch` /
`blocked` produce exit code 1 so CI can fail-closed.

---

## 8. Fail-closed rules (single page)

The battery cannot become a score / claim if any of these are true (per
case, propagating up to the battery):

- `provider_receipt:atlas` or `provider_receipt:rival` missing on disk in
  the case subdir.
- `patch_diff:atlas|rival` missing on disk.
- `test_log:atlas|rival` missing on disk.
- `workspace_blockers` non-empty (dirty after run, tracked .pyc, out of
  scope changes).
- `difficulty_level` absent or not in `L1..L5`.
- `patch_diff` or `test_log` sha256 mismatch — either between the current
  on-disk hash and the freshly-computed pack, or between the on-disk hash
  and a prior pack's recorded hash (prior pack drift).
- The per-run pack's `verify-evidence` (v2 single-run) fails for any case.
- `aggregate_claim_ready=true` is encoded in the pack (must remain false).
- `external_rivals_certification_status != 'blocked'`.

When any rule fires:

- `status = invalid_missing_evidence | invalid_hash_mismatch | blocked`,
- `aggregate_claim_ready = false`,
- `external_rivals_certification_status = blocked`,
- `no_provider_call = true`.

---

## 9. Test coverage

`tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceReplayMultiCaseTest.php`
locks down 12 cases:

1. Battery evidence aggregates per-case difficulty L5 and category summary.
2. Battery replay verifier passes on clean two-case battery.
3. Missing provider receipt blocks battery claim.
4. Dirty after run blocks battery claim.
5. Missing test log blocks battery claim.
6. Missing difficulty blocks battery claim.
7. Patch diff hash mismatch blocks battery claim (prior pack drift).
8. CLI `battery-evidence` writes aggregate pack on disk.
9. CLI `battery-verify-evidence --strict` returns failure on missing difficulty.
10. `aggregate_claim_ready` remains false even when every case passes.
11. `external_rivals_certification` stays blocked in the battery pack.
12. `battery-evidence` and `battery-verify-evidence` registered in CLI.

Plus the 30 v2 single-case tests in
`AtlasForgeRivalsEvidencePackReplayHardeningV2Test` continue to pass.

```bash
/opt/homebrew/bin/php artisan test \
  --filter='AtlasForgeRivalsBatteryEvidenceReplayMultiCase|AtlasForgeRivalsEvidencePackReplayHardeningV2'
```

---

## 10. Files of record

- Per-run collector (extended): `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCollectEvidenceService.php`
- Per-run verifier (v2):       `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackVerifierService.php`
- Battery aggregator (new):    `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceService.php`
- Battery verifier (new):      `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryReplayVerifierService.php`
- Dispatcher:                  `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php`
- CLI:                         `app/Console/Commands/AtlasForgeRivalsCommand.php`
- Corpus (L1-L5 source):       `app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php`
- Tests:                       `tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceReplayMultiCaseTest.php`
- Companion doc:               `atlas-forge-rivals-evidence-pack-replay-hardening-v2.md`

## Resumo

Bateria multi-case do Forge Rivals agora tem evidence pack agregado (battery_evidence_pack.v1) + verifier multi-case (battery_replay_verification.v1) que validam case-by-case com difficulty L1-L5 propagada, category_summary, difficulty_summary, replay_status, missing-evidence terminals e prior-pack drift detection. `aggregate_claim_ready=false` sempre; `external_rivals_certification` permanece BLOCKED.

## Papel no Atlas

Sub-superfície do Forge Rivals que sobe o contrato single-case (v2) para o nível de bateria multi-case. Garante que uma bateria com 12 cases de release é tão auditável quanto um único case — qualquer um dos 12 que falte evidência ou tiver hash mismatch invalida o claim final.

## Onde Se Encaixa

Companion direto de `atlas-forge-rivals-evidence-pack-replay-hardening-v2.md`. Lê o per-run evidence_pack.v2 e o per-run manifest (com `cases[]`) e produz o battery_evidence_pack.v1 + battery_replay_verification.v1. Não toca UI, Voice, Cartografia, Self-Construction ou external_rivals.

## Contratos

Schemas canônicos: `atlas.forge.rivals.battery_evidence_pack.v1`, `atlas.forge.rivals.battery_replay_verification.v1`. Difficulty ladder canônica `L1..L5` no corpus. `aggregate_claim_ready=false` sempre. `external_rivals_certification_status='blocked'` sempre.

## Fluxo

`run-real (multi-case) → collect-evidence(pre_adjudication) → battery-evidence(--run-ids) → battery-verify-evidence(--verify-mode=real_run) → adjudicate → report`. Battery verifier roda case-by-case; uma falha bloqueia o claim final.

## Regras para IA

Nunca aceitar bateria com missing provider receipt, missing patch diff, missing test log, missing difficulty ou hash mismatch em qualquer case. Nunca promover `aggregate_claim_ready=true` a partir do verifier. Nunca destravar `external_rivals_certification` por este doc. Sempre propagar `difficulty_level` (L1-L5) com `difficulty_level_origin`.

## Escopo de Implementacao

Serviços `AtlasForgeRivalsBatteryEvidenceService` (novo), `AtlasForgeRivalsBatteryReplayVerifierService` (novo), `AtlasForgeRivalsCollectEvidenceService` (extended para multi-case + difficulty surface), `AtlasForgeRivalsRunRealService` (perCase com difficulty), `AtlasForgeRivalsActionDispatcher` (wiring), `AtlasForgeRivalsCommand` (actions `battery-evidence` + `battery-verify-evidence`). Testes em `tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceReplayMultiCaseTest.php`. Sem mexer em UI, Voice, Cartografia, Self-Construction ou external_rivals.

## Dependencias

- `atlas-forge-rivals-evidence-pack-replay-hardening-v2.md` (single-case v2).
- `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md` (battery pipeline).
- `atlas-forge-rivals-provider-arena-corpus-v1.md` (corpus L1-L5).
- `WorkspaceHygieneService` para tracked `.pyc` e env de subprocess.

## Evidencias

12 testes em `AtlasForgeRivalsBatteryEvidenceReplayMultiCaseTest` + 30 testes single-case ainda verdes. CLI `battery-evidence` e `battery-verify-evidence` cobertas. Sidecar `battery_evidence_pack.json` validado contra schema canônico.

## Riscos

Aceitar bateria com qualquer artefato per-case ausente. Não detectar prior-pack drift por sobrescrita do battery pack antes de comparar. Permitir difficulty_level=null silencioso. Propagar `aggregate_claim_ready=true` a partir do verifier.

## Exemplos

```bash
# Aggregate two-case battery
php artisan atlas:forge:rivals battery-evidence \
  --run-ids=fr2-quick-A,fr2-quick-B --stage=pre_adjudication --json

# Strict real_run verification across both runs
php artisan atlas:forge:rivals battery-verify-evidence \
  --run-ids=fr2-quick-A,fr2-quick-B \
  --verify-mode=real_run --stage=pre_adjudication \
  --json --strict
```

## Proximas Acoes

Manter doc sincronizado se novos artifact keys per-case forem adicionados. Não promover external rivals cert sem aprovação humana.
