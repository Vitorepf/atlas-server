---
id: atlas-forge-rivals-matrix-report-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Matrix Report v1
status: active
category: programming-forge
priority: 90
summary: Relatório humano final da bateria multi-case do Forge Rivals. Lê battery_evidence_pack + scorecards per-case e emite winner geral, rankings por categoria e por L1-L5, heatmap categoria×L5, planning_score vs execution_score, invalid/suspicious separados, recomendação Atlas Decide e Markdown+JSON. Honesto com insufficient_evidence; nunca destrava external_rivals.
tags:
  - atlas
  - forge
  - rivals
  - matrix-report
  - difficulty-l5
  - atlas-decide
capabilities:
  - forge_rivals_matrix_report_v1
  - forge_rivals_human_facing_battery_summary
  - forge_rivals_atlas_decide_advisory
decisions:
  - Matrix Report é descritivo, não promove claim — sempre `claim_ready=false`.
  - Lê per-case scorecard primeiro (canon multi-case), depois `cases_breakdown`, depois run-level (fallback single-case).
  - Difficulty ladder canônica L1-L5 propagada do corpus; cases sem difficulty caem no balde `insufficient_sample`.
  - planning_score = média de {objective_alignment, scope_discipline, evidence_quality}; execution_score = média das 6 dimensões restantes.
  - Sem case comparable+scored => `insufficient_evidence`; nenhum winner ou recomendação é emitido.
  - external_rivals_certification permanece BLOCKED.
maintenance:
  - Atualizar quando `AtlasForgeRivalsMatrixReportService` mudar de schema, quando dimensões do adjudicator mudarem (afeta planning/execution split) ou quando ladder L1-L5 mudar no corpus.
  - Manter aliases CLI (`matrix`, `matrix-report`, `report-matrix`, `final-report`) sincronizados com command signature.
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportV1Test.php
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-matrix-report-v1
graph_title: Atlas Forge Rivals · Matrix Report v1
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-evidence-pack-replay-multi-case-v1
graph_status: active
graph_source: repo
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportService.php
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-matrix-report-v1.md
allowed_changes:
  - Adicionar seções additive ao Markdown/JSON do matrix report.
  - Endurecer regra de `insufficient_evidence` ou de recomendação Atlas Decide.
  - Adicionar aliases CLI sem renomear actions.
forbidden_changes:
  - Promover `claim_ready=true` a partir do matrix report.
  - Inventar dados quando faltar scorecard (sempre `insufficient_evidence`).
  - Misturar planning e execution num único score (split é canon).
  - Destravar `external_rivals_certification` a partir deste relatório.
depends_on:
  - atlas-forge-rivals-evidence-pack-replay-multi-case-v1
  - atlas-forge-rivals-evidence-pack-replay-hardening-v2
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-provider-arena-corpus-v1
flows_to:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
unlocks:
  - forge_rivals_human_readable_battery_matrix
governs:
  - forge_rivals_matrix_report_contract
evidence:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportV1Test.php
required_tests:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportV1Test.php
requires_evidence: true
risk_level: medium
next_actions:
  - Sincronizar este doc se novos eixos (cost vs quality, mode-aware tabelas) forem adicionados ao matrix.
---

# Atlas Forge Rivals · Matrix Report v1

**Status:** Delivered 2026-05-15 (Claude D · Matrix Report slice)
**Schema:** `atlas.forge.rivals.matrix_report.v1`
**Entrypoint:** `php artisan atlas:forge:rivals matrix-report --run-id=<id> --json`
**Companion docs:** `atlas-forge-rivals-evidence-pack-replay-multi-case-v1.md`,
`atlas-forge-rivals-evidence-pack-replay-hardening-v2.md`,
`atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`.

Relatório humano final da bateria Rivals multi-case. Lê o battery
evidence pack + scorecards per-case e devolve um snapshot legível
(Markdown) + estruturado (JSON) com tudo que o operador precisa para
**ler o resultado de uma bateria de 40 cases sem inventar nada**.

> **External rivals canon.** Matrix Report NUNCA destrava
> `external_rivals_certification`. Sempre `claim_ready=false`. Quando faltar
> evidência, retorna `insufficient_evidence` por desenho.

---

## 1. O que o relatório responde (e o que NÃO responde)

O matrix report responde:

1. **Winner geral** — Atlas vs Rival (Claude/Codex) ponderado por L1-L5.
2. **Ranking por categoria** — qual arm ganha mais em cada `task_category`.
3. **Ranking por dificuldade** — qual arm ganha mais em cada nível L1-L5.
4. **Heatmap** — leitura visual `categoria × L1..L5` com leader por célula.
5. **planning_score vs execution_score** — Atlas é melhor *planejando* ou *executando*?
6. **Invalid cases vs Suspicious cases** — separados, com motivo.
7. **Onde Atlas é melhor / onde Rival é melhor** — listas de categorias e dificuldades.
8. **Recomendação para Atlas Decide** — por categoria + global, advisory.

O matrix NÃO responde:

- Se a bateria pode virar claim externo (sempre `false`, ver Adjudicator).
- Se o resultado vale para release sem revisão humana (sempre `human_review_required` quando o spread é pequeno).
- Se o Atlas é "globalmente melhor" — só fala da bateria específica medida.

---

## 2. Inputs e fontes de dados

| Fonte                                                                | Para que serve                                                |
| -------------------------------------------------------------------- | ------------------------------------------------------------- |
| `runs/<run_id>/evidence/battery_evidence_pack.json`                  | Agregação de cases multi-run (per-case digest com L5).        |
| `runs/<run_id>/evidence/manifest.json`                               | Lista cases[] com `task_category` e `difficulty_level`.       |
| `runs/<run_id>/evidence/cases/<case_subdir>/scorecard.json`          | Per-case scorecard (preferido).                               |
| `runs/<run_id>/evidence/scorecard.json` (`cases_breakdown[case_id]`) | Fallback: scorecard run-level com breakdown por case.         |
| `runs/<run_id>/evidence/scorecard.json` (run-level)                  | Fallback final: legacy single-case run.                       |

Quando nenhuma das fontes existe ou produz `comparable+scored=true`, o
matrix devolve `status=insufficient_evidence` e refuses to declare winner
or recommendation.

---

## 3. Algoritmos canon

### 3.1 Winner geral (ponderado L1-L5)

Para cada case `comparable+scored=true`:
- `winner_case` = `atlas` se `atlas_score - rival_score ≥ tie_threshold`,
  `rival` se `≤ -tie_threshold`, senão `tie`.
- Adiciona `difficulty_weight` (L1=1.0, L2=1.5, L3=2.0, L4=2.5, L5=3.0)
  ao arm vencedor; `tie` divide o peso meio-a-meio.

`overall.winner` = `atlas` se `atlas_weighted - rival_weighted ≥ 1.0`,
`rival` se `≤ -1.0`, senão `tie`. Spread `< 1.0` weight unit é sempre
empate por desenho — evita ranking volúvel por flutuação de score.

### 3.2 Ranking por categoria

Para cada `task_category` visto: conta `atlas_wins`, `rival_wins`,
`ties`, calcula `win_rate` e marca `leader`.

### 3.3 Ranking por dificuldade L1-L5

Mesmo algoritmo, mas iterando sobre **todos** os 5 níveis canon. Níveis
sem case aparecem com `cases=0` e `leader=null` (verdade honesta, não
zeros falsos).

### 3.4 Heatmap categoria × dificuldade

Matriz `present_categories × L1..L5`. Cada célula carrega
`{atlas, rival, tie, total, leader}`. Células vazias renderizam `·`.

### 3.5 planning_score vs execution_score

A canon split sobre as 9 dimensões do adjudicator:

| Eixo       | Dimensões                                                            |
| ---------- | -------------------------------------------------------------------- |
| `planning` | `objective_alignment`, `scope_discipline`, `evidence_quality`        |
| `execution`| `patch_focus`, `implementation_complexity`, `test_quality`, `maintainability`, `risk_surface`, `cost_time_efficiency` |

Para cada case com `quality_dimensions`, média Atlas/Rival nas 3 (planning)
e 6 (execution) dimensões. Agrega entre cases. `leader` por eixo é
calculado com threshold `|atlas - rival| < 0.5 ⇒ tie`. Sem case com
`quality_dimensions`, o eixo retorna `atlas=null, rival=null,
sample_size=0` em vez de zero falso.

### 3.6 Invalid vs Suspicious

- **Invalid:** `verdict` começa com `invalid_*`. Surfaced em
  `invalid_cases[]` com `reason=invalid_verdict:<verdict>`.
- **Suspicious:** verdict comparable mas scorecard com `hard_failures` ou
  `score_source=gate_outcome`. Surfaced em `suspicious_cases[]` com
  `reason=hard_failures:<codes>` / `gate_outcome_only_no_quality_score`.

Invalid e suspicious são MUTUALMENTE EXCLUSIVOS no relatório (invalid tem
precedência) — sem dupla contagem.

### 3.7 Recomendação Atlas Decide

Por categoria com `total ≥ 2`:

- `atlas_wins > rival_wins` → `route_to_atlas_forge`
- `rival_wins > atlas_wins` → `route_to_raw_provider`
- empate → `human_review_inconclusive`
- amostragem `< 2` cases → `insufficient_sample`

`global_recommendation`:
- `default_to_atlas_forge_with_per_category_overrides` quando Atlas ganha mais categorias
- `default_to_raw_provider_with_per_category_overrides` quando Rival ganha mais
- `split_routing_per_category` quando empate em número de categorias decididas
- `human_review_required` quando todas as categorias caem em `human_review_inconclusive`
- `insufficient_evidence` quando não há case comparable+scored

---

## 4. Schema canonical v1 (resumo)

Schema: `atlas.forge.rivals.matrix_report.v1`. Top-level:

| Campo                                  | Tipo                | Notas                                                |
| -------------------------------------- | ------------------- | ---------------------------------------------------- |
| `battery_id`                           | string \| null      | `battery-<sha8>` ou explícito.                       |
| `generated_at`                         | ISO8601             |                                                      |
| `run_ids`                              | list&lt;string&gt;        |                                                      |
| `case_count`                           | int                 |                                                      |
| `comparable_scored_count`              | int                 |                                                      |
| `invalid_count`                        | int                 |                                                      |
| `suspicious_count`                     | int                 |                                                      |
| `status`                               | `ok` \| `insufficient_evidence` |                                       |
| `overall`                              | object              | `{winner, atlas_wins, rival_wins, tie_count, atlas_weighted, rival_weighted, spread, atlas_avg_score, rival_avg_score, comparable_scored_count, note}` |
| `category_ranking`                     | list&lt;object&gt;        | linhas com `category`, `cases`, `atlas_wins`, `rival_wins`, `ties`, `atlas_win_rate`, `rival_win_rate`, `leader` |
| `difficulty_ranking`                   | list&lt;object&gt;        | mesmo shape, indexado por `level` L1..L5 + `weight`.  |
| `heatmap.categories`                   | list&lt;string&gt;        | categorias presentes (ordenadas).                    |
| `heatmap.levels`                       | list&lt;string&gt;        | sempre `[L1..L5]`.                                    |
| `heatmap.cells[cat][level]`            | object              | `{atlas, rival, tie, total, leader}`.                |
| `planning_vs_execution.planning`       | object              | `{atlas, rival, leader, sample_size, dimensions}`. nulls quando sample=0. |
| `planning_vs_execution.execution`      | object              | idem.                                                |
| `atlas_better_in`                      | object              | `{categories, difficulty_levels}`.                   |
| `rival_better_in`                      | object              | idem.                                                |
| `invalid_cases[]`                      | list&lt;object&gt;        | `{run_id, case_id, task_category, difficulty_level, verdict, reason, hard_failures, bucket}`. |
| `suspicious_cases[]`                   | list&lt;object&gt;        | idem.                                                |
| `atlas_decide_recommendation`          | object              | `{status, note, entries[], global_recommendation}`.  |
| `difficulty_summary`                   | object              | herdado do battery pack.                             |
| `category_summary`                     | object              | herdado do battery pack.                             |
| `mode_distribution`                    | object              | herdado do battery pack.                             |
| `cases[]`                              | list&lt;object&gt;        | per-case row consumido pelo matrix (rationale por trás dos rankings). |
| `claim_ready`                          | bool                | **sempre `false`**.                                  |
| `external_provider_call`               | bool                | sempre `false`.                                      |
| `external_rivals_certification_status` | string              | sempre `blocked`.                                    |
| `separated_from_external_rivals_certification` | bool        | sempre `true`.                                       |
| `matrix_report_json_path`              | string \| null      | path JSON em disco.                                  |
| `matrix_report_md_path`                | string \| null      | path Markdown em disco.                              |
| `matrix_report_*_sha256`               | string \| null      | sha256 dos dois arquivos.                            |

---

## 5. CLI

```bash
# Bateria single-run multi-case
php artisan atlas:forge:rivals matrix-report --run-id=<id> --json

# Bateria N-run (release com 40 cases via 1 ou N run_ids)
php artisan atlas:forge:rivals matrix-report \
  --run-ids=fr2-release-a,fr2-release-b,fr2-release-c \
  --stage=pre_adjudication \
  --json --strict
```

Aliases: `matrix`, `report-matrix`, `final-report` → `matrix-report`.

`--strict` faz `insufficient_evidence` / `invalid_*` / `blocked` retornar
exit code 1 para CI fail-closed.

Saídas em disco (gravadas dentro do primeiro run válido):
- `runs/<first_run>/evidence/matrix_report.json`
- `runs/<first_run>/evidence/matrix_report.md`

---

## 6. Exemplo de tabela final (40 cases, 8 categorias × L1..L5)

A leitura humana da release segue 5 passos. Exemplo sintético:

```markdown
# Atlas Forge Rivals · Matrix Report

**Battery:** `battery-9f3a2c1e`
**Status:** `ok`
**Cases:** 40 total · 36 comparable+scored · 2 invalid · 2 suspicious

## TL;DR — Winner geral

- **Winner:** **Atlas Forge**
- **Atlas wins / Rival wins / Ties:** 21 / 12 / 3
- **Weighted total (Atlas vs Rival):** 47.5 vs 33.0 (spread 14.5)
- **Average score (Atlas vs Rival):** 78.3 vs 68.9

## Ranking por categoria

| Categoria             | Cases | Atlas | Rival | Empate | Leader        |
|---|---:|---:|---:|---:|---|
| `backend_logic`       |    8  |   6   |   2   |   0   | **Atlas Forge** |
| `frontend_ui`         |    5  |   2   |   3   |   0   | **Rival**       |
| `realistic_bugfix`    |    6  |   5   |   1   |   0   | **Atlas Forge** |
| `refactor`            |    4  |   2   |   1   |   1   | **Atlas Forge** |
| `test_design`         |    4  |   3   |   0   |   1   | **Atlas Forge** |
| `architecture`        |    4  |   1   |   3   |   0   | **Rival**       |
| `integration`         |    3  |   1   |   1   |   1   | **Empate**      |
| `performance_edge_case`|   2  |   1   |   1   |   0   | **Empate**      |

## Ranking por dificuldade (L1-L5)

| Nível | Peso | Cases | Atlas | Rival | Empate | Leader        |
|---|---:|---:|---:|---:|---:|---|
| **L1** |  1.0 |   8  |   6   |   1   |   1   | Atlas Forge   |
| **L2** |  1.5 |   0  |   0   |   0   |   0   | —             |
| **L3** |  2.0 |  20  |  10   |   8   |   2   | Atlas Forge   |
| **L4** |  2.5 |   0  |   0   |   0   |   0   | —             |
| **L5** |  3.0 |   8  |   5   |   3   |   0   | Atlas Forge   |

## Heatmap (categoria × dificuldade)

| Categoria \ Nível | L1 | L2 | L3 | L4 | L5 |
|---|---|---|---|---|---|
| `backend_logic`   | A 2/0/0 | · | A 4/2/0 | · | · |
| `frontend_ui`     | R 0/2/0 | · | T 2/1/0 | · | · |
| `realistic_bugfix`| A 2/0/0 | · | A 3/1/0 | · | · |
| `architecture`    | · | · | · | · | R 1/3/0 |
| ... |

_Legenda: `A` Atlas, `R` Rival, `T` Tie. `Ax/Rx/Tx` = wins por arm._

## planning_score vs execution_score

| Eixo       | Atlas | Rival | Leader        | Amostra | Dimensões                                  |
|---|---:|---:|---|---:|---|
| planning   | 81.4  | 64.2  | **Atlas Forge** |   36   | objective_alignment, scope_discipline, evidence_quality |
| execution  | 76.8  | 70.5  | **Atlas Forge** |   36   | patch_focus, implementation_complexity, test_quality, maintainability, risk_surface, cost_time_efficiency |

## Onde Atlas é melhor
- **Categorias:** `backend_logic`, `realistic_bugfix`, `refactor`, `test_design`
- **Dificuldades:** `L1`, `L3`, `L5`

## Onde Claude/Codex (Rival) é melhor
- **Categorias:** `frontend_ui`, `architecture`
- **Dificuldades:** —

## Invalid cases

| Run | Case | Categoria | L | Verdict | Motivo |
|---|---|---|---|---|---|
| `fr2-release-a` | `frontend-form-validation-accessibility-v2` | frontend_ui | L1 | invalid_no_patch_diff | invalid_verdict:invalid_no_patch_diff |
| `fr2-release-b` | `architecture-bounded-context-isolation` | architecture | L5 | invalid_tests_failed | invalid_verdict:invalid_tests_failed |

## Suspicious cases

| Run | Case | Categoria | L | Verdict | Motivo |
|---|---|---|---|---|---|
| `fr2-release-a` | `backend-pagination-off-by-one` | backend_logic | L3 | comparable | hard_failures:patch_diff_present_atlas |
| `fr2-release-c` | `performance-n-plus-one-query` | performance_edge_case | L3 | comparable | gate_outcome_only_no_quality_score |

## Recomendação para Atlas Decide

- **Global:** `default_to_atlas_forge_with_per_category_overrides`
- **Status:** `advisory`

| Categoria              | Recomendação           | Atlas win-rate | Rival win-rate | Amostra |
|---|---|---:|---:|---:|
| `backend_logic`        | `route_to_atlas_forge` | 0.750           | 0.250           |   8    |
| `frontend_ui`          | `route_to_raw_provider`| 0.400           | 0.600           |   5    |
| `realistic_bugfix`     | `route_to_atlas_forge` | 0.833           | 0.167           |   6    |
| `architecture`         | `route_to_raw_provider`| 0.250           | 0.750           |   4    |
| `integration`          | `human_review_inconclusive` | 0.333      | 0.333           |   3    |
| `performance_edge_case`| `human_review_inconclusive` | 0.500      | 0.500           |   2    |
| `refactor`             | `route_to_atlas_forge` | 0.500           | 0.250           |   4    |
| `test_design`          | `route_to_atlas_forge` | 0.750           | 0.000           |   4    |

> **claim_ready:** `false` · **external_rivals_certification:** `blocked` · **provider_call:** `false`
> Schema: `atlas.forge.rivals.matrix_report.v1`. Esta superfície descreve a evidência; não promove claim externo.
```

### 6.1 Como o humano lê esta tabela

1. **Começa pelo TL;DR.** Vê o winner, a contagem e o spread. Se o spread
   for pequeno (< 5 weighted points) e o `tie_count` alto, trate como
   empate apesar do label "Atlas Forge"/"Rival".
2. **Inspeciona o ranking por categoria.** É onde o sinal por *tipo de
   tarefa* aparece. A coluna `Leader` é a única que decide direção; o
   par `atlas_win_rate`/`rival_win_rate` mostra confiança.
3. **Inspeciona o ranking L1-L5.** Vê em que **dificuldade** o Atlas/Rival
   ganham. Atlas só vencer L1 e perder L5 conta uma história diferente
   de Atlas vencer L5 e perder L1 — pesos canon refletem isso.
4. **Heatmap.** Última linha de leitura — mostra concentração de força.
   Células `·` indicam zero amostras (não vencer por ausência, não por
   derrota). Use-o para detectar "onde temos cobertura insuficiente".
5. **Leitura crítica:** sempre olhe **Invalid + Suspicious** antes de
   confiar no winner. Se 8 dos 40 cases são invalid/suspicious, o winner
   está apoiado em 32 cases — o relatório diz isso explícito em
   `comparable_scored_count`.
6. **Recomendação Atlas Decide** é o output operacional: routing por
   categoria. `route_to_atlas_forge` ⇒ Atlas Decide deve preferir Atlas
   Forge naquela categoria; `human_review_inconclusive` ⇒ não confiar no
   sinal automatizado.
7. **Limitação:** o relatório nunca promove `claim_ready`. Mesmo com
   Atlas vencendo 21x12, o claim externo continua bloqueado por
   `external_rivals_certification`. Esse gate só se abre com aprovação
   humana documentada — não pelo matrix report.

---

## 7. `insufficient_evidence`

Sai sempre quando `comparable_scored_count == 0`:

- todos os cases têm `verdict` que começa com `invalid`, ou
- todos os cases têm `hard_failures` non-empty (verdict comparable mas
  com hard gate fail), ou
- nenhum case tem scorecard.

Nesse modo o relatório:
- emite `status=insufficient_evidence`,
- `overall.winner=null`,
- `category_ranking=[]`, `difficulty_ranking[*].cases=0`, `heatmap.cells={}`,
- `planning_vs_execution.*.atlas=null/rival=null/sample_size=0`,
- `atlas_decide_recommendation.status=insufficient_evidence` e zero entries,
- inclui **Invalid** e **Suspicious** sections quando há cases naqueles
  buckets, para o operador entender por que a bateria não rendeu sinal.

`--strict` faz esse status retornar exit 1 — CI fail-closed.

---

## 8. Test coverage

`tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportV1Test.php`
locks down 14 contract cases:

1. overall winner from comparable+scored cases.
2. category ranking with `leader` populated.
3. L1-L5 difficulty ranking (níveis vazios = `cases=0/leader=null`).
4. heatmap categoria × dificuldade.
5. planning_score vs execution_score split (com override).
6. invalid_cases vs suspicious_cases separados.
7. atlas_better_in / rival_better_in lists.
8. atlas_decide_recommendation per category + global.
9. markdown e JSON escritos em disco.
10. `insufficient_evidence` quando zero comparable+scored.
11. `claim_ready=false` e `external_rivals='blocked'`.
12. CLI `matrix-report --strict` retorna exit 1 em insufficient.
13. CLI `matrix-report` escreve files quando bateria é válida.
14. action `matrix-report` registrada no CLI.

```bash
/opt/homebrew/bin/php artisan test --filter='AtlasForgeRivalsMatrixReportV1'
```

---

## 9. Files of record

- Service:     `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportService.php`
- Battery dep: `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryEvidenceService.php`
- Dispatcher:  `app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php`
- CLI:         `app/Console/Commands/AtlasForgeRivalsCommand.php`
- Tests:       `tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsMatrixReportV1Test.php`
- Companion:   `atlas-forge-rivals-evidence-pack-replay-multi-case-v1.md`,
  `atlas-forge-rivals-evidence-pack-replay-hardening-v2.md`,
  `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`.

## Resumo

Matrix Report v1 emite winner geral, rankings por categoria e por L1-L5, heatmap, planning_score vs execution_score, invalid/suspicious separados, "onde Atlas/Rival é melhor", recomendação Atlas Decide e Markdown+JSON. Honesto com `insufficient_evidence`. `claim_ready=false` sempre; `external_rivals_certification='blocked'` sempre.

## Papel no Atlas

Superfície humana final do Forge Rivals: relatório que o operador lê depois da bateria multi-case para decidir routing por categoria, detectar regressões e ler sinal consultivo para Atlas Decide.

## Onde Se Encaixa

Filho direto do canon multi-case. Lê battery_evidence_pack.v1 + per-case scorecards. Companion de `battery-report` (roll-up básico).

## Contratos

Schema `atlas.forge.rivals.matrix_report.v1`. planning_score = média {objective_alignment, scope_discipline, evidence_quality}; execution_score = média das 6 dimensões restantes. `insufficient_evidence` quando zero case comparable+scored.

## Fluxo

`run-real → collect-evidence → battery-evidence → adjudicate → matrix-report`.

## Regras para IA

Nunca inventar score, nunca promover claim, nunca destravar external_rivals, nunca consolidar planning+execution num único score. `insufficient_evidence` é resposta legítima.

## Escopo de Implementacao

Service novo + dispatcher wire + CLI action `matrix-report` (+ aliases). Sem UI/Voice/Cartografia/Self-Construction.

## Dependencias

Multi-case v1, hardening v2, perfect battery v1, provider arena corpus v1.

## Evidencias

14 testes em `AtlasForgeRivalsMatrixReportV1Test`. CLI `--strict` coberto. Markdown + JSON em disco com sha256.

## Riscos

Aceitar winner com zero comparable; misturar planning/execution; promover `claim_ready=true`; inventar score per-case quando scorecard ausente.

## Exemplos

```bash
php artisan atlas:forge:rivals matrix-report \
  --run-ids=fr2-release-a,fr2-release-b,fr2-release-c \
  --stage=pre_adjudication --json --strict
```

## Proximas Acoes

Sincronizar este doc se o adjudicator alterar o set de 9 dimensões (afeta split planning/execution) ou se o corpus expandir a ladder L1-L5 (afeta `weights`).
