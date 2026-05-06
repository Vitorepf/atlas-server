> Cleanup status: superseded_source_material.
> Canonical replacement: docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md; docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md; docs/engineering-knowledge-base/atlas-ai-operating-system.md.
> Cleanup note: Operational telemetry details remain useful, but architecture authority lives in the canonical KB. Do not treat this standalone doc as Atlas AI mother architecture.

# Atlas AI Telemetry

Esta base mede qualidade, eficiencia e continuidade do Atlas AI em tres superficies:

- app mobile (`atlas_ai_sheet`)
- terminal (`atlas ai`)
- backend/worker (`server`, `worker`, `scheduler`)

O objetivo operacional e responder, com dados:

- a conversa continuou ou se perdeu?
- o usuario teve que reenviar ou trocar de conversa?
- o modelo respondeu com qualidade suficiente na primeira tentativa?
- houve remediacao, rework ou troca de provider?
- qual foi a latencia percebida no app e a latencia real do provider?
- qual foi o custo estimado por provider/model?

## Tabelas

- `ai_telemetry_events`: eventos brutos idempotentes vindos do app, CLI, server e worker.
- `ai_provider_cost_rates`: rates versionados por provider/model, em micro-USD por 1K tokens.
- `ai_outcome_links`: outcomes humanos ou sistemicos ligados a trace/thread/target.
- `ai_trace_metric_summaries`: rollup por trace com scores e sinais agregados.

## Fluxo

1. App/CLI/server gravam eventos brutos com `event_key`, `correlation_id`, `trace_id` quando disponivel e metadados redigidos.
2. Worker registra eventos canonicos da execucao: enqueue, claim, chamada ao provider, primeiro token, sucesso/falha e conclusao.
3. Feedback humano grava `feedback_score`, cria um `ai_outcome_links` canonico e recomputa a summary da trace.
4. O rollup agrega eventos, quality evaluations, actions, contexto, outcomes e custo em `ai_trace_metric_summaries`.
5. Scorecards leem summaries, nao eventos brutos, para manter dashboards baratos e estaveis.

## Comandos

Recomputar janela recente:

```bash
php artisan atlas:ai:telemetry:rollup --hours=72 --json
```

Recomputar uma trace:

```bash
php artisan atlas:ai:telemetry:rollup --trace=<trace-id> --json
```

Avaliar health gate da janela:

```bash
php artisan atlas:ai:telemetry:health --hours=72 --json
```

Recomputar, avaliar e emitir insight operacional no Mobile Inbox quando houver `warning` ou `critical`:

```bash
php artisan atlas:ai:telemetry:health --hours=48 --recompute --emit --json
```

Usar como quality gate em CI/automacao:

```bash
php artisan atlas:ai:telemetry:health --hours=24 --fail-on-critical --json
```

Listar rates ativos:

```bash
php artisan atlas:ai:telemetry:cost-rates --json
```

Listar rates que faltam com base nas summaries recentes:

```bash
php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json
```

Gerar um template JSON dos rates faltantes, sem hardcode de preco:

```bash
php artisan atlas:ai:telemetry:cost-rates \
  --missing \
  --hours=168 \
  --write-template=storage/app/atlas-ai-cost-rates.template.json
```

Importar rates revisados:

```bash
php artisan atlas:ai:telemetry:cost-rates \
  --import=storage/app/atlas-ai-cost-rates.json \
  --json
```

Upsert manual de rate:

```bash
php artisan atlas:ai:telemetry:cost-rates \
  --provider=claude_cli \
  --model=<model-or-runtime-id> \
  --input-microusd=0 \
  --output-microusd=0 \
  --json
```

Sincronizar rates definidos em config/env:

```bash
php artisan atlas:ai:telemetry:cost-rates --sync-config --json
```

## Configuracao de custo

Cost rates sao governados: o Atlas nunca consulta provider nem infere preco sozinho. Um operador humano deve revisar a fonte comercial vigente, preencher explicitamente `input_microusd_per_1k` e `output_microusd_per_1k`, em micro-USD por 1K tokens, e importar/upsertar o rate com uma janela de vigencia auditavel. O comando e as APIs tratam `provider/model obrigatorios`, rejeitam nomes vazios ou maiores que o limite armazenavel, valores negativos, valores fracionarios, valores fora do intervalo unsigned integer, datas invalidas, e `effective_until` anterior a `effective_from`. `currency` usa `USD` por padrao quando omitida ou vazia; valores fornecidos precisam ser codigos string de 3 a 8 letras e sao persistidos no rate para auditoria. Imports em lote e sync de config retornam erros por indice de linha para facilitar revisao humana antes de novo import/sync.

Nao ha preco hardcoded no codigo porque preco de provider/model muda. Use:

```env
ATLAS_AI_COST_RATES_JSON='[
  {
    "provider": "claude_cli",
    "model": "example-model",
    "input_microusd_per_1k": 0,
    "output_microusd_per_1k": 0,
    "effective_from": "2026-05-01T00:00:00Z"
  }
]'
```

Depois rode:

```bash
php artisan atlas:ai:telemetry:cost-rates --sync-config
php artisan atlas:ai:telemetry:rollup --hours=720
```

Quando o CLI usa o modelo default do proprio provider, o Atlas registra uma identidade estavel para telemetria (`claude_cli_default`, `codex_cli_default` ou o valor configurado em `ATLAS_AI_*_MODEL_IDENTITY`). Essa identidade nao e enviada como `--model` para o CLI; ela existe para custo, scorecard e auditoria. Se voce fixa um modelo real com `ATLAS_AI_CLAUDE_MODEL` ou `ATLAS_AI_CODEX_MODEL`, esse valor passa a ser usado tanto na chamada quanto na medicao.

Para providers de CLI, custo e sempre tratado como estimativa operacional:

- `cost_confidence=estimated`
- `cost_mode=operational_estimate`
- `cost_source=cli_token_estimate` quando o Atlas estimou tokens por caracteres
- `cost_source=cli_provider_usage_estimate` quando o CLI/provider retornou uso de tokens, mas o custo financeiro ainda depende de uma tabela de referencia

O Atlas nao interpreta esses valores como cobranca real. O health gate so marca custo como problema quando `cost_confidence=unknown`, ou seja, quando nao existe identidade/rate suficiente para produzir nem a estimativa.

## APIs

Todas as rotas abaixo usam `atlas.token`, exceto a rota mobile de eventos, que usa bearer do device.

- `POST /v1/mobile/telemetry/events`
- `POST /ai/telemetry/events`
- `GET /ai/telemetry/scorecard?hours=24&recompute=1`
- `GET /ai/telemetry/health?hours=24&recompute=1`
- `GET /ai/telemetry/summaries`
- `GET /ai/telemetry/cost-rates/missing?hours=168`
- `GET /ai/telemetry/cost-rates`
- `POST /ai/telemetry/cost-rates/import`
- `POST /ai/telemetry/cost-rates`
- `GET /ai/telemetry/outcomes`
- `POST /ai/telemetry/outcomes`

## Outcomes canonicos

Outcomes positivos:

- `human_marked_useful`
- `conversation_continued`
- `task_created`
- `task_completed`
- `project_created`
- `project_plan_accepted`
- `blocker_resolved`
- `routine_created`
- `decision_recorded`

Outcomes negativos ou de risco:

- `human_marked_not_useful`
- `human_marked_wrong_context`
- `human_marked_too_slow`
- `human_marked_too_expensive`
- `human_marked_unsafe`
- `human_dismissed`
- `user_reasked_same_intent`
- `user_abandoned_thread`
- `provider_switched_after_bad_answer`

## Health Gate

O health gate avalia o scorecard e classifica a janela como:

- `healthy`: sem sinais fora do threshold.
- `watch`: amostra insuficiente; nao agir ainda.
- `warning`: existe degradacao acionavel.
- `critical`: existe risco operacional que precisa entrar na fila de revisao.

Sinais avaliados:

- qualidade media final
- eficiencia media final
- taxa de sucesso de primeira passagem
- taxa de remediacao
- proporcao de custo desconhecido; custo estimado de CLI nao conta como desconhecido
- latencia media percebida no app
- taxa de traces lentas
- taxa de traces com baixa qualidade

Thresholds principais ficam em env vars `ATLAS_AI_HEALTH_*`. O comando agendado roda `atlas:ai:telemetry:health --hours=48 --emit` de hora em hora; ele emite insight no inbox, mas nao falha o scheduler. Para CI, use `--fail-on-critical`.

Quando a proporcao de custo desconhecido passa do threshold, o health gate inclui `evidence.missing_cost_rates` com os pares `provider/model` que precisam de rate ativo ou com a razao de bloqueio (`missing_model_identity`, `missing_provider_identity`). O app mobile mostra esses pares no painel de operacao, e o terminal consegue gerar um template importavel pelo comando `cost-rates` quando o par ja e configuravel.

## Garantias de implementacao

- Telemetria nao deve bloquear o fluxo de conversa.
- Eventos sao idempotentes por `event_key`.
- O app persiste outbox local e tenta flush em background.
- O CLI faz spool local quando a tabela ainda nao existe.
- Metadata passa por redacao antes de persistir.
- Summaries agregam por `trace_id` e `correlation_id`, nao por `client_id` amplo, para evitar misturar mensagens diferentes do mesmo aparelho.
