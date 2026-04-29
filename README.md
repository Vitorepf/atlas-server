# Atlas Server

Backend V1 do Atlas em Laravel, PostgreSQL 16 e storage local. O servidor e single-tenant, protegido por `X-Atlas-Token`, e foi desenhado para receber sync de um app local-first.

## Escopo V1

- Capturas de texto, audio e imagem
- Check-ins de estado com energia e mood de 1 a 5
- GPS no momento da captura
- Sinais passivos vindos do app iPhone: HealthKit e Rize.io
- Apple Watch: Action Button envia audio pelo app; complication le a missao do dia
- Transcricao local com whisper.cpp
- Rede privada via Tailscale
- Token estatico para autenticacao

## Stack

- Laravel 13 / PHP 8.3+
- PostgreSQL 16 com `pgvector`
- Queue database do Laravel
- Storage local por hash em `ATLAS_STORAGE_PATH`
- whisper.cpp no worker de transcricao

## Setup local

```bash
composer install
cp .env.example .env
php artisan key:generate
# edite ATLAS_TOKEN no .env
docker compose up -d db
php artisan migrate
php artisan serve --host=0.0.0.0 --port=3737
```

Para Sensor 4 / Rize, configure no `.env`:

```bash
RIZE_API_KEY=<sua-chave-da-rize>
RIZE_WEBHOOK_SECRET=
RIZE_GRAPHQL_ENDPOINT=https://api.rize.io/api/v1/graphql
RIZE_TIMEZONE=America/Sao_Paulo
RIZE_SYNC_ENABLED=true
RIZE_SYNC_LOOKBACK_DAYS=2
RIZE_SYNC_PAGE_SIZE=100
```

`RIZE_API_KEY` fica apenas no servidor. O app iPhone nunca recebe essa chave. O caminho principal do Atlas e import por API/pull; webhook e opcional e fica desativado se `RIZE_WEBHOOK_SECRET` estiver vazio.

Comandos Rize:

```bash
php artisan atlas:rize:inspect
php artisan atlas:rize:sync --days=30 --dry-run
php artisan atlas:rize:sync --days=30
```

O sync automatico roda pelo Laravel Scheduler quando `RIZE_SYNC_ENABLED=true` e `RIZE_API_KEY` esta configurada. Mantenha um scheduler ativo no servidor:

```bash
php artisan schedule:work
```

Se o schema GraphQL da sua conta Rize usar outro nome de campo para sessoes, rode `atlas:rize:inspect` e aponte uma query propria:

```bash
RIZE_SESSIONS_QUERY_PATH=/var/atlas/rize/sessions.graphql
RIZE_SESSIONS_ROOT_PATH=sessions.nodes
RIZE_SESSIONS_PAGE_INFO_PATH=sessions.pageInfo
```

Em outro terminal, para processar transcricoes:

```bash
php artisan queue:work --queue=transcription,default --tries=3 --timeout=600
```

## Docker

```bash
cp .env.example .env
composer install
php artisan key:generate
# edite ATLAS_TOKEN no .env
docker compose up -d --build
```

Servicos:

| Servico | Porta | Funcao |
| --- | --- | --- |
| `db` | `5433 -> 5432` | PostgreSQL + pgvector |
| `backend` | `3737` | API Laravel |
| `queue` | - | worker de transcricao |
| `scheduler` | - | coletas recorrentes, incluindo Rize API |

## Endpoints

Publico:

- `GET /health`

Protegidos por `X-Atlas-Token`:

- `POST /captures`
- `GET /captures`
- `GET /captures/{id}`
- `PATCH /captures/{id}`
- `DELETE /captures/{id}`
- `GET /captures/{id}/file`
- `GET /captures/{id}/transcription`
- `POST /checkins`
- `GET /checkins`
- `PATCH /checkins/{id}`
- `DELETE /checkins/{id}`
- `POST /passive-signals`
- `GET /passive-signals`
- `PATCH /passive-signals/{id}`
- `DELETE /passive-signals/{id}`
- `GET /digital-category-mappings`
- `POST /digital-category-mappings`
- `PATCH /digital-category-mappings/{id}`
- `DELETE /digital-category-mappings/{id}`
- `GET /digital-sessions`
- `POST /digital-sessions`
- `PATCH /digital-sessions/{id}`
- `DELETE /digital-sessions/{id}`
- `GET /digital-activity-snapshots`
- `POST /digital-activity-snapshots`
- `POST /digital-activity-snapshots/rebuild`
- `GET /procrastination-events`
- `POST /procrastination-events`
- `GET /mission/today`
- `PUT /mission/today`
- `POST /sync`

Integracao Rize:

- Pull API: `php artisan atlas:rize:sync --days=30`
- Inspect API: `php artisan atlas:rize:inspect`
- Webhook opcional: `POST /integrations/rize/webhook`

O webhook aceita `X-Rize-Webhook-Secret: $RIZE_WEBHOOK_SECRET` ou `?secret=$RIZE_WEBHOOK_SECRET`. Em ambiente local tambem aceita `X-Atlas-Token` quando `RIZE_WEBHOOK_SECRET` nao esta configurado. Para V1.5, a API/pull e o caminho recomendado porque funciona com servidor privado via Tailscale.

Audio/foto devem usar `POST /captures` como `multipart/form-data`, com arquivo no campo `file`. O endpoint `/sync` aceita apenas capturas `text` em JSON; binarios continuam pelo endpoint multipart.

Sinais passivos usam um formato generico: `source=healthkit|rize`, `signal_type`, valor numerico ou textual, janela temporal e `metadata`. Exemplos: `source=healthkit, signal_type=hrv_ms, value_numeric=62, unit=ms`; ou `source=rize, signal_type=activity_category, value_text=Software Development`.

Sensor 4 usa duas camadas: `digital_sessions` para eventos granulares por app/site/projeto e `digital_activity_snapshots` para agregados diarios correlacionaveis com saude, check-ins, capturas e missoes.

## Exemplos

```bash
curl http://localhost:3737/health
```

```bash
curl -X POST http://localhost:3737/checkins \
  -H "X-Atlas-Token: $ATLAS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": "7a8d2456-639d-4f4b-8b0a-427fc7f1dc21",
    "state": "focused",
    "energy_level": 4,
    "mood_level": 4,
    "note": "inicio de bloco profundo",
    "recorded_at": "2026-04-27T14:00:00.000Z",
    "recorded_timezone": "America/Sao_Paulo",
    "metadata": {}
  }'
```

```bash
curl -X POST http://localhost:3737/captures \
  -H "X-Atlas-Token: $ATLAS_TOKEN" \
  -F client_id=2c9b8c8f-5737-4ef7-b1cb-33f10330d2e4 \
  -F kind=audio \
  -F domain=blackink \
  -F captured_at=2026-04-27T14:30:00.000Z \
  -F captured_timezone=America/Sao_Paulo \
  -F content_duration_ms=23500 \
  -F metadata='{}' \
  -F file=@/path/to/audio.m4a
```

```bash
curl -X POST http://localhost:3737/passive-signals \
  -H "X-Atlas-Token: $ATLAS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "client_id": "b0bb1e0c-fb29-4ddd-9d16-458a364f8c20",
    "source": "healthkit",
    "signal_type": "hrv_ms",
    "value_numeric": 62,
    "unit": "ms",
    "started_at": "2026-04-27T12:00:00.000Z",
    "recorded_timezone": "America/Sao_Paulo",
    "metadata": {}
  }'
```

```bash
curl -X PUT http://localhost:3737/mission/today \
  -H "X-Atlas-Token: $ATLAS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "timezone": "America/Sao_Paulo",
    "title": "Fechar uma captura de alto valor",
    "detail": "Registrar contexto, energia e proximo passo antes do meio-dia.",
    "status": "active",
    "metadata": {}
  }'
```

## Verificacao

```bash
php artisan route:list
php artisan test
./vendor/bin/pint --test
```
