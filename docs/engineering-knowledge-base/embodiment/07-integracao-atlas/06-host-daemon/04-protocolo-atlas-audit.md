---
id: atlas-embodiment-07-integracao-atlas-06-host-daemon-04-protocolo-atlas-audit
type: engineering_knowledge
title: "04 — Protocolo Atlas-Daemon e Trilha de Auditoria"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 04 — Protocolo Atlas-Daemon e Trilha de Auditoria

> **Propósito:** especificar o **contrato wire-level** entre o Atlas Host Daemon e o Atlas backend (mensagens, schemas, handshake, versionamento) e a **conversão para Evidence Ledger** que sustenta a auditabilidade do subsistema de wake locks. Documento de referência — implementação consulta aqui antes de adicionar mensagem nova.
>
> **Pré-requisitos:** [01-visao-geral.md](./01-visao-geral.md), [../../04-protocolos/01-interaction-envelope.md](../../04-protocolos/01-interaction-envelope.md), [../../04-protocolos/02-eventos-evidence.md](../../04-protocolos/02-eventos-evidence.md), [../../04-protocolos/04-transporte.md](../../04-protocolos/04-transporte.md).
>
> **Fora do escopo:** implementação Swift do daemon (vai pra `02-apis-macos.md`), heurísticas de quando ativar/expirar razões (vai pra `01-visao-geral.md` (catálogo de razões e estados)), comandos físicos para StackChan (vai pra `../../04-protocolos/03-comandos-fisicos.md`).

---

## 1. Princípios do protocolo

Numerados para referência cruzada em decisões e revisões de schema.

| ID | Princípio | Razão |
|----|-----------|-------|
| **P1** | Daemon nunca fala direto com StackChan. Atlas é a ponte canônica. | Mantém o **Decision Receipt** como ponto único de decisão. Daemon é músculo, não córtex. |
| **P2** | Mensagens são JSON line-delimited (uma mensagem por linha terminada em `\n`). | Trivial de parsear, debugável com `cat` ou `nc`, evita framing binário customizado. |
| **P3** | Auth via shared secret no socket file (file mode 0600 + group ownership). | Filesystem permissions são o controle de acesso primário. Secret é defesa em profundidade. |
| **P4** | Daemon não vê conteúdo cognitivo — só metadata operacional. | Booleans, números, strings de razão padronizadas. Conteúdo (áudio, texto, imagem) nunca atravessa o socket. |
| **P5** | Tudo relevante vira Evidence (com sample rate por categoria). | Wake locks afetam comportamento físico do Mac — auditoria ex-post precisa reconstituir o por quê. |
| **P6** | Reconnect com backoff exponencial e wake lock preservado durante grace period. | Atlas pode reiniciar; daemon não pode liberar lock toda vez que perde conexão. |
| **P7** | Schema versionado em semver. Negociação no handshake. | Daemon e Atlas evoluem em ciclos diferentes. Quebra incompatível exige major bump. |

Cada mensagem deste documento referencia explicitamente os princípios que aplica.

---

## 2. Topologia da comunicação

```
┌────────────────┐    Unix socket   ┌──────────────────┐
│ Atlas Host     │ ◄──────────────► │ Atlas backend    │
│ Daemon (Swift) │  /tmp/atlas-     │ (Atlas core)     │
│                │  host.sock       │                  │
└────────────────┘                  └─────┬────────────┘
                                          │ WebSocket
                                          ▼
                                    ┌──────────────────┐
                                    │ StackChan        │
                                    │ (firmware)       │
                                    └──────────────────┘
```

Pontos a notar:

- O daemon **não tem** linha direta para o StackChan. Sinais vindos do robô (presença, wake word) chegam ao Atlas via WebSocket; Atlas decide e despacha para o daemon via Unix socket.
- O caminho inverso (daemon → robô) também passa pelo Atlas: daemon emite `daemon.sleep.imminent`, Atlas decide se quer sinalizar ao corpo, gera command bundle.
- Existe **um único processo** Atlas backend conectado ao socket por vez. Tentativa de segunda conexão é rejeitada com `error: socket_busy`.

---

## 3. Transporte — Unix domain socket

| Aspecto | Valor | Observação |
|---------|-------|------------|
| Path | `/tmp/atlas-host.sock` | Configurável via `~/.config/atlas-host-daemon/daemon.toml` chave `socket_path`. |
| Tipo | `SOCK_STREAM` (AF_UNIX) | Stream-oriented; o framing é JSON line-delimited (P2). |
| File mode | `0600` | Apenas owner lê/escreve. Daemon recria o socket com este mode no startup. |
| Owner | Mesmo UID que roda Atlas backend | Daemon e Atlas devem rodar como o mesmo usuário Unix. |
| Group ownership | UID primário do usuário | Não há multi-user; group é redundante mas explícito para auditoria. |
| Auth handshake | Shared secret em `~/.config/atlas-host-daemon/socket.secret` | Arquivo `0600`. Primeiro `daemon.hello` inclui secret; sem isto, Atlas fecha. |
| Idle timeout | Nenhum | Conexão é long-lived. Heartbeat a cada 30s mantém sanidade aplicacional. |
| Reconnect | Daemon reconnecta com backoff exponencial (P6). | Ver Seção 14. |

### 3.1 Por que Unix socket e não TCP localhost

| Critério | Unix socket | TCP localhost |
|----------|-------------|---------------|
| Controle de acesso | File permissions (kernel-enforced) | Porta exposta — qualquer processo local pode tentar conectar |
| Performance | Levemente menor latência (sem stack TCP) | Aceitável, mas não-zero |
| Auditoria | `lsof /tmp/atlas-host.sock` mostra conectados | Precisa `lsof -i :PORT` |
| Conflito de portas | N/A | Risco com outros serviços |
| Configuração | Path no filesystem | Porta + bind address |

A escolha é Unix socket por padrão. TCP localhost ficou registrado como fallback se algum dia rodar daemon em VM separada (improvável — daemon precisa de acesso direto a IOPMAssertion no host físico).

### 3.2 Auth — shared secret

1. No primeiro install do daemon, o instalador gera 32 bytes aleatórios via `SecRandomCopyBytes`, escreve em `~/.config/atlas-host-daemon/socket.secret` com mode `0400`.
2. Atlas backend lê o mesmo arquivo no startup. Se não conseguir ler, recusa conexões do daemon e loga erro fatal.
3. Daemon envia secret na mensagem `daemon.hello` (campo `auth.secret`, base64).
4. Atlas compara via `timingSafeEqual`. Mismatch → fecha com `error.code: auth_failed`.
5. Rotação: usuário roda `atlas-host-daemon rotate-secret`, daemon gera novo, escreve em ambos os arquivos atomicamente, força reconnect.

⚠️ **DECISÃO PENDENTE:** se vale adicionar challenge-response (HMAC sobre nonce do servidor) sobre o secret estático. Para um daemon local-only com socket 0600, secret estático é defesa adequada — challenge-response virou backlog.

---

## 4. Formato de mensagem

Toda mensagem do protocolo segue o **mesmo envelope canônico**. Apenas `payload` varia por tipo.

### 4.1 Envelope canônico

```json
{
  "protocol_version": "0.1",
  "message_id": "msg-2026-05-05T14:32:11.847Z-7c3e",
  "timestamp": "2026-05-05T14:32:11.847Z",
  "type": "daemon.heartbeat",
  "payload": { }
}
```

| Campo | Tipo | Obrigatório | Descrição |
|-------|------|-------------|-----------|
| `protocol_version` | string (semver) | sim | Versão deste protocolo. Após handshake, ambos os lados usam a versão negociada. |
| `message_id` | string (uuid-ish) | sim | Único na sessão. Formato livre — recomendação: `msg-<iso8601>-<random4>`. |
| `timestamp` | ISO8601 UTC com ms | sim | Momento de geração local. Diferenças de clock entre daemon e Atlas são esperadas e toleradas. |
| `type` | string (enum) | sim | Catálogo nas Seções 6 e 7. |
| `payload` | object | sim (pode ser `{}`) | Schema específico por `type`. |

### 4.2 Framing

- UTF-8.
- Uma mensagem por linha. Terminador é `\n` (LF, não CRLF).
- Newline literal **proibido** dentro do JSON serializado — usar JSON compacto (sem pretty-print).
- Tamanho máximo de mensagem: **64 KiB**. Acima disso, o lado receptor desconecta com `error.code: message_too_large`. (Mensagens deste protocolo são minúsculas — limite é defensivo.)

### 4.3 Exemplo wire

```
{"protocol_version":"0.1","message_id":"msg-2026-05-05T14:32:11.847Z-7c3e","timestamp":"2026-05-05T14:32:11.847Z","type":"daemon.heartbeat","payload":{"state":"AWAKE_LOCKED","active_reasons":[{"source":"presence_active","since":"2026-05-05T14:30:01.000Z","expires_at":"2026-05-05T14:42:11.847Z"}],"current_assertion_id":12345,"battery":{"pct":78,"charging":true},"mac_idle_seconds":42,"uptime_s":7321}}
```

---

## 5. Handshake inicial

Sequência obrigatória logo após o `connect()` do daemon.

```
daemon                                       atlas
  │                                            │
  │   connect(/tmp/atlas-host.sock)            │
  │ ─────────────────────────────────────────► │
  │                                            │
  │   daemon.hello                             │
  │   { protocol_version: "0.1",               │
  │     daemon_version: "0.3.1",               │
  │     auth: { secret: "base64..." } }        │
  │ ─────────────────────────────────────────► │
  │                                            │
  │           valida secret                    │
  │           negocia versão                   │
  │                                            │
  │                  atlas.welcome             │
  │                  { negotiated_protocol_version: "0.1",
  │                    atlas_version: "1.4.2", │
  │                    session_id: "sess-..." }│
  │ ◄───────────────────────────────────────── │
  │                                            │
  │   ──────── canal bidirectional ───────►   │
```

Regras:

1. Daemon **deve** ser quem inicia. Atlas nunca conecta ao daemon.
2. `daemon.hello` deve ser a primeira mensagem. Qualquer outra antes do welcome → Atlas fecha com `error.code: protocol_violation`.
3. Atlas **deve** responder com `atlas.welcome` em até 2s. Timeout do daemon → reconnect.
4. Mismatch de major version → Atlas fecha com `error.code: incompatible_version`. Daemon loga e degrada (ver Seção 13).
5. `session_id` é opaco ao daemon. Serve para correlação no Evidence Ledger.

---

## 6. Mensagens daemon → Atlas

Catálogo completo. Schemas detalhados das mensagens críticas estão na Seção 8.

| Tipo | Quando é enviada | Frequência típica | Princípios |
|------|------------------|-------------------|------------|
| `daemon.hello` | Imediatamente após connect. Único uso por sessão. | 1× por sessão | P3, P7 |
| `daemon.heartbeat` | A cada 30s. Reflete estado completo do daemon. | 2/min | P5 |
| `daemon.wake_lock.acquired` | Primeira razão entra; `IOPMAssertionCreate*` retorna ID. | Por transição IDLE→LOCKED | P5 |
| `daemon.wake_lock.reason_added` | Nova razão entra com lock já ativo. | Esporádica | P5 |
| `daemon.wake_lock.reason_expired` | Razão expirou (TTL ou release explícito); lock continua ativo. | Esporádica | P5 |
| `daemon.wake_lock.released` | Última razão saiu; assertion liberada. | Por transição LOCKED→IDLE | P5 |
| `daemon.sleep.imminent` | `NSWorkspace.willSleep` recebido. | Raro (poucas/dia) | P5 |
| `daemon.sleep.entered` | `NSWorkspace.willSleep` + janela curta sem cancelamento. Geralmente não entregue (daemon dorme junto). | Raro | P5 |
| `daemon.wake.detected` | `NSWorkspace.didWake` recebido. | Por wake do Mac | P5 |
| `daemon.battery.threshold` | Bateria cruza threshold configurado (ex.: <30%, <15%). | Esporádica | P5 |
| `daemon.config.reloaded` | Após `SIGHUP` ou comando `cmd.config_reload`. | Manual | P5 |
| `daemon.fallback.activated` | Daemon entra em modo fallback (ex.: Atlas inalcançável após N reconnects). | Raro | P5 |
| `daemon.error` | Erro interno relevante (falha em criar assertion, IO, etc). | Raro | P5 |

---

## 7. Mensagens Atlas → daemon

| Tipo | Quando é enviada | Princípios |
|------|------------------|------------|
| `atlas.welcome` | Resposta ao `daemon.hello`. | P3, P7 |
| `signal.presence.active` | StackChan reportou presença e Atlas decidiu propagar. Inclui TTL. | P1, P4 |
| `signal.presence.inactive` | Ausência confirmada (timeout no robô + decisão do Atlas). | P1, P4 |
| `signal.pipeline.active` | Decide processando interação. Inclui `expected_duration_ms` se conhecido. | P4 |
| `signal.pipeline.idle` | Pipeline desocupada; razão `pipeline_active` deve sair. | P4 |
| `signal.stream.open` | Stream de áudio (mic, fala) iniciado. Mantém wake lock enquanto stream durar. | P4 |
| `signal.stream.closed` | Stream encerrado. | P4 |
| `signal.ritual.lookahead` | Ritual programado em < 15min. Daemon deve garantir wake. | P4 |
| `signal.recent_interaction` | Interação cognitiva ocorreu. Concede grace period configurável. | P4 |
| `signal.manual_lock.acquire` | Usuário pediu lock explícito (CLI, automation, gesto). | P4 |
| `signal.manual_lock.release` | Liberar lock manual antes do TTL. | P4 |
| `cmd.config_reload` | Daemon recarrega `daemon.toml`. | — |
| `cmd.shutdown_gracefully` | Daemon libera assertion, fecha socket, sai. Raro. | — |
| `cmd.report_state` | Daemon envia heartbeat imediato (debug, replay). | — |

---

## 8. Schemas detalhados

Pelo menos os 6 schemas mais carregados de semântica. Demais mensagens seguem o mesmo padrão de envelope (Seção 4) com payload simples.

### 8.1 `daemon.hello`

```json
{
  "protocol_version": "0.1",
  "message_id": "msg-2026-05-05T14:30:00.001Z-001a",
  "timestamp": "2026-05-05T14:30:00.001Z",
  "type": "daemon.hello",
  "payload": {
    "daemon_version": "0.3.1",
    "supported_protocol_versions": ["0.1"],
    "host": {
      "hostname": "vitor-mbp",
      "os_version": "macOS 26.1",
      "architecture": "arm64",
      "model_identifier": "Mac15,3"
    },
    "auth": {
      "method": "shared_secret",
      "secret": "rH8kF9...base64...Q=="
    },
    "capabilities": [
      "iopm_assertion",
      "pmset_schedule",
      "nsworkspace_sleep_notify",
      "battery_telemetry"
    ]
  }
}
```

| Campo | Descrição |
|-------|-----------|
| `daemon_version` | Versão semver do binário daemon. |
| `supported_protocol_versions` | Lista — Atlas escolhe a maior em comum. |
| `host.*` | Metadados do Mac, vão para Evidence em `host.daemon.connected`. |
| `auth.method` | Atualmente sempre `shared_secret`. Reservado para futuro. |
| `capabilities` | Permite Atlas saber se pode ou não pedir certas ações (ex.: sem `pmset_schedule` não enviar `cmd.set_wake_schedule`). |

### 8.2 `atlas.welcome`

```json
{
  "protocol_version": "0.1",
  "message_id": "msg-2026-05-05T14:30:00.045Z-002b",
  "timestamp": "2026-05-05T14:30:00.045Z",
  "type": "atlas.welcome",
  "payload": {
    "negotiated_protocol_version": "0.1",
    "atlas_version": "1.4.2",
    "session_id": "sess-2026-05-05T14:30:00Z-9f1c",
    "active_signals_replay": [
      {
        "type": "signal.recent_interaction",
        "since": "2026-05-05T14:18:42.000Z"
      }
    ],
    "policy_hints": {
      "heartbeat_interval_s": 30,
      "battery_thresholds_pct": [30, 15, 5],
      "max_grace_after_disconnect_s": 120
    }
  }
}
```

`active_signals_replay` permite o daemon reconstruir razões ativas após reconnect sem que o Atlas precise re-emitir cada signal individualmente. Ver Seção 14.

### 8.3 `daemon.heartbeat`

```json
{
  "protocol_version": "0.1",
  "message_id": "msg-2026-05-05T14:32:11.847Z-7c3e",
  "timestamp": "2026-05-05T14:32:11.847Z",
  "type": "daemon.heartbeat",
  "payload": {
    "state": "AWAKE_LOCKED",
    "active_reasons": [
      {
        "source": "presence_active",
        "since": "2026-05-05T14:30:01.000Z",
        "expires_at": "2026-05-05T14:42:11.847Z",
        "extending_after_inactive": false
      },
      {
        "source": "stream_open",
        "since": "2026-05-05T14:32:08.412Z",
        "expires_at": null,
        "stream_ref": "audio:stkc_main:2026-05-05T14:32:08Z:b1d3"
      }
    ],
    "current_assertion_id": 12345,
    "battery": {
      "pct": 78,
      "charging": true,
      "time_to_full_min": 23,
      "time_to_empty_min": null
    },
    "mac_idle_seconds": 42,
    "uptime_s": 7321,
    "thermal_state": "normal",
    "pmset_wake_schedule": {
      "configured": true,
      "next_wake_at": "2026-05-06T05:55:00.000Z"
    }
  }
}
```

| Campo | Descrição |
|-------|-----------|
| `state` | Enum: `AWAKE_IDLE`, `AWAKE_LOCKED`, `SLEEP_PREP`, `SLEEPING`, `WAKING`, `FALLBACK`. |
| `active_reasons[]` | Lista das razões correntes. Ordenadas por `since` ascendente. |
| `active_reasons[].source` | Enum: `presence_active`, `pipeline_active`, `stream_open`, `ritual_lookahead`, `recent_interaction`, `manual_lock`. |
| `active_reasons[].expires_at` | `null` se sem TTL (ex.: `stream_open` expira no close, não por relógio). |
| `current_assertion_id` | Inteiro retornado por `IOPMAssertionCreate*`. `null` se sem lock. |
| `battery.*` | Snapshot leve. `time_to_*_min` pode ser `null` quando indeterminado. |
| `mac_idle_seconds` | Tempo desde último input HID. Útil para debug de presença. |
| `pmset_wake_schedule.next_wake_at` | Próximo wake agendado, se houver. |

### 8.4 `daemon.wake_lock.acquired`

```json
{
  "protocol_version": "0.1",
  "message_id": "msg-2026-05-05T14:30:01.012Z-1d4a",
  "timestamp": "2026-05-05T14:30:01.012Z",
  "type": "daemon.wake_lock.acquired",
  "payload": {
    "assertion_id": 12345,
    "assertion_type": "PreventUserIdleSystemSleep",
    "macos_assertion_name": "Atlas Host Daemon — presence_active",
    "trigger_reason": {
      "source": "presence_active",
      "since": "2026-05-05T14:30:01.000Z",
      "expires_at": "2026-05-05T14:40:01.000Z",
      "originating_signal_message_id": "msg-2026-05-05T14:30:00.998Z-9a2f"
    },
    "previous_state": "AWAKE_IDLE"
  }
}
```

`originating_signal_message_id` cria a cadeia de causalidade: o Evidence Ledger consegue ligar `signal.presence.active` → `daemon.wake_lock.acquired` → `host.wake_lock.acquired`. Sem isto, auditoria ex-post fica adivinhando.

### 8.5 `signal.presence.active`

```json
{
  "protocol_version": "0.1",
  "message_id": "msg-2026-05-05T14:30:00.998Z-9a2f",
  "timestamp": "2026-05-05T14:30:00.998Z",
  "type": "signal.presence.active",
  "payload": {
    "source_surface_id": "stackchan_main",
    "source_envelope_id": "stkc-2026-05-05T14:30:00.847Z-b8d1",
    "confidence": 0.95,
    "expires_at": "2026-05-05T14:40:00.998Z",
    "extend_grace_after_inactive_min": 10,
    "decision_receipt_id": "dr-2026-05-05T14:30:00.950Z-c4f2"
  }
}
```

`decision_receipt_id` é o ponteiro para o Decision Receipt do Atlas que autorizou o sinal. Daemon não interpreta — apenas registra para auditoria.

### 8.6 `signal.stream.open`

```json
{
  "protocol_version": "0.1",
  "message_id": "msg-2026-05-05T14:32:08.401Z-3e7b",
  "timestamp": "2026-05-05T14:32:08.401Z",
  "type": "signal.stream.open",
  "payload": {
    "stream_ref": "audio:stkc_main:2026-05-05T14:32:08Z:b1d3",
    "stream_kind": "voice_input",
    "expected_duration_ms": null,
    "max_duration_ms": 120000,
    "decision_receipt_id": "dr-2026-05-05T14:32:08.380Z-d5a1"
  }
}
```

`max_duration_ms` é hard cap — se daemon não receber `signal.stream.closed` em 120s, libera a razão `stream_open` automaticamente. Defende contra Atlas crash com stream pendurado.

### 8.7 `signal.manual_lock.acquire`

```json
{
  "protocol_version": "0.1",
  "message_id": "msg-2026-05-05T15:10:42.001Z-aa01",
  "timestamp": "2026-05-05T15:10:42.001Z",
  "type": "signal.manual_lock.acquire",
  "payload": {
    "request_id": "manual-lock-2026-05-05T15:10:42Z",
    "duration_ms": 1800000,
    "until": null,
    "reason_label": "focus_session",
    "originating_actor": "user_cli",
    "decision_receipt_id": "dr-2026-05-05T15:10:41.950Z-ee03"
  }
}
```

`duration_ms` **xor** `until` — exatamente um dos dois deve ser não-nulo. `reason_label` é livre mas curto (≤ 32 chars), aparece no `macos_assertion_name`.

### 8.8 `daemon.error`

```json
{
  "protocol_version": "0.1",
  "message_id": "msg-2026-05-05T14:35:00.010Z-err1",
  "timestamp": "2026-05-05T14:35:00.010Z",
  "type": "daemon.error",
  "payload": {
    "code": "iopm_assertion_failed",
    "severity": "warn",
    "context": {
      "operation": "IOPMAssertionCreateWithName",
      "kIOReturn": -536870174,
      "attempted_assertion_type": "PreventUserIdleSystemSleep"
    },
    "human_message": "IOPMAssertionCreateWithName retornou kIOReturnNotPrivileged"
  }
}
```

Códigos canônicos: `iopm_assertion_failed`, `socket_io_error`, `config_invalid`, `pmset_schedule_failed`, `unknown_signal_type`, `signal_payload_invalid`, `internal_panic`.

---

## 9. Eventos no Evidence Ledger

Atlas converte mensagens do protocolo em eventos do Evidence Ledger. Catálogo de eventos `host.*` e seus schemas resumidos. Para schema completo de evento ver [`../../04-protocolos/02-eventos-evidence.md`](../../04-protocolos/02-eventos-evidence.md).

| Evento | Origem (mensagem) | Sample rate | Payload essencial |
|--------|-------------------|-------------|-------------------|
| `host.daemon.started` | (interno Atlas, ao detectar processo) | 1/1 | `daemon_version`, `pid`, `host.*` |
| `host.daemon.connected` | `daemon.hello` + `atlas.welcome` | 1/1 | `session_id`, `negotiated_protocol_version`, `daemon_version`, `atlas_version`, `capabilities[]` |
| `host.daemon.disconnected` | (detecção EOF/timeout) | 1/1 | `session_id`, `reason` (`peer_eof`, `timeout`, `protocol_error`, `shutdown`), `last_heartbeat_at`, `reconnect_attempts` |
| `host.daemon.heartbeat` | `daemon.heartbeat` | **1/10** | `state`, `active_reasons_count`, `assertion_id`, `battery_pct`, `is_charging`, `mac_idle_s` |
| `host.wake_lock.acquired` | `daemon.wake_lock.acquired` | 1/1 | `assertion_id`, `assertion_type`, `trigger_reason{source,since,expires_at}`, `originating_signal_message_id`, `decision_receipt_id` |
| `host.wake_lock.reason_added` | `daemon.wake_lock.reason_added` | **1/2** | `source`, `since`, `expires_at`, `current_active_count`, `originating_signal_message_id` |
| `host.wake_lock.reason_expired` | `daemon.wake_lock.reason_expired` | **1/2** | `source`, `expired_at`, `cause` (`ttl`, `signal_release`, `max_duration_cap`), `remaining_active_count` |
| `host.wake_lock.released` | `daemon.wake_lock.released` | 1/1 | `assertion_id`, `total_lock_duration_ms`, `final_reason_at_release`, `peak_concurrent_reasons` |
| `host.sleep.imminent` | `daemon.sleep.imminent` | 1/1 | `eta_ms`, `active_reasons_at_sleep[]`, `mac_idle_s` |
| `host.sleep.entered` | `daemon.sleep.entered` | 1/1 | `entered_at`, `last_assertion_id` |
| `host.wake.detected` | `daemon.wake.detected` | 1/1 | `wake_at`, `slept_for_ms`, `wake_cause` (se inferível: `pmset_schedule`, `lid_open`, `network`, `unknown`) |
| `host.battery.threshold_breached` | `daemon.battery.threshold` | 1/1 | `threshold_pct`, `current_pct`, `direction` (`crossing_down`, `crossing_up`), `is_charging` |
| `host.fallback.activated` | `daemon.fallback.activated` | 1/1 | `cause`, `since_disconnect_s`, `current_grace_remaining_s` |
| `host.config.updated` | `daemon.config.reloaded` | 1/1 | `config_hash_before`, `config_hash_after`, `diff_summary` |
| `host.protocol.error` | `daemon.error` ou erro inferido pelo Atlas | 1/1 | `code`, `severity`, `context`, `recoverable` |

### 9.1 Por que sample rate em alguns

- `host.daemon.heartbeat`: 30s × 24h = 2880 eventos/dia. 1/10 → ~288/dia. Suficiente para detectar gaps; cheio é desperdício.
- `host.wake_lock.reason_added` / `reason_expired`: razões transitórias (ex.: `stream_open` abrindo/fechando rápido) podem gerar dezenas/min. 1/2 amostra metade — agregação ainda confiável, custo metade. **Acquired** e **released** sempre 1/1: são os pontos de transição, irrenunciáveis.

### 9.2 Encadeamento de causalidade

Toda cadeia `signal → wake_lock → evidence` mantém `originating_signal_message_id` e `decision_receipt_id` para reconstrução ex-post. Query típica de auditoria:

```
SELECT * FROM evidence
WHERE event = 'host.wake_lock.acquired'
  AND payload.trigger_reason.source = 'manual_lock'
  AND timestamp BETWEEN '2026-05-01' AND '2026-05-06'
JOIN decision_receipts ON payload.decision_receipt_id = id
```

Permite responder "todas as vezes que peguei lock manual semana passada e por quê" sem rebuscar logs.

---

## 10. Coordenação com StackChan via Atlas

Daemon **nunca** fala com StackChan (P1). Cenários onde o robô precisa reagir a eventos do daemon (ex.: Mac vai dormir → cara triste, LED roxo) são orquestrados pelo Atlas.

### 10.1 Sequência canônica — Mac vai dormir

```
1. NSWorkspace.willSleep recebido pelo daemon
2. daemon → Atlas: daemon.sleep.imminent
   { eta_ms: 30000, active_reasons_at_sleep: [...] }

3. Atlas decide: gera Decision Receipt
   "Mac dormindo em 30s; degradar StackChan para modo sleep_companion"

4. Atlas → StackChan (WebSocket, command bundle):
   - system.set_mode { mode: "degraded", reason: "mac_sleeping" }
   - face_render { expression: "sleepy", palette: "degraded" }
   - led.set_pattern { color: "purple", pattern: "breathing", period_ms: 4000 }
   - audio.say { text: null, beep: "soft_descending" }

5. StackChan ACKs cada comando do bundle.

6. Atlas → daemon: cmd.report_state (opcional, para snapshot)
   ou simplesmente nenhuma resposta — daemon prossegue após eta.

7. Mac dorme. Daemon dorme junto com o processo.
   StackChan permanece em modo degradado, autônomo.
```

### 10.2 Sequência inversa — Mac acorda

```
1. NSWorkspace.didWake recebido pelo daemon (provavelmente após pmset wake schedule).
2. daemon → Atlas: daemon.wake.detected
   { wake_at, slept_for_ms, wake_cause: "pmset_schedule" }

3. Atlas decide: gera Decision Receipt
   "Mac voltou; restaurar StackChan ao modo ambient se contexto permitir"

4. Atlas → StackChan:
   - system.set_mode { mode: "ambient" }
   - face_render { expression: "neutral", palette: "default" }
   - led.set_pattern { color: "warm_white", pattern: "calm_pulse" }
   - audio.say { text: null, beep: "soft_ascending" }

5. (se for ritual programado) Atlas dispara fluxo do ritual normalmente.
```

A separação preserva P1 e P4: daemon só vê `eta_ms: 30000`; nada sobre rosto, LED, áudio. A face triste é decisão **do Atlas** sobre o sinal operacional.

---

## 11. Privacy considerations

Espelha P4. Tabela de fronteira:

| Daemon **vê** | Daemon **NÃO vê** |
|--------------|--------------------|
| Booleans (presença sim/não, pipeline ativa sim/não) | Conteúdo de áudio (formas de onda, transcrição) |
| Numbers (TTL em ms, bateria %, idle seconds, ETA de sleep) | Conteúdo de imagem (frames de câmera, snapshots) |
| Strings de razão padronizadas (`presence_active`, `stream_open`, …) | Texto de mensagens do usuário ou do Atlas |
| Stream refs opacos (`audio:stkc_main:...:b1d3`) | Conteúdo apontado pelo stream ref |
| `decision_receipt_id` (opaco) | Corpo do Decision Receipt |
| `reason_label` em `manual_lock` (≤ 32 chars, livre) | Detalhes da motivação cognitiva por trás do manual lock |

### 11.1 macOS assertion name

`pmset -g assertions` lista assertions ativas com nome. O `macos_assertion_name` gerado pelo daemon segue:

```
Atlas Host Daemon — <reason_source>
```

Exemplos: `Atlas Host Daemon — presence_active`, `Atlas Host Daemon — manual_lock`. **Nunca** vaza `reason_label` arbitrário, conteúdo, ou IDs internos. `pmset -g assertions` é ferramenta de auditoria do macOS — qualquer admin local lê — e não deve carregar info contextualizável a usuários ou conversas.

### 11.2 O que vai para o Evidence Ledger

Tudo que cruza o socket. Como o socket só carrega metadata (P4), o Ledger fica auditável sem expor cognição. Eventos `host.*` podem ser exportados para análise externa (CSV, sankey de razões) sem sanitização adicional.

---

## 12. Versionamento do protocolo

`protocol_version` em semver `MAJOR.MINOR.PATCH`.

| Tipo de mudança | Bump | Compatibilidade | Exemplo |
|-----------------|------|-----------------|---------|
| Campo opcional novo em payload existente | PATCH (`0.1.0` → `0.1.1`) | Forward + backward | Adicionar `battery.cycle_count` em heartbeat |
| Tipo de mensagem novo | MINOR (`0.1.x` → `0.2.0`) | Backward (lado novo precisa de fallback) | Introduzir `signal.do_not_disturb` |
| Renomear/remover campo, mudar enum, mudar semântica | MAJOR (`0.x.y` → `1.0.0`) | Quebra | Remover `mac_idle_seconds`, ou trocar `state` enum |

### 12.1 Negociação no handshake

1. Daemon lista todas as versões que sabe falar em `daemon.hello.supported_protocol_versions`.
2. Atlas escolhe a **maior versão em comum** entre o que ele sabe e o que daemon ofereceu.
3. Resposta vai em `atlas.welcome.negotiated_protocol_version`.
4. Sem versão em comum → Atlas envia `error.code: incompatible_version`, fecha. Daemon entra em fallback (Seção 13.4).

Convenção: nunca mudamos major sem deprecar minor anterior por **pelo menos 2 releases** do daemon. Permite janela de overlap onde Atlas suporta n-1 e n.

---

## 13. Reconnect strategy

### 13.1 Detecção de desconexão

Daemon trata como desconexão:
- EOF do socket.
- Erro de IO em write.
- Heartbeat sem resposta de aliveness em 90s (3 ciclos perdidos).

### 13.2 Backoff

Exponencial com jitter:

| Tentativa | Delay base | Jitter | Total típico |
|-----------|-----------|--------|--------------|
| 1 | 1s | ±250ms | 0.75–1.25s |
| 2 | 2s | ±500ms | 1.5–2.5s |
| 3 | 4s | ±1s | 3–5s |
| 4 | 8s | ±2s | 6–10s |
| 5 | 16s | ±4s | 12–20s |
| 6+ | 30s (cap) | ±5s | 25–35s |

Reset do contador após 5 minutos contínuos conectado.

### 13.3 Wake lock durante reconnect — grace period

| Cenário | Comportamento |
|---------|---------------|
| Desconexão com `active_reasons` não vazio | Daemon mantém assertion ativa por `max_grace_after_disconnect_s` (default 120s, configurável). |
| Reconnect dentro do grace | Daemon envia `daemon.heartbeat` imediato; Atlas reenvia signals em `atlas.welcome.active_signals_replay`. Reconciliação. |
| Grace expirou sem reconnect | Daemon avalia: se há razão `manual_lock` ou `ritual_lookahead` (alta prioridade), mantém indefinidamente em modo fallback (Seção 13.4); caso contrário, libera. |
| Razões com TTL absoluto durante reconnect | TTL continua decrementando — TTL não é prorrogado pela desconexão. Se razão expirar durante grace e era a única, libera assertion. |

### 13.4 Fallback mode

Daemon entra em fallback quando reconnect falha por > N ciclos (configurável, default 10) ou quando handshake retorna `incompatible_version`.

Em fallback:

- Mantém políticas conservadoras hard-coded: respeita razões `manual_lock` e `ritual_lookahead` (se anteriormente conhecidas), libera demais.
- Loga em arquivo local `~/.local/state/atlas-host-daemon/fallback.log`.
- Continua tentando reconnect com cap em 30s.
- Emite `daemon.fallback.activated` no próximo reconnect bem-sucedido (com timestamp de entrada).
- Atlas, ao receber `daemon.fallback.activated`, gera `host.fallback.activated` no Ledger.

Princípio: degradar de forma **previsível** em vez de abandonar wake lock e deixar o Mac dormir no meio de um ritual.

---

## 14. Replay e reconciliação pós-reconnect

`atlas.welcome.active_signals_replay` é o mecanismo canônico de reconciliação.

Sequência:

```
1. Daemon reconnecta após disconnect de 45s.
2. Daemon → Atlas: daemon.hello { ... }
3. Atlas computa estado dos signals que estavam ativos:
   - presence_active: ainda válido (TTL não expirado)
   - stream_open: foi fechado durante a janela; NÃO replay
   - recent_interaction: válido com novo `since` (foi atualizado)
4. Atlas → daemon: atlas.welcome
   {
     ...,
     active_signals_replay: [
       { type: "signal.presence.active", ... mesmo payload ... },
       { type: "signal.recent_interaction", ... payload atualizado ... }
     ]
   }
5. Daemon reconcilia:
   - presence_active: já estava na lista local → renova TTL com `expires_at` do replay.
   - stream_open: estava local mas não veio no replay → libera com cause: `replay_omission`.
   - recent_interaction: estava local com `since` mais antigo → atualiza para o do replay.
6. Daemon emite daemon.heartbeat com estado reconciliado.
7. Atlas valida que estados batem; se não, loga `host.protocol.error` com `code: state_mismatch`.
```

`active_signals_replay` é **idempotente** — Atlas pode reenviar a qualquer momento via `cmd.report_state` se desconfiar de divergência.

---

## 15. Anti-padrões

| Anti-padrão | Por que evitar | Padrão correto |
|-------------|----------------|----------------|
| Daemon abrir conexão direta com StackChan | Quebra P1; surge segunda fonte de comando para o robô; impossibilita Decision Receipt unificado | Daemon → Atlas → StackChan, sempre. |
| Enviar conteúdo cognitivo no payload (ex.: transcrição em `signal.stream.open`) | Quebra P4; expande superfície de privacidade do daemon; Ledger vira repositório indesejado de cognição | Apenas `stream_ref` opaco. Conteúdo viaja em outro canal. |
| Pretty-print JSON na wire | Quebra framing line-delimited (P2); newlines internos parsings parciais | JSON compacto. `jq -c .` para debug. |
| Hardcode de TTL no daemon (ex.: presença sempre 10min) | Política vira do daemon; Atlas não consegue ajustar por contexto | Atlas envia `expires_at` em cada signal; daemon respeita. |
| Reabrir handshake no meio da sessão (re-auth) | Fora do contrato; Atlas não saberá lidar | Reconnect completo se confiança quebrada. |
| Logar `decision_receipt_id` como conteúdo (ex.: imprimir corpo do receipt) | Daemon não tem acesso e não deve ter; exposição lateral | Logar apenas o ID opaco. |
| Liberar wake lock instantaneamente no `disconnect` | Lock cai durante restart de Atlas; Mac dorme no meio de ritual | Grace period (Seção 13.3); avaliação por prioridade da razão. |
| Misturar `duration_ms` e `until` em manual_lock | Ambiguidade; um pode contradizer o outro | XOR no schema; lado receptor rejeita com `signal_payload_invalid`. |
| Ledger sem `originating_signal_message_id` em `host.wake_lock.*` | Auditoria perde cadeia de causalidade | Sempre carregar o ID da mensagem que originou. |

---

## 16. Decisões pendentes

| ID | Decisão | Status | Bloqueio |
|----|---------|--------|----------|
| **D-PROT-01** | Adicionar challenge-response sobre o shared secret? | Aberta | Avaliar custo/benefício após MVP rodar 30 dias. Atualmente: secret estático sobre socket 0600. |
| **D-PROT-02** | Limite máximo de `manual_lock.duration_ms`? | Aberta | Sem cap atualmente. Risco: bug em CLI deixa lock por 24h. Proposta: cap em 8h, override exige nova mensagem `signal.manual_lock.extend`. |
| **D-PROT-03** | `daemon.heartbeat` sample 1/10 é o número certo para o Ledger? | Aberta | Precisa medir volume real após 1 semana. Pode ajustar para 1/5 ou 1/20. |
| **D-PROT-04** | Replay de signals é por valor ou por ponteiro (re-issue)? | Decidida (por valor) | Atlas reenvia o payload completo no `active_signals_replay`. Re-issue criaria duplicação no Ledger. Revisitar se payload crescer. |
| **D-PROT-05** | Como o daemon expõe `mac_idle_seconds` em estados de privacy mode? | Aberta | Em `privacy_mode: hardened`, talvez não enviar `mac_idle_seconds` no heartbeat. Aguarda definição do privacy_mode no kernel. |
| **D-PROT-06** | `wake_cause` em `daemon.wake.detected` é confiável? | Aberta | macOS não dá API direta. Inferência por proximidade temporal a `pmset_schedule` é heurística. Pode ficar `unknown` por enquanto. |
| **D-PROT-07** | Devemos suportar múltiplos daemons (ex.: Mac + Mac mini de servidor)? | Aberta | Atual: 1 socket = 1 daemon. Multi-host exige `host_id` no envelope e tabela de afinidade. Adiar até segundo Mac entrar no setup. |

---

## 17. Próximos passos de leitura

1. [`./01-visao-geral.md`](./01-visao-geral.md) — visão do daemon como subsistema (estados, razões, posicionamento na arquitetura).
2. [`./02-apis-macos.md`](./02-apis-macos.md) — código Swift, estrutura de módulos, IOPMAssertion bindings, NSWorkspace observers.
3. [`./01-visao-geral.md`](./01-visao-geral.md) — quando ativar/expirar cada razão, TTLs default, override por usuário.
4. [`../../04-protocolos/02-eventos-evidence.md`](../../04-protocolos/02-eventos-evidence.md) — schema canônico de eventos do Evidence Ledger; este documento estende o catálogo `host.*`.
5. [`../../04-protocolos/04-transporte.md`](../../04-protocolos/04-transporte.md) — transporte WebSocket Atlas ↔ StackChan, complementar ao Unix socket Atlas ↔ daemon.
6. [`../../04-protocolos/03-comandos-fisicos.md`](../../04-protocolos/03-comandos-fisicos.md) — vocabulário de comandos físicos do Atlas para o corpo, usado nas sequências de coordenação (Seção 10).
