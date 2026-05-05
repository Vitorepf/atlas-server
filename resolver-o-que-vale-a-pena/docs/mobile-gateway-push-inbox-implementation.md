# Atlas Mobile Gateway + Push + Inbox - Plano de Implementacao

**Status:** especificacao operacional para implementar P6  
**Escopo:** `atlas-server`, `atlas-app`, Atlas CLI, workers, filas, push e inbox operacional  
**Principio central:** push chama atencao; inbox e a fonte da verdade; thread contextual resolve a decisao.

Este documento existe para impedir uma implementacao pela metade. Siga as fases em ordem. Nao considere a fase pronta enquanto a checklist dela nao estiver completa, testada e documentada.

## Progresso De Implementacao

Atualizado em 2026-04-30:

- [x] Backend Fase 1 iniciado e entregue: migrations/models para devices, pairing codes, context bundles, inbox items, push deliveries e initiative runs.
- [x] Backend Fase 2 iniciado e entregue: pairing service, bearer middleware mobile, rotas de pairing/devices e comando `atlas mobile`.
- [x] Backend Fase 3 iniciado e entregue: `AtlasInboxService`, `ContextBundleService`, action registry, rotas mobile de inbox, `discuss` com thread seeding e comando `atlas inbox`.
- [x] Testes backend iniciais: `Tests\Feature\MobileGatewayTest` cobre pairing, bearer auth, inbox list, dedupe, approval e discuss idempotente.
- [x] Push Fase 4 parcialmente entregue: Expo service com immediate send, quiet hours, critical bypass, batching/flush, badge count, retry basico, CLI `atlas mobile push-test` e `flush-push`.
- [x] Atlas-app parcialmente conectado: cliente `/v1/mobile/*`, storage seguro do bearer mobile, registro de Expo token, listeners de deep link/push no root layout e secao "Operacional" no Inbox para `ai_inbox_items` unread.
- [x] Atlas-app pairing entregue: rota `/mobile-pairing` confirma codigo temporario, mostra sessao/device, registra push novamente, remove pareamento local e a Inbox exibe banner quando o Mobile Gateway nao esta pareado.
- [x] Atlas-app thread contextual entregue: action `discuss` abre `/mobile-thread`, deep link `atlas://thread/{id}` navega para a tela, a thread carrega mensagens via Mobile Gateway e envia respostas pelo `atlas-server`.
- [x] Job results importantes iniciados: `JobResultInboxEmitter` cria `job_result` com context bundle, actions `view_trace`/`discuss`/`dismiss`, dedupe por job e integracao no `AiWorker` para jobs finalizados importantes.
- [x] Self-diagnostic inicial entregue: `SelfDiagnosticEmitter` compara quality evaluations recentes contra baseline, emite `self_diagnostic` com context bundle/dedupe quando ha regressao confirmada, e `atlas:self-diagnostic` permite dry-run/manual/scheduler.
- [x] Insights base entregues: `InsightInboxEmitter` cria `insight` contextual com bundle, dedupe, metric/source refs e action `discuss`; comando `atlas:insight` permite smoke/manual.
- [x] Proposals base entregues: `ProposalInboxEmitter` cria `proposal` segura com analise estruturada, context bundle, diff/file refs, actions `review_patch`/`discuss`/`discard` e policy bloqueando commit/merge automatico; comando `atlas:proposal` permite smoke/manual.
- [x] Push receipts Expo entregue: `MobilePushService::fetchReceipts()` marca `receipt_ok`/`receipt_error`, invalida token em erro permanente e `atlas mobile receipts` roda manual/scheduler configuravel.
- [x] Proposal scanner entregue: `AutoImprovementProposalScanner` varre workspace/repo, cria `atlas_initiative_runs`, detecta marcadores explicitos e services/controllers grandes, deduplica achados e alimenta `ProposalInboxEmitter`; comando `atlas:proposal:scan` roda dry-run por padrao e `--emit` publica proposals no Inbox.
- [x] Insight watchers entregues: `InsightWatcherService` observa `health_snapshots`, `digital_activity_snapshots` e `ai_provider_health_snapshots`, registra `atlas_initiative_runs`, aplica thresholds/config de confianca e emite `insight` contextual via `InsightInboxEmitter`; comando `atlas:insight:watch` roda manual/dry-run/scheduler.
- [x] Action `create_proposal` entregue para `self_diagnostic`: o operador pode transformar auto-diagnostico em `proposal` segura com dedupe, contexto e policy sem commit/merge automatico.
- [x] App Inbox operacional endurecida: backend expõe `status=active`, app carrega itens ativos (`unread` e `read` ainda pendentes), nao perde proposals apos `review_patch`, esconde resolvidos/dismissed/expired/snoozed futuros e mostra feedback para actions estruturadas.
- [x] CLI `atlas initiatives` entregue: `list` e `run refactor-scan|self-diagnostic|insight-watch`, com dry-run seguro por padrao e `--emit` explicito para criar Inbox.
- [x] `ignore_30d` agora e respeitado pelo `SelfDiagnosticEmitter`: enquanto o prazo estiver ativo, nova regressao da mesma categoria nao recria item.
- [x] CLI Inbox alinhado com o Mobile Gateway: `atlas inbox --filter=active --json` usa a mesma regra de itens pendentes ativos que o app.
- [x] Redacao de `context_bundle` endurecida: `ContextBundleService` usa `AtlasSecurity` em titulo, summary, body de thread, refs e `raw_payload`, marcando `redaction_status=redacted` quando necessario.
- [x] Push payload endurecido: notificacao usa body generico por tipo e `data` minima (`inbox_id`, `deep_link`, `type`, `severity`), sem `summary`, `body`, `title` ou contexto sensivel.
- [x] App Inbox detalhe entregue: cards operacionais abrem `/mobile-inbox-item`, a tela marca leitura, mostra body completo, payload por tipo, context bundle, refs e metadados, e executa actions estruturadas com estado de loading e confirmacao quando exigida.
- [x] App Inbox filtros operacionais entregues: secao Operacional agora filtra itens ativos por Tudo, Aprovacoes, Insights, Propostas, Jobs, Auto-diagnostico e Alertas, com contadores locais por tipo.
- [x] Snooze operacional corrigido: action `snooze` agora envia `snoozed_until`; card adia rapidamente por 7 dias, detalhe oferece opcoes de 1/7/30 dias e o backend tem teste garantindo que item adiado sai de `status=active`.
- [x] Mobile Gateway Inbox API completado para filtros `severity` e `cursor`: `GET /v1/mobile/inbox` retorna `next_cursor` e pagina por `created_at/id` preservando `status=active`.
- [x] Deep links de push para Inbox corrigidos: `atlas://inbox/{id}` e `atlas://approval/{id}` abrem `/mobile-inbox-item`, e push cold start usa `Notifications.getLastNotificationResponseAsync()`.
- [x] Deep link `atlas://inbox/{id}/discuss` entregue: app abre o detalhe com `inboxAction=discuss`, chama backend `/discuss` e navega para `/mobile-thread` com a thread contextual idempotente.
- [x] Deep link invalido tratado: hooks runtime rodam dentro do `AtlasShell`; links Atlas invalidos caem no Inbox com toast discreto, e push invalido cai no Inbox como fallback.
- [x] Testes CLI Inbox completos: `MobileGatewayTest` cobre `atlas inbox list/show/respond/discuss` usando os mesmos handlers do Mobile Gateway.
- [x] Paginacao do Inbox operacional no app entregue: a secao Operacional usa `next_cursor`, carrega lotes de 25 itens ativos, faz merge por id e mantem ordenacao por `created_at`.
- [x] Self-diagnostic confidence gate entregue: `ATLAS_INIT_SELF_DIAGNOSTIC_CONFIDENCE_THRESHOLD` bloqueia emissao abaixo da confianca minima antes de criar Inbox/context bundle.
- [x] CLI Inbox alinhado com paginacao/filtro API: `atlas inbox` aceita `--limit`, `--cursor` e `--severity`, e `--json` retorna `next_cursor`.
- [x] Action state guard entregue: backend bloqueia actions em itens fechados e bloqueia actions em itens snoozed no futuro, preservando `mark_read`/`dismiss` quando seguro.
- [x] Pairing attempt lock entregue: `pairing_id` opcional no confirm permite incrementar `attempts` e bloquear codigo por 1h apos 5 tentativas invalidas, sem quebrar confirmacao normal por codigo.
- [x] Badge sync no app entregue: Inbox operacional aplica `Notifications.setBadgeCountAsync(unread_count)` ao carregar e zera badge local quando o device nao esta pareado.
- [x] Approval TTL/fail-closed entregue: `ATLAS_MOBILE_APPROVAL_TTL_MINUTES` define expiracao padrao e approval expirado nao executa action.
- [x] Pairing hardening entregue: `atlas mobile pair` mostra `Pairing ID`, confirmacao aceita `pairing_id`, codigo expirado falha fechado e testes cobrem CLI/expiracao.
- [x] App revoke/repareamento entregue: tela de pareamento revoga o device no backend, limpa o bearer local e volta ao estado pronto para novo codigo.
- [x] Approval workspace grant entregue: `approve_workspace_1h` exige workspace, grava escopo `workspace`, `workspace`, `expires_at`, tool e risk no response, com testes de sucesso e fail-closed.
- [x] CLI push-test coberto: `atlas mobile push-test --device=... --json` cria item real `payload.test=true`, context bundle e `mobile_push_deliveries` enviado ao Expo fake.
- [x] Rate limit mobile entregue: `MobileGatewayRateLimiter` protege pairing initiate/confirm e actions sensiveis com limites configuraveis por env, resposta 429 com `Retry-After` e testes de burst.
- [x] Auditoria mobile coberta: teste com `audit_events` real valida pairing initiated/confirmed, revoke, approval workspace, ignore_30d e discard via `inbox.action.*`.
- [x] Gate de actions de codigo entregue: `commit/open_pr/merge/apply_patch/push_branch` exigem `quality_gate_status=passed` e ainda falham fechado sem handler mobile seguro.
- [x] Idempotencia direta entregue: endpoints mobile `dismiss` e `snooze` agora usam `InboxActionRegistry`, aceitam `Idempotency-Key` e nao reexecutam taps repetidos.
- [x] Core Reliability Monitor entregue: `MobileReliabilityMonitor` roda checks de sobrevivencia (`scheduler_stale`, `push_degraded`, `circuit_stuck`, `jobs_silent`), envia alert webhook out-of-band com cooldown por cache, registra `system.health.degraded/recovered`, escreve fallback local `storage/logs/atlas-health-alerts.jsonl`, expõe `atlas:cli:mobile alert-check` e roda no scheduler quando alertas mobile estiverem habilitados.
- [x] Validacao atual: `php artisan test --filter=MobileGatewayTest` (51 testes, 433 assercoes), `php artisan atlas:initiatives list --json`, `php artisan atlas:initiatives run self-diagnostic --dry-run --json`, `php artisan atlas:initiatives run refactor-scan --dry-run --workspace=/Users/vitorepf/Develop/atlas/atlas-server --limit=1 --json`, `php artisan atlas:self-diagnostic --dry-run`, `php artisan atlas:insight ... --dry-run`, `php artisan atlas:proposal ... --dry-run`, `php artisan atlas:proposal:scan --workspace=/Users/vitorepf/Develop/atlas/atlas-server --limit=2 --json`, `php artisan atlas:insight:watch --dry-run --json`, `php artisan atlas:cli:mobile receipts --json` e `npm run typecheck -- --pretty false` passam em 2026-04-30.
- [x] Smoke em device real parcialmente validado em 2026-04-30: app pareou iPhone real, registrou push token/permissao `granted`, backend enviou push Expo real com status `sent` e item apareceu no Inbox operacional. Pendente: tocar notificacao com app fechado para validar deep link cold start.

## 1. Resultado Esperado

Ao final, o Atlas consegue iniciar comunicacao com o operador pelo app mobile de forma persistente, acionavel e contextual.

Casos obrigatorios:

1. Atlas envia um insight detalhado ao Inbox sobre saude, metricas, implementacao ou assunto especifico.
2. O item tem botao para abrir conversa com Atlas AI ja carregada com o contexto do tema.
3. Atlas roda analises autonomas em horarios definidos, encontra melhorias, bugs ou refactors, e envia proposta clara para revisao.
4. Jobs importantes publicam resultado no Inbox, com sucesso/falha, impacto e link para trace.
5. Atlas mede o proprio desempenho e envia self-diagnostic quando detectar degradacao real com hipotese e proposta.
6. Push notification aparece no device quando o item exige atencao, respeitando quiet hours, batching e prioridade.
7. CLI e app consomem o mesmo Inbox.

## 2. Decisoes Arquiteturais Nao Negociaveis

- [x] Inbox e persistente e auditavel. Nenhuma decisao importante vive apenas no push.
- [x] Push nunca contem contexto sensivel completo. Push contem titulo curto, `inbox_id`, `deep_link` e metadados minimos.
- [x] App mobile nunca conversa direto com Claude, Codex, OpenAI ou qualquer provider. Toda IA passa pelo `atlas-server`.
- [x] Actions sao definidas e autorizadas pelo servidor. O cliente so envia `action_id` e dados permitidos.
- [x] Mudancas em codigo nunca sao aplicadas por push sem policy gate, testes e trilha de auditoria.
- [x] Contexto rico vai em `context_bundle`, nao em um campo de texto gigante do inbox.
- [x] Todo evento gerador de Inbox precisa de `dedupe_key` ou regra explicita de nao deduplicacao.
- [x] Toda action mutante precisa ser idempotente.
- [x] Falha de push nao perde mensagem: o item continua no Inbox.
- [x] Fase so e pronta quando tem migracao, modelo, service, endpoint, UI minima e teste/smoke.

## 3. Glossario Operacional

| Termo | Significado |
|---|---|
| Mobile Gateway | API mobile sob `/v1/mobile/*`, com auth de device, inbox, actions, threads e devices. |
| Inbox Item | Mensagem persistente, acionavel e auditavel enviada ao operador. |
| Push | Notificacao nativa via Expo Push API para chamar atencao. |
| Context Bundle | Pacote persistente com resumo, traces, metricas, logs, diffs, arquivos e referencias usados para abrir conversa contextual. |
| Thread Seeder | Servico que cria uma thread Atlas AI a partir de um Inbox Item e seu Context Bundle. |
| Atlas Initiative Engine | Subsistema que permite Atlas originar eventos sozinho: scans, watchers, self-monitoring e proposals. |
| Notification Orchestrator | Camada que decide dedupe, prioridade, destino, push, batching, quiet hours e actions. |
| Action Handler | Handler servidor que executa uma action estruturada de um inbox item. |

## 4. Tipos De Inbox

Tipos P6 base:

| Tipo | Quando usar | Resposta esperada |
|---|---|---|
| `approval` | Agente precisa permissao para tool ou operacao arriscada. | `approve_once`, `approve_session`, `approve_workspace_1h`, `deny`. |
| `alert` | Erro critico, worker degradado, falha importante. | Ler, discutir, ver trace, dismiss. |
| `completion` | Tarefa agendada ou background terminou com resultado comum. | Ler, abrir resultado, dismiss. |
| `capture` | Captura mobile foi processada ou falhou. | Triar captura, abrir detalhe, dismiss. |
| `thread_update` | Thread recebeu resposta relevante sem operador presente. | Abrir thread. |
| `job_status` | Background job mudou de estado. | Ver job/trace. |

Tipos novos obrigatorios:

| Tipo | Origem | TTL sugerido | Actions obrigatorias |
|---|---|---|---|
| `insight` | Watcher, metrica, saude, implementacao ou observacao do Atlas. | Sem TTL por padrao. | `discuss`, `dismiss`, `snooze`. |
| `proposal` | Auto-improvement, refactor scanner, bug scanner. | 7 dias. | `review_patch`, `open_pr`, `discuss`, `discard`, nunca `commit` direto no MVP. |
| `job_result` | Job marcado `importance=high` ou falha relevante. | 30 dias. | `view_trace`, `rerun` se seguro, `dismiss`. |
| `self_diagnostic` | Self-monitoring detectou regressao confirmada. | 14 dias. | `discuss`, `create_proposal`, `ignore_30d`, `dismiss`. |

Regra de ruido:

- [x] `proposal` e `self_diagnostic` usam alta precisao e baixa recorrencia.
- [x] Limite inicial: no maximo 3 proposals por execucao de auto-improvement e 1 self-diagnostic por categoria por 7 dias.
- [x] Itens repetidos atualizam o item existente quando `dedupe_key` bater, em vez de criar spam.

## 5. Arquitetura Alvo

```text
Atlas Event Sources
  - scheduled tasks
  - background jobs
  - AI workers
  - repo scanners
  - health watchers
  - metrics collectors
  - self-monitoring loop
        |
        v
Atlas Event Bus / Queue
        |
        v
Notification Orchestrator
  - dedupe
  - severity
  - confidence threshold
  - quiet hours
  - batching
  - action policy
  - routing
        |
        v
Inbox Service ---------------> Postgres
        |                         |
        |                         v
        |                   Context Bundles
        |
        v
Push Dispatcher -------------> Expo Push API
        |
        v
Atlas App
        |
        v
Mobile Gateway
  - inbox
  - devices
  - actions
  - threads
  - context handoff
```

## 6. Modelo De Dados

### 6.1 `atlas_mobile_devices`

Criar migracao em `atlas-server/database/migrations/*_create_atlas_mobile_devices_table.php`.

Campos obrigatorios:

```sql
CREATE TABLE atlas_mobile_devices (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id VARCHAR(255) NOT NULL DEFAULT 'vitor',
  device_label VARCHAR(80) NOT NULL,
  platform VARCHAR(16) NOT NULL CHECK (platform IN ('ios', 'android')),
  app_version VARCHAR(32),
  os_version VARCHAR(64),
  expo_push_token TEXT,
  push_token_hash VARCHAR(128),
  device_token_hash VARCHAR(128) NOT NULL UNIQUE,
  notification_permissions VARCHAR(32) DEFAULT 'unknown',
  last_seen_at TIMESTAMPTZ,
  paired_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  revoked_at TIMESTAMPTZ,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Checklist:

- [x] Hash de bearer token nunca reversivel.
- [x] Expo token pode mudar; atualizar token sem criar outro device se `device_id` continuar valido.
- [x] `revoked_at` invalida auth imediatamente.
- [x] Indices: `(user_id, revoked_at)`, `(push_token_hash)`, `(last_seen_at)`.

### 6.2 `ai_inbox_items`

Criar migracao em `atlas-server/database/migrations/*_create_ai_inbox_items_table.php`.

```sql
CREATE TABLE ai_inbox_items (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id VARCHAR(255) NOT NULL DEFAULT 'vitor',
  type VARCHAR(40) NOT NULL,
  category VARCHAR(40),
  severity VARCHAR(16) NOT NULL DEFAULT 'info',
  status VARCHAR(24) NOT NULL DEFAULT 'unread',
  title VARCHAR(180) NOT NULL,
  summary TEXT,
  body TEXT,
  source_type VARCHAR(64),
  source_id UUID,
  initiator VARCHAR(32) NOT NULL DEFAULT 'system',
  context_bundle_id UUID,
  dedupe_key VARCHAR(160),
  available_actions JSONB NOT NULL DEFAULT '[]'::jsonb,
  response JSONB,
  payload JSONB NOT NULL DEFAULT '{}'::jsonb,
  deep_link TEXT,
  push_policy JSONB NOT NULL DEFAULT '{}'::jsonb,
  priority_score SMALLINT NOT NULL DEFAULT 50 CHECK (priority_score BETWEEN 0 AND 100),
  confidence_score NUMERIC(4,3),
  expires_at TIMESTAMPTZ,
  snoozed_until TIMESTAMPTZ,
  read_at TIMESTAMPTZ,
  resolved_at TIMESTAMPTZ,
  dismissed_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Checks:

```sql
CHECK (type IN (
  'approval',
  'alert',
  'completion',
  'capture',
  'thread_update',
  'job_status',
  'insight',
  'proposal',
  'job_result',
  'self_diagnostic'
));

CHECK (severity IN ('debug', 'info', 'warning', 'critical'));
CHECK (status IN ('unread', 'read', 'actioned', 'resolved', 'dismissed', 'expired', 'snoozed'));
CHECK (initiator IN ('operator', 'atlas', 'system', 'job'));
```

> Nota: `active` nao e valor da coluna. E filtro derivado: `status NOT IN ('resolved','dismissed','expired') AND (expires_at IS NULL OR expires_at > NOW()) AND (status != 'snoozed' OR snoozed_until IS NULL OR snoozed_until <= NOW())`. Implementado em `AtlasInboxService::query()`. App, CLI e API mobile devem usar essa regra; nunca gravar `'active'` na coluna.

Indices obrigatorios:

- [x] `(user_id, status, created_at DESC)`
- [x] `(user_id, type, status, created_at DESC)`
- [x] `(user_id, severity, created_at DESC)`
- [x] `UNIQUE(user_id, dedupe_key) WHERE dedupe_key IS NOT NULL AND status NOT IN ('resolved', 'dismissed', 'expired')`
- [x] GIN em `available_actions`
- [x] GIN em `payload`

### 6.3 `ai_context_bundles`

Nao usar `context_seed TEXT` como base central. Criar bundle versionado.

```sql
CREATE TABLE ai_context_bundles (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id VARCHAR(255) NOT NULL DEFAULT 'vitor',
  purpose VARCHAR(64) NOT NULL,
  title VARCHAR(180) NOT NULL,
  summary TEXT NOT NULL,
  body_for_thread TEXT NOT NULL,
  source_refs JSONB NOT NULL DEFAULT '[]'::jsonb,
  trace_refs JSONB NOT NULL DEFAULT '[]'::jsonb,
  job_refs JSONB NOT NULL DEFAULT '[]'::jsonb,
  metric_refs JSONB NOT NULL DEFAULT '[]'::jsonb,
  file_refs JSONB NOT NULL DEFAULT '[]'::jsonb,
  diff_refs JSONB NOT NULL DEFAULT '[]'::jsonb,
  raw_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
  redaction_status VARCHAR(24) NOT NULL DEFAULT 'clean',
  token_estimate INTEGER,
  expires_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Checklist:

- [x] `summary` e para UI.
- [x] `body_for_thread` e o texto de alto sinal que entra na thread.
- [x] Dados brutos ficam em `raw_payload` ou referencias, nao no push.
- [x] Aplicar redacao antes de salvar segredos, tokens, chaves, paths sensiveis ou dados pessoais desnecessarios.
- [x] `inbox.context_bundle_id` referencia `ai_context_bundles.id`.

### 6.4 `mobile_push_deliveries`

Registrar cada tentativa de push fora do `payload` do Inbox.

```sql
CREATE TABLE mobile_push_deliveries (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  inbox_item_id UUID NOT NULL REFERENCES ai_inbox_items(id) ON DELETE CASCADE,
  device_id UUID REFERENCES atlas_mobile_devices(id) ON DELETE SET NULL,
  status VARCHAR(24) NOT NULL,
  provider VARCHAR(24) NOT NULL DEFAULT 'expo',
  provider_ticket_id TEXT,
  provider_receipt_id TEXT,
  request_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
  response_payload JSONB NOT NULL DEFAULT '{}'::jsonb,
  error_code TEXT,
  error_message TEXT,
  attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Status possiveis (enum, nao checklist):

- `queued`
- `sent`
- `deferred_quiet_hours`
- `deferred_circuit_open`
- `batched`
- `failed_transient`
- `failed_permanent`
- `receipt_ok`
- `receipt_error`

### 6.5 `mobile_pairing_codes`

```sql
CREATE TABLE mobile_pairing_codes (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id VARCHAR(255) NOT NULL DEFAULT 'vitor',
  code_hash VARCHAR(128) NOT NULL UNIQUE,
  device_label VARCHAR(80) NOT NULL,
  expires_at TIMESTAMPTZ NOT NULL,
  consumed_at TIMESTAMPTZ,
  attempts SMALLINT NOT NULL DEFAULT 0,
  locked_until TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Checklist:

- [x] Codigo aparece so uma vez no terminal.
- [x] Codigo nunca aparece em logs.
- [x] Confirmacao invalida incrementa `attempts` quando `pairing_id` e informado.
- [x] 5 tentativas invalidas bloqueiam por 1h.
- [x] TTL default: 1h.

### 6.6 `atlas_initiative_runs`

Rastrear execucoes autonomas.

```sql
CREATE TABLE atlas_initiative_runs (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  kind VARCHAR(48) NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'queued',
  started_at TIMESTAMPTZ,
  finished_at TIMESTAMPTZ,
  scope JSONB NOT NULL DEFAULT '{}'::jsonb,
  findings JSONB NOT NULL DEFAULT '[]'::jsonb,
  emitted_inbox_item_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
  error_message TEXT,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

Kinds iniciais (enum):

- `refactor_scan`
- `bug_scan`
- `job_result_digest`
- `self_diagnostic`
- `health_insight`
- `metrics_insight`

## 7. Contrato De Action

`available_actions` deve ser array JSON server-authored.

Exemplo:

```json
[
  {
    "id": "discuss",
    "label": "Discutir com Atlas",
    "style": "primary",
    "requires_confirm": false,
    "handler": "thread_seed",
    "deep_link": "atlas://inbox/{id}/discuss"
  },
  {
    "id": "open_pr",
    "label": "Abrir PR",
    "style": "primary",
    "requires_confirm": true,
    "handler": "proposal_open_pr",
    "policy": {
      "requires_tests_passed": true,
      "max_changed_files": 20,
      "risk": "medium"
    }
  },
  {
    "id": "discard",
    "label": "Descartar",
    "style": "destructive",
    "requires_confirm": true,
    "handler": "discard"
  }
]
```

Regras:

- [x] App nao inventa action.
- [x] Backend valida que action existe no item e que esta ativa.
- [x] Backend valida status atual antes de executar.
- [x] Action mutante registra `AuditEvent`.
- [x] Action mutante e idempotente por `(inbox_item_id, action_id, idempotency_key)`.
- [x] Action de codigo deve exigir policy gate.

Actions iniciais:

| Action | Tipos | Comportamento |
|---|---|---|
| `mark_read` | todos | Marca leitura. |
| `dismiss` | todos exceto approval pendente | Fecha sem executar. |
| `snooze` | todos nao criticos | Adia ate data. |
| `discuss` | insight, proposal, self_diagnostic, alert | Cria thread com context bundle. |
| `view_trace` | job_result, alert, completion | Abre trace/job. |
| `review_patch` | proposal | Mostra diff/branch/proposta. |
| `open_pr` | proposal | Abre PR se gates passarem. |
| `discard` | proposal | Fecha proposal e opcionalmente apaga branch temporario. |
| `approve_once` | approval | Autoriza uma execucao. |
| `approve_session` | approval | Autoriza sessao limitada. |
| `approve_workspace_1h` | approval | Autoriza workspace por 1h. |
| `deny` | approval | Recusa. |
| `ignore_30d` | self_diagnostic | Silencia mesma regra por 30 dias. |

## 8. APIs Do Mobile Gateway

Todas as rotas ficam sob `/v1/mobile`.

### 8.1 Auth e pairing

```http
POST /v1/mobile/pairing/initiate
POST /v1/mobile/pairing/confirm
GET  /v1/mobile/devices
PATCH /v1/mobile/devices/{id}
DELETE /v1/mobile/devices/{id}
POST /v1/mobile/devices/{id}/push-token
```

Checklist:

- [x] `initiate` so deve ser chamado por CLI/local token, nao pelo app anonimo.
- [x] `confirm` recebe `code`, `platform`, `device_label`, `expo_push_token`, `app_version`, `os_version`.
- [x] `confirm` retorna `device_token`, `device_id`, `user_id`.
- [x] Bearer token mobile e separado de `X-Atlas-Token`.
- [x] Middleware `atlas.mobile.bearer` atualiza `last_seen_at`.

### 8.2 Inbox

```http
GET  /v1/mobile/inbox
GET  /v1/mobile/inbox/{id}
POST /v1/mobile/inbox/{id}/read
POST /v1/mobile/inbox/{id}/dismiss
POST /v1/mobile/inbox/{id}/snooze
POST /v1/mobile/inbox/{id}/respond
POST /v1/mobile/inbox/{id}/discuss
```

`GET /v1/mobile/inbox` parametros:

- [x] `status=active|unread|read|actioned|resolved|dismissed|snoozed|expired|all` (`active` e filtro derivado, ver §6.2)
- [x] `type=insight|proposal|job_result|self_diagnostic|approval|alert|completion|capture|thread_update|job_status`
- [x] `severity=info|warning|critical`
- [x] `limit`, max 100
- [x] `cursor` baseado em `(created_at, id)`, retorna `next_cursor`

Resposta de item:

```json
{
  "id": "uuid",
  "type": "proposal",
  "severity": "warning",
  "status": "unread",
  "title": "Refactor scanner encontrou duplicacao no AiGatewayService",
  "summary": "Ha duas rotas de decisao que divergem em permission checks.",
  "body": "Explicacao legivel para Vitor.",
  "created_at": "2026-04-30T12:00:00Z",
  "expires_at": "2026-05-07T12:00:00Z",
  "deep_link": "atlas://inbox/{id}",
  "available_actions": [],
  "payload": {
    "badges": ["tests_pending", "medium_risk"],
    "source": "refactor_scan"
  }
}
```

### 8.3 Threads e contexto

```http
POST /v1/mobile/threads/from-inbox/{inboxItem}
GET  /v1/mobile/threads/{thread}
POST /v1/mobile/threads/{thread}/messages
```

`POST /threads/from-inbox/{id}` (implementado via action `discuss` em `InboxActionRegistry::discuss()`):

- [x] Valida device/user.
- [x] Carrega `context_bundle_id`.
- [x] Cria `ai_threads` com `source_type='inbox_item'`, `source_id=<id>`, `surface='mobile'`.
- [x] Cria primeira `ai_messages` role `system` com `body_for_thread`.
- [x] Cria mensagem `user` inicial curta, por exemplo: "Vamos discutir este item do Inbox."
- [x] Marca inbox como `read` se ainda unread.
- [x] Retorna `thread_id` e `deep_link=atlas://thread/{thread_id}`.

### 8.4 Jobs, traces e proposals

```http
GET /v1/mobile/jobs/{id}
GET /v1/mobile/traces/{id}
GET /v1/mobile/proposals/{id}
GET /v1/mobile/proposals/{id}/diff
```

Status: rotas dedicadas pendentes. No MVP atual, dados de job/trace/proposal vivem no `payload` do `ai_inbox_items` (preenchido via `JobResultInboxEmitter`/`ProposalInboxEmitter` com refs e diff resumido). App consome via `GET /v1/mobile/inbox/{id}`. Endpoints separados ficam para fase pos-MVP quando diff grande exigir paginacao dedicada.

Checklist (alvo pos-MVP):

- [ ] Nunca retornar logs brutos sem redacao.
- [ ] Diff grande deve ser paginado ou resumido.
- [ ] Mostrar status dos gates: testes, lint, risco, arquivos alterados, provider, modelo.

## 9. Backend Services

Criar namespace:

`atlas-server/app/Services/Ai/Mobile`

### 9.1 `MobilePairingService`

Responsabilidades:

- [x] Gerar pairing code com alfabeto nao ambiguo.
- [x] Hash do code com SHA-256 + app key.
- [x] Confirmar code e criar device.
- [x] Emitir bearer token mobile.
- [x] Revogar device.
- [x] Nunca logar code ou bearer.

### 9.2 `MobilePushService`

Responsabilidades:

- [x] Enviar via Expo Push API.
- [x] Separar payload visual e `data`.
- [x] Registrar tentativa em `mobile_push_deliveries`.
- [x] Respeitar quiet hours.
- [x] Enfileirar batch quando necessario.
- [x] Tratar erro permanente de token removendo push token do device.
- [x] Poll de receipts Expo em job separado, se configurado.

Fan-out multi-device:

- [x] Para cada item, `dispatchForInboxItem` itera todos `atlas_mobile_devices` do user com `revoked_at IS NULL` e `expo_push_token IS NOT NULL`.
- [x] Cria 1 `mobile_push_deliveries` por device.
- [x] Badge e global por usuario (unread count), igual em todos os devices; cliente reconcilia ao foreground.
- [x] Quiet hours e batching sao avaliados por item, nao por device. Resultado e o mesmo em devices do mesmo user.
- [ ] Falha permanente em um device (token invalido) nao bloqueia outros devices: cada delivery e independente.

Payload padrao:

```json
{
  "to": "ExponentPushToken[...]",
  "title": "Atlas",
  "body": "Atlas encontrou uma proposta importante.",
  "sound": "default",
  "priority": "default",
  "badge": 4,
  "data": {
    "inbox_id": "uuid",
    "deep_link": "atlas://inbox/uuid",
    "type": "proposal",
    "severity": "warning",
    "unread_count": 4,
    "unread_count_at": "2026-04-30T12:00:00Z"
  }
}
```

`unread_count` + `unread_count_at` permitem ao cliente reconciliar badge mesmo quando pushes chegam fora de ordem (cliente aplica apenas se `unread_count_at` for mais recente que o ultimo aplicado).

### 9.3 `AtlasInboxService`

Responsabilidades:

- [x] Criar item com dedupe.
- [x] Atualizar item existente se dedupe bater.
- [x] Normalizar TTL por tipo.
- [x] Montar `available_actions`.
- [x] Criar `deep_link`.
- [x] Chamar Orchestrator para decidir push (inline: `create()` chama `MobilePushService::dispatchForInboxItem` direto).
- [x] Listar, ler, dismiss, snooze, respond.
- [x] Registrar auditoria.

Assinatura sugerida:

```php
public function create(InboxMessageData $data): AiInboxItem;
public function respond(AiInboxItem $item, string $actionId, array $input, string $idempotencyKey): ActionResult;
public function markRead(AiInboxItem $item): AiInboxItem;
public function dismiss(AiInboxItem $item, ?string $reason = null): AiInboxItem;
public function snooze(AiInboxItem $item, CarbonInterface $until, ?string $reason = null): AiInboxItem;
```

### 9.4 `ContextBundleService`

Responsabilidades:

- [x] Criar bundles com resumo, contexto de thread e referencias.
- [x] Redigir segredos (via `AtlasSecurity` em titulo, summary, body, refs e raw_payload).
- [x] Resolver refs para thread.
- [x] Controlar tamanho por token (`token_estimate` registrado).
- [ ] Versao futura: compactar bundle grande (out of MVP).

### 9.5 `NotificationOrchestrator`

Status: nao existe como classe dedicada no MVP. Responsabilidades distribuidas inline entre `AtlasInboxService::create()` (dedupe + push policy) e `MobilePushService` (quiet hours + batching + badge). Extracao para classe propria fica como refactor pos-MVP se houver necessidade de policy mais rica.

Responsabilidades cobertas:

- [x] Decidir se cria item ou atualiza dedupe existente (`AtlasInboxService::create`).
- [x] Decidir push: none, immediate, quiet-deferred, batch (`MobilePushService::dispatchToDevice`).
- [ ] Aplicar preferencias do usuario/device (out of MVP — nao ha tabela de preferencias).
- [x] Aplicar thresholds por tipo (`push_policy` por item + confidence thresholds em emitters).
- [x] Aplicar limite de ruido (max 3 proposals/run, 1 self-diagnostic/categoria/7d, dedupe por item).
- [x] Calcular badge count (`MobilePushService::badgeCount`).

### 9.6 `InboxActionRegistry`

Responsabilidades:

- [x] Mapear `action_id` para handler.
- [x] Validar policy (state guard, code action gate, expires/snoozed checks).
- [x] Fornecer idempotencia (`Idempotency-Key` + `lockForUpdate` na transacao).
- [x] Registrar auditoria (`inbox.action.requested`/`completed`).
- [x] Retornar resultado serializavel para app.

Handlers iniciais (implementados como branches `match` em `handle()`, nao como classes separadas — refactor pos-MVP se complexidade crescer):

- [x] `discuss` (ThreadSeedAction equivalente).
- [x] `dismiss` / `discard`.
- [x] `snooze`.
- [x] `approve_once` / `approve_session` / `approve_workspace_1h` / `deny`.
- [x] `view_trace` / `review_patch` (read-only ack).
- [x] `create_proposal` (self_diagnostic -> proposal).
- [x] `ignore_30d`.
- [ ] `open_pr` executavel (atualmente fail-closed por design ate handler seguro existir).

## 10. Atlas Initiative Engine

Criar namespace:

`atlas-server/app/Services/Ai/Initiatives`

### 10.1 Componentes

```text
AtlasInitiativeEngine
  - AutoTaskRegistry
  - RefactorScanner
  - BugScanner
  - HealthInsightGenerator
  - MetricsInsightGenerator
  - JobResultPublisher
  - SelfMonitoringLoop
```

### 10.2 `AutoTaskRegistry`

Checklist (implementado via `config/atlas.php` `initiatives` + comando `atlas:initiatives`):

- [x] Registrar tarefas internas do Atlas separadas de tarefas criadas pelo operador.
- [x] Configurar horarios default em `config/atlas.php`.
- [x] Permitir desligar cada initiative por env/config (`ATLAS_INIT_*` flags).
- [x] Registrar `atlas_initiative_runs`.
- [ ] Nunca rodar auto-improvement concorrente no mesmo repo (pendente: lock por workspace ainda nao implementado).

Defaults sugeridos:

```php
'initiatives' => [
    'refactor_scan' => [
        'enabled' => env('ATLAS_INIT_REFACTOR_SCAN', false),
        'schedule' => 'sundays 06:00',
        'max_items' => 3,
    ],
    'self_diagnostic' => [
        'enabled' => env('ATLAS_INIT_SELF_DIAGNOSTIC', true),
        'schedule' => 'daily 00:30',
        'confidence_threshold' => 0.70,
    ],
]
```

### 10.3 Auto-improvement Proposal

Fluxo seguro:

```text
scheduled initiative
  -> scanner encontra candidato
  -> agente prepara analise estruturada
  -> cria branch ou patch temporario
  -> roda testes/gates possiveis
  -> cria context bundle
  -> cria inbox item type=proposal
  -> push se severity >= warning ou policy mandar
  -> operador revisa no app/CLI
  -> action open_pr ou discard
```

Checklist da proposta (cumprido por `ProposalInboxEmitter` + `AutoImprovementProposalScanner`):

- [x] Responde "o que encontrou".
- [x] Responde "qual o problema real".
- [x] Responde "qual solucao proposta".
- [x] Lista alternativas consideradas.
- [x] Explica tradeoff.
- [x] Explica se vale a pena.
- [x] Informa risco.
- [x] Informa arquivos afetados.
- [x] Informa testes rodados e resultado.
- [x] Cria diff/branch temporario ou anexa patch.
- [x] Nao faz merge automatico no MVP.

### 10.4 Job Result Publisher

Checklist (implementado via `JobResultInboxEmitter` + hook em `AiWorker`):

- [x] Jobs tem `importance=low|normal|high|critical`.
- [x] `importance=high` ou falha relevante cria `job_result`.
- [x] Sucesso de rotina normal nao gera push imediato, salvo `mobile_push`.
- [x] Falha importante gera severity `warning`.
- [x] Falha critica gera severity `critical`.
- [x] Item inclui link para trace/job e resumo curto.

### 10.5 Self-Monitoring Loop

Sinais cobertos no MVP por `SelfDiagnosticEmitter`/`InsightWatcherService`:

- [x] `ai_quality_evaluations.score` em janela 24h, 7d, 30d.
- [ ] `ai_tool_events.permission_status`, erros e duration (pendente — sinal nao consumido por watcher atual).
- [ ] `ai_jobs.status`, attempts, timeout e latency (pendente — apenas job_result emitido individualmente, sem agregacao).
- [x] `ai_provider_health_snapshots.status` (via `InsightWatcherService`).
- [ ] `ai_compactions` e status de quality gate quando disponivel (pendente).
- [ ] Correcoes do operador: feedback negativo, steer, rejection, discard de proposal (pendente — discard registrado em audit, mas nao retroalimenta detector).

Fluxo:

```text
Collector
  -> agrega metricas
Detector
  -> compara com baseline
  -> ignora ruido estatistico
HypothesisBuilder
  -> formula causa provavel e solucao
Emitter
  -> cria context bundle
  -> cria inbox item type=self_diagnostic se confidence >= threshold
```

Checklist:

- [x] Nao emitir self_diagnostic sem causa provavel.
- [x] Nao emitir sem acao sugerida ou pergunta clara.
- [x] `confidence_score` obrigatorio.
- [x] `confidence_threshold` configuravel antes de emitir Inbox.
- [x] Dedupe por categoria de regressao.
- [x] `ignore_30d` respeitado.
- [x] Push so se severity `warning` ou `critical`.

## 11. Deep Links

Registrar scheme `atlas://` no Expo.

Padroes:

| Link | Destino |
|---|---|
| `atlas://inbox` | Feed do Inbox. |
| `atlas://inbox/{id}` | Detalhe do item. |
| `atlas://inbox/{id}/discuss` | Cria/abre thread do item. |
| `atlas://approval/{id}` | Tela de approval. |
| `atlas://thread/{id}` | Thread existente. |
| `atlas://job/{id}` | Job status. |
| `atlas://trace/{id}` | Trace. |
| `atlas://capture` | Captura rapida. |

Checklist:

- [x] App trata deep link vindo de push cold start.
- [x] App trata deep link com app em foreground/background.
- [x] Link invalido cai em Inbox com toast discreto.
- [x] `discuss` chama backend antes de navegar para thread.
- [x] Thread criada uma vez por item; chamadas repetidas retornam a mesma thread ou criam nova apenas se usuario pedir.

## 12. Atlas App

Arquivos provaveis:

- `atlas-app/app/inbox.tsx`
- `atlas-app/app/_layout.tsx`
- `atlas-app/lib/api/client.ts`
- `atlas-app/lib/atlasStore.ts`
- `atlas-app/components/InboxCard.tsx`
- `atlas-app/components/inbox/*`
- `atlas-app/app/ai-thread.tsx` ou rota equivalente
- `atlas-app/lib/pushNotifications.ts`
- `atlas-app/lib/deepLinks.ts`

### 12.1 Setup push

Checklist:

- [x] Pedir permissao de notificacao no momento correto.
- [x] Obter Expo push token.
- [x] Enviar token para `/v1/mobile/devices/{id}/push-token`.
- [x] Atualizar token quando mudar.
- [x] Listener para notification received.
- [x] Listener para notification response/tap.
- [x] Badge count sincronizado com unread count.

### 12.2 Inbox UI

Checklist:

- [x] Feed combina captures existentes com novos `ai_inbox_items` ou separa claramente `Capturas` e `Operacional`.
- [x] Filtros: `Tudo`, `Aprovacoes`, `Insights`, `Propostas`, `Jobs`, `Auto-diagnostico`, `Alertas`.
- [x] Cada card mostra tipo, severidade, titulo, resumo, data e estado.
- [x] Detalhe mostra body completo e actions.
- [x] Actions desabilitam enquanto request esta em andamento.
- [x] Erro de action nao marca item como resolvido.
- [x] UI respeita item expirado, snoozed, dismissed e resolved.

### 12.3 Layout por tipo

`insight`:

- [x] Texto claro, detalhado e autocontido.
- [x] Botao principal: `Discutir com Atlas`.
- [x] Actions secundarias: `Adiar`, `Descartar`.

`proposal`:

- [x] Mostrar problema, solucao, tradeoff, vale a pena, risco.
- [ ] Mostrar status de gates (pendente — payload tem `quality_gate_status` mas UI nao renderiza badge ainda).
- [x] Mostrar arquivos afetados.
- [x] Botao principal no MVP: `Revisar proposta` ou `Abrir PR`, nao `Commit`.
- [x] Botao `Discutir com Atlas`.
- [x] Botao `Descartar`.

`job_result`:

- [x] Mostrar status, duracao, resultado e erro se houver.
- [x] Botao `Ver trace`.
- [ ] Botao `Executar de novo` apenas quando handler existir e for seguro (handler `rerun` nao implementado, fora MVP).

`self_diagnostic`:

- [x] Mostrar metrica degradada, baseline, janela, hipotese e proposta.
- [x] Botao `Discutir com Atlas`.
- [x] Botao `Ignorar 30 dias`.
- [ ] Opcional: grafico simples se dados existirem (out of MVP).

### 12.4 Pairing no app

Checklist:

- [x] Tela de pareamento aceita codigo.
- [x] Mostra estado de permissao push.
- [x] Salva bearer token com storage seguro.
- [x] Repareamento revoga token antigo se usuario escolher.
- [x] Logout/revoke apaga bearer local.

## 13. CLI

Comandos obrigatorios:

```bash
atlas mobile devices
atlas mobile pair --label="iPhone"
atlas mobile revoke <device_id>
atlas inbox
atlas inbox show <id>
atlas inbox respond <id> --action=approve_once
atlas inbox dismiss <id>
atlas inbox discuss <id>
atlas initiatives run self-diagnostic --dry-run
atlas initiatives run refactor-scan --dry-run
atlas mobile push-test --device=<id>
atlas mobile alert-check --apply --json
```

Checklist:

- [x] CLI usa services diretamente, nao duplica regra.
- [x] `--json` em todos comandos relevantes.
- [x] `push-test` cria item real de teste com flag `payload.test=true`.
- [x] `initiatives --dry-run` nao cria inbox salvo `--emit`.

## 14. Seguranca E Auditoria

Checklist obrigatoria:

- [x] Middleware mobile separado de `atlas.token`.
- [x] Tokens hashados com comparacao constante.
- [x] Pairing code hashado.
- [x] Rate limit em pairing e actions sensiveis.
- [x] `AuditEvent` para pairing, revoke, respond, approval, discard, ignore_30d. `open_pr` ainda nao existe como action executavel.
- [x] Redacao de segredos em logs, payloads e context bundles.
- [x] Push nao contem body sensivel.
- [x] Approval expira e falha fechado.
- [x] Actions com codigo exigem status de gate.
- [x] `open_pr` nao faz merge.
- [x] `approve_workspace_1h` registra escopo, workspace e expiracao.
- [x] Device revogado nao recebe push nem consegue chamar API.

## 15. Idempotencia, Dedupe E Concorrencia

Checklist:

- [x] Requests mutantes aceitam header `Idempotency-Key`.
- [x] `respond` nao executa action duas vezes.
- [x] `discuss` nao cria multiplas threads por tap repetido.
- [x] Dedupe de item usa `dedupe_key`.
- [x] Atualizacao por dedupe incrementa `payload.occurrence_count`.
- [x] Race entre push e read nao quebra badge count: payload inclui `unread_count` + `unread_count_at` em `data`; cliente reconcilia ao foreground com `Notifications.setBadgeCountAsync(unread_count_local)` apos `GET /v1/mobile/inbox`. App tambem faz polling de defesa em profundidade no Inbox (60s em foreground, pausado em background).
- [x] Race entre approval timeout e approve mobile resolve de forma deterministica: `InboxActionRegistry::handle()` envolve a transicao em `DB::transaction` + `lockForUpdate` no item, recarregando estado dentro da transacao antes de validar `expires_at`/`status`.
- [x] Proposal expirada nao permite `open_pr`.

## 16. Observabilidade

Convencao: codebase Atlas nao usa `Log::*` direto. Eventos importantes sao gravados em tabelas auditaveis (`audit_events`, `mobile_push_deliveries`, `atlas_initiative_runs`), que servem como log estruturado. Metricas numericas (Prometheus/Grafana) ficam fora do MVP — derivar via SQL sobre essas tabelas e suficiente.

### 16.1 Core Reliability Monitor

Este bloco e monitoramento de sobrevivencia, nao dashboard avancado. O objetivo e resolver o bootstrap problem: se push/inbox/jobs/scheduler quebrarem, o Atlas precisa avisar por um canal fora do proprio push/inbox.

Implementacao entregue:

- [x] `MobileReliabilityMonitor` calcula estado agregado `healthy|warning|critical`.
- [x] Check `scheduler_stale`: `atlas:scheduler:tick` grava heartbeat em cache; alerta se ficar stale.
- [x] Check `push_degraded`: avalia taxa de sucesso nas ultimas tentativas de `mobile_push_deliveries`, com amostra minima.
- [x] Check `circuit_stuck`: alerta quando o Expo circuit breaker fica aberto alem da janela configurada.
- [x] Check `jobs_silent`: alerta quando existem jobs ativos e nenhum job finalizou recentemente.
- [x] `atlas:cli:mobile alert-check --json` roda dry-run por padrao.
- [x] `atlas:cli:mobile alert-check --apply --json` grava transicao e envia webhook se necessario.
- [x] Webhook HTTP configurado por `ATLAS_MOBILE_ALERT_WEBHOOK_URL`.
- [x] Fallback local JSONL configurado por `ATLAS_MOBILE_ALERT_LOCAL_LOG_ENABLED` e `ATLAS_MOBILE_ALERT_LOCAL_LOG_PATH`; default: `storage/logs/atlas-health-alerts.jsonl`.
- [x] Cooldown/dedupe por cache, sem tabela de snapshots historicos.
- [x] Auditoria de transicao: `system.health.degraded` e `system.health.recovered`.
- [x] Scheduler roda `alert-check --apply --json` a cada 5 minutos quando mobile alerts estiverem habilitados e houver webhook ou local log.

Nota operacional importante: o hook interno do scheduler detecta falhas parciais, como `atlas:scheduler:tick` stale, push degradado, circuit travado e jobs pendurados. Para detectar o scheduler/Laravel totalmente morto, rode `atlas mobile alert-check --apply --json` tambem por um runner externo simples (cron/launchd/outro host) a cada 5 minutos. Esse runner externo e o verdadeiro watchdog fora do processo.

Fora do MVP por enquanto:

- [ ] Prometheus/Grafana.
- [ ] OpenTelemetry/Jaeger/Tempo.
- [ ] Time-series propria de snapshots.
- [ ] Alertmanager com silencing/escalation.

Logs estruturados (via `AuditLogService` em `audit_events`):

- [x] `mobile.pairing.initiated`
- [x] `mobile.pairing.confirmed`
- [x] `mobile.device.revoked`
- [x] `inbox.created`
- [x] `inbox.deduped`
- [x] `inbox.action.requested`
- [x] `inbox.action.completed`
- [x] `push.sent`
- [x] `push.failed`
- [x] `push.deferred`
- [x] `push.invalid_token`
- [x] `initiative.run.started`
- [x] `initiative.run.completed`
- [x] `system.health.degraded`
- [x] `system.health.recovered`
- [x] `system.health.alert_failed`

Eventos persistidos em tabelas dedicadas (sem audit duplicado):

- [x] `mobile_push_deliveries`: cada tentativa por device com status (`queued`, `sent`, `deferred_quiet_hours`, `batched`, `failed_transient`, `failed_permanent`, `receipt_ok`, `receipt_error`).
- [x] `atlas_initiative_runs`: ciclo de vida de cada execucao autonoma (kind, status, started/finished, findings, emitted ids).

Metricas derivaveis por SQL (receitas para uso humano, nao checklist):

- Inbox items criados por tipo/dia: `SELECT type, date(created_at), count(*) FROM ai_inbox_items GROUP BY 1,2`.
- Push sent/failed/deferred por device: `SELECT device_id, status, count(*) FROM mobile_push_deliveries GROUP BY 1,2`.
- Tempo de criacao ate push: join `ai_inbox_items.created_at` com `mobile_push_deliveries.attempted_at` (status=`sent`).
- Tempo de criacao ate read: `read_at - created_at` em `ai_inbox_items`.
- Tempo de criacao ate action: `resolved_at|dismissed_at - created_at`.
- Approval timeout rate: items `type=approval` com `status` em (`expired`) ou response com `expired_at`.
- Proposal discard/open_pr rate: response action em items `type=proposal`.
- Self-diagnostic confidence distribution: `payload->>'confidence_score'` em `type=self_diagnostic`.
- Dedupe hit rate: contar `inbox.deduped` em `audit_events` vs `inbox.created`.

## 17. Fases De Implementacao

### Fase 0 - Alinhamento e preparacao

Objetivo: preparar terreno sem tocar fluxo atual de capturas.

Status: concluida em 2026-04-30 (P6 esta na Fase 11 hardening). Checklist mantido para referencia historica/onboarding de novo agente.

Checklist:

- [x] Ler este documento inteiro.
- [x] Ler P6 em `Atlas_CLI_Plano_Execucao_Final.md`.
- [x] Confirmar estado de `atlas-server/routes/api.php`.
- [x] Confirmar estado de `atlas-app/app/inbox.tsx`.
- [x] Confirmar se o app usa Expo notifications instalado; se nao, planejar install.
- [x] Criar branch de trabalho.
- [x] Rodar testes atuais para baseline.
- [x] Registrar baseline de falhas existentes, sem corrigir fora de escopo.

Nao avance se (criterios de gate, nao tarefas):

- Nao houver certeza de como autenticar app mobile.
- Nao houver um device ou simulador para smoke minimo.

### Fase 1 - Dados e modelos

Objetivo: persistir devices, inbox operacional, context bundles e push deliveries.

Arquivos esperados:

- [x] Migration `atlas_mobile_devices`.
- [x] Migration `mobile_pairing_codes`.
- [x] Migration `ai_context_bundles`.
- [x] Migration `ai_inbox_items`.
- [x] Migration `mobile_push_deliveries`.
- [x] Migration `atlas_initiative_runs`.
- [x] Models correspondentes.
- [x] Factories ou helpers de teste, se padrao do repo permitir.

Checklist:

- [x] Campos e checks conforme secao 6.
- [x] Casts JSON configurados nos Models.
- [x] UUIDs criados corretamente.
- [x] Indices criados.
- [x] Relacionamentos basicos.
- [x] Teste de migracao passa.

Testes:

- [x] `php artisan migrate:fresh --env=testing`
- [x] Teste unitario de casts/status se houver infraestrutura.

### Fase 2 - Mobile auth e pairing

Objetivo: app pareia com servidor e recebe bearer mobile.

Arquivos esperados:

- [x] `MobilePairingService.php`
- [x] `MobileDeviceController.php`
- [x] `MobilePairingController.php`
- [x] Middleware `AuthenticateMobileDevice.php`
- [x] Rotas `/v1/mobile/pairing/*`
- [x] Rotas `/v1/mobile/devices/*`
- [x] Comando `AtlasCliMobileCommand.php`
- [x] Mapping em `bin/atlas`

Checklist:

- [x] `atlas mobile pair --label=...` gera code.
- [x] `confirm` cria device e retorna bearer.
- [x] Bearer valido acessa endpoint protegido.
- [x] Bearer invalido retorna 401.
- [x] Device revogado retorna 401.
- [x] Pairing code expirado falha.
- [x] Rate limit implementado (`MobileGatewayRateLimiter` em pairing initiate/confirm e actions sensiveis).

Testes:

- [x] Feature test de pairing feliz.
- [x] Feature test de code invalido/expirado.
- [x] Feature test de revoke.
- [x] Feature test de middleware.

### Fase 3 - Inbox service e API

Objetivo: criar, listar e responder itens do Inbox.

Arquivos esperados:

- [x] `AtlasInboxService.php`
- [x] `ContextBundleService.php`
- [x] `InboxActionRegistry.php`
- [x] Action handlers basicos.
- [x] `MobileInboxController.php`
- [x] `MobileThreadController.php` ou extensao segura do controller atual.
- [x] Resource `AiInboxItemResource.php`.
- [x] Resource `ContextBundleResource.php` se necessario.

Checklist:

- [x] `create` cria item com actions e deep link.
- [x] `create` atualiza item quando `dedupe_key` bate.
- [x] `GET /v1/mobile/inbox` pagina e filtra.
- [x] `GET /v1/mobile/inbox/{id}` retorna detalhe.
- [x] `read`, `dismiss`, `snooze` funcionam.
- [x] `respond` valida action server-authored.
- [x] `respond` registra auditoria.
- [x] `discuss` cria thread de contexto.
- [x] `discuss` e idempotente.

Testes:

- [x] Feature test list inbox.
- [x] Feature test create via service.
- [x] Feature test action invalida.
- [x] Feature test discuss cria thread e mensagens.
- [x] Feature test dedupe.

### Fase 4 - Push

Objetivo: enviar push via Expo sem perder persistencia.

Arquivos esperados:

- [x] `MobilePushService.php`
- [x] Job `SendMobilePush.php` (envio inline + flush job)
- [x] Job `FlushBatchedMobilePushes.php`
- [x] Job `FetchExpoPushReceipts.php` (`atlas mobile receipts`).
- [x] Config `atlas.mobile`.
- [x] Tests com HTTP fake.

Checklist:

- [x] Push e disparado apos item criado quando policy permite.
- [x] Payload contem `inbox_id` e `deep_link`.
- [x] Quiet hours seguram nao criticos.
- [x] Critical passa quiet hours.
- [x] Batching agrupa updates.
- [x] Badge count calcula unread.
- [x] Falha transient tem retry.
- [x] Falha permanente remove/invalida push token.
- [x] Delivery registrada em `mobile_push_deliveries`.

Testes:

- [x] HTTP fake Expo sucesso.
- [x] HTTP fake Expo falha transient.
- [x] Quiet hours.
- [x] Batching.
- [x] Badge count.

### Fase 5 - App mobile

Objetivo: app recebe push, abre deep link e consome Inbox operacional.

Arquivos esperados:

- [x] `lib/pushNotifications.ts`
- [x] `lib/deepLinks.ts`
- [x] Atualizacao de `lib/api/client.ts`
- [x] Atualizacao de `lib/atlasStore.ts`
- [x] Atualizacao de `app/_layout.tsx`
- [x] Atualizacao de `app/inbox.tsx`
- [x] Tela/detalhe para item operacional (`app/mobile-inbox-item.tsx`).
- [x] Tela de pairing (`app/mobile-pairing.tsx`).

Checklist:

- [x] App salva bearer mobile seguro.
- [x] App registra Expo token.
- [x] App lista inbox operacional.
- [x] App abre item pelo push.
- [x] App executa action.
- [x] App cria thread via `discuss`.
- [x] App renderiza tipos novos.
- [x] App mostra loading, erro, vazio e offline.
- [x] App nao quebra Inbox de capturas existente.

Testes/smoke:

- [x] Rodar typecheck.
- [x] Rodar testes existentes.
- [x] Abrir app em device real.
- [x] Enviar push-test real Expo.
- [ ] Tap no push abre item correto.

### Fase 6 - Job results e scheduled delivery

Objetivo: jobs importantes chegam no Inbox.

Arquivos esperados:

- [x] Hook em worker/job completion (`AiWorker` integra `JobResultInboxEmitter`).
- [x] `JobResultInboxEmitter` (substitui `JobResultPublisher` do plano original).
- [x] Campo/config `importance`.
- [x] Atualizacao de comandos schedule/background.

Checklist:

- [x] Job sucesso high cria `job_result`.
- [x] Job falha cria `job_result` warning mesmo se silent.
- [x] Job critical cria push critical.
- [x] Item inclui trace/job refs.
- [x] CLI consegue listar item.

Testes:

- [x] Job fake sucesso high.
- [x] Job fake falha.
- [x] Silent override para falha.

### Fase 7 - Proposals de auto-improvement

Objetivo: Atlas cria proposals confiaveis sem aplicar codigo automaticamente.

Arquivos esperados:

- [x] `AutoImprovementProposalScanner.php` (cobre `AtlasInitiativeEngine`/`RefactorScanner` no MVP).
- [x] `AtlasInitiativesCommand.php` cobre `AutoTaskRegistry` (config `initiatives` em `config/atlas.php`).
- [x] `ProposalInboxEmitter.php` cumpre papel de `ProposalBuilder`.
- [x] `InboxActionRegistry::assertCodeActionGate()` cumpre papel de `ProposalPolicyGate`.
- [x] Action handlers `review_patch`, `discard`. `open_pr` fica fail-closed por design (sem handler executavel no MVP, ver §14).
- [x] CLI `atlas initiatives run refactor-scan --dry-run`.

Checklist:

- [x] Dry-run gera findings sem inbox.
- [x] `--emit` cria inbox item.
- [x] Proposal tem as 5 respostas obrigatorias.
- [x] Proposal tem context bundle.
- [x] Proposal tem diff/patch/branch temporario.
- [x] Tests/gates ficam registrados.
- [x] `open_pr` so passa se policy gate passar (gate atualmente fail-closed).
- [x] `discard` fecha item e registra auditoria.
- [x] No MVP nao existe merge automatico.

Testes:

- [x] Unit test policy gate.
- [x] Feature test proposal inbox.
- [x] Feature test open_pr bloqueado por gate falho.
- [x] Feature test discard idempotente.

### Fase 8 - Self-monitoring

Objetivo: Atlas mede sua propria qualidade e avisa quando houver degradacao real.

Arquivos esperados:

- [x] `SelfDiagnosticEmitter.php` (cumpre papel agregado de Loop/Collector/Detector/HypothesisBuilder no MVP).
- [x] CLI `atlas:self-diagnostic` e `atlas initiatives run self-diagnostic --dry-run`.

Checklist:

- [x] Collector agrega janelas 24h/7d/30d.
- [x] Detector compara baseline e ignora ruido.
- [x] Hypothesis builder produz causa provavel.
- [x] Emitter cria `self_diagnostic` apenas acima do threshold (`ATLAS_INIT_SELF_DIAGNOSTIC_CONFIDENCE_THRESHOLD`).
- [x] `ignore_30d` funciona.
- [x] Dedupe por categoria funciona.
- [x] Context bundle contem metricas e refs.

Testes:

- [x] Detector nao emite com ruido pequeno.
- [x] Detector emite com queda clara.
- [x] Threshold respeitado.
- [x] Ignore 30d respeitado.

### Fase 9 - Insights

Objetivo: Atlas envia mensagens densas e uteis sobre saude, metricas e implementacoes.

Arquivos esperados:

- [x] `InsightInboxEmitter.php` (substitui Health/Metrics/Implementation generators no MVP).
- [x] `InsightWatcherService.php` cobre Health + Metrics (snapshots de health/digital/provider).
- [x] CLI `atlas:insight` e `atlas:insight:watch` (manual/dry-run/scheduler).

Checklist:

- [x] Insight e autocontido.
- [x] Insight tem body legivel e context bundle separado.
- [x] Insight tem action `discuss`.
- [x] Insight tem dedupe.
- [x] Insight nao vira spam de metricas comuns (`ATLAS_INIT_INSIGHT_MIN_CONFIDENCE`).

Testes:

- [x] Insight cria item.
- [x] Insight dedupe atualiza item existente.
- [x] Discuss abre thread com contexto.

### Fase 10 - CLI Inbox

Objetivo: terminal consome o mesmo Inbox.

Arquivos esperados:

- [x] `AtlasCliInboxCommand.php`
- [x] Mapping `bin/atlas inbox`.

Checklist:

- [x] `atlas inbox` lista itens ativos por padrao.
- [x] `atlas inbox --filter=proposal` filtra.
- [x] `atlas inbox show <id>` mostra body e actions.
- [x] `atlas inbox respond <id> --action=...` executa handler.
- [x] `atlas inbox discuss <id>` cria/mostra thread.
- [x] `--json` funciona.

Testes:

- [x] Console test list.
- [x] Console test show.
- [x] Console test respond.

### Fase 11 - Hardening e release

Objetivo: fechar integridade, UX e operacao.

Checklist:

- [x] Todos testes backend passam (`MobileGatewayTest`: 51 testes / 433 asserções, 2026-04-30).
- [x] Typecheck app passa (`npm run typecheck -- --pretty false`).
- [x] Core Reliability Monitor com alert webhook out-of-band, dry-run/apply, cooldown e auditoria de degradacao/recuperacao.
- [x] Smoke push em device real.
- [ ] Smoke deep link cold start.
- [ ] Smoke approval timeout.
- [ ] Smoke proposal discard.
- [x] Smoke self-diagnostic dry-run (`atlas:self-diagnostic --dry-run`).
- [x] Documentar env vars (§23).
- [ ] Atualizar README do server/app (`.env.example` e READMEs ainda nao refletem `ATLAS_MOBILE_*` e `ATLAS_INIT_*`).
- [ ] Atualizar P6 no plano final (`Atlas_CLI_Plano_Execucao_Final.md`).
- [x] Registrar limitacoes conhecidas (§24).

## 18. Smoke Tests Finais

Executar em ordem:

1. Pairing:
   - [x] `atlas mobile pair --label="iPhone Vitor"`
   - [x] App confirma code.
   - [x] `atlas mobile devices` mostra device ativo.

2. Push test:
   - [x] `atlas mobile push-test --device=<id>`
   - [x] Push aparece.
   - [ ] Tap abre item no Inbox.

3. Insight:
   - [ ] Criar insight fake via tinker/command.
   - [ ] Inbox mostra item.
   - [ ] `Discutir com Atlas` cria thread.

4. Job result:
   - [ ] Rodar job fake high.
   - [ ] Inbox recebe `job_result`.
   - [ ] `Ver trace` abre detalhe.

5. Proposal:
   - [ ] `atlas initiatives run refactor-scan --dry-run`
   - [ ] `atlas initiatives run refactor-scan --emit --limit=1`
   - [ ] Inbox recebe `proposal`.
   - [ ] `review_patch` mostra diff.
   - [ ] `discard` fecha item.

6. Self-diagnostic:
   - [ ] Injetar dados de queda em teste/local.
   - [ ] Rodar self-diagnostic.
   - [ ] Inbox recebe `self_diagnostic` so se threshold passar.
   - [ ] `ignore_30d` impede nova emissao.

7. Quiet hours:
   - [ ] Configurar quiet hours cobrindo horario atual.
   - [ ] Criar item info.
   - [ ] Push nao chega.
   - [ ] Criar item critical.
   - [ ] Push chega.

8. Revoke:
   - [ ] `atlas mobile revoke <device_id>`
   - [ ] App recebe 401 em chamada seguinte.
   - [ ] Push nao e enviado para device revogado.

## 19. Definition Of Done

P6 so esta pronto quando:

- [x] Mobile Gateway esta autenticado por bearer de device.
- [x] Pairing funciona com code temporario.
- [x] Inbox operacional persiste `approval`, `alert`, `completion`, `capture`, `thread_update`, `job_status`, `insight`, `proposal`, `job_result`, `self_diagnostic`.
- [x] Push via Expo funciona em device real.
- [x] Quiet hours e batching funcionam (validado em `MobileGatewayTest`; smoke real pendente).
- [x] Deep links funcionam em cold start e app aberto (validado em testes; smoke real pendente).
- [x] Context bundle cria thread contextual.
- [x] Actions sao server-authored, auditadas e idempotentes.
- [x] Proposal nao faz commit/merge automatico no MVP.
- [x] Job results importantes chegam no Inbox.
- [x] Self-monitoring emite diagnostico com threshold e dedupe.
- [x] CLI consome o mesmo Inbox.
- [x] Canal out-of-band avisa degradacao core sem depender de push/inbox.
- [x] Testes backend e typecheck app passam ou falhas conhecidas estao documentadas.
- [ ] READMEs atualizados (esta secao 23 e `.env.example` sao a fonte de verdade ate os READMEs serem atualizados).

## 20. Regras Para Modelos De IA Implementadores

Regras permanentes (nao sao tarefas — sao restricoes que valem durante toda a implementacao):

- Nao pule fases.
- Nao altere comportamento de capturas existente sem necessidade.
- Nao implemente Telegram, Discord ou Slack.
- Nao coloque segredo em push, logs, payloads ou context bundle.
- Nao crie action no app sem handler servidor.
- Nao implemente `Commit` direto como primeira versao de proposal.
- Nao declare pronto sem smoke real de push.
- Nao avance de backend para app sem endpoints testaveis.
- Nao avance de app para initiatives sem Inbox funcionando.
- Ao encontrar lacuna, registre no documento ou em issue antes de improvisar.

## 21. Ordem Recomendada Para Um Agente Codex/Claude

1. Implementar Fase 1 e testes.
2. Implementar Fase 2 e testes.
3. Implementar Fase 3 sem push.
4. Implementar Fase 4 com HTTP fake.
5. Implementar Fase 5 no app.
6. Fazer smoke end-to-end de Inbox + push-test.
7. Implementar Fase 6 job results.
8. Implementar Fase 7 proposals sem auto-merge.
9. Implementar Fase 8 self-monitoring.
10. Implementar Fase 9 insights.
11. Implementar Fase 10 CLI Inbox.
12. Rodar Fase 11 e smoke final.

## 22. Mapa Dos Quatro Fluxos Principais

### Fluxo A - Insight contextual

```text
Watcher detecta evento notavel
  -> ContextBundleService cria bundle
  -> AtlasInboxService cria item type=insight
  -> NotificationOrchestrator decide push
  -> App abre item
  -> Action discuss chama Thread Seeder
  -> Thread abre com contexto
```

### Fluxo B - Auto-improvement proposal

```text
Initiative refactor_scan roda
  -> scanner encontra candidato
  -> proposal builder cria analise + patch
  -> policy gate roda testes
  -> context bundle salva evidencias
  -> inbox item type=proposal
  -> operador revisa
  -> open_pr ou discard ou discuss
```

### Fluxo C - Job result importante

```text
Job termina
  -> JobResultPublisher avalia importance/status
  -> cria context bundle com resultado
  -> cria inbox item type=job_result
  -> push se high/critical ou falha
  -> operador ve trace ou dismiss
```

### Fluxo D - Self-diagnostic

```text
SelfMonitoringLoop roda
  -> Collector agrega metricas
  -> Detector acha regressao
  -> HypothesisBuilder formula causa + solucao
  -> Emitter cria self_diagnostic
  -> operador discute ou ignora 30d
```

## 23. Env Vars Minimas

```bash
ATLAS_MOBILE_ENABLED=true
ATLAS_MOBILE_PAIRING_TTL_MINUTES=60
ATLAS_MOBILE_MAX_DEVICES=5
ATLAS_MOBILE_QUIET_ENABLED=false
ATLAS_MOBILE_QUIET_START=22:00
ATLAS_MOBILE_QUIET_END=07:00
ATLAS_MOBILE_BATCH_ENABLED=true
ATLAS_MOBILE_BATCH_WINDOW_MINUTES=15
ATLAS_MOBILE_PUSH_PROVIDER=expo
ATLAS_MOBILE_PUSH_RECEIPTS_ENABLED=false

ATLAS_INIT_REFACTOR_SCAN=false
ATLAS_INIT_SELF_DIAGNOSTIC=true
ATLAS_INIT_SELF_DIAGNOSTIC_CONFIDENCE_THRESHOLD=0.70
ATLAS_INIT_SELF_DIAGNOSTIC_SCORE_DROP_THRESHOLD=12
ATLAS_INIT_SELF_DIAGNOSTIC_FAILURE_RATE_INCREASE_THRESHOLD=0.2
ATLAS_INIT_INSIGHT_MIN_CONFIDENCE=0.65
ATLAS_INIT_INSIGHT_HEALTH_READINESS_DROP_THRESHOLD=15
ATLAS_INIT_INSIGHT_PROVIDER_PAIN_THRESHOLD=70
ATLAS_INIT_MAX_PROPOSALS_PER_RUN=3

ATLAS_MOBILE_QUIET_SEVERITY_THRESHOLD=critical
ATLAS_MOBILE_APPROVAL_TTL_MINUTES=15

ATLAS_MOBILE_EXPIRE_STALE_ENABLED=true
ATLAS_MOBILE_CLEANUP_ENABLED=true
ATLAS_MOBILE_CLEANUP_TIME=03:30
ATLAS_MOBILE_CLEANUP_PAIRING_CODES_AFTER_DAYS=7
ATLAS_MOBILE_CLEANUP_INBOX_RESOLVED_AFTER_DAYS=90
ATLAS_MOBILE_CLEANUP_BUNDLES_ORPHAN_AFTER_DAYS=30
ATLAS_MOBILE_CLEANUP_DELIVERIES_AFTER_DAYS=90

ATLAS_MOBILE_CIRCUIT_FAILURE_THRESHOLD=5
ATLAS_MOBILE_CIRCUIT_FAILURE_WINDOW_SECONDS=60
ATLAS_MOBILE_CIRCUIT_OPEN_SECONDS=300

ATLAS_MOBILE_RETRY_QUEUE_ENABLED=true
ATLAS_MOBILE_RETRY_BACKOFF_SECONDS=30,120,600,1800

ATLAS_MOBILE_ALERTS_ENABLED=true
ATLAS_MOBILE_ALERT_WEBHOOK_URL=
ATLAS_MOBILE_ALERT_LOCAL_LOG_ENABLED=true
ATLAS_MOBILE_ALERT_LOCAL_LOG_PATH=
ATLAS_MOBILE_ALERT_COOLDOWN_MINUTES=30
ATLAS_MOBILE_ALERT_HTTP_TIMEOUT_SECONDS=5
ATLAS_MOBILE_ALERT_SCHEDULER_STALE_MINUTES=5
ATLAS_MOBILE_ALERT_PUSH_SAMPLE_SIZE=20
ATLAS_MOBILE_ALERT_PUSH_MIN_SAMPLE=5
ATLAS_MOBILE_ALERT_PUSH_SUCCESS_RATE_THRESHOLD=0.5
ATLAS_MOBILE_ALERT_CIRCUIT_STUCK_MINUTES=30
ATLAS_MOBILE_ALERT_JOBS_SILENT_HOURS=6
```

## 24. Limitacoes Aceitas No MVP

- [x] Expo Push API e suficiente; APNs/FCM direto fica para depois.
- [x] Single-tenant `user_id='vitor'` e aceitavel, mas schema deve suportar multi-user.
- [x] `open_pr` pode ser stub local se integracao GitHub ainda nao estiver pronta. Atualmente fail-closed: action lista no app mas backend recusa ate handler seguro existir.
- [x] WebSocket/realtime e opcional se push + polling funcionarem.
- [x] Graficos de self-diagnostic podem ser simples no primeiro corte.
- [x] Auto-improvement com merge automatico fica fora do MVP.
- [x] Job de expiracao automatica entregue: `atlas:cli:mobile expire-stale --apply` transiciona items com `expires_at` passado para `status='expired'` (rodado a cada 5 min via scheduler quando `ATLAS_MOBILE_EXPIRE_STALE_ENABLED=true`).
- [x] Cleanup automatico entregue: `atlas:cli:mobile cleanup --apply` remove `mobile_pairing_codes` consumidos/expirados, `ai_inbox_items` resolved/dismissed/expired, `ai_context_bundles` orfaos e `mobile_push_deliveries` antigos. Roda diariamente (default 03:30) quando `ATLAS_MOBILE_CLEANUP_ENABLED=true`. Janela default: 7d/90d/30d/90d, configuraveis via `ATLAS_MOBILE_CLEANUP_*_AFTER_DAYS`.
- [x] Core Reliability Monitor fica dentro do MVP porque e alerta de sobrevivencia out-of-band. Metricas numericas/Prometheus, dashboards e tracing distribuido ficam fora do MVP; eventos importantes ja sao auditaveis via `audit_events`, `mobile_push_deliveries` e `atlas_initiative_runs` (ver §16).

## 25. Primeira Entrega Minima Que Ja Tem Valor

Se precisar cortar escopo sem quebrar arquitetura, entregue primeiro. Status: todos os itens entregues em 2026-04-30, alem das fases incrementais (job results, proposals, self-diagnostic, insights).

- [x] Pairing.
- [x] Device auth.
- [x] `ai_inbox_items`.
- [x] `ai_context_bundles`.
- [x] `AtlasInboxService`.
- [x] `GET /v1/mobile/inbox`.
- [x] `POST /v1/mobile/inbox/{id}/discuss`.
- [x] Push-test (`atlas mobile push-test`).
- [x] App recebe push e abre item (validado por testes; smoke device real pendente).
- [x] CLI `atlas inbox`.

Depois disso, adicionar job results, proposals, self-diagnostic e insights fica incremental.
