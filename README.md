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

## Atlas AI Gateway

O Atlas AI Gateway e a camada propria do Atlas para chamar IA sem prender o sistema a um provider. A fundacao continua sendo Laravel + PostgreSQL + AtlasVault. Claude CLI e Codex CLI sao apenas motores locais plugaveis, usando as contas ja autenticadas no Mac.

## Engineering Blueprint System

O Atlas Server inclui o pipeline project-level do Engineering Blueprint System:

```bash
atlas project blueprint prepare --project-id=<id>
atlas project blueprint create --project-id=<id>
atlas project blueprint validate --project-id=<id>
atlas project blueprint freeze --project-id=<id> --version=<n>
atlas project tasks generate --project-id=<id> --from-blueprint=<n>
atlas qa --task-id=<id>
atlas review --deep --task-id=<id>
atlas db review --task-id=<id>
atlas db explain --task-id=<id>
```

O estado operacional fica em Postgres, com blueprints de projeto versionados,
contracts por task, evidence `manual_qa`/`database_review`, review findings com
confidence/category e promocao de runs reais para Atlas-Bench.

Principios operacionais:

- O app nunca conversa direto com Claude, Codex ou OpenAI.
- O app cria uma interacao em `POST /ai/interactions`.
- O servidor registra `ai_traces` e `ai_jobs`.
- O worker local roda no Mac, chama `claude` ou `codex`, salva tentativas, stdout/stderr, erro, latencia e resposta.
- O painel de configuracoes do app mostra fila, health dos providers e ultimo evento do worker.
- Multi-agente e excecao deliberada; o padrao e um agente/skill por intent.

Para configurar o Atlas CLI no Mac, use sempre o bootstrap. Ele diagnostica Claude/Codex, atualiza o `.env` com backup, instala o launcher `atlas` no PATH local e roda a validacao final automaticamente:

```bash
./bin/atlas bootstrap --dry-run
./bin/atlas bootstrap --refresh-providers --strict
```

Para dar ao Atlas controle governado sobre tudo dentro do usuario `vitorepf` e habilitar o modo operador local:

```bash
./bin/atlas bootstrap --operator-mode --operator-root=/Users/vitorepf --refresh-providers --strict
```

Depois disso, `atlas ask` e `atlas dev` herdam o modo operador por padrao dentro das raizes autorizadas. O workspace ativo continua sendo a pasta onde voce rodou o comando, mas o Atlas pode operar em qualquer caminho dentro de `/Users/vitorepf` quando a tarefa exigir.

Para desenvolvimento diario, rode `atlas dev` sem tarefa para abrir o Dev Cockpit. Ele mostra workspace, provider, permissao, thread, git e skills. Em repos com skills locais, use `atlas skills trust` uma vez para confiar no repo e parar avisos repetidos. Skills locais complementam o Atlas, mas nao substituem skills internas com o mesmo nome.

Para screenshots e analise visual no `atlas dev`, copie a imagem no macOS e peça naturalmente: `analise essa tela`, `corrija esse screenshot`, `o que esta errado nesse print?`. O Atlas detecta a referência visual, anexa a imagem atual do clipboard automaticamente e usa Codex CLI como motor visual. `/paste-image` continua existindo como fallback manual. Para arquivo direto:

```bash
atlas ask --image ~/Desktop/tela.png "analise essa tela"
```

Se o diretorio `~/.local/bin` ainda nao estiver no PATH do shell:

```bash
./bin/atlas bootstrap --write-shell-profile --refresh-providers --strict
```

O bootstrap e o unico fluxo recomendado para configuracao. Edite o `.env` manualmente apenas se precisar sobrescrever defaults avancados:

```bash
ATLAS_AI_ENABLED=true
ATLAS_AI_DEFAULT_PROVIDER=claude_cli
ATLAS_AI_DEFAULT_AGENT=orquestrador
ATLAS_AI_WORKDIR=/Users/vitorepf/Develop/atlas
ATLAS_AI_WORKER_ID=vitors-macbook-pro-1
ATLAS_AI_TIMEOUT_SECONDS=300
ATLAS_AI_MAX_ATTEMPTS=2
ATLAS_AI_RETRY_DELAY_SECONDS=300
ATLAS_AI_CLAUDE_BIN=claude
ATLAS_AI_CODEX_BIN=codex
ATLAS_AI_CODEX_SANDBOX=read-only
```

Inicialize o master prompt e as skills no AtlasVault:

```bash
php artisan atlas:ai:bootstrap-skills
```

Arquivos criados:

- `AtlasVault/00-constituicao/master-prompt-atlas-ai.md`
- `AtlasVault/_skills/orquestrador/SKILL.md`
- `AtlasVault/_skills/vault-curador/SKILL.md`
- `AtlasVault/_skills/blackink/SKILL.md`
- `AtlasVault/_skills/financas/SKILL.md`
- `AtlasVault/_skills/saude/SKILL.md`

O comando `atlas doctor --strict` roda automaticamente no fim do `atlas bootstrap`. Use comandos internos como `atlas setup`, `atlas install` ou `atlas providers` apenas para diagnostico avancado.

Readiness final do produto terminal:

```bash
atlas final --strict
```

Uso real e release:

```bash
atlas dogfood run
atlas dogfood record --scenario=dev_task --provider=codex_cli --result=passed --duration-minutes=90
atlas dogfood report --strict
atlas release --version=v2.0.0
```

`atlas dogfood run` e smoke limpo: valida integracao e apaga artefatos persistidos. `atlas dogfood report --strict` exige uso real nao-smoke antes do release final.

Documentacao operacional unica:

- `docs/atlas-cli-final-product.md`
- `docs/atlas-cli-release-checklist.md`
- `docs/atlas-cli-fair-claude-benchmark.md`

O CI obrigatorio do Atlas CLI fica em `.github/workflows/atlas-cli.yml` e roda testes, `git diff --check`, `atlas final --strict` e o gate estrutural de release usando stubs versionados em `scripts/ci`.

Processar jobs pendentes:

```bash
php artisan atlas:ai:work
# ou uma rodada pequena para diagnostico:
php artisan atlas:ai:work --once --limit=1
```

Enfileirar uma interacao manual:

```bash
php artisan atlas:ai:enqueue "Analise minha prontidao de hoje" --agent=saude --provider=claude_cli
```

Se quiser que o scheduler processe jobs automaticamente, ative `ATLAS_AI_SCHEDULE_WORKER=true` e mantenha o scheduler rodando:

```bash
php artisan schedule:work
```

Para diagnostico sem consumir chamadas de IA, use apenas `atlas:ai:health` ou `GET /ai/providers/status`.

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
- `POST /ai/interactions`
- `GET /ai/interactions`
- `GET /ai/interactions/{id}`
- `POST /ai/interactions/{id}/feedback`
- `GET /ai/jobs`
- `GET /ai/jobs/{id}`
- `POST /ai/jobs/{id}/retry`
- `POST /ai/jobs/{id}/cancel`
- `GET /ai/providers/status`
- `POST /ai/providers/check`
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
