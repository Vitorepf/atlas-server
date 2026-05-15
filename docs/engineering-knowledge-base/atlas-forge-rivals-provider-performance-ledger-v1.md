---
id: atlas-forge-rivals-provider-performance-ledger-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Provider Performance Ledger v1
status: active
category: architecture
priority: 80
summary: Append-only local ledger + decide-signal projection that turns Rivals scorecards into Atlas-Decide-grade intelligence by provider, model, role and task category.
tags:
  - atlas-forge
  - rivals
  - provider-performance-ledger
  - atlas-decide
  - audit
  - certification
capabilities:
  - provider_performance_ledger
  - decide_signal_projection
  - cost_quality_frontier
  - fair_vs_full_power_delta
  - atlas_forge_vs_raw_provider_delta
  - aggregated_evidence_for_atlas_decide
decisions:
  - The ledger is read-side intelligence on top of adjudicator scorecards; it never calls a provider.
  - Atlas Decide consumes the decide-signal as advisory input only — the ledger never claims authority.
  - Hard-failed runs are recorded as invalid negative signal but excluded from rankings and the cost/quality frontier.
  - external_rivals_certification stays blocked forever as far as this ledger is concerned.
maintenance:
  - When the adjudicator schema changes, update the entry mapping and the docs section that lists the canonical scorecard fields.
  - Keep the nine-invariant certification in sync with the service contract; never relax an invariant without a follow-up doc.
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderPerformanceLedgerCertification.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-forge-rivals-provider-performance-ledger-v1

graph_title: Atlas Forge Rivals · Provider Performance Ledger v1

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1

graph_status: active

graph_source: repo

owner: programming

repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderPerformanceLedgerCertification.php

allowed_changes:
  - Atualizar quando o adjudicator emitir novos campos no scorecard ou quando os thresholds de confidence/stale precisarem evoluir.
  - Manter os 9 invariantes em sincronia com o contrato exposto na cert.

forbidden_changes:
  - Permitir que o ledger ou o decide-signal chamem provider externo, gastem token, destravem external_rivals_certification, ou promovam claim_ready=true.
  - Ranquear runs com hard_failures em qualquer agregado ou no cost_quality_frontier.

depends_on:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-operator-battery-v2

flows_to:
  - atlas-decide

unlocks:
  - provider-performance-ranking-by-task-category-and-role

governs:
  - rivals_provider_performance_intelligence

evidence:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerServiceTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderPerformanceLedgerCertificationTest.php
  - "php artisan atlas:forge:rivals audit --json"
  - "php artisan atlas:forge:rivals ledger --json"
  - "php artisan atlas:forge:rivals decide-signal --task-category=frontend --role=builder --json"

required_tests:
  - "php artisan test --filter='ProviderPerformanceLedger'"
  - "php artisan atlas:forge:rivals audit --json"

requires_evidence: true

risk_level: medium

next_actions:
  - Conectar atlas:decide para consumir decide-signal como input advisory.
  - Adicionar retention/compaction ao entries.jsonl quando volume justificar.
  - Surface UI Atlas Code Premium (ranking, provider cards, cost/quality scatter).
---

# Atlas Forge Rivals · Provider Performance Ledger v1

Status: **available** (delivered 2026-05-15)
Schema: `atlas.forge.rivals.provider_performance_ledger.v1`
Entry schema: `atlas.forge.rivals.provider_performance_ledger_entry.v1`
Decide signal schema: `atlas.forge.rivals.decide_signal.v1`
Certification: `atlas_forge_rivals_provider_performance_ledger_certification`
Canonical commands:

```bash
php artisan atlas:forge:rivals ledger --json --strict
php artisan atlas:forge:rivals ledger-record --run-id=<id> --task-category=<cat> --role=<role> --json --strict
php artisan atlas:forge:rivals decide-signal --task-category=<cat> --role=<role> --json --strict
php artisan atlas:forge:rivals audit --json --strict
```

## Motivation

A naive global "winner" ranking across Claude Code, Codex, Gemini, and Atlas
Forge is misleading: each provider is strong in different regimes (frontend
vs bugfix, builder vs reviewer, fair vs full_power, framework X vs Y). The
Provider Performance Ledger is the canonical substrate that lets the
**Atlas Decide** layer learn which provider/model/role/strategy is best for
**a specific situation** based on **real** local evidence — without ever
calling a provider itself.

The ledger is **read-side intelligence** on top of the existing
adjudicator's scorecards. The Rivals pipeline already produces:

- `evidence/manifest.json`
- `evidence/scorecard.json` (`atlas.forge.rivals.adjudication.v1`)
- `evidence/evidence_pack.json`
- `evidence/atlas_receipt.json` / `evidence/rival_receipt.json`

The ledger absorbs these into an append-only feed and computes aggregates
that downstream `atlas:decide` can consume as **advisory** signal.

## Hard safety contract

The ledger NEVER:

- calls an external provider (zero token spend);
- unlocks `external_rivals_certification`;
- promotes a completion claim (`claim_ready` always `false`);
- masks a hard-failed run as a win;
- overwrites prior entries (append-only by `entry_id`);
- ranks invalid entries (hard failures excluded from aggregates and the
  `cost_quality_frontier`);
- decides anything as final authority — `decide-signal` is **advisory only**.

## Architecture

```
+----------------------+        +--------------------------+
|  atlas:forge:rivals  | -----> |  ProviderPerformance      |
|  ledger-record       |        |  LedgerService            |
|  ledger              |        |  - record(scorecard)      |
|  decide-signal       |        |  - snapshot(+filters)     |
+----------+-----------+        |  - loadEntries()          |
           |                    +-------------+------------+
           |                                  |
           v                                  v
+----------------------+        +--------------------------+
|  DecideSignal         | <---- |  ledger/entries.jsonl    |
|  ProjectionService    |       |  ledger/entries/<id>.json|
|  - project(filters)   |       |  (append-only)           |
+----------------------+        +--------------------------+
```

### Services

- `App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService`
- `App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService`

### Certification

- `App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsProviderPerformanceLedgerCertification`
  exposed in the `atlas:forge:rivals audit --json` payload alongside the
  existing Operator Battery, Perfect Battery, and Provider Arena Core
  certifications.

### Storage layout

By default the ledger persists to
`storage/app/rivals-forge-ledger/` with this layout:

```
storage/app/rivals-forge-ledger/
├── entries.jsonl              # append-only one-line-per-entry index
└── entries/
    ├── <run_id>-<arm>-<short_evidence_hash>-<micro>.json
    └── ...
```

The root is overridable via the Laravel config key
`atlas_rivals.ledger_root` (string path), e.g. for tests:

```php
config(['atlas_rivals.ledger_root' => $tmp]);
```

## Entry schema (`atlas.forge.rivals.provider_performance_ledger_entry.v1`)

Each run produces **two** entries (one per arm — `atlas` and `rival`). The
canonical fields:

```jsonc
{
  "schema_version": "atlas.forge.rivals.provider_performance_ledger_entry.v1",
  "entry_id": "<run_id>-<arm>-<evidence_hash_12>-<microsuffix>",
  "recorded_at": "2026-05-15T12:00:00+00:00",
  "run_id": "<run_id>",
  "battery_id": "<battery_id|run_id>",
  "arena_run_id": "<arena_run_id|run_id>",
  "arm": "atlas|rival",
  "arm_id": "atlas:anthropic_claude:claude_sonnet:fair",
  "runner_type": "atlas_forge|raw_provider",
  "provider": "anthropic_claude|openai_codex|openai_gpt|google_gemini|atlas_decide|unknown",
  "model": "claude_sonnet|claude_opus|codex|auto|...",
  "task_category": "frontend|backend|bugfix|refactor|feature|test|docs|devops|security|unknown",
  "role": "builder|reviewer|repair_agent|context_scout|test_generator|architect|docs",
  "framework": "react|laravel|...|null",
  "mode": "fair|full_power|diagnostic|replay_only|local_fake|...",
  "preset": "smoke|quick|release|full",
  "score_total": 78.42,                // null when hard-failed
  "scores_by_dimension": {              // per adjudicator dimension, this arm only
    "patch_focus": 92.0,
    "scope_discipline": 100.0,
    "...": 0.0
  },
  "winner": "atlas|rival|human_review_required_tie|null",
  "outcome": "winner|loser|human_review_required|invalid",
  "human_review_required": false,
  "hard_failures": [],                  // populated from scorecard
  "tests_passed": true,
  "replay_passed": true,
  "scope_violations": 0,
  "intervention_count": 0,
  "duration_ms": 60000,
  "cost_estimate": 0.01,                // from receipt token_cost (may be null)
  "tokens_used": 100,
  "evidence_pack_hash": "<sha256>",
  "adjudication_hash": "<sha256>",
  "valid_for_ranking": true,            // false when hard_failures || score null
  "claim_ready": false,                 // always false (ledger never claims)
  "separated_from_external_rivals_certification": true,
  "external_provider_call": false,
  "provider_tokens_spent": false
}
```

The `evidence_pack_hash` is the `sha256` of
`manifest|atlas_receipt|rival_receipt|workspace_hashes` SHAs joined with `|`.
Records lacking this hash are rejected with `evidence_hash_required`.

## Roles taxonomy

```
builder | reviewer | repair_agent | context_scout |
test_generator | architect | docs
```

These map to the seven operator roles a provider can fulfil. A provider can
be measured separately per role so we learn, e.g., that Claude Opus is a
strong `reviewer` even when it ranks mid as a `builder`.

## Task categories taxonomy

```
frontend | backend | bugfix | refactor | feature |
test | docs | devops | security | unknown
```

Any unknown label is normalized to `unknown` rather than rejected, so the
ledger can absorb future categories without breaking persistence.

## Aggregates

`atlas:forge:rivals ledger --json` returns the live ledger plus aggregates:

- `by_task_category`: ranked summary per category (avg score among valid
  entries, sample size, confidence band, latest evidence age, stale flag,
  provider/model set, latest run ids).
- `by_role`: same structure keyed by role.
- `by_provider_model`: same structure keyed by `provider:model`.
- `by_framework`: only entries that surfaced a `framework`.
- `atlas_forge_vs_raw_provider_delta`: average score delta between
  `runner_type=atlas_forge` and `runner_type=raw_provider`.
- `fair_vs_full_power_delta`: average score delta between `full_power` and
  `fair` modes.
- `cost_quality_frontier`: per-(provider, model, mode) average cost vs
  average score, sorted score desc / cost asc. **Invalid entries are
  excluded** so a hard-failed run can never appear as a cost win.

### Confidence model

| evidence_count | confidence              |
|----------------|-------------------------|
| 0              | `insufficient_evidence` |
| 1–2            | `low`                   |
| 3–5            | `medium`                |
| ≥ 6            | `high`                  |

`stale_data` is `true` when the latest recorded entry in the bucket is
older than 14 days. Both signals drive the `should_explore_alternative`
flag in the projection.

## Decide signal (`atlas.forge.rivals.decide_signal.v1`)

`atlas:forge:rivals decide-signal --task-category=X --role=Y [--framework=Z]`
returns an **advisory** recommendation:

```jsonc
{
  "schema_version": "atlas.forge.rivals.decide_signal.v1",
  "signal": "ok|insufficient_evidence|human_review_required",
  "task_category": "frontend",
  "role": "builder",
  "framework": null,
  "recommended_provider": "anthropic_claude",
  "recommended_model": "claude_sonnet",
  "recommended_average_score": 78.4,
  "evidence_count": 12,
  "confidence": "high",
  "alternative_recommendation": {
    "provider": "openai_codex",
    "model": "codex",
    "average_score": 75.9,
    "sample_size": 8,
    "confidence": "high"
  },
  "latest_run_ids": ["<id>", "..."],
  "latest_recorded_at": "2026-05-15T12:00:00+00:00",
  "latest_age_days": 1,
  "stale_data": false,
  "invalid_entries_seen": 0,
  "tie_entries_seen": 0,
  "should_explore_alternative": false,
  "should_use_full_power": true,
  "should_require_human_review": false,
  "advisory_only": true,
  "separated_from_external_rivals_certification": true
}
```

Decision rules:

- `signal=insufficient_evidence` when no valid entry matches
  (task_category, role) — `recommended_*` are `null`.
- `signal=human_review_required` when invalid or tie entries exist AND
  evidence_count is below `CONFIDENCE_MEDIUM_THRESHOLD`.
- `should_explore_alternative=true` when the runner-up gap is below
  `CLOSE_RACE_GAP` (4.0), or the bucket is `low` confidence, or the data is
  stale.
- `should_use_full_power=true` when both `fair` and `full_power` samples
  exist AND the full_power average exceeds the fair average by at least
  `FULL_POWER_WORTH_IT_DELTA` (3.0).

The Decide signal is **never** authoritative — Atlas Decide may choose to
overrule it, ignore it, or escalate to a human. The projection only
proposes.

## Recording an entry

```bash
php artisan atlas:forge:rivals ledger-record \
  --run-id=<id> \
  --task-category=frontend \
  --role=builder \
  --framework=react \
  --json --strict
```

The recorder:

1. Resolves the run's canonical paths via `AtlasForgeRivalsRunPathResolver`.
2. Reads `manifest.json`, `scorecard.json`, `evidence_pack.json`.
3. Rejects with `manifest_missing` / `scorecard_missing` /
   `evidence_pack_missing` when any required artifact is absent.
4. Resolves `task_category`/`role` from the CLI override → manifest →
   scorecard, in that order. Missing values trigger
   `task_category_required` or `role_required`.
5. Computes the evidence pack hash. Missing hash → `evidence_hash_required`.
6. Emits one entry per arm (`atlas` + `rival`) with `valid_for_ranking`
   true ⇔ no hard failures and a non-null score.
7. Persists each entry to `entries/<entry_id>.json` AND appends to
   `entries.jsonl`. **Nothing is overwritten** — re-recording the same
   run yields fresh entries that preserve history.

## Audit invariants (9 total)

The certification verifies every invariant by introspecting source files
and class existence — same pattern as Perfect Battery v1:

| # | Invariant                                | What it proves                                                                                  |
|---|------------------------------------------|-------------------------------------------------------------------------------------------------|
| 1 | `ledger_available`                       | `ledger` + `ledger-record` actions wired + service class loads                                  |
| 2 | `no_synthetic_score_as_claim`            | Ledger entries are always `claim_ready=false`, never `true`                                     |
| 3 | `invalid_runs_do_not_rank`               | `valid_for_ranking` + `hard_failures` + `invalid_entries_excluded_from_ranking=true` declared   |
| 4 | `decide_signal_is_advisory_only`         | Projection always emits `advisory_only=true`                                                    |
| 5 | `evidence_hash_required`                 | Recorder rejects without an evidence pack hash                                                  |
| 6 | `task_category_required`                 | Recorder rejects without a task_category                                                        |
| 7 | `role_required`                          | Recorder rejects without a role; ROLES catalogue is declared                                    |
| 8 | `external_rivals_remains_blocked`        | Ledger + signal + cert + doc all assert separation from `external_rivals_certification`         |
| 9 | `provider_tokens_not_spent_by_ledger`    | Ledger + signal always emit `external_provider_call=false` and `provider_tokens_spent=false`    |

Audit output is exposed under `provider_performance_ledger_certification` in
`atlas:forge:rivals audit --json` and under the canonical
`atlas_forge_rivals_provider_performance_ledger_certification` key in the
`certifications` map.

## Future UI surface (Atlas Code premium)

The ledger feed is shaped for a future enterprise panel:

- **Ranking by category**: `by_task_category` aggregates → grouped bars.
- **Provider cards**: `by_provider_model` rows → confidence chip + sample
  size + latest evidence + age + stale flag.
- **Cost/quality frontier**: `cost_quality_frontier` → scatter (cost vs
  score).
- **Atlas Decide signal**: `decide-signal --task-category=...` →
  reasoning bullets, alternative recommendation, explore/full_power hints.

No UI ships in this slice; the JSON contract is the durable interface.

## Operator workflow

1. Run the rivals battery and produce a scorecard.
2. Call `ledger-record` with the run id + task_category + role.
3. Inspect `ledger --json` for aggregates.
4. Ask `decide-signal --task-category=X --role=Y --json` before launching
   the next real run; honour `should_require_human_review` and
   `should_explore_alternative` when the data isn't decisive.
5. Re-audit with `audit --json` — `provider_performance_ledger_certification`
   must be `available`.

## Resumo

Ledger append-only canônico que transforma scorecards do Rivals em
inteligência operacional consumível pelo Atlas Decide, sem nunca chamar
provider, nunca gastar token, nunca destravar `external_rivals_certification`.

## Papel no Atlas

Camada de aprendizado operacional entre Rivals (mede) e Atlas Decide (decide).
Atlas Decide consome o `decide-signal` como sinal **advisory** para escolher
provider, model, role e modo (fair vs full_power) em runs futuros.

## Onde Se Encaixa

Encaixa entre `atlas:forge:rivals adjudicate` (gera scorecard) e a futura
`atlas:decide` (escolhe estratégia). O ledger é leitura local em cima da
evidência já produzida pela pipeline Rivals — sem chamadas externas.

## Contratos

- **Schema do snapshot**: `atlas.forge.rivals.provider_performance_ledger.v1`.
- **Schema da entrada**: `atlas.forge.rivals.provider_performance_ledger_entry.v1`.
- **Schema do sinal**: `atlas.forge.rivals.decide_signal.v1`.
- **Cert**: `atlas_forge_rivals_provider_performance_ledger_certification`
  com 9 invariantes; aparece em `atlas:forge:rivals audit --json`.

## Fluxo

```
scorecard.json ──► ledger-record ──► entries.jsonl + entries/<id>.json
                                          │
                                          ▼
                                       snapshot
                                          │
                                          ▼
                                    decide-signal (advisory)
                                          │
                                          ▼
                                     Atlas Decide
```

## Regras para IA

- Nunca chamar provider externo a partir do ledger ou da projeção.
- Nunca promover claim — entradas têm `claim_ready=false` sempre.
- Nunca rankear runs com hard failure no `cost_quality_frontier` nem nos
  agregados de média.
- Nunca destravar `external_rivals_certification` — o ledger declara
  separação explícita.
- Quando faltar `task_category`, `role` ou `evidence_pack_hash`, **bloquear**
  o record com erro determinístico, nunca preencher silenciosamente.

## Escopo de Implementacao

- `AtlasForgeRivalsProviderPerformanceLedgerService` (record/snapshot/load).
- `AtlasForgeRivalsDecideSignalProjectionService` (project).
- `AtlasForgeRivalsProviderPerformanceLedgerCertification` (9 invariantes).
- 3 sub-actions no `atlas:forge:rivals`: `ledger`, `ledger-record`,
  `decide-signal`.
- Persistência local em `storage/app/rivals-forge-ledger/` (override via
  `atlas_rivals.ledger_root`).

## Dependencias

- `AtlasForgeRivalsRunPathResolver` para localizar manifest/scorecard.
- Scorecards produzidos pelo `AtlasForgeRivalsAdjudicatorService`.
- `AtlasForgeRivalsResponseBuilder` para expor a cert no `audit`.

## Evidencias

- 17 testes unit em `tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerServiceTest.php`.
- 7 testes feature em `tests/Feature/Ai/Programming/AtlasForgeRivalsProviderPerformanceLedgerCertificationTest.php`.
- `php artisan atlas:forge:rivals audit --json` retorna
  `provider_performance_ledger_certification.status=available`.

## Riscos

- Se a pipeline Rivals começar a gerar scorecards sem `quality_dimensions`
  ou sem hashes em `evidence_pack`, o ledger continua honesto (registra
  como invalid), mas perde sinal estatístico. Reavaliar threshold de
  confidence quando isso acontecer.
- Crescimento ilimitado do `entries.jsonl` — a versão atual NÃO faz
  rotation; uma slice futura precisa endereçar retention/compaction.

## Exemplos

```bash
# Gravar a evidência de um run no ledger.
php artisan atlas:forge:rivals ledger-record \
  --run-id=<id> --task-category=frontend --role=builder \
  --framework=react --json --strict

# Inspecionar os agregados.
php artisan atlas:forge:rivals ledger --json --strict

# Pedir o sinal para o Atlas Decide.
php artisan atlas:forge:rivals decide-signal \
  --task-category=frontend --role=builder --json --strict
```

## Proximas Acoes

- Conectar `atlas:decide` para consumir o `decide-signal` como input
  advisory.
- Adicionar rotation/retention ao `entries.jsonl` quando volume justificar.
- Surgir a UI Atlas Code Premium descrita acima (ranking, provider cards,
  cost/quality scatter).
