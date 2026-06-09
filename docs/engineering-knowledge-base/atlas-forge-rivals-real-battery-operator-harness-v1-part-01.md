---
id: atlas-forge-rivals-real-battery-operator-harness-v1-part-01
type: engineering_knowledge
title: Atlas Forge Rivals Real Battery Operator Harness v1 · Parte 1
status: source_material
implementation_state: source_material_no_runtime_authority
category: programming-forge
priority: 88
summary: Recorte focado de Atlas Forge Rivals Real Battery Operator Harness v1: Matrix Runner v1 (8x5 corpus consumer) ate Multi-case Release Runner v1 (battery.json + L1-L5 + resume).
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
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-real-battery-operator-harness-v1-part-01
graph_title: Atlas Forge Rivals Real Battery Operator Harness v1 Parte 1
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-forge-rivals-real-battery-operator-harness-v1
graph_status: active
graph_source: repo
human_name: Atlas Forge Rivals Real Battery Operator Harness v1 Parte 1
canonical_name: Atlas Forge Rivals Real Battery Operator Harness v1 Parte 1
technical_name: atlas-forge-rivals-real-battery-operator-harness-v1-part-01
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1-part-01.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1-part-01.md
allowed_changes:
  - Atualizar somente o detalhe operacional desta parte.
forbidden_changes:
  - Transformar Rivals em routing, provider decision ou feature de produto.
depends_on:
  - atlas-forge-rivals-real-battery-operator-harness-v1
flows_to:
  - atlas-forge-rivals-reliability-lockdown-v1
unlocks:
  - forge_rivals_readable_cartography
governs:
  - forge_rivals_real_run_authorization
evidence:
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter este recorte alinhado ao índice canônico.
---
# Atlas Forge Rivals Real Battery Operator Harness v1 · Parte 1

## Resumo

Este recorte preserva uma parte focada de Atlas Forge Rivals Real Battery Operator Harness v1: Matrix Runner v1 (8x5 corpus consumer) ate Multi-case Release Runner v1 (battery.json + L1-L5 + resume).

## Papel no Atlas

Mantém detalhe operacional Rivals fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md`.

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
## Matrix Runner v1 (8x5 corpus consumer)

Camada nova entregue 2026-05-16 sobre o multi-case v1: o runner agora **consome** o corpus 8 categorias × 5 dificuldades (40 cases) que o Claude 1 está formando, sem editar o corpus. Tudo dirigido por dados — qualquer expansão futura (10×6, 12×7, etc.) reaproveita o mesmo pipeline.

### Campos da matriz preservados por case

O adapter (`AtlasForgeRivalsRunRealService::adaptCorpusCase`) passa os campos canônicos da matriz para o runner e o BatteryStateService grava em `battery.json::cases[]`:

| Campo | Tipo | Origem | Fallback |
|---|---|---|---|
| `category` | string | corpus declarado | `''` |
| `task_category` | string | corpus declarado (legacy alias) | derivado de `category` |
| `difficulty` | string `easy\|medium\|hard` | corpus declarado | `''` |
| `difficulty_level` | string `L1`…`L5` | corpus declarado | `difficultyToLevel(difficulty)` |
| `difficulty_weight` | float | ladder L1-L5 (1.0 → 3.0) | mapeamento canon |
| `difficulty_score` | float | corpus declarado (Schema Contract Service) | `difficulty_weight` |
| `planning_weight` | float | corpus declarado | `difficulty_weight` |
| `execution_weight` | float | corpus declarado | `difficulty_weight` |

Tudo passa por `BatteryStateService::initialize` sem hardcode de N — `case_count` é sempre `count($cases)`. 40 ou 60 ou 100, o pipeline trata igual.

### Status canônico (counters)

`status` retorna o bloco `progress` para qualquer bateria (vazia ou cheia):

```json
"progress": {
  "total": 40,
  "passed": 7,
  "failed": 2,
  "invalid": 1,
  "running": 1,
  "pending": 28,
  "skipped": 1,
  "remaining": 29
}
```

`remaining = pending + running`. Quando não há bateria inicializada, todos voltam `0` — o cliente nunca precisa de `if (battery['exists'])` para ler counters.

### Action `next` battery-aware

`AtlasForgeRivalsNextService` agora checa `battery.json` antes do manifest legado:

- Bateria com `pending_case_count > 0` → `phase: battery_paused_pending_cases` + `command: php artisan atlas:forge:rivals resume --run-id=<id> --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json` (sempre carrega as 3 confirmações como template; operador edita se quiser).
- Bateria com todos os cases terminal → `phase: battery_settled_all_cases_terminal` + `command: php artisan atlas:forge:rivals battery-report --run-id=<id> --json`.
- Sem bateria → cai no advisor de pipeline atual (run-real / setup / etc.).

`observations.battery_next_case_id` é o `case_id` exato do próximo case a rodar — `next` responde "qual é o próximo case?" e "qual é o comando seguro pra continuar?" no mesmo envelope.

### BatteryReport: 3 scores adicionais

`battery-report` (rota canônica `AtlasForgeRivalsBatteryReportService`) agora emite quatro blocos de score:

| Bloco | Pesos lidos | Significado |
|---|---|---|
| `weighted_score` | `difficulty_weight` (L1-L5) | score histórico, sempre populado |
| `planning_score` | `planning_weight` (matrix) | quanto a bateria pontuou no eixo de planejamento |
| `execution_score` | `execution_weight` (matrix) | quanto a bateria pontuou no eixo de execução |
| `difficulty_score` | `difficulty_score` (matrix) | índice de dificuldade agregado |

Quando o corpus ainda não declara um dos pesos (fase de transição do Claude 1), o adapter falha para `difficulty_weight` e o score continua honesto — nenhum 0 falso. `report.md` mostra os 4 blocos como linhas separadas no `Overview`.

### Como o runner processa N cases

```
RunRealService::run(input)
  └── resolveCaseContext(input, preset) ──→ N cases (40, 60, 100, …)
       ├── source=provider_arena_corpus  (case_set declarado OU preset=release)
       └── adaptCorpusCase × N           (passa-through todos os pesos da matriz)
  └── battery.initialize(runId, ctx, allCases) ──→ battery.json com N entries pending
  └── isResume? filter cases para apenas state ∈ {pending, running}
  └── foreach case:
       ├── battery.markCaseRunning(runId, caseId)
       ├── reset worktree (entre cases)
       ├── stage fixture + workspace_hash_before
       ├── runArm atlas / runArm rival   (em local_fake: zero subprocess)
       ├── after_clean_check
       ├── verdict por case (worst-of)
       ├── persistir per-case em cases/<case_id>/
       └── battery.markCaseFinished(runId, caseId, verdict, summary)
  └── battery.finalize(runId, aggregate)
       └── computeBatteryAggregateVerdict / computeBatteryClaimReady
```

### Como retoma falhas

```
operator: ^C ou exit 1 → bateria fica em battery_status=paused com N pending
operator runs:
  php artisan atlas:forge:rivals next --run-id=<id> --json
  └── retorna { phase: battery_paused_pending_cases, command: 'resume --run-id=<id> --confirm-* --json' }
operator copy-pastes resume:
  └── RunReal lê battery.json, encontra resume_count > 0, filtra cases→pendings
  └── battery.recordEvent('battery_resumed', { resume_count: 1, pending_case_count: N })
  └── continua iterando do próximo pending
```

Cases já terminais nunca re-rodam automaticamente. Para forçar re-execução, operador chama `markCaseRunning` via API ou usa `reset --run-id=<id> --reason=…` (apaga a bateria inteira).

### Como difficulty metadata é preservada end-to-end

```
corpus (Claude 1 v1)
  └── case manifest declara: difficulty, difficulty_level, difficulty_score,
                              planning_weight, execution_weight
       (Schema Contract Service valida no init do app)
adapter (adaptCorpusCase)
  └── persiste todos os campos no payload do case (com fallback para
       difficulty_weight quando o corpus ainda não declara)
battery.json (BatteryStateService)
  └── cada cases[] grava: difficulty / difficulty_level / difficulty_weight /
                          difficulty_score / planning_weight / execution_weight
events.jsonl
  └── case_started + case_finished carregam difficulty_level + task_category
report (BatteryReportService)
  └── 3 scores separados (planning, execution, difficulty) +
       breakdown por L1-L5 + breakdown por categoria
audit / replay
  └── difficulty_level continua disponível no manifest e na cases/<id>/*
```

Difficulty é preservada em TODA camada — sem regravar, sem perder, sem inferir.

## Multi-case Release Runner v1 (battery.json + L1-L5 + resume)

Camada nova entregue 2026-05-16 sobre o v2 single-button: cada run de bateria persiste um `battery.json` canônico e um `battery.jsonl` append-only no nível da bateria, com suporte explícito a resume seguro, estados por case e ladder de dificuldade L1-L5.

### Layout canônico

```
/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/
  ├── atlas/                              # worktree do braço Atlas Forge (git worktree add)
  ├── rival/                              # worktree do braço baseline (git worktree add)
  ├── battery.json                        # catálogo da bateria (schema v1)
  ├── battery.jsonl                       # eventos de bateria (battery_started/case_state_changed/...)
  ├── events.jsonl                        # eventos detalhados do run (provider_started/heartbeat/...)
  ├── intent.json                         # snapshot da intenção do run
  └── evidence/
      ├── manifest.json                   # manifest da sessão atual (multi → worst-of)
      ├── atlas_receipt.json              # receipt agregado (worst-of)
      ├── rival_receipt.json              # idem
      ├── workspace_hashes.json
      ├── battery_report.md               # report agregado por categoria + L1-L5
      ├── scorecard.json                  # produzido pelo adjudicator
      ├── report.md                       # report executivo do adjudicator (já existia)
      └── cases/
          ├── backend-pagination-off-by-one/
          │   ├── atlas_receipt.json      # per-case
          │   ├── rival_receipt.json
          │   ├── workspace_hashes.json
          │   ├── atlas_patch.diff
          │   ├── rival_patch.diff
          │   ├── atlas_test.log
          │   ├── rival_test.log
          │   ├── atlas_provider_stdout.log
          │   ├── atlas_provider_stderr.log
          │   ├── rival_provider_stdout.log
          │   └── rival_provider_stderr.log
          ├── frontend-form-validation-accessibility/...
          └── ...
```

### Schema `atlas.forge.rivals.battery.v1`

Exemplo enxuto (shape canônico produzido por `php artisan atlas:forge:rivals run-battery --case-set=quick --dry-run --json`):

```json
{
  "schema_version": "atlas.forge.rivals.battery.v1",
  "run_id": "battery-20260516-001242-wilufk",
  "preset": "smoke", "case_set": "quick", "mode": "fair",
  "atlas_model": "claude_sonnet", "rival_model": "claude_sonnet",
  "started_at": "2026-05-16T00:12:42+00:00", "updated_at": "...", "finished_at": null,
  "resume_count": 0, "case_count": 3,
  "cases": [
    { "case_id": "backend-pagination-off-by-one", "case_index": 0, "task_category": "bugfix",
      "difficulty": "easy", "difficulty_level": "L1", "difficulty_weight": 1.0,
      "state": "pending", "verdict": null, "attempts": 0,
      "evidence_dir": "cases/backend-pagination-off-by-one" },
    { "case_id": "frontend-form-validation-accessibility", "difficulty_level": "L3", "state": "pending", "...": "..." },
    { "case_id": "performance-n-plus-one-query", "difficulty_level": "L5", "state": "pending", "...": "..." }
  ],
  "battery_status": "running", "aggregate_verdict": null, "claim_ready": false,
  "external_provider_call": false, "provider_tokens_spent": false,
  "separated_from_external_rivals_certification": true
}
```

### Estados por case e por bateria

Per-case (`cases[].state`):

| Estado | Origem | Como sair |
| --- | --- | --- |
| `pending` | criado na inicialização | runner avança ao chamar `markCaseRunning` |
| `running` | runner começou o case (start ou retry) | termina via `markCaseFinished` |
| `completed` | `verdict=comparable` | terminal |
| `failed` | `verdict in {invalid_tests_failed, invalid_no_patch_diff, inconclusive, invalid_provider_timeout}` | terminal |
| `invalid` | `verdict in {invalid_workspace_after_run, invalid_fixture_blocked}` | terminal |
| `skipped` | `markCaseSkipped(reason)` chamado pelo operador | terminal |

Per-battery (`battery_status`):

| Estado | Significado |
| --- | --- |
| `pending` | inicializada mas runner ainda não chamou `finalize` |
| `running` | runner em execução |
| `paused` | `finalize` rodou mas ainda há cases pending (resume disponível) |
| `completed` | todos os cases em terminal e nenhum blocker |
| `blocked` | finalize chamado com `blocked=true` (ex.: todos cases fixture-blocked) |
| `stalled` | sem heartbeat há mais que o limite |

### Ladder L1-L5 e como dificuldade entra no score

`AtlasForgeRivalsProviderArenaCorpusService` é a única fonte de verdade do mapping (constantes `DIFFICULTY_LEVEL_L1..L5`, `DIFFICULTY_TO_LEVEL`, `DIFFICULTY_LEVEL_SCORE_WEIGHTS`):

| Bucket corpus | Level canon | Peso default no score |
| --- | --- | --- |
| `easy` | `L1` | 1.0 |
| — | `L2` (reservado) | 1.5 |
| `medium` | `L3` | 2.0 |
| — | `L4` (reservado) | 2.5 |
| `hard` | `L5` | 3.0 |

`AtlasForgeRivalsBatteryReportService` calcula `weighted_score_percent = sum(completed_weights) / sum(all_weights) * 100`. O score é **informativo** — `claim_ready=false` continua absoluto quando qualquer case está fora de `completed`, e `external_rivals_certification` permanece selado.

### CLI

```
# planeja sem provider (sem confirmações)
php artisan atlas:forge:rivals run-battery --case-set=quick --dry-run --json --strict

# bateria real release (12 cases). exige as 3 confirmações
php artisan atlas:forge:rivals run-battery \
  --preset=release --mode=fair \
  --atlas-model=sonnet --rival=claude_sonnet \
  --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json --strict

# inspeciona a bateria + heartbeat + battery snapshot
php artisan atlas:forge:rivals status --run-id=<run_id> --json

# devolve o próximo case pendente (ou null)
php artisan atlas:forge:rivals next --run-id=<run_id> --json

# retoma a bateria, executando só os cases ainda pending
php artisan atlas:forge:rivals resume --run-id=<run_id> \
  --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call \
  --json --strict

# report agregado por categoria + L1-L5 (lê battery.json)
php artisan atlas:forge:rivals battery-report --run-id=<run_id> --json
```

### Resume seguro

`resume` (action) é equivalente a `run-battery --resume`. Em qualquer caso, o runner:

1. Lê `battery.json` (se existe). Bumps `resume_count`.
2. Filtra `cases` para apenas `pending`/`running` (cases terminal nunca re-rodam automaticamente).
3. Se nenhum case pending: retorna `note=all_cases_already_terminal_in_battery_resume` sem rodar nada.
4. Senão: executa apenas os cases pending, mantendo `runs/<run_id>/cases/<case_id>/` dos cases anteriores intocados.

`status` retorna `next_command` apontando para `resume` quando a bateria está paused e há cases pending.

### Contratos absolutos

- `claim_ready=false` enquanto qualquer case não estiver em `completed`.
- `external_provider_call=false` e `provider_tokens_spent=false` em todo response de dry-run / blocked / local_fake.
- `separated_from_external_rivals_certification=true` em todos os responses.
- Nenhum case `skipped`/`failed`/`invalid`/`pending` pode produzir score final.
- Per-case `attempts` incrementa a cada `markCaseRunning` — auditável.
