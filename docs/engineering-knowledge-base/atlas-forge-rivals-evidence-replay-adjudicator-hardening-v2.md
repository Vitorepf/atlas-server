---
id: atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2
type: engineering_knowledge
title: Atlas Forge Rivals · Evidence / Replay / Adjudicator Hardening v2
status: active
category: programming-forge
priority: 88
summary: Hardening v2 do ciclo evidence → replay → adjudicate → report do Forge Rivals. Quebra a ordem circular do v1 separando `collect-evidence` em duas fases (pre_adjudication / final) e isolando `replay` por fase. NÃO destrava external_rivals_certification.
tags:
  - atlas
  - forge
  - rivals
  - evidence
  - replay
  - adjudicator
capabilities:
  - forge_rivals_evidence_replay_v2
  - forge_rivals_adjudicator_contract
  - forge_rivals_two_stage_evidence
decisions:
  - O ciclo evidence → replay roda duas vezes — antes e depois do adjudicator — para eliminar a dependência circular do scorecard.
  - O contrato continua subordinado à canon `external_rivals_certification BLOCKED por construção`.
  - Hardening v2 é additive sobre o v1 do Perfect Battery; não remove fases.
maintenance:
  - Atualizar quando `AtlasForgeRivalsCollectEvidenceService`, `AtlasForgeRivalsReplayService`, `AtlasForgeRivalsAdjudicatorService` ou `AtlasForgeRivalsReportService` mudarem de shape.
  - Não declarar `external_rivals_certification` desbloqueado a partir deste doc.
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCollectEvidenceService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReplayService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2
graph_title: Atlas Forge Rivals · Evidence / Replay / Adjudicator Hardening v2
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
human_name: "Atlas Forge Rivals · Evidence / Replay / Adjudicator Hardening v2"
canonical_name: "Atlas Forge Rivals · Evidence / Replay / Adjudicator Hardening v2"
technical_name: atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCollectEvidenceService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReplayService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md
allowed_changes:
  - Adicionar campos additive ao evidence pack v2 sem renomes.
  - Endurecer regras de replay para artefatos required/optional por estágio.
forbidden_changes:
  - Fundir `collect-evidence(pre_adjudication)` e `collect-evidence(final)` em uma chamada só.
  - Exigir `scorecard.json` em `pre_adjudication`.
  - Declarar `external_rivals_certification` desbloqueado a partir deste doc.
depends_on:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-operator-battery-v2
flows_to:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
unlocks:
  - forge_rivals_run_battery_v2_pipeline_passes_without_circular_replay
governs:
  - forge_rivals_evidence_replay_contract
evidence:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceReplayAdjudicatorHardeningV2Test.php
evidence_refs:
  - test: AtlasForgeRivalsEvidenceReplayAdjudicatorHardeningV2Test
  - symbol: AtlasForgeRivalsCollectEvidenceService
required_tests:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceReplayAdjudicatorHardeningV2Test.php
requires_evidence: true
risk_level: high
next_actions:
  - Monitorar pipeline `run-battery` em modos `fair`/`full_power` quanto a regressões circulares.
---
# Atlas Forge Rivals · Evidence / Replay / Adjudicator Hardening v2

**Schema:** `atlas.forge.rivals.evidence_pack.v2`
**Replay schema:** `atlas.forge.rivals.replay.v2`
**Adjudicator schema:** `atlas.forge.rivals.adjudication.v1` (unchanged)
**Report schema:** `atlas.forge.rivals.report.v2` (unchanged)
**Entrypoint:** `php artisan atlas:forge:rivals run-battery`
**Status:** Delivered 2026-05-15. Companion to
`atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`.

This doc is the canonical contract for the v2 hardening of the
evidence → replay → adjudicate → report cycle. It does not replace the
perfect battery doc; it tightens the contract so the single-button battery
cannot die from circular ordering between evidence and adjudication.

> **External rivals canon.** Hardening v2 NEVER unlocks
> `external_rivals_certification`. That cert remains operator-approval-gated
> and separately tracked. Every layer (run-battery, adjudicator, report,
> certification, this doc) restates the separation explicitly.

---

## 1. The bug v2 closes

Under the v1 chain the operator-facing single button ran:

```
run-real → collect-evidence → replay → adjudicate → report
```

`collect-evidence` enumerated `scorecard.json` as an artifact and recorded
`present=false` because the adjudicator had not yet written it. `replay`
then walked every entry in the artifact dict and any `present=false` value
produced a `:not_present_at_replay` mismatch — including the scorecard that
the very next phase would have created. Result: `replay` blocked with
`scorecard:not_present_at_replay`, the adjudicator was never reached, and
the operator saw an opaque "replay failed" with no explanation that the
contract was itself circular.

Hardening v2 splits the cycle into two explicit stages and pushes the
required/optional decision into a single policy so the bug becomes
impossible to express.

---

## 2. Canonical v2 ordering

The `run-battery` orchestrator now runs **twelve** phases, in this order:

```
doctor → setup → preflight → dry-run → plan-real → run-real →
collect-evidence(pre_adjudication) → replay(pre_adjudication) →
adjudicate →
collect-evidence(final) → replay(final) →
report
```

- `collect-evidence(pre_adjudication)` builds the artifact bundle BEFORE
  adjudication exists. `scorecard` is not even enumerated at this stage.
- `replay(pre_adjudication)` validates that bundle and only blocks on
  artifacts the policy lists as required at that stage.
- `adjudicate` writes `evidence/scorecard.json` deterministically.
- `collect-evidence(final)` re-collects, now that the scorecard exists,
  capturing its sha256.
- `replay(final)` re-validates the bundle including the scorecard hash.
- `report` renders the executive markdown explaining the outcome.

`full-smoke` is a sibling chain (`collect-evidence → replay → report`,
no adjudication step). It now pins both `collect-evidence` and `replay` to
`pre_adjudication` so the scorecard is never expected in that path.

---

## 3. `evidence_stage` and `artifact_required_policy`

Single source of truth:
`app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePolicy.php`.

Two stages exist:

| Stage              | Includes scorecard? | Used by                                       |
| ------------------ | ------------------- | --------------------------------------------- |
| `pre_adjudication` | No                  | run-battery (phase 7/8), adjudicator's internal replay, full-smoke |
| `final`            | Yes — required      | run-battery (phase 10/11), report's internal replay               |

`AtlasForgeRivalsEvidencePolicy::plan($stage, $manifest)` returns the
required + optional artifact split for a `(stage, verdict, mode)` triple:

| Artifact key                                            | When required                            | When optional                     |
| ------------------------------------------------------- | ---------------------------------------- | --------------------------------- |
| `manifest`, `events_jsonl`, `intent_json`               | Always                                   | Never                             |
| `atlas_receipt`, `rival_receipt`, `workspace_hashes`    | verdict = `comparable`                   | verdict starts with `invalid` / `unknown` / `inconclusive` |
| `atlas_patch`, `rival_patch`, `atlas_test_log`, `rival_test_log` | `comparable` AND mode ∈ `{fair, full_power}` | Any other (esp. `local_fake` / invalid run) |
| `scorecard`                                             | stage = `final` (always required there)  | Stage = `pre_adjudication` (not enumerated) |

`is_comparable_real_run = verdict === 'comparable' && mode ∈ {fair, full_power}` is
the policy's only branch. `local_fake` is intentionally allowed to lack
patch diffs and test logs because the in-process fake provider does not
produce them.

---

## 4. Schema deltas

### `atlas.forge.rivals.evidence_pack.v2`

New fields (v1 fields are preserved verbatim):

- `evidence_stage`: `"pre_adjudication"` | `"final"`
- `required_artifacts`: `list<string>`
- `optional_artifacts`: `list<string>`
- `artifact_policy`: `map<key, "required"|"optional">`
- `missing_required`: `list<string>` of artifact keys
- `missing_optional`: `list<string>` of artifact keys
- `is_comparable_real_run`: `bool`

Backward-compat: `missing_evidence` (v1 string-prefixed list, e.g.
`"missing_evidence:manifest"`) is still emitted and equals `missing_required`
projected through the prefix. The adjudicator's `evidence_complete` hard
gate continues to consume `missing_evidence` unchanged.

### `atlas.forge.rivals.replay.v2`

New fields (v1 fields are preserved verbatim):

- `replay_stage`: `"pre_adjudication"` | `"final"`
- `required_mismatches`: `list<string>` — required artifacts absent / missing on disk
- `optional_missing`: `list<string>` — optional artifacts absent (informational, never a blocker)
- `hash_mismatches`: `list<string>` — present-but-tampered artifacts

Backward-compat: `mismatches` continues to be the union of
`required_mismatches + hash_mismatches` (the blocker set), unchanged from
v1 semantics.

Legacy v1 packs (no `evidence_stage` / `required_artifacts` /
`optional_artifacts`) still replay correctly: replay treats every artifact
listed in the pack as required, preserving v1 outcomes.

---

## 5. Adjudicator contract (hardened wiring + gate outcome separation)

The adjudicator remains:

- Deterministic and local only — no LLM is ever asked to judge.
- Infrastructure/evidence/replay/scope hard gates keep `score = null` and
  `winner = null` (`WINNER_NONE`).
- One-sided deterministic test failures may emit `gate_winner=atlas|rival` and
  `gate_result.kind=one_sided_test_failure`, but still keep `winner = null`,
  both scores null, and `quality_score_available=false`. This prevents
  "Atlas 100 x 0 Claude" style false quality claims.
- `tie_threshold` defaults to 5.0 (`|atlas_score - rival_score| < threshold` ⇒
  `human_review_required_tie`).
- `claim_ready = true` only when winner ∈ `{atlas, rival}` and every hard
  gate passed. Even then, **this never unlocks** `external_rivals_certification`.

The only wiring change: when the adjudicator runs its internal replay
verification, it now calls
`replay->replay(['evidence_stage' => 'pre_adjudication'])` explicitly. That
breaks the circular requirement on the scorecard (which the adjudicator is
about to write) and makes the test
`test_full_local_fake_chain_reaches_report_without_circular_block` pass
end-to-end.

---

## 6. Report contract (defensive rendering)

The premium report keeps refusing to declare a winner unless ALL of:

- `manifest.verdict === 'comparable'`
- `replay.replay_passes === true`
- `scorecard.hard_failures` is empty
- `scorecard.winner ∈ {atlas, rival, human_review_required_tie}`

It renders these outcomes:

| Outcome                  | `winner` | `claim_ready` | `declared_why`                |
| ------------------------ | -------- | ------------- | ----------------------------- |
| Comparable + quality win | `atlas` or `rival` | `true`  | `quality_winner:atlas|rival`  |
| Comparable + tie         | `human_review_required_tie` | `false` | `statistical_tie_human_review_required` |
| Gate outcome only        | `null` with `gate_winner=atlas|rival` | `false` | `gate_winner:atlas|rival_no_quality_score` |
| Invalid                  | `null`   | `false`       | `invalid:<verdict>`           |
| Replay-failed            | `null`   | `false`       | `replay_failed`               |
| Hard-fail                | `null`   | `false`       | `hard_failures:<codes>`       |
| Blocked evidence         | `null`   | `false`       | `adjudication_missing`        |

The heredoc rendering is now defensive against missing manifest keys
(`workspace_hash_before`, `atlas_receipt_hash`, etc.) so the report never
explodes mid-render — it surfaces empty strings instead.

The response always sets:

- `unlocks_external_rivals_certification: false`
- `separated_from_external_rivals_certification: true`
- `external_provider_call: false`

---

## 7. CLI flags

```bash
# Always-on actions
php artisan atlas:forge:rivals collect-evidence --run-id=<id> --json
php artisan atlas:forge:rivals replay           --run-id=<id> --json
php artisan atlas:forge:rivals adjudicate       --run-id=<id> --json
php artisan atlas:forge:rivals report           --run-id=<id> --json
php artisan atlas:forge:rivals run-battery      --mode=local_fake --atlas-model=sonnet \
                                                --rival=claude_sonnet --preset=quick --json --strict

# New optional flags
--stage=pre_adjudication        # Force collect-evidence/replay to use this stage
--stage=final                   # (default for standalone collect/replay calls)
--require-final-scorecard       # Alias for --stage=final, surfaces intent
```

If neither `--stage` nor `--require-final-scorecard` is given, the input
field stays `null`. The CollectEvidence service normalizes a missing stage
to `final` (the v1-compatible default) so existing scripts keep working.
The internal `run-battery` orchestrator always passes the stage explicitly.

---

## 8. Test coverage

`tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceReplayAdjudicatorHardeningV2Test.php`
locks down the v2 contract with 14 cases:

- `policy_pre_adjudication_does_not_require_scorecard_for_comparable_real_run`
- `policy_final_requires_scorecard_when_comparable`
- `policy_invalid_local_fake_run_treats_per_arm_artifacts_as_optional`
- `collect_pre_adjudication_does_not_require_scorecard`
- `collect_final_requires_scorecard_and_blocks_when_absent`
- `collect_final_passes_when_scorecard_exists`
- `replay_pre_adjudication_does_not_block_on_scorecard_absence`
- `replay_final_validates_scorecard_hash`
- `replay_local_fake_invalid_run_lists_optional_missing_without_blocking`
- `replay_comparable_real_run_requires_patch_and_test_logs`
- `invalid_run_never_claims_ready`
- `hard_failure_keeps_score_null_and_winner_null`
- `report_renders_invalid_run_without_winner_and_without_claim`
- `full_local_fake_chain_reaches_report_without_circular_block`
- `legacy_v1_pack_without_evidence_stage_still_replays`

The existing adjudicator test
(`AtlasForgeRivalsAdjudicatorServiceTest`) continues to pass: legacy
synthetic packs without `evidence_stage` exercise the v1 compatibility
branch in replay.

Run the full battery:

```bash
/opt/homebrew/bin/php artisan test \
  --filter='AtlasForgeRivals|RivalsForge|AtlasRivals|ForgeNativeRivals|FairClaudePolicy'
```

---

## 9. Operator runbook (delta vs perfect battery v1)

Same headline command — no operator-visible regression:

```bash
php artisan atlas:forge:rivals run-battery \
  --mode=local_fake --atlas-model=sonnet --rival=claude_sonnet \
  --preset=quick --json --strict
```

Expected output for `local_fake`:

- `status: "ok"`, `phases_passed: 12`, `phases_failed: 0`.
- `winner: null` (correct — local_fake produces `verdict=invalid_no_patch_diff`).
- `scorecard.hard_failures` lists `verdict_comparable, patch_diff_present_atlas, patch_diff_present_rival`.
- `claim_ready: false`, `external_provider_call: false`,
  `provider_tokens_spent: false`,
  `separated_from_external_rivals_certification: true`.
- `report_path` is on disk, the markdown declares `Verdict: INVALID · ZERO claim`.

For a `comparable` real run the same 12 phases pass; the adjudicator emits
a quality-determined winner or a `human_review_required_tie`. `claim_ready`
becomes `true` only on a clean atlas-or-rival victory, and even then
`unlocks_external_rivals_certification` stays `false`.

---

## 10. Files of record

- Policy: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidencePolicy.php`
- Collect: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsCollectEvidenceService.php`
- Replay: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReplayService.php`
- Adjudicator: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php`
- Report: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php`
- Run-battery: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php`
- Full-smoke: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsFullSmokeService.php`
- CLI: `app/Console/Commands/AtlasForgeRivalsCommand.php`
- Contract tests: `tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceReplayAdjudicatorHardeningV2Test.php`
- Related doc: `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`
- Related cert: `atlas_forge_rivals_perfect_battery_certification` (unchanged invariants)

## Resumo

Hardening v2 elimina a ordem circular do ciclo `collect-evidence → replay → adjudicate → report` separando coleta de evidência em dois estágios (`pre_adjudication` antes do scorecard, `final` depois) e isolando `replay` por estágio. Mantém as invariantes do Perfect Battery e do `external_rivals_certification` BLOCKED.

## Papel no Atlas

Sub-superficie do Forge Rivals responsável pelo contrato de integridade entre `collect-evidence`, `replay`, `adjudicate` e `report`. Define a política de quais artefatos são obrigatórios em qual fase.

## Onde Se Encaixa

Companheiro de `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md` e dependência direta de `atlas-forge-rivals-provider-arena-core-v1.md`. Governa o pipeline executado pelo CLI `atlas:forge:rivals run-battery`.

## Contratos

Schemas: `atlas.forge.rivals.evidence_pack.v2` (collect-evidence), `atlas.forge.rivals.replay.v2` (replay), `atlas.forge.rivals.adjudication.v1` (adjudicator, inalterado), `atlas.forge.rivals.report.v2` (report, inalterado). Política: `collect-evidence(pre_adjudication)` nunca enumera `scorecard.json`; `replay(pre_adjudication)` só bloqueia em artefatos required para o estágio; `collect-evidence(final)` captura sha256 do scorecard; `replay(final)` valida o bundle completo. `external_rivals_certification` permanece BLOCKED.

## Fluxo

`doctor → setup → preflight → dry-run → plan-real → run-real → collect-evidence(pre_adjudication) → replay(pre_adjudication) → adjudicate → collect-evidence(final) → replay(final) → report`.

## Regras para IA

Nunca declare `external_rivals_certification` desbloqueado a partir deste doc. Nunca colapse as duas chamadas de `collect-evidence` em uma só. Nunca exija `scorecard.json` em `pre_adjudication`.

## Escopo de Implementacao

Serviços `AtlasForgeRivalsCollectEvidenceService`, `AtlasForgeRivalsReplayService`, `AtlasForgeRivalsAdjudicatorService`, `AtlasForgeRivalsReportService`, `AtlasForgeRivalsRunBatteryService`, `AtlasForgeRivalsFullSmokeService` e CLI `AtlasForgeRivalsCommand`.

## Dependencias

`atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`, `atlas-forge-rivals-operator-battery-v2.md`, contratos de evidência e replay já existentes.

## Evidencias

Contract tests em `tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsEvidenceReplayAdjudicatorHardeningV2Test.php` e a entrega registrada em 2026-05-15.

## Riscos

Reintroduzir circularidade ao fundir os estágios; declarar `scorecard` obrigatório em `pre_adjudication`; promover `external_rivals_certification` sem aprovação humana.

## Exemplos

Operador roda `php artisan atlas:forge:rivals run-battery --mode=fair ...`; pipeline progride sem `scorecard:not_present_at_replay`; adjudicator escreve scorecard; segunda passagem de evidence/replay confirma sha256 do scorecard; report renderiza.

## Proximas Acoes

Manter doc sincronizado caso o conjunto de artefatos required/optional mude por fase. Não promover external rivals cert sem aprovação humana.
