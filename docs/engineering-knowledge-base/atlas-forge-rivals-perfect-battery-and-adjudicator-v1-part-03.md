---
id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-03
type: engineering_knowledge
title: Atlas Forge Rivals Perfect Battery & Adjudicator v1 · Parte 3
status: active
category: programming-forge
priority: 88
summary: Recorte focado de Atlas Forge Rivals Perfect Battery & Adjudicator v1: 5. Premium report (`report.md` + JSON) ate 11. Related docs.
tags:
  - atlas
  - forge
  - rivals
  - split-doc
capabilities:
  - forge_rivals_documentation_split
decisions:
  - Este recorte preserva detalhe operacional Rivals sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-03
graph_title: Atlas Forge Rivals Perfect Battery & Adjudicator v1 Parte 3
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
owner: programming_rivals
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-03.md
allowed_changes:
  - Atualizar somente o detalhe operacional desta parte.
forbidden_changes:
  - Transformar Rivals em routing, provider decision ou feature de produto.
depends_on:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
flows_to:
  - atlas-forge-rivals-operator-battery-v2
unlocks:
  - forge_rivals_readable_cartography
governs:
  - forge_rivals_run_battery_pipeline
evidence:
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter este recorte alinhado ao índice canônico.
---
# Atlas Forge Rivals Perfect Battery & Adjudicator v1 · Parte 3

## Resumo

Este recorte preserva uma parte focada de Atlas Forge Rivals Perfect Battery & Adjudicator v1: 5. Premium report (`report.md` + JSON) ate 11. Related docs.

## Papel no Atlas

Mantém detalhe operacional Rivals fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`.

## Contratos

Rivals mede desempenho e evidência. Atlas Decide continua dono de routing/modelo.

## Fluxo

Índice Rivals → recorte operacional → comando/evidência/report correspondente.

## Regras para IA

Não transformar medição em decisão de provider. Não promover claim sem evidência, replay e gates.

## Escopo de Implementacao

Este arquivo guarda apenas o detalhe extraído do documento maior.

## Dependencias

Depende do índice canônico Rivals e do glossário.

## Evidencias

A evidência de origem é o documento principal e docs-health verde.

## Riscos

Risco principal: confundir harness/medição com decisão operacional do Atlas.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar quando o contrato correspondente mudar e rodar docs-health.

## Conteudo Extraido
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
  "gate_winner": "atlas|rival|null",
  "gate_result": null,
  "winner_reason": [],
  "atlas_score": 86.5,
  "rival_score": 79.2,
  "score_source": "quality_dimensions|gate_outcome",
  "quality_score_available": true,
  "quality_score_reason": null,
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
| `gate_winner: atlas` / `rival`, `winner: null` | Exactly one arm failed tests while replay/evidence gates stayed valid | Gate outcome only; quality score N/A; fix failed arm or run another case |
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
