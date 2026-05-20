---
id: atlas-forge-rivals-evidence-pack-replay-hardening-v2
type: engineering_knowledge
title: Atlas Forge Rivals · Evidence Pack + Replay Hardening v2
status: active
category: programming-forge
priority: 90
summary: Hardening v2 do Evidence Pack + Replay do Forge Rivals. Define artifact layout canônico, artifact_index sidecar, verifier strict por modo (dry_run/fake_run/real_run/replay), reason_missing obrigatório, after-clean-check e fail-closed. NÃO destrava external_rivals_certification.
tags:
  - atlas
  - forge
  - rivals
  - evidence
  - replay
  - verifier
capabilities:
  - forge_rivals_evidence_pack_v2
  - forge_rivals_replay_manifest_v2
  - forge_rivals_artifact_index_v1
  - forge_rivals_evidence_verification_v2
decisions:
  - Toda evidência present=false carrega reason_missing — verifier rejeita o pack se faltar.
  - real_run exige provider receipts não-fake, patch diffs, test logs, workspace hashes before/after e after-clean-check clean.
  - Hash mismatch em qualquer artifact present=true => verification status=invalid_hash_mismatch.
  - replay nunca chama provider; verifier nunca chama provider.
  - claim_ready=false em todo pack/verification — verifier nunca promove claim sozinho.
  - external_rivals_certification permanece BLOCKED por construção.
maintenance:
  - Atualizar este doc quando o set de artifact keys, modos do verifier ou o artifact_index schema mudarem.
  - Manter aliases CLI (`evidence`, `verify-evidence`) sincronizados com o command signature.
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCollectEvidenceService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackVerifierService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReplayService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePolicy.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunPathResolver.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackReplayHardeningV2Test.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-evidence-pack-replay-hardening-v2
graph_title: Atlas Forge Rivals · Evidence Pack + Replay Hardening v2
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2
graph_status: active
graph_source: repo
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCollectEvidenceService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackVerifierService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReplayService.php
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-evidence-pack-replay-hardening-v2.md
allowed_changes:
  - Adicionar campos additive ao evidence pack v2 e ao artifact_index v1.
  - Endurecer regras do verifier por modo.
  - Adicionar aliases CLI sem renomear actions.
forbidden_changes:
  - Aceitar real_run sem provider receipt real ou com fake/test_mode=true.
  - Aceitar evidence pack com present=false sem reason_missing.
  - Promover claim_ready a partir do verifier.
  - Destravar `external_rivals_certification` a partir deste contrato.
depends_on:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2
  - atlas-forge-rivals-real-battery-operator-harness-v1
flows_to:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
unlocks:
  - forge_rivals_real_run_evidence_pack_is_blindado
governs:
  - forge_rivals_evidence_verification_contract
evidence:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackReplayHardeningV2Test.php
required_tests:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackReplayHardeningV2Test.php
requires_evidence: true
risk_level: high
next_actions:
  - Monitorar pipeline `run-battery` em `fair`/`full_power` para regressões na coleta de provider_receipts.
  - Sincronizar este doc se novos artifact keys forem adicionados.
---

# Atlas Forge Rivals · Evidence Pack + Replay Hardening v2

**Status:** Delivered 2026-05-15 (Claude D · Evidence/Replay slice)
**Pack schema:** `atlas.forge.rivals.evidence_pack.v2`
**Sidecar schema:** `atlas.forge.rivals.artifact_index.v1`
**Replay schema:** `atlas.forge.rivals.replay.v2`
**Verifier schema:** `atlas.forge.rivals.evidence_verification.v2`
**Entrypoint:** `php artisan atlas:forge:rivals`

This doc tightens the evidence/replay contract delivered in
`atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md`. It is the
canonical reference for how a Rivals run's artifacts are laid out on disk,
what fields the pack must surface, how the replay manifest is verified, and
which modes the verifier admits.

> **External rivals canon.** This hardening NEVER unlocks
> `external_rivals_certification`. The cert remains operator-approval-gated
> and separately tracked. Every layer (collect-evidence, replay,
> verify-evidence, this doc) restates the separation.

---

## 1. Artifact layout (per run)

The path resolver lives at
`AtlasForgeRivalsRunPathResolver::DEFAULT_ROOT = /Users/vitorepf/develop/Atlas-rivals/runs`.
The CLI canonically writes:

```
<root>/<run_id>/
  ├── intent.json
  ├── events.jsonl
  ├── atlas/                         # Atlas Forge arm worktree
  └── rival/                         # rival baseline arm worktree
  └── evidence/
      ├── manifest.json              # run manifest written by run-real
      ├── atlas_receipt.json         # per-arm provider receipt
      ├── rival_receipt.json
      ├── atlas_provider_stdout.log
      ├── atlas_provider_stderr.log
      ├── rival_provider_stdout.log
      ├── rival_provider_stderr.log
      ├── atlas_patch.diff           # per-arm git diff (binary safe)
      ├── rival_patch.diff
      ├── atlas_test.log             # per-arm validation command output
      ├── rival_test.log
      ├── workspace_hashes.json      # before/after sha256 + dirty + blockers
      ├── evidence_pack.json         # the pack itself (v2)
      ├── artifact_index.json        # sidecar — single source for sha256s
      ├── scorecard.json             # written by adjudicator (final stage)
      └── report.md                  # written by report (final stage)
```

`run_id` matches `[A-Za-z0-9_\-.]{1,128}` (see `RunPathResolver::RUN_ID_PATTERN`).
The base path can never escape the configured root (traversal-impossible by
construction). The default root can be overridden via
`config('atlas_rivals.runs_root')`.

> **Why this layout (not `cases/<case_id>/atlas/...`):** A real run is one
> case per `run_id`. Multi-case batteries iterate runs, not folders inside a
> run. Per-arm folders live under `atlas/` and `rival/` (full worktrees);
> per-arm evidence is co-located in `evidence/` for replay locality.

---

## 2. Evidence pack v2 — top-level fields

Schema: `atlas.forge.rivals.evidence_pack.v2`. The pack written to
`evidence/evidence_pack.json` carries:

| Field                                            | Type                | Notes                                                          |
| ------------------------------------------------ | ------------------- | -------------------------------------------------------------- |
| `schema_version`                                 | string              | Pinned to `atlas.forge.rivals.evidence_pack.v2`.               |
| `evidence_stage`                                 | enum                | `pre_adjudication` \| `final`.                                  |
| `mode_for_evidence`                              | enum                | `dry_run` \| `fake_run` \| `real_run` \| `unknown`. Derived from run manifest mode. |
| `run_id`                                         | string              | Mirrors the path resolver.                                     |
| `collected_at`                                   | ISO8601             | Timestamp the pack was assembled.                              |
| `paths`                                          | object              | Full path resolver output for the run.                         |
| `artifacts`                                      | map<key, descriptor>| Each descriptor: `path`, `present`, `bytes`, `sha256`, `policy`, optional `reason_missing`. |
| `required_artifacts` / `optional_artifacts`      | list<string>        | From `AtlasForgeRivalsEvidencePolicy::plan()`.                 |
| `missing_required` / `missing_optional`          | list<string>        | Artifact keys that did not satisfy the policy.                 |
| `missing_evidence`                               | list<string>        | Legacy v1 string-prefixed list (`missing_evidence:<key>`).     |
| `verdict`                                        | string              | Copied from run manifest.                                      |
| `claim_ready`                                    | bool                | **Always `false`** when any `missing_required` is non-empty.   |
| `manifest_summary`                               | object              | Compact snapshot of run manifest (mode/preset/models/case_id). |
| `is_comparable_real_run`                         | bool                | Policy-computed.                                               |
| `workspace_hash_before` / `workspace_hash_after` | object              | Per-arm sha256 of `git status --porcelain -uall` output.        |
| `after_clean_check`                              | object              | `{ran, clean, dirty_after_run, workspace_blockers, arm_blocking_changes, changed_files, head_changed, source, reason_not_run}`. |
| `provider_receipts`                              | object              | Per-arm summary: `present`, `test_mode`/`fake`, `exit_code`, `test_exit_code`, `killed`, `stdout_hash`, `stderr_hash`, `command_hash`, `prompt_hash`, `test_log_hash`, `patch_diff_hash`, `source`. |
| `tracked_bytecode_artifacts`                     | list<string>        | Aggregated across arms; presence is terminal except in `dry_run`. |
| `external_provider_call`                         | bool                | Mirrors run manifest.                                          |
| `provider_tokens_spent`                          | bool                | Mirrors run manifest.                                          |
| `provider_tokens_may_have_been_spent`            | bool                | Same as `provider_tokens_spent` (legacy-safe alias).           |
| `external_rivals_certification_status`           | string              | Constant `blocked`.                                            |
| `promotes_external_rivals_claim`                 | bool                | Always `false`.                                                |
| `separated_from_external_rivals_certification`   | bool                | Always `true`.                                                 |

### Reason missing

Whenever an artifact has `present=false`, the descriptor MUST carry
`reason_missing`. The collector assigns:

- `required_artifact_absent_on_disk:<key>` for required artifacts.
- `optional_for_local_fake_mode:<key>` for any per-arm artifact missing under
  `local_fake`.
- `optional_for_invalid_verdict:<verdict>` for invalid run verdicts.
- `optional_for_{inconclusive,unknown}_verdict` for inconclusive/unknown.
- `scorecard_not_required_for_pre_adjudication_stage` for the scorecard at
  the pre-adjudication stage.

If the verifier finds a `present=false` descriptor lacking `reason_missing`,
it emits `absent_without_reason_missing:<key>` and marks the pack invalid.

---

## 3. Artifact index v1 — sidecar

Schema: `atlas.forge.rivals.artifact_index.v1`. Written to
`evidence/artifact_index.json` next to the pack. The index is the single
source of truth for replay tooling that wants a flat map of artifact key →
`{path, present, bytes, sha256, policy, reason_missing}` without parsing the
full evidence pack.

The verifier reads it as a sanity sidecar; if the schema doesn't match the
canonical version, verification is invalidated with
`artifact_index_schema_version_invalid:<found>`.

---

## 4. Replay manifest v2

Schema: `atlas.forge.rivals.replay.v2`. Replay never invokes the provider.
It walks the artifact list, re-hashes every `present=true` entry, and splits
the outcome into:

- `required_mismatches`: required artifact absent / missing on disk.
- `optional_missing`: optional artifact absent (informational, never blocks).
- `hash_mismatches`: present-but-tampered artifacts.
- `mismatches`: the union of blockers (legacy v1 field, unchanged semantics).

The decision payload always carries `claim_ready=false` unless the manifest
itself, the scorecard (final stage) and `verdict=comparable` all agree.

---

## 5. Verifier modes

Schema: `atlas.forge.rivals.evidence_verification.v2`. The verifier loads
`evidence_pack.json`, re-hashes every `present=true` artifact, runs replay
internally and applies one of four mode-specific strict checks.

| Mode        | Requires provider receipt? | Real receipt rejected if `test_mode=true`? | Requires patch + test log + workspace hashes? | Notes |
| ----------- | -------------------------- | ------------------------------------------ | --------------------------------------------- | ----- |
| `dry_run`   | No                         | n/a                                        | No                                            | Refuses pack with `external_provider_call=true`. |
| `fake_run`  | Yes — but only `test_mode=true` accepted | Yes (it's the inverse)               | No                                            | `external_provider_call` must be false.          |
| `real_run`  | Yes — must NOT be `test_mode=true` / `fake=true` | Yes                                    | Yes                                           | `mode_for_evidence` must equal `real_run`; `after_clean_check.ran=true && clean=true`; tracked `.pyc` blocks. |
| `replay`    | Inherits from pack         | n/a                                        | No                                            | Pure integrity: every `present=true` sha256 must match disk; artifact index schema must match; legacy v1 pack still replays. |

Verifier output:

- `status`: `passed` \| `blocked` \| `invalid_missing_evidence` \| `invalid_hash_mismatch`.
- `claim_ready`: **always `false`**. Verifier never promotes the claim.
- `blockers`, `invalid_reasons`, `missing_evidence`: parallel lists for
  human-readable diagnosis and machine consumption.
- `external_rivals_certification_status`: always `blocked`.
- `no_provider_call`: always `true`.

---

## 6. CLI surface

The canonical CLI is `atlas:forge:rivals`. Three actions matter for this
hardening:

```bash
# Collect / re-collect the evidence pack (and the sidecar artifact_index.json).
php artisan atlas:forge:rivals evidence --run-id=<id> --json --strict
# Alias of the legacy `collect-evidence` action — kept verbatim for compat.

# Replay: hash check + replay manifest verification (no provider call).
php artisan atlas:forge:rivals replay --run-id=<id> --json --strict

# Verify: strict mode-aware contract enforcement.
php artisan atlas:forge:rivals verify-evidence --run-id=<id> \
  --mode=real_run --json --strict
# `--mode` accepts dry_run|fake_run|real_run|replay; the briefing-canon form
# `verify-evidence --mode=real_run` is honored verbatim. A dedicated
# `--verify-mode` flag exists to avoid collision with run-real's `--mode`
# (fair|full_power|local_fake) when the operator wants both in the same
# wrapper script.
```

The `--strict` flag turns any non-`passed` verification status into exit
code `1`. Without `--strict`, the command still surfaces the verdict but
returns `0` so dashboards can inspect the JSON.

Aliases recognised by the dispatcher: `verify`, `evidence-verify`,
`verify-pack`, `verify-evidence-pack` all resolve to `verify-evidence`.
`collect` and `evidence` resolve to `collect-evidence`.

---

## 7. Fail-closed rules (single page)

A run cannot become a score / claim if any of these are true:

- `provider_receipt:atlas` or `provider_receipt:rival` is missing in
  `real_run`.
- `provider_receipt.test_mode=true` (or `fake=true`) and the verifier mode
  is `real_run`.
- `provider_receipt` is missing the canonical hashes
  (`stdout_hash`/`stderr_hash`/`command_hash`/`prompt_hash`).
- `atlas_patch` or `rival_patch` is absent in `real_run`.
- `atlas_test_log` or `rival_test_log` is absent in `real_run`.
- `workspace_hash_before` or `workspace_hash_after` is missing per arm.
- `after_clean_check.ran` is false (no run was actually recorded).
- `after_clean_check.clean` is false (workspace dirty after run).
- Tracked `.pyc`/`.pyo`/`__pycache__` files exist in the workspace
  (`tracked_bytecode_artifacts` non-empty).
- Any artifact declared `present=true` has a sha256 mismatch on disk
  (`artifact_hash_mismatch:<key>`).
- Any artifact has `present=false` without `reason_missing`.
- `claim_ready=true` is encoded in the pack (must remain false).
- `promotes_external_rivals_claim=true` (must remain false).
- `events.jsonl` is missing on disk.
- `manifest.json` is missing on disk.
- The artifact index sidecar's `schema_version` is not
  `atlas.forge.rivals.artifact_index.v1`.

When any rule fires:
- `status = invalid_missing_evidence | invalid_hash_mismatch | blocked`,
- `claim_ready = false`,
- `external_rivals_certification_status = blocked`,
- `no_provider_call = true`.

---

## 8. PYTHONDONTWRITEBYTECODE — operator subprocess contract

Every subprocess the harness spawns inherits the env block from
`WorkspaceHygieneService::forceBytecodeDisabledEnv()`:

- `PYTHONDONTWRITEBYTECODE=1` so Python never writes `.pyc` into the
  worktree at runtime,
- `PYTHONPYCACHEPREFIX=<sys_get_temp_dir>/atlas-rivals-pycache` so any
  unavoidable bytecode lands outside the worktree.

If `git ls-files '*.pyc' '*.pyo' '*__pycache__*'` returns anything in the
worktree, the run is invalid regardless of env — the operator must run the
resolution command the doctor service prints.

---

## 9. Compatibilidade com v1

Packs older than v2 (`schema_version =
atlas.forge.rivals.evidence_pack.v1`) still replay:

- replay treats every artifact in `artifacts` as required (v1 semantics),
- the verifier accepts the legacy schema in modes `replay` and `dry_run`,
- the legacy `missing_evidence` prefixed list keeps being emitted alongside
  v2's `missing_required` so the adjudicator's `evidence_complete` hard gate
  remains unchanged.

---

## 10. Test coverage

`tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackReplayHardeningV2Test.php`
locks down all 30 obligatory contract tests from the Claude D briefing:

1. real_run evidence pack carries provider receipts.
2. missing provider receipt invalidates verification.
3. missing patch diff invalidates verification.
4. missing test log invalidates verification.
5. missing events.jsonl invalidates verification.
6. artifact hash mismatch invalidates verification.
7. `present=false` without `reason_missing` invalidates verification.
8. optional artifact with `reason_missing` is accepted.
9. clean after-check passes real_run verification.
10. dirty after-check invalidates real_run verification.
11. tracked `.pyc` blocks real_run verification.
12. `WorkspaceHygieneService::forceBytecodeDisabledEnv()` returns the canonical block.
13. `events.jsonl` supports canonical event kinds.
14. replay validates artifacts without provider call.
15. replay with hash mismatch fails.
16. `manifest` sha256 in the artifact index is deterministic across collects.
17. `case_id` in the pack stays stable across collects.
18. artifact sha256 in the pack stays stable across collects.
19. `dry_run` does not require provider receipt.
20. `fake_run` accepts `test_mode=true` receipts.
21. `real_run` rejects `test_mode=true` / `fake=true` receipts.
22. `external_rivals_certification_status` stays `blocked` in every mode.
23. `claim_ready=false` when evidence is invalid.
24. passed verification does not promote `claim_ready=true`.
25. CLI `evidence` writes the pack + the artifact index sidecar.
26. CLI `replay` returns a replay status payload.
27. CLI `verify-evidence` with `--strict` returns exit 1 on invalid.
28. legacy v1 packs still replay via the v2 services.
29. the canonical doc exists at the expected repo path.
30. `verify-evidence` and `evidence` are registered actions in the CLI signature.

Run only this suite:

```bash
/opt/homebrew/bin/php artisan test \
  --filter='AtlasForgeRivalsEvidencePackReplayHardeningV2'
```

Run the full Rivals regression:

```bash
/opt/homebrew/bin/php artisan test \
  --filter='Rivals|ForgeRivals|ForgeNativeRivals|EvidencePack|Replay|ProviderArena'
```

---

## 11. Files of record

- Evidence collect: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCollectEvidenceService.php`
- Replay: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReplayService.php`
- Verifier (v2): `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackVerifierService.php`
- Policy: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePolicy.php`
- Path resolver: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunPathResolver.php`
- Dispatcher: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php`
- CLI: `app/Console/Commands/AtlasForgeRivalsCommand.php`
- Tests: `tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackReplayHardeningV2Test.php`
- Companion doc: `atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md`

## Resumo

Evidence Pack v2 + Replay Manifest v2 + Artifact Index v1 + Verifier v2 entregues 2026-05-15. Evidence pack carrega provider receipts, workspace hashes, after-clean-check, reason_missing por artifact ausente e `tracked_bytecode_artifacts`. Verifier strict por modo (dry_run/fake_run/real_run/replay) recusa real_run sem provider receipt real, sem patch diff, sem test log, com workspace dirty, com tracked .pyc, com hash mismatch ou com present=false sem reason_missing. claim_ready=false em todo lugar; external_rivals_certification permanece blocked.

## Papel no Atlas

Sub-superfície do Forge Rivals responsável pelo contrato de integridade do evidence pack e do replay manifest. Define quais campos o pack carrega, como o artifact index sidecar é montado e como o verifier strict admite ou rejeita um run para virar score/claim.

## Onde Se Encaixa

Companion direto de `atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md`. Endurece os contratos consumidos pelo Perfect Battery e pelo Real Battery Operator Harness. Não toca UI, Voice, Cartografia ou Self-Construction.

## Contratos

Schemas canônicos: `atlas.forge.rivals.evidence_pack.v2`, `atlas.forge.rivals.artifact_index.v1`, `atlas.forge.rivals.replay.v2`, `atlas.forge.rivals.evidence_verification.v2`. Verifier admite `dry_run|fake_run|real_run|replay`. `external_rivals_certification` permanece BLOCKED em todo modo. `claim_ready` nunca promovido pelo verifier sozinho.

## Fluxo

`run-real → collect-evidence(pre_adjudication) → verify-evidence(real_run) → replay(pre_adjudication) → adjudicate → collect-evidence(final) → verify-evidence(replay) → replay(final) → report`. Operador pode chamar `verify-evidence` ad-hoc em qualquer ponto.

## Regras para IA

Nunca aceitar pack com `present=false` sem `reason_missing`. Nunca admitir `real_run` com provider receipt `test_mode=true`/`fake=true`. Nunca promover `claim_ready=true` a partir do verifier. Nunca destravar `external_rivals_certification` por este doc. Sempre injetar `PYTHONDONTWRITEBYTECODE=1` em subprocessos.

## Escopo de Implementacao

Serviços `AtlasForgeRivalsCollectEvidenceService` (additive), `AtlasForgeRivalsEvidencePackVerifierService` (novo), `AtlasForgeRivalsReplayService` (mantido), `AtlasForgeRivalsActionDispatcher` (wiring), `AtlasForgeRivalsCommand` (actions `evidence` + `verify-evidence`). Testes em `tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePackReplayHardeningV2Test.php`. Sem mexer em UI, Voice, Cartografia, Self-Construction ou external_rivals.

## Dependencias

- `atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md`
- `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`
- `atlas-forge-rivals-real-battery-operator-harness-v1.md`
- `WorkspaceHygieneService` para tracked `.pyc` e env de subprocess.

## Evidencias

30 testes em `AtlasForgeRivalsEvidencePackReplayHardeningV2Test`. CLI `evidence`/`replay`/`verify-evidence` cobertas por testes integrados. Sidecar `artifact_index.json` validado contra schema canônico.

## Riscos

Reintroduzir `present=false` sem `reason_missing` em algum collector. Aceitar `test_mode=true` em modo `real_run` por bug do verifier. Permitir hash mismatch silenciar no replay. Tratar `claim_ready=true` como derivável do verifier.

## Exemplos

```bash
# Coleta + grava artifact_index.json
php artisan atlas:forge:rivals evidence --run-id=fr2-20260515-abc123 --json

# Replay (rehash artifacts)
php artisan atlas:forge:rivals replay --run-id=fr2-20260515-abc123 --json --strict

# Verifier strict real_run (recusa fake receipt, patch diff vazio, workspace dirty)
php artisan atlas:forge:rivals verify-evidence \
  --run-id=fr2-20260515-abc123 \
  --mode=real_run \
  --json --strict
```

## Proximas Acoes

Sincronizar este doc se algum artifact key novo entrar em `AtlasForgeRivalsCollectEvidenceService` ou se o verifier ganhar um quinto modo. Nunca promover `external_rivals_certification` por este doc.
