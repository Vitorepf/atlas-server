---
id: atlas-forge-rivals-reporting-v1
type: engineering_knowledge
title: Atlas Forge Rivals Reporting v1
status: active
category: programming-forge
priority: 94
summary: Relatório humano-final do Forge Rivals (schema atlas.forge.rivals.report.v3). Define headline, executive summary, vencedores por categoria, confidence ladder, suspicious results, hard failures, claim status separado de external_rivals_certification e próximas ações para o operador.
tags:
  - atlas
  - forge
  - rivals
  - report
  - confidence
  - atlas-decide
capabilities:
  - forge_rivals_human_report_v3
  - forge_rivals_doctor_next_advisory
  - forge_rivals_atlas_decide_advisory_signal
decisions:
  - Report v3 NUNCA destrava external_rivals_certification.
  - Report v3 separa battery_result_valid de claim_ready de external claim.
  - Adjudicator continua determinístico local; report é camada de leitura.
  - quick=flow_validated apenas; release com 12 casos × 8 categorias = trusted_battery.
maintenance:
  - Atualizar quando AtlasForgeRivalsReportService mudar schema ou markdown.
  - Manter sincronia com benchmark-strategy-v1 (confidence ladder) e perfect-battery-and-adjudicator-v1 (pipeline).
related_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsNextService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDoctorService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-reporting-v1
graph_title: Atlas Forge Rivals Reporting v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsNextService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDoctorService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-reporting-v1.md
allowed_changes:
  - Adicionar novos campos não destrutivos ao schema v3.
  - Refinar headline / executive_summary para clareza humana.
  - Endurecer detecção de suspicious (thresholds, novas heurísticas).
forbidden_changes:
  - Declarar winner sem replay verde e evidence completo.
  - Promover external_rivals_certification a partir do report.
  - Aceitar synthetic score ou esconder hard failures.
  - Remover separação entre battery_result_valid e claim_ready.
depends_on:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-rivals-evidence-pack-replay-manifest-v1
flows_to:
  - atlas-forge-rivals-provider-performance-ledger-v1
  - atlas-decide
unlocks:
  - operator_reads_battery_outcome_in_single_human_report
  - atlas_decide_consumes_advisory_signal_from_report
governs:
  - forge_rivals_report_v3_envelope
  - forge_rivals_claim_status_block
  - forge_rivals_atlas_decide_advisory_recommendations
evidence:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportV3Test.php
required_tests:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportV3Test.php
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
next_actions:
  - Quando multi-case real chegar, agregar categorias com mais de uma run e ampliar confidence ladder.
  - Conectar provider_performance_signal ao Ledger como entrada advisory.
---

# Atlas Forge Rivals · Reporting v1

**Schema:** `atlas.forge.rivals.report.v3`
**Entrypoint:** `php artisan atlas:forge:rivals report --run-id=<id> --json --strict`
**Adjacent:** `doctor`, `next`, `ledger-record`, `audit`

## Resumo

Report v3 é a cabine humana que transforma `manifest.json` + `scorecard.json` + replay em um veredito legível por pessoas: vencedor por categoria, vencedor por caso, confiança, suspeitas, hard failures, sinal medido advisory para Atlas Decide e próximas ações concretas. JSON estável + Markdown premium em pt-BR. Nunca destrava `external_rivals_certification`.

## Papel no Atlas

Forge Rivals produz três planos: o pipeline determinístico (run-battery), o adjudicator local (scorecard) e a camada humana (report). O report v3 é o único artefato que o operador lê quando quer responder "Atlas é melhor que Claude para qual tarefa?" — e responde sem mentir.

## Onde Se Encaixa

Acima de `atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`. Consome scorecard determinístico. Alimenta `atlas-forge-rivals-provider-performance-ledger-v1.md` apenas como sinal advisory.

## Contratos

### Envelope JSON v3

| Campo | Tipo | Descrição |
| --- | --- | --- |
| `schema_version` | string | `atlas.forge.rivals.report.v3` |
| `run_id` | string | id canônico do run |
| `preset` / `mode` | string | passados pelo manifest |
| `arms[]` | list | `{id, label, model}` para atlas e rival |
| `headline` | string (pt-BR) | frase humana sobre o resultado |
| `executive_summary` | string (pt-BR) | resumo com cases, categorias, confiança, motivo de não-100 |
| `overall_result` | object | winner, scores, margem, totais por categoria |
| `category_results[]` | list | por categoria: cases, winner, margem, why, caveats, measured_ahead, routing_effect=none |
| `difficulty_results[]` | list | por banda L1..L5 (`unknown` reservado): cases, winner, margem, validade |
| `planning_vs_execution_results[]` | list | split por `work_kind` (planning vs execution) |
| `provider_results[]` | list | por par `atlas_model_vs_rival_model` |
| `mode_results[]` | list | por modo (`fair` vs `full_power` vs `local_fake`) |
| `case_results[]` | list | por caso: winner, scores, evidence_status, replay_status, short_reason, **difficulty_band**, **work_kind**, **mode**, **atlas_model**, **rival_model** |
| `filters_applied` | object | filtros recebidos da CLI: `{category, difficulty, provider, mode}` |
| `confidence` | object | `{level, reason, is_trusted}` |
| `validity` | object | replay/adjudication/hard_failures clean? |
| `suspicious_results[]` | list | casos suspeitos com reasons |
| `hard_failures[]` | list | gates duros do scorecard |
| `evidence` | object | estado da evidência (multi-case ou single) |
| `replay` | object | passes/mismatches/event_count |
| `cost_time` | object | tempo, stdout, tokens |
| `provider_performance_signal` | object | linhas advisory para o Ledger |
| `atlas_decide_recommendations` | object | `{advisory_only:true, should_update_provider_topology:false, never_changes_atlas_decide_topology:true, owner_of_model_routing:"atlas_decide", measured_signal_by_category[]}` |
| `human_review` | object | required + checklist |
| `next_actions[]` | list | `[{kind, reason, command}]` |
| `artifacts[]` | list | paths presentes |
| `safety` | object | external_rivals_certification_status |
| `claim_status` | object | `{battery_result_valid, claim_ready, external_rivals_certification_status, human_review_required, can_feed_ledger, can_feed_decide_signal}` |
| `deterministic_hash` | string | sha256 do envelope (estável p/ mesmo input) |

Os campos v2 (`winner`, `claim_ready`, `declared_why`, `verdict`, `hard_gates`, `quality_dimensions` etc.) continuam sendo emitidos para compat com tools downstream.

### Headline canônicas

- `Resultado inconclusivo: evidência inválida (<verdict>).`
- `Resultado inconclusivo: replay falhou — evidência não confiável.`
- `Resultado inconclusivo: adjudicação ausente — rode atlas:forge:rivals adjudicate.`
- `Resultado inconclusivo: hard failures bloqueiam claim.`
- `Resultado inconclusivo: N caso(s) suspeito(s) — triagem obrigatória.`
- `Empate técnico: Atlas <a> vs Rival <r>, confiança <level>.`
- `Bateria release trusted: Atlas venceu A categoria(s), Rival venceu R, empate em E.`
- `Resultado por categoria — Atlas: A, Rival: R, empate: E (confiança <level>).`
- `Run quick: harness validado, vencedor do caso = <winner>. Não declara superioridade global.`

### Confidence ladder

| Nível | Condição mínima | Pode declarar |
| --- | --- | --- |
| `inconclusive` | replay/evidence/hard failures inválidos OU suspicious presente | nada |
| `flow_validated` | replay verde, ≤4 casos OU 1 categoria, sem suspicious | harness ok |
| `directional_signal` | ≥5 casos, ≥2 categorias, sem suspicious | tendência |
| `trusted_battery` | preset=release, ≥12 casos, ≥8 categorias, sem suspicious | vencedor da bateria |

`claim_ready=true` exige `trusted_battery` + winner + zero suspicious + replay verde. `can_feed_decide_signal=true` exige os mesmos critérios.

### Categorias canônicas

`frontend`, `backend`, `bugfix`, `tests`, `refactor`, `architecture`, `docs`, `performance`, `security`, `integration` (alias inferido). Cada `category_results[i]` contém:

- `category`
- `cases` (quantos rodaram)
- `atlas_score` / `rival_score` (média)
- `winner` (`atlas` / `rival` / `human_review_required_tie` / `null`)
- `margin`
- `confidence` (`medium` ≥3 casos, `low` 2, `directional_only` 1)
- `why` (frase humana)
- `best_case` / `worst_case`
- `caveats[]` (`amostra_pequena`, `hard_failures_em_X`, `replay_failed_em_X`)
- `measured_ahead_for_this_category` (`atlas` / `rival` / `tie` / `null`)
- `routing_effect` sempre `none`

### Canon de Dificuldade L1–L5

O report preserva o canon de dificuldade do corpus em todo case, score, evidence, replay e relatório. Bandas válidas: `L1`, `L2`, `L3`, `L4`, `L5`. `unknown` é fallback tolerado quando o manifest não declara — bandas `unknown` nunca alimentam `difficulty_fit` no signal.

| Banda | Significado | Score canônico |
| :---: | --- | ---: |
| L1 | trivial / cosmético | 1.0 |
| L2 | mecânico simples | 2.0 |
| L3 | padrão diário | 3.0 |
| L4 | exige design | 4.0 |
| L5 | arquitetural / sutil | 5.0 |

`difficulty_results[]` agrupa casos por banda e emite `validity` em `{valid, suspect, insufficient}`. `insufficient` quando a amostra é menor que 3 cases ou quando a banda é `unknown`.

### Provider Performance Signal v1

Schema dedicado: `atlas.forge.rivals.provider_performance_signal.v1`. Sempre `advisory_only=true`, `should_update_provider_topology=false`, `never_changes_atlas_decide_topology=true` e `owner_of_model_routing=atlas_decide`. Rivals emits measured evidence; Atlas Decide decides model routing.

| Campo | Descrição |
| --- | --- |
| `provider_measurement[]` | por arm (`atlas`, `rival`): `{model, wins, losses, ties, cases, measured_tier: measured_ahead_strong/measured_competitive/measured_behind/measured_weak_in_this_battery/directional_only, routing_effect:none, win_rate}` |
| `provider_recommendation[]` | alias de compatibilidade para `provider_measurement[]`; o campo `recommendation` contém tier medido, não ordem de routing |
| `category_fit[]` | por categoria: `{measured_ahead, winner, validity, validity_reason, cases, margin, routing_effect:none}` |
| `difficulty_fit[]` | mesmo shape para L1..L5 |
| `mode_fit[]` | mesmo shape para `fair` vs `full_power` |
| `provider_pair_fit[]` | mesmo shape para `atlas_model_vs_rival_model` |
| `do_not_use_when[]` | `[{condition, detail}]` — bloqueadores explícitos (suspicious, evidence ausente, rival underperformou) |
| `fallback_hint` | string canônica (`rivals_signal_unusable_until_replay_passes`, `rivals_signal_safe_to_consume_as_advisory_input_for_atlas_decide`, etc.) |
| `confidence` | `{level, reason, is_trusted}` (espelha o confidence ladder) |
| `rows[]` | linha por caso com `case_id`, `task_category`, `difficulty_band`, `work_kind`, `mode`, `atlas_model`, `rival_model`, scores, winner, suspicious, replay_passes |

### Limite de Autoridade com Atlas Decide

Rivals não escolhe o modelo certo para cada tarefa. Isso é trabalho exclusivo do Atlas Decide.

O report pode dizer: "nesta bateria, neste corpus, Atlas/Rival ficou medido à frente em determinada categoria". O report não pode dizer: "roteie esta categoria para este provider", "use este modelo como primary_builder" ou "substitua topology". Qualquer consumo pelo Atlas Decide é `advisory_only`; a decisão de roteamento continua fora do Rivals.

### Filtros CLI

Filtros aplicam ao `case_results[]` antes da agregação, então todos os rankings derivados refletem o subset filtrado. Quatro dimensões:

| Flag | Valor |
| --- | --- |
| `--category` | nome canônico (`frontend`, `backend`, `bugfix`, `tests`, `refactor`, `architecture`, `docs`, `performance`, `security`) |
| `--difficulty` | `L1`, `L2`, `L3`, `L4`, `L5` |
| `--provider` | substring de modelo (`opus`, `sonnet`, `codex`) — match em qualquer arm |
| `--mode-filter` | `fair`, `full_power`, `local_fake` (`--mode` segue reservado para `run-real`/`run-battery`) |

Exemplo:

```bash
php artisan atlas:forge:rivals report --run-id=<id> \
  --category=backend --difficulty=L4 --provider=opus --mode-filter=fair --json --strict
```

`filters_applied` no envelope mostra o que entrou.

### Matrix Evidence & Replay Lock

Quando o run é multi-case (`runs/<id>/cases/<case_id>/`), o report v3 emite `matrix_evidence_lock` (schema `atlas.forge.rivals.matrix_evidence_lock.v1`). É o gate que decide se a bateria pode promover `battery_result_valid=true`.

**Evidence obrigatória por case** (declarada em `required_artifacts_per_case`):

| Artefato | Caminho canônico | Por que |
| --- | --- | --- |
| `manifest` | `cases/<case>/evidence/manifest.json` | declara `case_id`, `task_category`, `difficulty_band`, `mode`, modelos |
| `scorecard` | `cases/<case>/evidence/scorecard.json` | veredito determinístico local com winner, scores, hard_failures |
| `atlas_receipt` | `cases/<case>/evidence/atlas_receipt.json` | receipt do arm Atlas (real ou fake) |
| `rival_receipt` | `cases/<case>/evidence/rival_receipt.json` | receipt do arm Rival |
| `atlas_patch` | `cases/<case>/evidence/atlas_patch.diff` | diff completo do Atlas |
| `rival_patch` | `cases/<case>/evidence/rival_patch.diff` | diff completo do Rival |
| `atlas_test_log` | `cases/<case>/evidence/atlas_test.log` | output do test command no Atlas |
| `rival_test_log` | `cases/<case>/evidence/rival_test.log` | output do test command no Rival |
| `workspace_hashes` | `cases/<case>/evidence/workspace_hashes.json` | sha256 antes/depois do worktree, `dirty_after_run` |
| `difficulty_band` | dentro do manifest | canon L1-L5; **`unknown` é inválido para claim** |

**Regras para claim final** (`claim_status.claim_ready`):

1. `matrix_ok=true` — nenhum case marcado inválido (hard floor: 1 case inválido derruba).
2. `invalid_ratio ≤ 0.10` — até 10% dos casos podem ser inválidos antes de cair de produção (mas em multi-case o hard floor de 1 também derruba).
3. `missing_difficulty_cases=[]` — todos os cases declaram L1-L5 válido.
4. `replay_drift_cases=[]` — todo case replayou sem `hard_failures` nem `replay_did_not_pass`.
5. `replay_passes=true` no agregado da bateria.
6. Adjudicação determinou winner real (não-null), `human_review_required=false`.
7. Confidence `trusted_battery` + zero suspicious.
8. Mesmo com tudo verde, `external_rivals_certification` continua `blocked_requires_operator_approval`.

**Falha em qualquer regra acima** ⇒ `claim_status.claim_ready=false`, `can_feed_ledger=false`, `can_feed_decide_signal=false`, `matrix_blocks_claim_final=true`. Hard floor é `MATRIX_INVALID_HARD_FLOOR=1` e ratio é `MATRIX_INVALID_RATIO_BLOCK=0.10`.

**Replay walks every case.** O verifier `battery-verify-evidence` (schema `atlas.forge.rivals.battery_replay_verification.v1`) re-hashes patch + test log por arm em cada case, valida `difficulty_level ∈ L1-L5`, bloqueia em hash drift, dirty workspace, receipts ausentes, bytecode tracked. Single-case `replay` continua walking `evidence_pack.json` do run.

### Suspicious detection

Um caso é marcado como `suspicious` quando alguma destas heurísticas dispara:

- `rival_score_below_70_for_strong_provider` — Claude/Codex/Opus pontuou abaixo de 70.
- `large_margin_in_single_case` — |margem| ≥ 30 em um único caso.
- `atlas_patch_almost_empty` / `rival_patch_almost_empty` — patch ≤ 64 bytes.

Suspicious → `confidence=inconclusive`, `claim_ready=false`, `human_review_required=true`, `can_feed_ledger=false`.

### Claim status (separation block)

| Campo | true significa |
| --- | --- |
| `battery_result_valid` | evidence completo, winner conhecido, sem human review |
| `claim_ready` | trusted_battery + winner + zero suspicious + replay verde |
| `external_rivals_certification_status` | sempre `blocked_requires_operator_approval` |
| `human_review_required` | tie/suspicious/hard failure / evidence ausente |
| `can_feed_ledger` | battery_valid + zero suspicious |
| `can_feed_decide_signal` | can_feed_ledger + trusted_battery |

**Mesmo `claim_ready=true` NÃO destrava `external_rivals_certification`.** Esse claim externo permanece gated por aprovação de operador, sempre.

## Fluxo

1. Operador roda `run-battery` (ou pipeline parcial) e obtém `run_id`.
2. `report --run-id=<id>` lê manifest + scorecard + replay (single-case) ou agrega `runs/<id>/cases/<case_id>/` (multi-case).
3. Render produz JSON v3 + `evidence/report.md` em pt-BR.
4. `next` (advisory) detecta o estado e devolve o próximo comando único.
5. `doctor` valida ambiente (corpus, git, worktree, policy, provider binaries).

### CLI

```bash
php artisan atlas:forge:rivals report --run-id=<id> --json --strict
php artisan atlas:forge:rivals next --run-id=<id> --json --strict
php artisan atlas:forge:rivals doctor --json --strict
```

### Doctor: novos checks

- `corpus_registry_loadable` — `ok=true` quando o registry carrega ≥1 caso. Quando injetado como `null` (unit-test seam), reporta sentinel info.
- `external_rivals_policy_safe` — sempre `ok=true`, afirma `external_rivals_certification=blocked_requires_operator_approval`.

### Next: fluxo

| Fase detectada | Próxima ação |
| --- | --- |
| `no_run_id` | `run-battery --mode=local_fake --preset=quick` |
| `run_dir_missing` | `setup --source-ref=HEAD` |
| `worktrees_missing` | `setup --source-ref=HEAD --run-id=<id>` |
| `manifest_missing` | `run-real --run-id=<id>` |
| `verdict_invalid` | `reset --reason=invalid_run` |
| `confirmations_missing` | `run-real --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call` |
| `evidence_missing` | `collect-evidence --run-id=<id>` |
| `scorecard_missing` | `adjudicate --run-id=<id>` |
| `report_missing` | `report --run-id=<id> --json --strict` |
| `complete` | `ledger-record --run-id=<id>` |

## Regras para IA

- Não declarar vencedor sem evidence completo, replay verde, sem hard failures.
- Não esconder suspeitas — exibi-las marca confiança como `inconclusive`.
- Não promover `external_rivals_certification` a partir do report.
- Não aceitar synthetic score (`atlas_score`/`rival_score` nulos quando evidence inválido).
- Não declarar superioridade global em preset `quick`.
- Não atualizar topology a partir do report — `should_update_provider_topology` sempre `false`.

## Escopo de Implementacao

`AtlasForgeRivalsReportService` (v3), `AtlasForgeRivalsNextService` (novo), `AtlasForgeRivalsDoctorService` (estendido), `AtlasForgeRivalsActionDispatcher` (rota `next`), `AtlasForgeRivalsCommand` (action `next` adicionada).

## Dependencias

Adjudicator determinístico v1 (`atlas.forge.rivals.adjudication.v1`), evidence pack v2 hardening, replay v2, corpus do Provider Arena.

## Evidencias

`AtlasForgeRivalsReportV3Test` cobre 27 cenários: headline clara, inconclusivo (evidência/replay/adjudicação), winner por categoria, empate, suspicious, hard failures, claim separation, confidence ladder, advisory_only, markdown gerado em pt-BR, deterministic hash, artifacts paths, próximas ações, e doctor com corpus registry awareness.

## Riscos

Operador confundir `claim_ready` com claim externo. Confiança `trusted_battery` sem triage de suspeitas. Categoria com amostra pequena tratada como evidência forte. Mitigações: separação explícita via `claim_status`, `confidence.reason` legível, `caveats[]` por categoria, `human_review.checklist[]` adaptativo, `next_actions[]` com comando único e o quê fazer a seguir.

## Exemplos

```bash
# Bateria release real
php artisan atlas:forge:rivals run-battery --mode=fair --atlas-model=sonnet --rival=claude_sonnet \
  --preset=release --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json --strict

# Ler relatório humano
php artisan atlas:forge:rivals report --run-id=<id> --json --strict

# Saber o próximo passo
php artisan atlas:forge:rivals next --run-id=<id> --json --strict
```

## Proximas Acoes

1. Quando o orquestrador multi-case real entregar `runs/<id>/cases/*`, o report já agrega — testes cobrem o caminho.
2. Conectar `provider_performance_signal` ao Ledger como entrada advisory (sem destravar Decide topology).
3. Ampliar confidence ladder com `provider_ranking` quando o Ledger tiver histórico suficiente.
