---
id: atlas-forge-rivals-scoring-sanity-fairness-confidence-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Scoring Sanity, Fairness & Confidence v1
status: deprecated
superseded_by: docs/engineering-knowledge-base/atlas-rivals-product-v1.md
implementation_state: source_material_no_runtime_authority
category: programming-forge
priority: 92
summary: "SUPERSEDED by atlas-rivals-product-v1 (Rivals 2.0). Legacy: Canon de sanidade, validade, fairness e confidence para impedir que runs quebradas, fixtures invalidas, replay ausente ou evidencia incompleta virem score confiavel no Forge Rivals."
tags:
  - atlas
  - forge
  - rivals
  - scoring
  - fairness
  - confidence
capabilities:
  - forge_rivals_scoring_sanity_gates
  - forge_rivals_validity_classes
  - forge_rivals_confidence_level_v1
  - forge_rivals_release_trusted_guard
flows_to:
  - atlas-forge-rivals-battery-report-v2
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-programming-professional-completion-audit
unlocks:
  - forge_rivals_score_claim_sanity
  - forge_rivals_release_trusted_confidence_ladder
governs:
  - forge_rivals_single_run_validity
  - forge_rivals_battery_release_trusted_rules
  - forge_rivals_external_claim_blockers
evidence:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorScoringSanityV1Test.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsBatteryReportReleaseTrustedV1Test.php
evidence_refs:
  - test: AtlasForgeRivalsAdjudicatorScoringSanityV1Test
  - symbol: AtlasForgeRivalsAdjudicatorService
required_tests:
  - php artisan test tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorScoringSanityV1Test.php
  - php artisan test tests/Feature/Ai/Programming/AtlasForgeRivalsBatteryReportReleaseTrustedV1Test.php
requires_evidence: true
risk_level: high
next_actions:
  - Manter este canon alinhado com o adjudicator single-run e com o battery report release_trusted.
  - Nunca usar confidence_level baixo, invalido ou local_fake como claim externo.
decisions:
  - Um score extremo ou perfeito e harness failure ate que provider run, fixture, replay, evidencia e artefatos comparaveis estejam verdes.
  - release_trusted e sinal battery-wide; nunca e atribuido por um unico caso.
  - external_rivals_certification permanece bloqueado por decisao de operador, mesmo quando o score local e reportavel.
maintenance:
  - Atualizar antes de alterar sanity gates, validity_class, confidence_level_v1 ou release_trusted.
  - Manter testes de adjudicator e battery report cobrindo qualquer novo blocker de fairness.
related_paths:
  - docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryReportService.php
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorScoringSanityV1Test.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsBatteryReportReleaseTrustedV1Test.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-battery-report-v2.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
allowed_changes:
  - Ajustar thresholds, gates e mensagens quando testes de adjudicator e battery report forem atualizados juntos.
  - Adicionar novos blockers de fairness desde que invalidem scores inseguros por default.
forbidden_changes:
  - Promover score invalido, local_fake, sem replay ou sem evidencia completa para claim externo.
  - Desbloquear external_rivals_certification por este canon.
  - Chamar provider, gastar tokens ou usar LLM judge para justificar score local.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-battery-report-v2
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-scoring-sanity-fairness-confidence-v1
graph_title: Atlas Forge Rivals · Scoring Sanity, Fairness & Confidence v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-benchmark-strategy-v1
graph_status: deprecated
graph_source: repo
human_name: "Atlas Forge Rivals · Scoring Sanity, Fairness & Confidence v1"
canonical_name: "Atlas Forge Rivals · Scoring Sanity, Fairness & Confidence v1"
technical_name: atlas-forge-rivals-scoring-sanity-fairness-confidence-v1
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-scoring-sanity-fairness-confidence-v1.md
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-scoring-sanity-fairness-confidence-v1.md
---

> SUPERSEDED (2026-07-02 / docs overhaul 2026-07-09): Rivals 1.0 removido. Canon vivo: `atlas-rivals-product-v1.md` + `atlas-rivals-structure-v1.md`. Kill-map histórico: `atlas-rivals2-rebuild-map-v1.md`. Runtime: `atlas:rivals`.


# Atlas Forge Rivals · Scoring Sanity, Fairness & Confidence v1

## PÉTRO — Curriculum ladder / anti-ceiling fallacy

**Canon:** `docs/engineering-knowledge-base/atlas-rivals-curriculum-ladder-and-anti-ceiling-fallacy.md`

- **80–100% num teste = aprovação de NÍVEL escolar**, nunca teto do modelo, do Atlas, nem “inteligência suprema”.
- Suite saturada → **S_sanity** (regressão/paridade). Multiplicador e claim forte → só em **S_frontier** não saturado.
- Raw ou Atlas dominou o frontier → **promover L_{k+1}** (próximo livro). **Proibido** dizer “50× / N×M impossível para sempre porque já faz 80%”.
- **2026 ≠ 2030 ≠ 2040 ≠ 2050**; o currículo **sobe**. Atlas 80% em teste medíocre = **inútil como meta final** — elevar o nível.
- Essência Rivals: medir com/sem Atlas e **sempre melhorar o Atlas no frontier**, não congelar tabuada.



**Status:** active · canon · 2026-05-16
**Owner:** ForgeRivals adjudicator pipeline
**Companion canon:** [`atlas-forge-rivals-benchmark-strategy-v1.md`](./atlas-forge-rivals-benchmark-strategy-v1.md), [`atlas-forge-rivals-battery-report-v2.md`](./atlas-forge-rivals-battery-report-v2.md)

## Why this exists

A scoreline like "Atlas 100 × 0 Claude" is a **harness failure until proved
otherwise**. Without explicit sanity gates the adjudicator can hand Atlas a
phantom win whenever the rival's driver crashes, the fixture seed is broken or
the replay never ran. This canon defines the rules the adjudicator runs before
it is allowed to declare a winner, and the 5-level ladder operators use to
decide if a score is reportable.

## When a score is trustworthy

The single-run adjudicator emits, alongside the legacy `confidence.level`, a
canonical 5-level `confidence_level` exposed in
`scorecard.fairness.confidence_level`:

| Level             | Meaning                                                                 | Claim allowed?    |
| ----------------- | ----------------------------------------------------------------------- | ----------------- |
| `invalid`         | Score cannot be defended — see `validity_class` for the structural why. | Never             |
| `low`             | Score exists but is fragile (narrow margin, hard gate dirty, extreme without proof, human intervention). | Never (single case) |
| `medium`          | Score is usable as a hint, not a publishable claim.                     | Never (single case) |
| `high`            | Score is trustworthy at single-case granularity.                        | Single-case OK     |
| `release_trusted` | Battery-wide promotion. NEVER assigned from a single run.               | Yes               |

`release_trusted` is the only level that justifies an external claim, and it
is **always** computed from a multi-case battery report, never from a single
case.

## Cost/token telemetry-only rule

Rivals measures cost, token use, wall time and stdout volume for operator
auditing, but these dimensions never decide a round winner. In v1 this is
reported as `cost_time_efficiency` with `winner_decision_weights` equal to
`0.0`; in v2 this is reported as `cost_time` with the same winner exclusion.
If a case manifest tries to assign positive winner weight to cost/time, the
resolver must zero that dimension and renormalize the remaining technical
dimensions. This prevents Atlas from losing or winning because it naturally
spends more tokens while still keeping the operational cost visible.

The same rule applies to weak patch-shape heuristics. Smaller diffs,
lower touched-file counts, lower average bytes per file, or lower apparent
implementation complexity are diagnostic signals only. They can identify
review risk, but they do not prove semantic quality and must not decide a
winner. A model only wins through stronger evidence: deterministic acceptance,
replayable evidence, scope correctness, test/oracle quality, production
invariant reasoning, rollback safety, compatibility, and ceiling-360 contract
coverage.

## Sanity gates (single-run)

The adjudicator now produces a `fairness.sanity_gates` block — each gate is a
boolean an operator can audit in one line. The 5-level ladder only climbs above
`low` when every gate is green.

| Gate                              | What it asserts                                                                 |
| --------------------------------- | ------------------------------------------------------------------------------- |
| `provider_run_clean`              | Neither arm was killed, timed out, or returned empty driver output.             |
| `fixture_clean`                   | Manifest verdict is comparable, no `fixture_error`, no `fixture_blockers`, case ids match between manifest and receipts. |
| `human_intervention_clean`        | Workspace was not dirty before or after the run; no `human_assisted` flag.      |
| `replay_verified`                 | Replay re-hashed the run end-to-end and matched.                                |
| `evidence_complete`               | `evidence_pack.missing_evidence` is empty and the `evidence_complete` hard gate is green. |
| `both_sides_produced_artifacts`   | Both arms produced a non-empty patch and a test log path.                       |
| `extreme_score_supported`         | If `|atlas - rival| ≥ 50`, evidence is intact and mode is real (no `local_fake`). |

A failing gate downgrades `confidence_level` to `invalid` (provider/fixture/
replay/evidence/single-sided artifact) or `low` (hard gate unclean, human
intervention, extreme without proof).

## Validity classes (single-run)

Surfaced in `fairness.validity_class`:

| Class                            | Trigger                                                                 |
| -------------------------------- | ----------------------------------------------------------------------- |
| `valid`                          | Every gate green; quality dimensions can speak.                         |
| `invalid_provider_run`           | Kill / timeout / `driver_not_configured` / `stalled_runner_no_heartbeat` / empty driver output. (Alias: legacy `VALIDITY_INVALID_PROVIDER_FAILURE`.) |
| `invalid_fixture`                | Manifest verdict starts with `invalid_fixture`/`invalid_workspace`, receipt `fixture_error=true`, fixture_blockers present, or case_id mismatch. |
| `invalid_test_failure`           | One arm failed tests with everything else green — `gate_winner` still surfaces, but no quality score. |
| `invalid_missing_evidence`       | `evidence_pack.missing_evidence` non-empty.                             |
| `invalid_replay_drift`           | Replay re-hash failed.                                                  |
| `invalid_harness_artifact`       | Out-of-scope files, bytecode artifacts or dirty-after-run.              |
| `invalid_local_fake_no_real_claim` | Mode is `local_fake` — proves the harness, never the model.             |
| `needs_triage_extreme_score`     | `|margin| ≥ 50` without intact evidence / fair mode.                    |

A hard fail always sets `winner=null`, `atlas_score=null`, `rival_score=null`,
`claim_ready=false`. An empate caused by missing evidence is `invalid`, not
`tie` — a real empate requires both sides to have produced comparable
artifacts.

## `release_trusted` (battery-wide)

The battery report (`atlas:forge:rivals battery-report --run-id=<id>`) emits
the v1 ladder as `confidence_level_v1`. `release_trusted = true` requires
**every** condition below to be true:

1. **Volume** — at least 12 valid cases (`RELEASE_TRUSTED_MIN_CASES`).
2. **Category diversity** — every one of the 8 canonical categories appears
   in at least one valid case:
   - `planning`, `frontend_ui`, `backend_logic`, `realistic_bugfix`,
     `refactor`, `test_design`, `architecture`, `integration_performance`.
3. **Difficulty distribution** — every level `L1..L5` appears in at least one
   valid case.
4. **Sanity gates green across the battery:**
   - `replay_verified_all` — every case replayed successfully.
   - `evidence_complete_all` — every case has a complete evidence pack.
   - `workspace_clean_all` — no case had a dirty workspace before/after.
   - `human_intervention_clean` — `battery.human_assisted` is false.
   - `provider_run_clean` — no case hit `output_empty_driver_error`,
     `timeout_without_result`, `stalled_runner_no_heartbeat`,
     `provider_returned_non_zero_with_empty_stdout`.
   - `no_contamination`, `no_hard_failures`.
5. **Real provider mode** — `mode ∈ {fair, full_power}`. `local_fake` never
   reaches `release_trusted` regardless of coverage.

The battery report surfaces:

- `confidence_level_v1` — invalid / low / medium / high / release_trusted.
- `release_trusted` — boolean.
- `release_category_coverage` — required / present / missing / complete.
- `release_difficulty_coverage` — required / present / missing / complete.
- `release_sanity_gates` — the boolean map above.
- `confidence.release_trusted_reasons` — human-readable reasons when blocked.

## Empate vs invalid

| Situation                                               | Outcome   |
| ------------------------------------------------------- | --------- |
| Both arms ran, both scored, margin < tie_threshold.     | `tie` (allowed, `human_review_required`). |
| One arm has no comparable artifact (empty patch/log).   | `invalid` — never tie. |
| One side had provider error.                            | `invalid_provider_run`. |
| Score available but evidence partial.                   | `invalid_missing_evidence` / `confidence_level=invalid`. |
| Extreme margin without intact evidence.                 | `needs_triage_extreme_score`. |

## Non-promotion guarantees

- `external_rivals_certification` stays **BLOCKED** by construction in every
  path of this canon.
- No provider is invoked, no token is spent, no LLM judges the run.
- `claim_ready` requires `confidence_level=high` (single-case) or
  `release_trusted=true` (battery) — never less.
- `release_trusted` is the strictest signal Atlas surfaces locally; promotion
  beyond it (e.g. an external certification claim) is an operator decision,
  not an adjudicator decision.

## Where the code lives

- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php` —
  single-run sanity gates, validity classes, `confidence_level` derivation.
- `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryReportService.php` —
  `release_trusted` promotion, category/difficulty coverage, battery sanity
  gates.
- `tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorScoringSanityV1Test.php` —
  single-run invariants.
- `tests/Feature/Ai/Programming/AtlasForgeRivalsBatteryReportReleaseTrustedV1Test.php` —
  battery-wide `release_trusted` invariants.

## Resumo

Este canon define quando um score do Forge Rivals e confiavel, quando deve ser invalidado e quando uma bateria pode chegar a `release_trusted`.

## Papel no Atlas

O documento impede que o Atlas transforme falhas de harness, fixtures quebradas, replay ausente ou evidencia incompleta em vantagem artificial para qualquer modelo.

## Onde Se Encaixa

Ele fica entre o adjudicator single-run e o battery report, alimentando reports humanos sem liberar certificacao externa automaticamente.

## Contratos

Os contratos principais sao `fairness.sanity_gates`, `fairness.validity_class`, `scorecard.fairness.confidence_level` e `confidence_level_v1` no battery report.

## Fluxo

O fluxo e: run comparavel, sanity gates, validity class, confidence single-run, agregacao battery-wide e decisao humana sobre claims externos.

## Regras para IA

IA nao pode promover score invalido, local_fake, sem replay, sem evidencia completa ou com provider failure. Empate real exige artefatos comparaveis dos dois lados.

## Escopo de Implementacao

O escopo cobre adjudication, battery report, tests e docs de scoring sanity. Nao cobre provider execution, external certification ou UI de Rivals.

## Dependencias

Depende do benchmark strategy, do battery report v2, do adjudicator service e dos testes de fairness/release trusted.

## Evidencias

Evidencia minima: testes unitarios do adjudicator scoring sanity e testes feature do battery report release trusted.

## Riscos

O risco principal e declarar uma vitoria falsa por erro de harness, provider, fixture, replay ou evidencia. O default seguro e invalidar.

## Exemplos

Um placar `Atlas 100 x 0 rival` sem replay, sem artefatos dos dois lados ou em `local_fake` e invalido para claim externo.

## Proximas Acoes

Manter este canon em sincronia com qualquer mudanca em sanity gates, validity classes, confidence ladder ou release trusted.
