---
id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-01
type: engineering_knowledge
title: Atlas Forge Rivals Perfect Battery & Adjudicator v1 · Parte 1
status: source_material
category: programming-forge
priority: 88
summary: Recorte focado de Atlas Forge Rivals Perfect Battery & Adjudicator v1: 1. The single button ate 4. Adjudicator (deterministic, local).
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
graph_id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-01
graph_title: Atlas Forge Rivals Perfect Battery & Adjudicator v1 Parte 1
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
human_name: Atlas Forge Rivals Perfect Battery & Adjudicator v1 Parte 1
canonical_name: Atlas Forge Rivals Perfect Battery & Adjudicator v1 Parte 1
technical_name: atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-01
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-01.md
owner: programming_rivals
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-01.md
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
# Atlas Forge Rivals Perfect Battery & Adjudicator v1 · Parte 1

## Resumo

Este recorte preserva uma parte focada de Atlas Forge Rivals Perfect Battery & Adjudicator v1: 1. The single button ate 4. Adjudicator (deterministic, local).

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
## 1. The single button

```bash
php artisan atlas:forge:rivals run-battery \
  --mode=fair --atlas-model=sonnet --rival=claude_sonnet \
  --preset=release --prompt-mode=spec-perfect \
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

Prompt realism and battery taxonomy are governed by
`atlas-forge-rivals-battery-modes-and-human-prompts-v1.md`: `spec-perfect`,
`human-normal`, `messy-real`, `enterprise-change`, `provider-arena`,
`atlas-power`, `fair-mode`, `power-mode`, `category-battery` and
`difficulty-ladder`.

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

### Hard gates

`verdict_comparable` · `provider_exit_zero_{atlas,rival}` ·
`tests_passed_{atlas,rival}` · `replay_passes` · `evidence_complete` ·
`no_out_of_scope_files_{atlas,rival}` · `no_bytecode_artifacts_{atlas,rival}` ·
`dirty_after_run_false` · `patch_diff_present_{atlas,rival}`.

Infrastructure, evidence, replay, scope, dirty-workspace, and missing-patch hard
gates fail closed to `winner=null` and scores null. A one-sided
`tests_passed_*` failure is reported differently: it may emit
`gate_winner=atlas|rival` and `gate_result.kind=one_sided_test_failure`, but it
still keeps `winner=null`, both scores null, and
`quality_score_available=false`. That is a gate outcome, not a quality score.

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

- Infrastructure/evidence/replay/scope hard fail ⇒ `winner=null`, scores null,
  gates explain failure.
- One-sided test failure ⇒ `gate_winner=atlas|rival`, `winner=null`, scores
  null, `quality_score_available=false`. This says which arm survived the gate;
  it is not a comparable quality result.
- `|atlas-rival| < threshold` (default 5.0) ⇒ `winner=human_review_required_tie`.
- Otherwise ⇒ `winner=atlas|rival`, `claim_ready=true`, structured `winner_reason`.

Adjudicator NEVER calls a provider; fully unit-testable against synthetic receipts.

---
