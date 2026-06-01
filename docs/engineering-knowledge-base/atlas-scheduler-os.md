---
id: atlas-scheduler-os
type: engineering_knowledge
title: Atlas Scheduler OS
status: active
category: architecture
priority: 95
summary: Contrato canonico do heartbeat local que prova se o Laravel scheduler esta vivo para loops Patamar 4.
tags:
  - atlas-ai
  - scheduler
  - patamar-4
  - heartbeat
capabilities:
  - atlas_scheduler_os
  - patamar4_scheduler_health
decisions:
  - Scheduler OS e probe local-first de liveness do Laravel scheduler; nao executa provider, benchmark ou rivals.
  - Silent alarm deve ser sinal explicito quando heartbeat falta ou ultrapassa threshold.
maintenance:
  - Atualizar quando comandos scheduler, heartbeat JSONL, launchd install ou Patamar 4 state mudarem.
  - Rodar docs-health e testes focados de scheduler apos alterar.
related_paths:
  - app/Services/Ai/Patamar4/AtlasSchedulerHealthService.php
  - app/Console/Commands/AtlasSchedulerHeartbeatCommand.php
  - app/Console/Commands/AtlasSchedulerStatusCommand.php
  - app/Console/Commands/AtlasSchedulerInstallLaunchdCommand.php
  - tests/Unit/Ai/Patamar4/AtlasSchedulerHealthServiceTest.php
  - tests/Feature/Console/AtlasSchedulerHeartbeatCommandTest.php
  - tests/Feature/Http/AtlasPatamar4StateControllerTest.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-scheduler-os
graph_title: Atlas Scheduler OS
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-cognition-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Scheduler OS
canonical_name: Atlas Scheduler OS
technical_name: atlas-scheduler-os
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-scheduler-os.md

owner: patamar4

repo_paths:
  - docs/engineering-knowledge-base/atlas-scheduler-os.md

allowed_changes:
  - Atualizar este doc quando codigo, testes, scheduler local ou Patamar 4 state mudarem.

forbidden_changes:
  - Declarar autonomia 24/7 sem heartbeat, status command e testes verificaveis.

depends_on:
  - atlas-cognition-operating-system

flows_to:
  - atlas-patamar-4-substrato-cognitivo-autonomo

unlocks:
  - patamar4-scheduler-liveness

governs:
  - patamar4

evidence:
  - docs/engineering-knowledge-base/atlas-scheduler-os.md
  - app/Services/Ai/Patamar4/AtlasSchedulerHealthService.php
evidence_refs:
  - symbol: AtlasSchedulerHealthService
  - command: atlas:scheduler:tick
  - test: AtlasSchedulerHealthServiceTest

required_tests:
  - "php artisan test tests/Unit/Ai/Patamar4/AtlasSchedulerHealthServiceTest.php tests/Feature/Console/AtlasSchedulerHeartbeatCommandTest.php tests/Feature/Http/AtlasPatamar4StateControllerTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true
risk_level: medium
visual_tags:
  - scheduler
  - patamar4
  - local-first
ai_entrypoints:
  - Leia invariants, operacao e testes canon antes de alterar scheduler ou claims 24/7.
ai_usage_notes:
  - Trate launchd como instalacao local do operador; nao declare ativo sem heartbeat recente.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Cron silencioso parece autonomia ativa sem executar schedule:run.
observability_signals:
  - atlas.scheduler.status.v1 silent_alarm
next_actions:
  - Manter doc, testes e state envelope sincronizados com Scheduler OS runtime.
---
# Atlas Scheduler OS — Patamar 4 Cron 24/7 (canon)

> **Status:** `building`
> **Group:** patamar4 / autonomous loop
> **ACOS subsystem:** `ASOS — Atlas Scheduler OS`
> **Authority:** docs canônicos governam implementação. Postgres/Code Intelligence/chat são read-models.
> **Claim policy:** provider-safe. Nada de benchmark/rivals/superiority/external_rivals. Local-first.

## 1. Razão de existir

O loop autônomo Atlas Patamar 4 — reconciliation, nightly counterfactuals, ADML sweep, swarm outcome ledger, cron `*/15min` — só funciona se o **scheduler Laravel está vivo no Mac do operador**. Sem cron, `schedule:run` nunca dispara; o loop morre silencioso. **Atlas Scheduler OS** é a probe canônica que garante 24/7.

## 2. Arquitetura

Três peças locais (zero dependência externa):

1. **`AtlasSchedulerHealthService`** (`app/Services/Ai/Patamar4/AtlasSchedulerHealthService.php`)
   - `recordHeartbeat(actor)` — append-only JSONL `storage/atlas/scheduler/heartbeat.jsonl`. Cada linha = sha256.
   - `status(threshold)` — calcula `age_seconds`, `silent_alarm` boolean.
   - `lastHeartbeat()`, `listHeartbeats(tail)` — read-only.
   - Schemas: `atlas.scheduler.heartbeat.v1`, `atlas.scheduler.status.v1`.

2. **3 comandos artisan**
   - `atlas:scheduler:heartbeat` — invocado pelo Laravel scheduler a cada minuto.
   - `atlas:scheduler:status [--threshold=300] [--strict] [--json]` — operador inspeciona; `--strict` exit `3` se silent.
   - `atlas:scheduler:install-launchd [--uninstall] [--dry-run] [--label=com.atlas.scheduler]` — escreve `~/Library/LaunchAgents/com.atlas.scheduler.plist`, faz `launchctl load`.

3. **Wiring `bootstrap/app.php`**
   ```php
   $schedule->command('atlas:scheduler:heartbeat')->everyMinute()
       ->withoutOverlapping()->runInBackground();
   ```

4. **Integração `AtlasPatamar4StateService`** — campo `scheduler` no envelope `/atlas/patamar4/state`:
   ```json
   {
     "schema_version": "atlas.scheduler.status.v1",
     "last_heartbeat_at": "2026-05-26T03:04:00+00:00",
     "age_seconds": 47,
     "silent_alarm": false,
     "silent_threshold_seconds": 300,
     "heartbeat_count": 1440,
     "claim_policy": { ... }
   }
   ```

## 3. launchd plist canon

Gerado por `atlas:scheduler:install-launchd`. Roda `php artisan schedule:run` a cada `StartInterval=60` s, `RunAtLoad=true`, logs em `storage/atlas/scheduler/launchd.{out,err}.log`.

`install-launchd` é idempotente: `launchctl unload` antes do `load`, sobrescreve o plist.

## 4. Operação

Operador roda **uma vez no Mac local**:

```
php artisan atlas:scheduler:install-launchd
```

A partir daí, todo minuto:
- launchd dispara `schedule:run`
- `schedule:run` dispara `atlas:scheduler:heartbeat` + todos os outros jobs canon (reconciliation `*/15min`, counterfactuals 03:00, telemetry, etc.)
- heartbeat.jsonl ganha 1 linha
- `/atlas/patamar4/state.scheduler.silent_alarm` permanece `false`

Se silenciar > 5 min, `silent_alarm=true` aparece no state. UI e CLI mostram. Operador re-executa `install-launchd`.

## 5. Invariants

1. Append-only JSONL com sha256 por record.
2. Nenhuma chamada externa — local-first absoluto.
3. `claim_policy` hardcoded provider-safe.
4. Plist nunca grava credenciais.
5. `install-launchd --dry-run` não escreve nem carrega.
6. Status `silent_alarm` = `true` quando `age_seconds > threshold` OU heartbeat ausente.
7. Read-only para Patamar 4 state (zero side-effect).

## 6. Filtro 5 perguntas

1. **Wrapper composto?** Sim — sem cron vivo, todo o loop autônomo é fake. Esta probe é foundation.
2. **Antifrágil?** Sim — falha do launchd vira sinal silent_alarm, não silêncio.
3. **Linguagem natural fim-a-fim?** Sim — operador roda 1 comando e Atlas opera 24/7.
4. **Destrava função/empresa?** Sim — sem 24/7 não há autonomia empresarial.
5. **Local-first?** Sim — launchd Mac, JSONL local, zero rede.

## 7. Testes canon

- `tests/Unit/Ai/Patamar4/AtlasSchedulerHealthServiceTest.php`
- `tests/Feature/Console/AtlasSchedulerHeartbeatCommandTest.php`
- `tests/Feature/Console/AtlasSchedulerStatusCommandTest.php`
- `tests/Feature/Console/AtlasSchedulerInstallLaunchdCommandTest.php`
- `tests/Feature/Http/AtlasPatamar4StateControllerTest.php` — assert `scheduler` field.

## 8. Cross-references

- ACOS Scorecard subsystem `ASOS` registrado em `AtlasCognitionScoreCardService`.
- Patamar 4 state envelope: `atlas-patamar4-state.md`.
- launchd Mac convention (StartInterval=60).

## Resumo

Atlas Scheduler OS prova liveness local do Laravel scheduler para evitar que
loops Patamar 4 parecam vivos quando `schedule:run` nao esta executando.

## Papel no Atlas

E uma probe local-first de infraestrutura operacional, nao um executor de IA,
provider, rivals, benchmark ou fonte autoral de verdade.

## Onde Se Encaixa

Fica entre launchd, Laravel scheduler, Patamar 4 state e ACOS scorecard,
publicando heartbeat/status para superficies de observabilidade.

## Contratos

Heartbeat e append-only JSONL com hash; status e read-only; `--strict` deve
falhar quando `silent_alarm=true`; plist nao grava credenciais.

## Fluxo

Operador instala launchd localmente; launchd chama `schedule:run`; scheduler
executa heartbeat; state/API/CLI exibem idade do heartbeat e silent alarm.

## Regras para IA

Nao declarar autonomia 24/7 sem heartbeat recente. Nao substituir este scheduler
por fluxo paralelo. Nao instalar launchd sem comando explicito do operador.

## Escopo de Implementacao

Mudancas pertencem ao service de scheduler health, comandos artisan scheduler,
bootstrap scheduler wiring, Patamar 4 state e testes focados.

## Dependencias

Depende de Laravel scheduler, launchd no Mac local do operador, storage JSONL
local e Patamar 4 state como superficie read-only.

## Evidencias

Evidencia valida: testes focados, heartbeat JSONL, `atlas:scheduler:status`,
Patamar 4 state com `atlas.scheduler.status.v1` e docs-health verde.

## Riscos

Risco principal: silencio operacional. Sem heartbeat, loops podem parecer
autonomos enquanto nenhum cron real esta rodando.

## Exemplos

Se `age_seconds > threshold`, `atlas:scheduler:status --strict --json` deve sair
nao-zero e expor `silent_alarm=true`.

## Proximas Acoes

Adicionar testes para status/install-launchd se ainda ausentes e manter ACOS
scorecard coerente com pipeline real do Scheduler OS.
