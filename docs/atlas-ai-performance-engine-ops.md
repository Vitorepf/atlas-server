# Atlas AI Performance Engine Ops

Este documento define como operar o engine de performance do Atlas AI em producao.

## Rollout

Use uma flag principal:

- `ATLAS_REPORT_ENGINE_VERSION=legacy`: relatorio atual, sem engine.
- `ATLAS_REPORT_ENGINE_VERSION=shadow`: engine roda em paralelo, grava `ai_performance_report_runs`, mas o payload mobile continua schema v1.
- `ATLAS_REPORT_ENGINE_VERSION=next`: engine produz schema v2 e pode emitir recomendacoes no Inbox.

Modo de execucao opcional:

- `ATLAS_REPORT_ENGINE_RUN_MODE=shadow`: grava runs sem emissao pelo engine.
- `ATLAS_REPORT_ENGINE_RUN_MODE=live`: usado no `next`.
- `ATLAS_REPORT_ENGINE_RUN_MODE=dry_run`: nao cria rows de run, audit, finding, recommendation ou Inbox; usado para smoke seguro.

## Ordem Diaria

Scheduler recomendado:

1. `atlas:ai:recommendations:measure --json` as 06:40
2. `atlas:ai:metrics:snapshot-refresh --days=2 --json` as 06:50
3. `atlas:ai:telemetry:performance-report --type=auto --recompute --emit --json` as 07:05

O snapshot precisa rodar antes do relatorio para alimentar EWMA, Mann-Kendall e CUSUM. O measurement roda antes para que o relatorio da manha ja mostre recomendacoes resolvidas, medidas ou self-healed.
O monitor `atlas:cli:mobile alert-check --apply --json`, quando habilitado, tambem valida `performance_report_fresh`: depois de `ATLAS_AI_PERFORMANCE_REPORT_TIME + ATLAS_AI_PERFORMANCE_REPORT_GRACE_MINUTES`, a ausencia do Inbox diário vira alerta `critical`.

## Comandos Operacionais

Rodar o engine para uma data:

```bash
php artisan atlas:ai:engine:run --date=2026-04-30 --engine-version=shadow --json
```

Rodar multi-window:

```bash
php artisan atlas:ai:engine:run --date=2026-04-30 --type=multi --windows=3,7,15,30 --engine-version=shadow --json
```

Replay de um run salvo:

```bash
php artisan atlas:ai:engine:run --replay=<run_id> --json
```

Backfill:

```bash
php artisan atlas:ai:engine:backfill --from=2026-04-01 --to=2026-04-30 --type=both --engine-version=shadow --refresh-snapshots --json
```

Medir recomendacoes aplicadas:

```bash
php artisan atlas:ai:recommendations:measure --json
```

Atualizar snapshots:

```bash
php artisan atlas:ai:metrics:snapshot-refresh --date=2026-04-30 --days=1 --json
```

Smoke seguro do pipeline:

```bash
php artisan atlas:ai:performance:smoke --date=2026-04-30 --windows=3,7,15,30 --json
```

O smoke valida tabelas, configuracao, refresh de snapshots, payload diario, payload multi-window e o contrato de `dry_run`. Ele pode escrever snapshots idempotentes, mas deve manter `ai_performance_report_runs`, `ai_data_confidence_audit`, `ai_report_findings`, `ai_performance_recommendations` e `ai_inbox_items` sem alteracao. Use `--strict` em CI/cron de readiness para falhar tambem quando houver warnings como janela sem traces.

## Checks

Ultimos runs:

```sql
select report_date, report_type, engine_version, run_mode, status, trust_score,
       finding_count, recommendation_count, duration_ms, layer_errors
from ai_performance_report_runs
order by started_at desc
limit 20;
```

Verificar replay:

```sql
select id, input_hash, output_hash, schema_version
from ai_performance_report_runs
where output_snapshot is not null
order by started_at desc
limit 10;
```

Recomendacoes abertas:

```sql
select state, kind, target_metric, target_dimension, priority_score, measurement_due_at
from ai_performance_recommendations
where state in ('proposed','acknowledged','in_progress','applied','snoozed')
order by priority_score desc, created_at desc;
```

## Regras de Qualidade

- Relatorio diario compara ontem contra o dia anterior; `--recompute` precisa cobrir 2 dias.
- Relatorio multi compara cada janela contra a janela anterior equivalente; `--recompute` precisa cobrir 2x a maior janela.
- Risco de tools sempre deve respeitar a janela do scorecard.
- Alertas por taxa de tools devem respeitar `tool_*_min_calls`.
- `shadow` deve rodar por alguns dias antes de `next`; comparar `status`, `trust_score`, `finding_count` e `layer_errors`.
- `next` so deve ser ligado depois de `ai_metric_daily_snapshots` ter pelo menos 7 dias uteis de dados ou depois de backfill.
- Antes de ligar `next`, rode `atlas:ai:performance:smoke --strict --json`; falha nesse comando bloqueia rollout.
- `snoozed` e estado valido de recomendacao; se PostgreSQL ja tinha o CHECK antigo, rode as migrations novas para aplicar `2026_05_01_160000_allow_snoozed_recommendation_state`.
