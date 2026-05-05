# Atlas AI Telemetry, Quality And Efficiency - Implementacao Core

**Status:** especificacao operacional para implementacao  
**Escopo:** `atlas-server`, `atlas-app`, Atlas CLI, workers, providers, evals, dashboards e self-diagnostic  
**Prioridade:** core do Atlas AI  
**Principio central:** o Atlas so pode evoluir se medir, de forma auditavel, se foi util, rapido, barato, continuo e confiavel.

Este documento define a implementacao profissional para medir eficiencia e qualidade do Atlas AI no app mobile, terminal e backend. A implementacao deve seguir as fases em ordem. Nao considerar uma fase pronta sem migracoes, services, endpoints, instrumentacao, testes e smoke documentado.

## 1. Problema

Hoje o Atlas ja mede partes importantes:

- traces, jobs, attempts, fila, status e latencia backend;
- health de provider;
- avaliacoes heuristicas de qualidade;
- acoes corretivas;
- feedback humano simples;
- compactacoes, snapshots, estado de sessao e handoffs;
- alguns self-diagnostics de regressao.

Mas ainda faltam metricas decisivas:

- custo real por resposta;
- tokens reais por provider/modelo;
- latencia fim a fim percebida no app;
- eficiencia real de contexto;
- score positivo de continuidade;
- resultado depois da resposta;
- taxa de retrabalho;
- comparacao A/B entre providers;
- benchmarks fixos/golden tests;
- efetividade das remediacoes;
- scorecards consistentes para app, CLI e backend.

Sem isso o Atlas pode parecer funcional enquanto degrada em custo, continuidade, qualidade ou utilidade real. Esta camada deve virar base do produto.

## 2. Resultado Esperado

Ao final da implementacao, o Atlas consegue responder objetivamente:

1. Quanto custou cada resposta?
2. Quanto tempo o usuario esperou no app e no terminal?
3. Qual provider/modelo foi mais eficiente por tipo de tarefa?
4. A resposta foi util para o usuario?
5. A resposta gerou resultado concreto?
6. A conversa preservou continuidade?
7. O contexto enviado foi suficiente ou excessivo?
8. O Atlas precisou de remediacao?
9. A remediacao melhorou a resposta?
10. Uma mudanca de prompt/contexto/provider melhorou ou piorou regressao?

## 3. Decisoes Arquiteturais Nao Negociaveis

- [ ] Telemetria bruta e append-only. Eventos nao devem ser reescritos; resumos podem ser recomputados.
- [ ] Todo evento mutante precisa de idempotencia por `event_key`.
- [ ] App mobile e CLI devem usar o mesmo vocabulario de eventos.
- [ ] Nenhuma metrica sensivel deve ir para push notification.
- [ ] Metadata deve passar por redacao antes de persistir payload rico.
- [ ] Scores agregados sempre guardam seus componentes. Nunca salvar apenas um score final opaco.
- [ ] Todo trace precisa poder ser explicado: tempo, custo, provider, contexto, qualidade, feedback e outcome.
- [ ] O sistema deve funcionar offline no app e no CLI com outbox local.
- [ ] Clock do cliente nunca e fonte unica de verdade. Usar `occurred_at_client` e `received_at`.
- [ ] Falha de telemetria nao pode impedir resposta de IA. Telemetria deve ser best-effort com retry.
- [ ] Dados antigos devem ser backfilled a partir das tabelas atuais.

## 4. Glossario

| Termo | Significado |
|---|---|
| Telemetry Event | Evento atomico, append-only, emitido por app, CLI, server, worker ou provider. |
| Correlation ID | ID que liga eventos de uma mesma operacao fim a fim, mesmo antes de existir `trace_id`. |
| Client ID | ID idempotente da submissao iniciada pelo cliente. Ja e usado para evitar duplicidade de envio. |
| Trace Summary | Resumo computado de performance, custo, qualidade e outcome de um `ai_trace`. |
| Outcome Link | Relacao entre uma resposta do Atlas e um resultado concreto, como tarefa criada ou bloqueador resolvido. |
| Quality Score | Score final de qualidade/utilidade, composto por qualidade automatica, feedback, continuidade e outcome. |
| Efficiency Score | Score final de eficiencia, composto por custo, latencia, first-pass success e eficiencia de contexto. |
| Golden Eval | Caso fixo de avaliacao com input, contexto, expectativa, criterios de falha e score. |

## 5. Arquitetura Alvo

```text
Atlas App / Atlas CLI / Server / Worker / Provider
        |
        v
AiTelemetryClient / AiTelemetryCollector
        |
        v
ai_telemetry_events  append-only
        |
        v
AiTraceMetricAggregator
        |
        v
ai_trace_metric_summaries
        |
        +--> AiOutcomeAttributionService
        +--> AiContinuityScorer
        +--> AiContextEfficiencyScorer
        +--> AiCostEstimator
        +--> AiEvalRunner
        +--> AiProviderComparisonService
        |
        v
Scorecards / Operations dashboard / CLI dashboard / Self-diagnostic / Routing decisions
```

## 6. Modelo De Dados

### 6.1 `ai_telemetry_events`

Fonte bruta de verdade para todos os eventos.

```sql
CREATE TABLE ai_telemetry_events (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  event_key TEXT NOT NULL UNIQUE,
  correlation_id UUID,
  trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
  thread_id UUID REFERENCES ai_threads(id) ON DELETE SET NULL,
  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
  ai_job_id UUID REFERENCES ai_jobs(id) ON DELETE SET NULL,
  ai_job_attempt_id UUID REFERENCES ai_job_attempts(id) ON DELETE SET NULL,
  client_id UUID,
  surface TEXT NOT NULL CHECK (surface IN (
    'mobile',
    'cli',
    'server',
    'worker',
    'scheduler',
    'eval'
  )),
  runtime TEXT CHECK (runtime IS NULL OR runtime IN (
    'ios',
    'android',
    'mac_cli',
    'laravel',
    'worker',
    'scheduler',
    'test'
  )),
  app_version TEXT,
  cli_version TEXT,
  provider TEXT,
  model TEXT,
  agent_slug TEXT,
  event_name TEXT NOT NULL,
  event_phase TEXT,
  occurred_at_client TIMESTAMPTZ,
  received_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  duration_ms INTEGER CHECK (duration_ms IS NULL OR duration_ms >= 0),
  numeric_value NUMERIC,
  unit TEXT,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  privacy JSONB NOT NULL DEFAULT '{}'::jsonb,
  schema_version SMALLINT NOT NULL DEFAULT 1,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ai_telemetry_trace_received
  ON ai_telemetry_events(trace_id, received_at DESC);

CREATE INDEX idx_ai_telemetry_correlation_received
  ON ai_telemetry_events(correlation_id, received_at DESC);

CREATE INDEX idx_ai_telemetry_surface_event_received
  ON ai_telemetry_events(surface, event_name, received_at DESC);

CREATE INDEX idx_ai_telemetry_provider_received
  ON ai_telemetry_events(provider, received_at DESC);

CREATE INDEX idx_ai_telemetry_metadata
  ON ai_telemetry_events USING GIN(metadata);
```

Regras:

- `event_key` deve ser deterministico quando o cliente puder repetir o envio.
- `correlation_id` nasce no app/CLI antes de existir trace.
- `client_id` liga telemetria ao envio idempotente da mensagem.
- `received_at` e horario confiavel para ordering server-side.
- `occurred_at_client` serve para latencia percebida, com correcao de skew.

### 6.2 `ai_trace_metric_summaries`

Resumo computado por trace. Pode ser recalculado.

```sql
CREATE TABLE ai_trace_metric_summaries (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  trace_id UUID NOT NULL UNIQUE REFERENCES ai_traces(id) ON DELETE CASCADE,
  thread_id UUID REFERENCES ai_threads(id) ON DELETE SET NULL,
  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
  client_id UUID,
  surface TEXT NOT NULL,
  runtime TEXT,
  provider TEXT,
  model TEXT,
  agent_slug TEXT,
  task_type TEXT,
  status TEXT NOT NULL,

  app_send_to_accept_ms INTEGER,
  app_send_to_visible_ms INTEGER,
  queue_wait_ms INTEGER,
  first_token_ms INTEGER,
  provider_latency_ms INTEGER,
  total_latency_ms INTEGER,
  backgrounded_during_run BOOLEAN NOT NULL DEFAULT FALSE,
  recovered_from_pending BOOLEAN NOT NULL DEFAULT FALSE,

  prompt_tokens INTEGER,
  completion_tokens INTEGER,
  total_tokens INTEGER,
  estimated_tokens INTEGER,
  token_source TEXT,
  cost_microusd INTEGER,
  cost_confidence TEXT NOT NULL DEFAULT 'unknown',

  context_tokens INTEGER,
  context_refs_count INTEGER,
  useful_context_refs_count INTEGER,
  compaction_used BOOLEAN NOT NULL DEFAULT FALSE,
  provider_handoff_used BOOLEAN NOT NULL DEFAULT FALSE,
  context_efficiency_score SMALLINT,

  auto_quality_score SMALLINT,
  continuity_score SMALLINT,
  human_feedback_score SMALLINT,
  outcome_score SMALLINT,
  remediation_score SMALLINT,
  final_quality_score SMALLINT,
  final_efficiency_score SMALLINT,

  first_pass_success BOOLEAN,
  needed_remediation BOOLEAN NOT NULL DEFAULT FALSE,
  remediation_count INTEGER NOT NULL DEFAULT 0,
  reask_detected BOOLEAN NOT NULL DEFAULT FALSE,
  provider_switched_after_response BOOLEAN NOT NULL DEFAULT FALSE,

  score_components JSONB NOT NULL DEFAULT '{}'::jsonb,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  computed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ai_trace_metric_surface_computed
  ON ai_trace_metric_summaries(surface, computed_at DESC);

CREATE INDEX idx_ai_trace_metric_provider_computed
  ON ai_trace_metric_summaries(provider, computed_at DESC);

CREATE INDEX idx_ai_trace_metric_quality
  ON ai_trace_metric_summaries(final_quality_score, computed_at DESC);

CREATE INDEX idx_ai_trace_metric_efficiency
  ON ai_trace_metric_summaries(final_efficiency_score, computed_at DESC);
```

### 6.3 `ai_provider_cost_rates`

Tabela versionada de custo por provider/modelo.

```sql
CREATE TABLE ai_provider_cost_rates (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  provider TEXT NOT NULL,
  model TEXT NOT NULL,
  input_microusd_per_1k INTEGER NOT NULL,
  output_microusd_per_1k INTEGER NOT NULL,
  currency TEXT NOT NULL DEFAULT 'USD',
  effective_from TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  effective_until TIMESTAMPTZ,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ai_provider_cost_rates_lookup
  ON ai_provider_cost_rates(provider, model, effective_from DESC);
```

Regras:

- Guardar custo em micro USD para evitar decimal quebrado.
- Quando provider CLI nao expuser tokens reais, usar estimativa e marcar `cost_confidence='estimated'`.
- Quando houver tokens reais, `cost_confidence='actual'`.

### 6.4 `ai_outcome_links`

Liga resposta de IA a resultado real no produto.

```sql
CREATE TABLE ai_outcome_links (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
  thread_id UUID REFERENCES ai_threads(id) ON DELETE SET NULL,
  session_id UUID REFERENCES ai_sessions(id) ON DELETE SET NULL,
  outcome_type TEXT NOT NULL,
  target_type TEXT,
  target_id UUID,
  value_score SMALLINT CHECK (value_score IS NULL OR value_score BETWEEN 0 AND 100),
  confidence NUMERIC(4,3),
  source TEXT NOT NULL DEFAULT 'system',
  occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_ai_outcome_trace
  ON ai_outcome_links(trace_id, occurred_at DESC);

CREATE INDEX idx_ai_outcome_type
  ON ai_outcome_links(outcome_type, occurred_at DESC);
```

Tipos obrigatorios:

| `outcome_type` | Quando emitir |
|---|---|
| `task_created` | Resposta gerou tarefa. |
| `task_completed` | Usuario completou tarefa ligada a resposta. |
| `project_created` | Resposta gerou projeto. |
| `project_plan_accepted` | Plano/proposta foi aceito. |
| `blocker_resolved` | Bloqueador foi resolvido com ajuda da resposta. |
| `routine_created` | Rotina criada a partir de IA. |
| `decision_recorded` | Decisao foi registrada no estado/projeto. |
| `conversation_continued` | Usuario continuou a mesma thread depois da resposta. |
| `user_reasked_same_intent` | Usuario repetiu pedido por baixa utilidade/continuidade. |
| `user_abandoned_thread` | Thread ficou abandonada apos resposta ativa. |
| `provider_switched_after_bad_answer` | Usuario trocou provider apos resposta ruim. |

### 6.5 Evals

```sql
CREATE TABLE ai_eval_suites (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  slug TEXT NOT NULL UNIQUE,
  title TEXT NOT NULL,
  description TEXT,
  status TEXT NOT NULL DEFAULT 'active',
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE ai_eval_cases (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  suite_id UUID NOT NULL REFERENCES ai_eval_suites(id) ON DELETE CASCADE,
  slug TEXT NOT NULL,
  task_type TEXT,
  input_text TEXT NOT NULL,
  context_fixture JSONB NOT NULL DEFAULT '{}'::jsonb,
  expected_behavior JSONB NOT NULL DEFAULT '{}'::jsonb,
  fail_if JSONB NOT NULL DEFAULT '[]'::jsonb,
  scoring_rubric JSONB NOT NULL DEFAULT '{}'::jsonb,
  weight INTEGER NOT NULL DEFAULT 1,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE(suite_id, slug)
);

CREATE TABLE ai_eval_runs (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  suite_id UUID NOT NULL REFERENCES ai_eval_suites(id) ON DELETE CASCADE,
  provider TEXT,
  model TEXT,
  agent_slug TEXT,
  prompt_version TEXT,
  context_version TEXT,
  git_head TEXT,
  status TEXT NOT NULL DEFAULT 'running',
  average_score NUMERIC(5,2),
  passed_count INTEGER NOT NULL DEFAULT 0,
  failed_count INTEGER NOT NULL DEFAULT 0,
  started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  finished_at TIMESTAMPTZ,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE ai_eval_results (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  run_id UUID NOT NULL REFERENCES ai_eval_runs(id) ON DELETE CASCADE,
  case_id UUID NOT NULL REFERENCES ai_eval_cases(id) ON DELETE CASCADE,
  trace_id UUID REFERENCES ai_traces(id) ON DELETE SET NULL,
  score SMALLINT CHECK (score BETWEEN 0 AND 100),
  status TEXT NOT NULL,
  judge_version TEXT,
  failures JSONB NOT NULL DEFAULT '[]'::jsonb,
  metrics JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE(run_id, case_id)
);
```

Casos obrigatorios do suite inicial `atlas-ai-core-v1`:

- continuidade entre sessoes;
- mensagem enviada e app fechado imediatamente;
- troca de thread enquanto modelo roda;
- resposta curta executiva;
- debug tecnico com validacao;
- nao vazar `context_pack`, `trace_id`, `thread_id` ou prompt interno;
- nao responder como Claude, Codex ou ChatGPT;
- provider handoff com resumo preservado;
- compactacao preserva decisao e proximo passo;
- criacao de tarefa a partir de conversa;
- resolucao de bloqueador;
- remediacao de `lost_continuity`.

## 7. Eventos Canonicos

### 7.1 Ciclo mobile de mensagem

| Evento | Origem | Obrigatorio | Observacao |
|---|---|---|---|
| `atlas_ai_sheet_opened` | app | sim | Mede entrada no fluxo. |
| `thread_hydration_started` | app | sim | Antes de listar threads/traces. |
| `thread_hydration_succeeded` | app | sim | Inclui contagem de threads/traces. |
| `thread_hydration_failed` | app | sim | Nao deve apagar thread atual. |
| `message_send_pressed` | app | sim | Antes de persistir pending submission. |
| `pending_submission_stored` | app | sim | Garante sobrevivencia ao app fechar. |
| `interaction_request_started` | app | sim | Antes do POST. |
| `interaction_accepted` | app/server | sim | Trace criado/retornado. |
| `trace_visible_in_ui` | app | sim | Primeira vez que trace aparece no app. |
| `first_response_content_visible` | app | sim | Primeiro token/texto visivel. |
| `response_final_visible` | app | sim | Resposta final renderizada. |
| `app_backgrounded_during_trace` | app | sim | Quando sair do app/tela com trace ativo. |
| `pending_submission_recovered` | app | sim | Recuperacao apos retorno. |
| `thread_switched_while_trace_active` | app | sim | UX liberada sem misturar estado. |

### 7.2 Ciclo server/worker/provider

| Evento | Origem | Obrigatorio |
|---|---|---|
| `trace_created` | server | sim |
| `job_enqueued` | server | sim |
| `job_claimed` | worker | sim |
| `provider_call_started` | worker/provider | sim |
| `provider_first_token` | worker/provider | quando disponivel |
| `provider_call_succeeded` | worker/provider | sim |
| `provider_call_failed` | worker/provider | sim |
| `job_requeued` | worker | sim |
| `job_cancelled` | server/worker | sim |
| `trace_completed` | server/worker | sim |
| `quality_evaluated` | server | sim |
| `quality_action_planned` | server | sim |
| `quality_action_started` | server | sim |
| `quality_action_completed` | server | sim |

### 7.3 Ciclo CLI

| Evento | Origem | Obrigatorio |
|---|---|---|
| `cli_command_started` | CLI | sim |
| `cli_command_completed` | CLI | sim |
| `cli_context_profiled` | CLI | sim para comandos AI |
| `cli_provider_strategy_selected` | CLI/server | sim quando houver provider |
| `cli_tool_started` | CLI/runtime | sim |
| `cli_tool_completed` | CLI/runtime | sim |
| `cli_quality_gate_started` | CLI | sim quando aplicavel |
| `cli_quality_gate_completed` | CLI | sim quando aplicavel |

### 7.4 Outcomes

| Evento | Origem |
|---|---|
| `outcome_task_created` | server |
| `outcome_task_completed` | server |
| `outcome_project_created` | server |
| `outcome_project_plan_accepted` | server |
| `outcome_blocker_resolved` | server |
| `outcome_decision_recorded` | server |
| `outcome_conversation_continued` | server/app |
| `outcome_reask_detected` | server |
| `outcome_thread_abandoned` | scheduled rollup |

## 8. Services

### 8.1 `AiTelemetryCollector`

Responsavel por validar, redigir, deduplicar e persistir eventos.

Contrato:

```php
final class AiTelemetryCollector
{
    public function record(array $event): AiTelemetryEvent;

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array{accepted:int,duplicates:int,rejected:int,errors:array<int,array<string,mixed>>}
     */
    public function recordBatch(array $events): array;
}
```

Regras:

- aceitar eventos duplicados sem erro;
- recusar `event_name` desconhecido apenas se `strict=true`;
- truncar payloads grandes;
- redigir secrets, tokens, paths sensiveis e prompt bruto quando necessario;
- emitir audit event apenas para rejeicoes criticas, nao para todo evento.

### 8.2 `AiTraceMetricAggregator`

Calcula `ai_trace_metric_summaries`.

Entradas:

- `ai_traces`;
- `ai_jobs`;
- `ai_job_attempts`;
- `ai_quality_evaluations`;
- `ai_quality_actions`;
- `ai_context_snapshots`;
- `ai_compactions`;
- `ai_provider_handoffs`;
- `ai_telemetry_events`;
- `ai_outcome_links`.

Quando rodar:

- ao finalizar trace;
- ao registrar feedback;
- ao criar outcome;
- ao concluir acao corretiva;
- via comando de backfill/recompute.

Contrato:

```php
final class AiTraceMetricAggregator
{
    public function recomputeTrace(string $traceId): AiTraceMetricSummary;

    public function recomputeWindow(CarbonInterface $since, ?CarbonInterface $until = null): int;
}
```

### 8.3 `AiCostEstimator`

Responsavel por tokens e custo.

Regras:

- preferir token usage real do provider;
- se ausente, estimar por texto/prompt/contexto;
- guardar fonte em `token_source`;
- custo deve respeitar data efetiva da tabela de rates;
- custo desconhecido nunca deve virar zero silencioso.

### 8.4 `AiContinuityScorer`

Score 0-100 para continuidade.

Sinais positivos:

- mesmo `thread_id` preservado;
- `session_state` usado;
- compactacao usada quando thread longa;
- provider handoff usado quando houve troca;
- resposta referencia decisoes/proximos passos anteriores sem expor contexto interno;
- usuario continua mesma thread depois da resposta.

Sinais negativos:

- flag `lost_continuity`;
- feedback `wrong_context`;
- resposta diz que nao ha contexto quando havia contexto;
- nova thread criada logo apos pergunta similar;
- provider switch apos resposta ruim;
- remediacao `retry_with_continuity`.

### 8.5 `AiContextEfficiencyScorer`

Score 0-100 para eficiencia de contexto.

Componentes:

- qualidade final;
- tokens de contexto;
- quantidade de mensagens incluidas;
- uso de compactacao;
- refs realmente usados;
- ausencia de vazamento de contexto;
- ausencia de perda de continuidade.

Formula inicial:

```text
context_efficiency_score =
  quality_component
  + continuity_component
  + compactness_component
  + relevance_component
  - leak_penalty
```

Guardar detalhes em `score_components.context_efficiency`.

### 8.6 `AiOutcomeAttributionService`

Cria `ai_outcome_links` quando uma acao do produto puder ser atribuida a uma resposta.

Regras:

- se a acao vier diretamente de uma thread/trace, `confidence >= 0.85`;
- se for inferida por proximidade temporal, `confidence <= 0.65`;
- outcomes inferidos devem ser revisaveis;
- nao criar outcome duplicado para mesmo `trace_id + outcome_type + target_id`.

### 8.7 `AiScorecardService`

Calcula scorecards por janela.

Dimensoes:

- provider;
- model;
- agent;
- surface;
- task type;
- workspace;
- app version;
- CLI version.

Saidas:

```text
quality_average
efficiency_average
cost_total
cost_per_useful_response
latency_p50
latency_p95
success_rate
first_pass_success_rate
remediation_rate
wrong_context_rate
reask_rate
outcome_rate
```

## 9. Scores Canonicos

### 9.1 Final Quality Score

```text
final_quality_score =
  0.30 * auto_quality_score
  0.25 * outcome_score
  0.20 * continuity_score
  0.15 * human_feedback_score
  0.10 * remediation_score
```

Fallbacks:

- se nao houver feedback humano, redistribuir peso entre auto quality, outcome e continuity;
- se nao houver outcome ainda, marcar `score_components.outcome.status='pending'`;
- nunca tratar ausencia de outcome imediato como falha instantanea.

### 9.2 Final Efficiency Score

```text
final_efficiency_score =
  0.30 * first_pass_success_component
  0.25 * latency_component
  0.20 * cost_component
  0.15 * context_efficiency_score
  0.10 * reliability_component
```

Componentes:

- `first_pass_success_component`: alto quando nao precisou remediacao, retry ou reask;
- `latency_component`: baseado em p50/p95 por superficie e tipo de tarefa;
- `cost_component`: custo relativo ao valor/outcome;
- `reliability_component`: sem duplicata, sem pending perdido, sem falha de polling.

### 9.3 Remediation Score

```text
remediation_score =
  100 se nao precisou remediacao
  80 se remediacao melhorou e foi aceita
  55 se remediacao melhorou mas custou caro/lenta
  25 se remediacao nao melhorou
  0 se entrou em loop ou falhou
```

## 10. Endpoints

### 10.1 Server API

```text
POST /api/ai/telemetry/events
GET  /api/ai/metrics/traces/{trace}
POST /api/ai/metrics/traces/{trace}/recompute
GET  /api/ai/metrics/scorecards
GET  /api/ai/metrics/providers
GET  /api/ai/evals/suites
POST /api/ai/evals/runs
GET  /api/ai/evals/runs/{run}
```

### 10.2 Mobile Gateway

```text
POST /v1/mobile/telemetry/events
GET  /v1/mobile/ai/scorecard
```

O endpoint mobile deve usar bearer do device. O endpoint server deve usar auth atual do Atlas.

### 10.3 CLI

Comandos novos:

```text
atlas metrics
atlas metrics trace <trace-id>
atlas metrics providers
atlas metrics recompute --since=24h
atlas eval run <suite>
atlas eval compare <run-a> <run-b>
atlas telemetry flush
atlas telemetry tail
```

## 11. Instrumentacao Mobile

Criar `atlas-app/lib/atlasAiTelemetry.ts`.

Responsabilidades:

- gerar `correlation_id`;
- gerar `event_key`;
- armazenar eventos offline;
- enviar batch quando app estiver ativo;
- retry com backoff;
- limite de tamanho da fila;
- redacao minima client-side;
- nunca bloquear UX.

API sugerida:

```ts
export function startAtlasAiTelemetrySession(input: {
  surface: 'mobile'
  threadId?: string | null
  clientId?: string | null
}): { correlationId: string }

export async function recordAtlasAiEvent(input: {
  eventName: string
  correlationId?: string
  traceId?: string | null
  threadId?: string | null
  clientId?: string | null
  durationMs?: number
  numericValue?: number
  unit?: string
  metadata?: Record<string, unknown>
}): Promise<void>

export async function flushAtlasAiTelemetry(): Promise<void>
```

Pontos obrigatorios em `AtlasAiSheet.tsx`:

- abrir sheet;
- hidratar thread;
- falha ao listar threads;
- selecionar thread;
- iniciar nova thread;
- tocar enviar;
- salvar pending submission;
- POST de interacao iniciado;
- POST aceito;
- trace visivel;
- resposta final visivel;
- app background/foreground com trace ativo;
- pending submission recuperada;
- troca de thread enquanto trace ativo;
- feedback registrado;
- quality action executada.

Checklist mobile:

- [ ] Eventos continuam sendo enviados apos fechar e reabrir app.
- [ ] Duplicatas nao criam metricas duplicadas.
- [ ] `client_id` liga pending submission, trace e eventos.
- [ ] Telemetria falhando nao quebra envio de mensagem.
- [ ] Teste cobre envio e background imediato.

## 12. Instrumentacao CLI

Criar service no server:

```php
App\Services\Ai\Cli\AtlasCliTelemetry
```

Responsabilidades:

- abrir run context para cada comando;
- gravar evento local se server/db indisponivel;
- flush de arquivos JSONL;
- anexar workspace, branch, head, dirty count e command;
- correlacionar comando com trace/job quando houver IA.

Spool local:

```text
storage/app/atlas-cli/telemetry-outbox/*.jsonl
```

Eventos obrigatorios:

- command start/end;
- provider strategy;
- prompt/context profile;
- provider call;
- tool start/end;
- test run;
- git diff/status;
- quality gate;
- eval run;
- error/interrupt/cancel.

Checklist CLI:

- [ ] Todo comando principal emite start/end.
- [ ] Exit code sempre e capturado.
- [ ] Interrupcao/Ctrl-C emite evento de cancelamento.
- [ ] Spool local e flush idempotente.
- [ ] `atlas metrics` mostra scorecard sem depender do app.

## 13. Instrumentacao Server/Worker

Pontos obrigatorios:

- `AiGatewayService::enqueueInteraction`;
- criacao/atualizacao de `AiTrace`;
- criacao/atualizacao de `AiJob`;
- claim de job no worker;
- inicio/fim/falha de provider call;
- sync de council;
- qualidade avaliada;
- quality action planejada/executada;
- feedback de trace;
- compactacao;
- provider handoff;
- snapshots de contexto;
- criacao de task/project/blocker/routine a partir de IA.

Regra:

- Worker events existentes continuam existindo.
- Telemetry events complementam worker events com vocabulario unico e correlacao cross-surface.

## 14. Backfill

Criar comando:

```text
php artisan atlas:ai:metrics:backfill --since=30d
```

O comando deve:

1. Ler `ai_traces` existentes.
2. Recriar summaries usando jobs, attempts, quality, actions e snapshots existentes.
3. Criar eventos sinteticos apenas quando util, com `metadata.synthetic=true`.
4. Nao sobrescrever telemetria real.
5. Ser seguro para rodar varias vezes.

## 15. Dashboards

### 15.1 App mobile

Adicionar aba ou secao em Operacao Atlas AI:

- qualidade media;
- eficiencia media;
- custo 24h/7d;
- latencia p50/p95;
- taxa de first-pass success;
- taxa de remediacao;
- taxa de continuidade ruim;
- recoveries de pending submission;
- score por provider;
- score por agente;
- ultimas regressions.

### 15.2 CLI

`atlas metrics` deve mostrar:

```text
Atlas AI metrics - 24h
Quality: 86/100
Efficiency: 78/100
Cost: $0.42
Latency p50/p95: 4.2s / 18.9s
First-pass success: 81%
Remediation rate: 9%
Wrong-context rate: 2%
Best provider debug: codex_cli
Best provider executive: claude_cli
Open regressions: 1
```

### 15.3 Server observability

Expandir `/api/ai/observability` sem quebrar contrato atual:

- manter campos existentes;
- adicionar `metrics`;
- adicionar `scorecards`;
- adicionar `cost`;
- adicionar `latency`;
- adicionar `reliability`.

## 16. Alertas E Self-Diagnostic

O `SelfDiagnosticEmitter` deve passar a considerar:

- queda de `final_quality_score`;
- queda de `final_efficiency_score`;
- aumento de custo por resposta util;
- aumento de `wrong_context_rate`;
- aumento de `reask_rate`;
- queda de first-pass success;
- aumento de pending submissions nao recuperadas;
- provider com custo/latencia/falha acima do baseline.

Regras:

- Alertas precisam de amostra minima.
- Alertas precisam de baseline.
- Alertas precisam de dedupe.
- Alertas precisam de proposta acionavel.

## 17. Ordem De Implementacao

### Fase 0 - Preparacao

Objetivo: travar vocabulario e evitar implementacao divergente.

Checklist:

- [ ] Aprovar este documento como fonte de verdade.
- [ ] Adicionar link no glossario.
- [ ] Criar enum/lista canonica de `event_name`.
- [ ] Definir limites de payload e redacao.
- [ ] Definir config inicial em `config/atlas.php`.
- [ ] Definir suite inicial `atlas-ai-core-v1`.

Pronto quando:

- doc linkado;
- nomes canonicos definidos;
- nenhuma implementacao com nome paralelo.

### Fase 1 - Telemetria Bruta

Objetivo: capturar eventos de app, CLI e server com idempotencia.

Backend:

- [ ] Migration `ai_telemetry_events`.
- [ ] Model `AiTelemetryEvent`.
- [ ] Service `AiTelemetryCollector`.
- [ ] Controller `AiTelemetryController`.
- [ ] Rotas server e mobile.
- [ ] Redacao via suporte comum.
- [ ] Testes de batch, duplicata, payload invalido e auth.

App:

- [ ] `atlasAiTelemetry.ts`.
- [ ] Fila offline AsyncStorage.
- [ ] Flush em foreground.
- [ ] Flush em eventos importantes.

CLI:

- [ ] `AtlasCliTelemetry`.
- [ ] Spool JSONL.
- [ ] `atlas telemetry flush`.

Pronto quando:

- app, CLI e server conseguem gravar evento igual;
- duplicatas sao aceitas sem duplicar;
- telemetria offline e reenviada.

### Fase 2 - Instrumentar Fluxo Atual

Objetivo: cobrir o ciclo completo de mensagem.

Mobile:

- [ ] Sheet opened.
- [ ] Hydration start/success/fail.
- [ ] Send pressed.
- [ ] Pending stored.
- [ ] Request started/accepted.
- [ ] Trace visible.
- [ ] Response final visible.
- [ ] App background/foreground.
- [ ] Pending recovered.
- [ ] Thread switched while running.

Server/worker:

- [ ] Trace created.
- [ ] Job enqueued/claimed.
- [ ] Provider call started/succeeded/failed.
- [ ] Trace completed/failed/cancelled.
- [ ] Quality evaluated.
- [ ] Quality action planned/run.

CLI:

- [ ] Command started/completed.
- [ ] Provider selected.
- [ ] Tool/test/git events.
- [ ] Interrupt/cancel.

Pronto quando:

- uma mensagem enviada no app gera timeline completa;
- um comando CLI com IA gera timeline completa;
- uma falha de provider aparece conectada ao trace/job.

### Fase 3 - Summaries, Latencia E Custo

Objetivo: transformar evento bruto em metricas por trace.

Backend:

- [ ] Migration `ai_trace_metric_summaries`.
- [ ] Migration `ai_provider_cost_rates`.
- [ ] `AiCostEstimator`.
- [ ] `AiTraceMetricAggregator`.
- [ ] Endpoint de recompute por trace.
- [ ] Backfill a partir de dados existentes.

Metricas minimas:

- [ ] total latency;
- [ ] queue wait;
- [ ] provider latency;
- [ ] first token quando disponivel;
- [ ] app send to visible;
- [ ] tokens reais/estimados;
- [ ] custo real/estimado;
- [ ] first-pass success;
- [ ] remediation count.

Pronto quando:

- todo trace finalizado tem summary;
- custo desconhecido aparece como desconhecido, nao zero;
- dashboard consegue usar summary sem recalcular tudo.

### Fase 4 - Continuidade, Contexto E Outcome

Objetivo: medir utilidade real, nao apenas performance.

Backend:

- [ ] Migration `ai_outcome_links`.
- [ ] `AiOutcomeAttributionService`.
- [ ] `AiContinuityScorer`.
- [ ] `AiContextEfficiencyScorer`.
- [ ] Integrar task/project/blocker/routine creation.
- [ ] Detectar reask basico.
- [ ] Detectar provider switch apos resposta ruim.

Scores:

- [ ] `continuity_score`.
- [ ] `context_efficiency_score`.
- [ ] `outcome_score`.
- [ ] `final_quality_score`.
- [ ] `final_efficiency_score`.

Pronto quando:

- uma resposta que cria tarefa gera outcome;
- uma resposta com continuidade ruim reduz score;
- uma resposta cara e pouco util reduz eficiencia.

### Fase 5 - Dashboard App E CLI

Objetivo: tornar as metricas visiveis e operaveis.

App:

- [ ] Expandir Operacao Atlas AI.
- [ ] Mostrar score qualidade/eficiencia.
- [ ] Mostrar custo, latencia p50/p95.
- [ ] Mostrar confiabilidade mobile.
- [ ] Mostrar provider/agent scorecard.

CLI:

- [ ] `atlas metrics`.
- [ ] `atlas metrics trace`.
- [ ] `atlas metrics providers`.
- [ ] `atlas metrics recompute`.

Server:

- [ ] Expandir `/api/ai/observability`.

Pronto quando:

- app e CLI respondem as mesmas perguntas com a mesma fonte;
- dashboard mostra diagnostico, nao so numeros soltos.

### Fase 6 - Evals E Golden Tests

Objetivo: impedir regressao de prompt/contexto/provider.

Backend:

- [ ] Migrations de evals.
- [ ] Seeder `atlas-ai-core-v1`.
- [ ] `AiEvalRunner`.
- [ ] `AiEvalJudge`.
- [ ] CLI `atlas eval run`.
- [ ] CLI `atlas eval compare`.

Casos obrigatorios:

- [ ] continuidade entre sessoes;
- [ ] app fechado apos enviar;
- [ ] troca de thread enquanto modelo roda;
- [ ] debug com validacao;
- [ ] sem vazamento de contexto interno;
- [ ] sem identidade errada de provider;
- [ ] compactacao preserva decisao;
- [ ] provider handoff preserva resumo;
- [ ] outcome task/project/blocker.

Pronto quando:

- suite roda localmente;
- resultados ficam persistidos;
- comparacao antes/depois mostra delta.

### Fase 7 - A/B De Providers E Routing Inteligente

Objetivo: escolher provider por evidencia.

Backend:

- [ ] `AiProviderComparisonService`.
- [ ] Tabelas de comparison run/result ou uso de eval runs com metadata.
- [ ] Comparacao por task type.
- [ ] Scorecard por provider/modelo.
- [ ] Alimentar router com scorecards.

Regras:

- A/B nao deve rodar em toda mensagem.
- A/B deve respeitar custo e privacidade.
- A/B deve ser opt-in/configuravel.
- Resultados precisam considerar qualidade, continuidade, custo e latencia.

Pronto quando:

- Atlas sabe qual provider tende a vencer por tipo de tarefa;
- router consegue justificar escolha com metricas.

### Fase 8 - Self-Diagnostic Avancado

Objetivo: Atlas detectar sua propria degradacao com evidencias.

Adicionar watchers:

- [ ] queda de qualidade final;
- [ ] queda de eficiencia;
- [ ] aumento de custo;
- [ ] aumento de latencia p95;
- [ ] aumento de wrong-context;
- [ ] aumento de reask;
- [ ] aumento de pending nao recuperado;
- [ ] provider degradado por scorecard, nao so health check.

Pronto quando:

- self-diagnostic abre Inbox contextual com metricas, hipotese e proposta;
- dedupe evita spam;
- operador consegue transformar diagnostico em proposal.

## 18. Testes Obrigatorios

### Backend unit

- [ ] `AiTelemetryCollectorTest`
- [ ] `AiTraceMetricAggregatorTest`
- [ ] `AiCostEstimatorTest`
- [ ] `AiContinuityScorerTest`
- [ ] `AiContextEfficiencyScorerTest`
- [ ] `AiOutcomeAttributionServiceTest`
- [ ] `AiScorecardServiceTest`

### Backend feature

- [ ] batch telemetry server auth;
- [ ] batch telemetry mobile auth;
- [ ] duplicate event idempotent;
- [ ] recompute trace summary;
- [ ] observability inclui scorecards;
- [ ] outcome criado por task/project/blocker;
- [ ] eval run persiste resultados.

### App

- [ ] typecheck;
- [ ] telemetry queue offline;
- [ ] send message then background;
- [ ] pending recovery emits events;
- [ ] duplicate flush does not duplicate event keys;
- [ ] dashboard renders empty/partial/full metrics.

### CLI

- [ ] command start/end;
- [ ] interrupt emits cancelled;
- [ ] spool writes offline;
- [ ] flush idempotent;
- [ ] `atlas metrics --json`;
- [ ] `atlas eval run --json`.

### Smoke real

- [ ] enviar mensagem no app e sair imediatamente;
- [ ] voltar e confirmar trace/resposta/telemetry;
- [ ] trocar conversa enquanto trace roda;
- [ ] rodar comando CLI AI;
- [ ] ver ambos no mesmo scorecard.

## 19. Configuracao

Adicionar em `config/atlas.php`:

```php
'ai_metrics' => [
    'enabled' => env('ATLAS_AI_METRICS_ENABLED', true),
    'telemetry_strict_events' => env('ATLAS_AI_TELEMETRY_STRICT_EVENTS', false),
    'telemetry_max_batch' => (int) env('ATLAS_AI_TELEMETRY_MAX_BATCH', 100),
    'telemetry_max_metadata_bytes' => (int) env('ATLAS_AI_TELEMETRY_MAX_METADATA_BYTES', 12000),
    'summary_recompute_window_hours' => (int) env('ATLAS_AI_METRICS_RECOMPUTE_WINDOW_HOURS', 24),
    'cost_default_confidence' => env('ATLAS_AI_COST_DEFAULT_CONFIDENCE', 'estimated'),
    'evals_enabled' => env('ATLAS_AI_EVALS_ENABLED', true),
    'provider_ab_enabled' => env('ATLAS_AI_PROVIDER_AB_ENABLED', false),
    'mobile_outbox_max_events' => (int) env('ATLAS_AI_MOBILE_OUTBOX_MAX_EVENTS', 500),
    'cli_outbox_max_files' => (int) env('ATLAS_AI_CLI_OUTBOX_MAX_FILES', 500),
],
```

## 20. Riscos E Controles

| Risco | Controle |
|---|---|
| Telemetria vira gargalo | Batch, async, best-effort, indexes corretos. |
| Dados sensiveis vazam em metadata | Redacao server-side obrigatoria e limites de payload. |
| Scores viram caixa preta | Guardar `score_components`. |
| Eventos duplicados poluem metricas | `event_key` unico e collector idempotente. |
| Clock mobile distorce latencia | Guardar client/server time e calcular skew. |
| Custo estimado parece real | `cost_confidence` obrigatorio. |
| A/B fica caro | Feature flag, sampling e limite por dia. |
| Outcome inferido vira falso positivo | Confidence e source obrigatorios. |
| Dashboard confunde sem amostra | Mostrar sample size e janela. |

## 21. Definition Of Done Global

A implementacao completa so esta pronta quando:

- [ ] toda mensagem app tem timeline fim a fim;
- [ ] todo comando CLI AI tem timeline fim a fim;
- [ ] todo trace finalizado tem summary;
- [ ] app e CLI mostram o mesmo scorecard;
- [ ] custo por resposta aparece como real ou estimado;
- [ ] continuidade e eficiencia de contexto tem score;
- [ ] outcomes principais sao atribuidos;
- [ ] suite `atlas-ai-core-v1` roda e persiste resultado;
- [ ] self-diagnostic usa scores novos;
- [ ] smoke real de app fechado apos envio passa;
- [ ] `php artisan test --filter=Ai` passa;
- [ ] `php artisan test --filter=MobileGatewayTest` passa;
- [ ] `npm run typecheck` passa;
- [ ] `npm run test:atlas-ai` passa.

## 22. Primeiro PR Recomendado

O primeiro PR deve ser pequeno e estrutural:

1. Migration/model `ai_telemetry_events`.
2. `AiTelemetryCollector`.
3. `AiTelemetryController`.
4. Rotas server/mobile.
5. Testes de batch/idempotencia/auth/redacao basica.
6. `atlas-app/lib/atlasAiTelemetry.ts` com outbox offline.
7. Instrumentar apenas:
   - sheet opened;
   - send pressed;
   - pending stored;
   - interaction accepted;
   - app backgrounded;
   - pending recovered.
8. `AtlasCliTelemetry` minimo com command start/end e spool.

Esse PR cria a fundacao. Nao implementar score complexo antes de existir evento bruto confiavel.

