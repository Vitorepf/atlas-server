---
id: atlas-forge-rivals-battery-report-v2
type: engineering_knowledge
title: Atlas Forge Rivals · Battery Report v2
status: active
category: programming-forge
priority: 91
summary: Relatório multi-case humano-final do Forge Rivals (schema atlas.forge.rivals.battery_report.v2). Responde quem ganhou, em quais categorias canônicas, em quais dificuldades L1-L5, com qual confidence e por quê. Honesto: marca result_invalid_for_ranking quando justiça falha. Nunca destrava external_rivals_certification, nunca emite score sintético.
tags:
  - atlas
  - forge
  - rivals
  - battery-report
  - confidence
  - difficulty-l5
  - canon-categories
capabilities:
  - forge_rivals_battery_report_v2
  - forge_rivals_category_winner_diagnosis
  - forge_rivals_difficulty_scaling_diagnosis
  - forge_rivals_confidence_ladder
flows_to:
  - atlas-forge-rivals-reporting-v1
  - atlas-forge-rivals-matrix-report-v1
  - atlas-programming-professional-completion-audit
unlocks:
  - forge_rivals_human_final_battery_summary
  - forge_rivals_category_and_difficulty_diagnosis
governs:
  - forge_rivals_battery_report_v2_schema
  - forge_rivals_confidence_ladder
  - forge_rivals_result_validity
evidence:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsBatteryReportV2Test.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsMultiCaseReleaseRunnerTest.php
required_tests:
  - php artisan test tests/Feature/Ai/Programming/AtlasForgeRivalsBatteryReportV2Test.php
  - php artisan test tests/Feature/Ai/Programming/AtlasForgeRivalsMultiCaseReleaseRunnerTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Manter docs-health verde depois de qualquer ajuste no report v2.
  - Rodar a bateria release antes de usar o report como evidência humana.
decisions:
  - Schema bumpa para atlas.forge.rivals.battery_report.v2 sem remover campos v1 (category_aggregate, difficulty_aggregate, weighted_score, planning_score, execution_score, difficulty_score, claim_ready).
  - Adiciona surface v2: global_score / winner / winner_reason, confidence{level,reason,reasons[],is_trusted}, cases_total/valid/invalid, categories[] (atlas_avg/rival_avg/delta/winner/confidence por categoria canônica), difficulty_bands[] L1-L5 com delta_grows_with_difficulty, per_case_results[], hard_failures[], contaminated_game, why_score_counts / why_score_does_not_count, result_valid_for_ranking, safety.{external_rivals_certification_status, unlocks_external_rivals_certification, synthetic_score_admitted}.
  - Confidence ladder canônica: inconclusive → flow_validated → category_signal (≥12 casos válidos & ≥6 categorias com válidos) → trusted_battery (≥12 casos & ≥8 categorias).
  - Categorias canônicas (espelham AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES): planning, frontend_ui, backend_logic, realistic_bugfix, refactor, test_design, architecture, integration_performance.
  - Dificuldades canônicas L1, L2, L3, L4, L5 (DIFFICULTY_LEVELS), com pesos 1.0/1.5/2.0/2.5/3.0.
  - winner é derivado do delta global (atlas_avg − rival_avg). |Δ|<5.0 ⇒ human_review_required_tie. Hard failure ou contamination ⇒ winner=null.
  - claim_ready=true SOMENTE quando todos casos completed (mode≠local_fake) AND result_valid_for_ranking AND winner≠tie.
  - external_rivals_certification permanece blocked_requires_operator_approval em todo envelope. unlocks_external_rivals_certification é sempre false.
  - Markdown PT-BR humano: ## Resumo Executivo, ## Tabela Global, ## Resultado por Categoria, ## Resultado por Dificuldade L1-L5, ## Casos Inválidos/Contaminados, ## Casos Detalhados, ## Hard Failures, ## Por que o score conta/NÃO conta, ## Confidence, ## Próximas Ações, ## Cláusula de Segurança.
  - Hard gates centrais do AtlasForgeRivalsAdjudicatorService NÃO são modificados pelo report v2.
maintenance:
  - Atualizar quando AtlasForgeRivalsBatteryReportService mudar de schema, quando confidence ladder mudar ou quando TASK_CATEGORIES/DIFFICULTY_LEVELS forem renomeados no corpus.
  - Manter `tests/Feature/Ai/Programming/AtlasForgeRivalsBatteryReportV2Test.php` em sincronia com qualquer nova surface obrigatória.
  - Quando per-case scorecards forem geradas automaticamente pelo adjudicator (hoje vivem só em test fixtures e na via test-seed), atualizar o caminho `runs/<id>/cases/<safe>/evidence/scorecard.json` referenciado aqui.
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryReportService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryStateService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/Corpus/AtlasForgeRivalsProviderArenaCorpusService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsActionDispatcher.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsBatteryReportV2Test.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-reporting-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-matrix-report-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-battery-report-v2
graph_title: Atlas Forge Rivals · Battery Report v2
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
human_name: "Atlas Forge Rivals · Battery Report v2"
canonical_name: "Atlas Forge Rivals · Battery Report v2"
technical_name: atlas-forge-rivals-battery-report-v2
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-battery-report-v2.md
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsBatteryReportService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-battery-report-v2.md
allowed_changes:
  - Adicionar seções additive ao envelope JSON (mantendo todos campos v1+v2 listados em decisions).
  - Endurecer regras de confidence ou de detecção de contaminação.
  - Adicionar tradução PT-BR adicional ao Markdown.
forbidden_changes:
  - Remover ou renomear campos v1 (category_aggregate, difficulty_aggregate, weighted_score, planning_score, execution_score, difficulty_score, claim_ready, report_path) — quebraria contratos downstream (MatrixRunner/MultiCaseRelease tests + dispatcher).
  - Promover `claim_ready=true` ou `unlocks_external_rivals_certification=true` por qualquer caminho.
  - Inventar score quando per-case scorecard falta. Surface honestamente como `evidence_status=missing_scorecard` e marca o caso como `valid_for_ranking=false`.
  - Mudar hard gates do AtlasForgeRivalsAdjudicatorService a partir deste report (mudança fora de escopo).
depends_on:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-provider-arena-corpus-v1
  - atlas-forge-rivals-real-battery-operator-harness-v1
breaks_when:
  - per-case scorecards mudam de path canônico (`runs/<run_id>/cases/<safe>/evidence/scorecard.json`).
  - TASK_CATEGORIES é renomeado sem atualizar legacy alias.
  - DIFFICULTY_LEVELS deixa de ser L1-L5.
---
# Atlas Forge Rivals · Battery Report v2

## Resumo

Este módulo define o contrato canônico do Battery Report v2 do Atlas Forge Rivals. Ele transforma uma bateria multi-case em um relatório humano-final com winner global, diagnóstico por categoria, diagnóstico por dificuldade L1-L5, confidence ladder e cláusulas de segurança explícitas.

## Papel no Atlas

O Battery Report v2 é uma peça de evidência do eixo Programming/Forge/Rivals. Ele não é um desbloqueio automático de external rivals nem uma promoção de produto; sua função é produzir um relatório auditável para decisão humana.

## Onde Se Encaixa

O contrato se encaixa depois da execução/adjudicação da bateria Rivals e antes de qualquer claim humano sobre resultado competitivo. Ele complementa `atlas-forge-rivals-reporting-v1` e `atlas-forge-rivals-matrix-report-v1`.

## Contratos

O schema canônico é `atlas.forge.rivals.battery_report.v2`. A superfície obrigatória, compatibilidade v1, confidence ladder, justice gates, markdown emitido, comandos e testes estão detalhados nas seções abaixo.

## Fluxo

1. Rodar ou localizar um `run_id` de bateria.
2. Gerar o report v2 via `atlas:forge:rivals run-battery` ou `atlas:forge:rivals battery-report`.
3. Validar scorecards, hard gates, contaminação, confidence e safety.
4. Usar o Markdown apenas como evidência humana; nunca como unlock automático.

## Regras para IA

- Nunca inventar score quando scorecard por caso estiver ausente.
- Nunca promover `claim_ready=true` em `mode=local_fake`.
- Nunca setar `unlocks_external_rivals_certification=true`.
- Preservar campos v1 e adicionar apenas campos compatíveis.

## Escopo de Implementacao

O escopo permitido está em `AtlasForgeRivalsBatteryReportService`, testes de Battery Report v2 e este documento. Mudanças em adjudicação hard gate, provider real, external rivals certification ou Self-Construction OS ficam fora deste módulo.

## Dependencias

Depende de `atlas-forge-rivals-perfect-battery-and-adjudicator-v1`, `atlas-forge-rivals-provider-arena-corpus-v1`, `atlas-forge-rivals-real-battery-operator-harness-v1` e dos serviços listados em `related_paths`.

## Evidencias

Evidência mínima: testes dedicados do report v2, payload JSON com schema `atlas.forge.rivals.battery_report.v2`, Markdown emitido e confirmação de safety (`external_rivals_certification_status=blocked_requires_operator_approval`, `unlocks_external_rivals_certification=false`, `synthetic_score_admitted=false`).

## Riscos

Riscos principais: score sintético, bateria contaminada, workspace dirty, hard gate ignorado, confidence inflada, empate tratado como vitória, ou unlock acidental de external rivals certification.

## Exemplos

Exemplos de payload estão nas seções `Estrutura categories[]`, `Estrutura difficulty_bands[]` e `Estrutura per_case_results[]`.

## Proximas Acoes

Manter a bateria release e o report v2 alinhados com as categorias/dificuldades do corpus, fortalecer evidência por caso e preservar docs-health/architecture-validate verdes.

## Propósito

O **Battery Report v2** responde quatro perguntas que o report v1 não respondia:

1. **Quem ganhou?** — Atlas, Rival ou empate estatístico.
2. **Em quais áreas?** — winner por categoria canônica (8 categorias do corpus).
3. **Em quais dificuldades?** — winner por nível L1-L5 + sinal de escalada (`delta_grows_with_difficulty`).
4. **Com que confidence?** — ladder em 4 níveis (`inconclusive`, `flow_validated`, `category_signal`, `trusted_battery`).

Quando justiça falha (hard gate técnico, contaminação, sample insuficiente), o report **diz claramente** "resultado inválido para ranking" via `result_valid_for_ranking=false` + `why_score_does_not_count`. Nunca esconde a falha, nunca emite score sintético.

## Schema

`atlas.forge.rivals.battery_report.v3` (com `schema_version_v2` e `schema_version_v1` mantidos como aliases)

### Evolução v3 (multi-case canon)

`v3` mantém **todo** campo `v2`/`v1` como subset, e adiciona surface canônica
para o operador-humano e ferramentas downstream:

- `aggregate_score` — delta médio Atlas − Rival sobre todos os casos válidos (== `global_score`).
- `aggregate_atlas_avg` / `aggregate_rival_avg` — alias flat das médias globais.
- `confidence_level` — alias flat de `confidence.level` (ladder canônico).
- `case_results[]` — alias canônico de `per_case_results[]`.
- `category_results[]` — alias canônico de `categories[]`.
- `difficulty_results[]` — alias canônico de `difficulty_bands[]`.
- `total_cases` — alias flat de `cases_total`.
- `is_single_case` / `is_multi_case` — flags booleanos calculados a partir de `cases_total`.

Cada `case_results[]` ganha um sub-objeto `paths`:

- `case_dir`, `evidence_dir`
- `scorecard_path` + `scorecard_present`
- `manifest_path` + `manifest_present`
- `report_path` + `report_present`
- `replay_manifest_path` + `replay_manifest_present`
- `atlas_receipt_path` + `atlas_receipt_present`
- `rival_receipt_path` + `rival_receipt_present`
- `workspace_hashes_path` + `workspace_hashes_present`

Isto permite ao operador auditar cada caso isoladamente sem reconstruir o layout
de runs; o `*_present` é o estado real no disco no momento do render.

#### Caso degenerado single-case

Quando `cases_total == 1`, o envelope v3 continua emitindo o ladder completo
de L1-L5 (com níveis vazios reportando `valid_cases=0`) e as 8 categorias
canônicas (idem). `confidence_level` nunca alcança `trusted_battery` em
single-case, mas o envelope segue coerente — single-case é tratado como caso
degenerado de multi-case, sem duplicação de contrato.

#### Garantias mantidas pelo v3

- Hard fail em qualquer caso → `result_valid_for_ranking=false`, `winner=null`,
  `claim_ready=false`, mesmo se score médio é alto. Casos saudáveis ainda
  aparecem em `case_results[]` (não escondidos).
- Evidence/replay faltando → `evidence_status=missing_scorecard` ou
  `replay_status=fail` no caso, e `result_valid_for_ranking=false` no global.
- `external_rivals_certification_status` permanece
  `blocked_requires_operator_approval`; `unlocks_external_rivals_certification`
  permanece `false` em qualquer cenário.



### Surface canônica (envelope)

| Campo | Tipo | Significado |
| --- | --- | --- |
| `schema_version` | string | `atlas.forge.rivals.battery_report.v2` |
| `global_score` (delta) | float\|null | atlas_avg − rival_avg (apenas casos válidos para ranking) |
| `global_atlas_avg` / `global_rival_avg` | float\|null | médias globais (casos válidos para ranking) |
| `winner` | `'atlas' \| 'rival' \| 'human_review_required_tie' \| null` | null quando hard fail ou contamination |
| `winner_reason` | list<string> | razões legíveis (delta, threshold, hard_fail bloqueios) |
| `confidence` | `{level, reason, reasons[], is_trusted}` | ladder canônica |
| `cases_total` / `cases_valid` / `cases_invalid` | int | totais e contagem para ranking |
| `categories[]` | list | por categoria canônica do corpus |
| `difficulty_bands[]` | list | L1-L5 + `delta_grows_with_difficulty` |
| `per_case_results[]` | list | case_id, category, difficulty, atlas_score, rival_score, winner, hard_gates, evidence_status, replay_status, contamination_reason |
| `hard_failures[]` | list | por caso, com código canônico |
| `contaminated_game` | bool | true se qualquer caso tem dirty workspace, out_of_scope, bytecode, killed, synthetic_score, forbidden_files ou external_rivals_unlock |
| `contamination_reasons` | list<string> | razões agregadas |
| `result_valid_for_ranking` | bool | false bloqueia qualquer leitura competitiva do report |
| `why_score_counts` / `why_score_does_not_count` | list<string> | diagnóstico humano |
| `safety` | `{external_rivals_certification_status, unlocks_external_rivals_certification, synthetic_score_admitted}` | sempre `blocked_requires_operator_approval`, `false`, `false` |
| `claim_ready` | bool | true SOMENTE quando todos casos completed (mode≠local_fake) AND result_valid_for_ranking AND winner ≠ tie |
| `report_path` | string | caminho do Markdown emitido |

Campos v1 (`category_aggregate`, `difficulty_aggregate`, `weighted_score`, `planning_score`, `execution_score`, `difficulty_score`) **continuam presentes** byte-for-byte. v2 é estritamente additive.

### Estrutura `categories[]`

```jsonc
{
  "category_id": "backend_logic",
  "cases_total": 5,
  "valid_cases": 5,
  "atlas_avg": 88.0,
  "rival_avg": 70.0,
  "delta": 18.0,
  "winner": "atlas",
  "confidence": "trusted_battery",
  "atlas_wins": 5,
  "rival_wins": 0,
  "ties": 0,
  "invalid_cases": 0,
  "hard_failed_cases": 0
}
```

### Estrutura `difficulty_bands[]`

```jsonc
{
  "level": "L4",
  "weight": 2.5,
  "cases_total": 8,
  "valid_cases": 8,
  "atlas_avg": 90.0,
  "rival_avg": 65.0,
  "delta": 25.0,
  "winner": "atlas",
  "atlas_wins": 8,
  "rival_wins": 0,
  "ties": 0,
  "invalid_cases": 0,
  "delta_grows_with_difficulty": true,
  "rival_grows_with_difficulty": false
}
```

### Estrutura `per_case_results[]`

```jsonc
{
  "case_id": "backend-pagination-cursor",
  "category": "backend_logic",
  "task_category_legacy": "backend",
  "difficulty_level": "L3",
  "atlas_score": 88.0,
  "rival_score": 72.0,
  "score_source": "quality_dimensions",
  "winner": "atlas",
  "hard_gates": {"passed_count": 4, "failed_count": 0, "failed_codes": []},
  "evidence_status": "present",
  "replay_status": "pass",
  "contamination_reason": null,
  "valid_for_ranking": true
}
```

`evidence_status` ∈ `{present, missing_scorecard, missing_manifest, missing_receipts, pending, skipped}`.

`replay_status` ∈ `{pass, fail, unknown}`.

`contamination_reason` ∈ `{null, dirty_workspace_after_run, out_of_scope_files, bytecode_artifacts, arm_killed, synthetic_score_admitted, touched_forbidden_files, external_rivals_unlock_attempted}`.

## Confidence ladder

| Nível | Quando |
| --- | --- |
| `inconclusive` | 0 casos OR 0 válidos OR contaminated_game |
| `flow_validated` | hard_failures presentes OR `cases_valid < 12` OR `categories_with_valid < 6` |
| `category_signal` | `cases_valid ≥ 12` AND `categories_with_valid ≥ 6` AND sem hard fail AND sem contamination |
| `trusted_battery` | `cases_valid ≥ 12` AND `categories_with_valid ≥ 8` AND sem hard fail AND sem contamination |

`is_trusted=true` apenas em `trusted_battery`. `claim_ready` exige pelo menos `category_signal`.

## Justice gates

1. Qualquer caso com `hard_gates.failed_count > 0` ⇒ winner=null, claim_ready=false, surface no array `hard_failures[]`.
2. Qualquer caso com contamination (dirty/out_of_scope/bytecode/killed/synthetic/forbidden/external_unlock) ⇒ `contaminated_game=true`, winner=null, claim_ready=false.
3. Per-case scorecard ausente ⇒ `evidence_status=missing_scorecard`, `valid_for_ranking=false`. Score NÃO é inventado.
4. Per-case replay falhou ⇒ `replay_status=fail`, `valid_for_ranking=false`.
5. |delta global| < 5.0 ⇒ winner = `human_review_required_tie`, claim_ready=false.
6. external_rivals_certification permanece **blocked_requires_operator_approval** em qualquer caminho.

## Markdown emitido

Seções obrigatórias (ordem):

1. `# Atlas Forge Rivals · Battery Report v2`
2. `## Resumo Executivo` (1 parágrafo PT-BR: bateria, válidos, winner, confidence, categorias atlas/rival, mode local_fake aviso)
3. `## Tabela Global` (preset, mode, atlas/rival models, contadores, atlas_avg/rival_avg/delta/winner/confidence, weighted_score, claim_ready, external_provider_call)
4. `## Resultado por Categoria` (uma linha por categoria canônica)
5. `## Resultado por Dificuldade L1-L5` + nota de escalada
6. `## Casos Inválidos / Contaminados` (vazio quando todos válidos)
7. `## Casos Detalhados` (per-case com hard_gates ✓/✗)
8. `## Hard Failures` (vazio quando nenhum)
9. `## Por que o score conta` / `## Por que o score NÃO conta`
10. `## Confidence` (level + reasons[])
11. `## Próximas Ações` (comando sugerido + razões do winner)
12. `## Cláusula de Segurança` (external_rivals_certification_status, synthetic_score_admitted, claim_ready honesto)

## Comandos canônicos

```bash
# Roda bateria + report v2 em uma chamada (release; em quick é 3 casos, em release é 12).
php artisan atlas:forge:rivals run-battery \
  --case-set=release \
  --mode=local_fake \
  --atlas-model=claude_sonnet \
  --rival=claude_sonnet \
  --preset=release \
  --json --strict

# Re-renderiza o report v2 sobre um run_id existente.
php artisan atlas:forge:rivals battery-report --run-id=<RUN_ID> --json --strict
```

CLI alias para o action: `battery-report` (também acessível via dispatcher).

## Testes canônicos

- `tests/Feature/Ai/Programming/AtlasForgeRivalsBatteryReportV2Test.php` — schema v2, 40 casos canônicos com categorias e dificuldades surfaced, caso inválido marca confidence/missing_scorecard, hard gate bloqueia winner, empate produz human_review_required, vencedor por categoria, external_rivals continua blocked, contaminação invalida ranking, json+markdown gerados, escala L1→L5 detectada.
- `tests/Feature/Ai/Programming/AtlasForgeRivalsMatrixRunnerTest::test_battery_report_surfaces_planning_execution_difficulty_score_blocks` — contrato v1 preservado em v2.
- `tests/Feature/Ai/Programming/AtlasForgeRivalsMultiCaseReleaseRunnerTest::test_battery_report_action_renders_against_an_initialised_battery` — dispatcher continua expondo a action.

## Não-objetivos

- v2 NÃO modifica hard gates centrais do AtlasForgeRivalsAdjudicatorService.
- v2 NÃO escreve per-case scorecards (continua sendo papel do adjudicator / fixtures de teste).
- v2 NÃO substitui o `matrix-report` (que emite ranking ponderado L1-L5 com heatmap). Os dois coexistem.
- v2 NUNCA destrava external_rivals_certification, nunca promove claim_ready a partir de mode=local_fake, nunca emite score sintético.
