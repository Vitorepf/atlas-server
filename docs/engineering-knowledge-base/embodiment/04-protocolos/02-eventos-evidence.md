# 02 — Eventos no Evidence Ledger

> **Propósito:** catalogar, **com schema**, todos os eventos que o Embodiment produz no Evidence Ledger. Volume é alto; sample rate por tipo é parte do contrato. Sem este catálogo, Ledger vira lixão.
>
> **Pré-requisitos:** [README](../README.md), [02-arquitetura/04-integracao-kernel.md](../02-arquitetura/04-integracao-kernel.md), [01-interaction-envelope.md](01-interaction-envelope.md), [03-comandos-fisicos.md](03-comandos-fisicos.md).
>
> **Fora do escopo:** estrutura interna do Evidence Ledger (assumida como dada), retenção/compactação (decisão da alma).

---

## 1. Princípios

### P1 — Tudo relevante vira Evidence
Princípio do Atlas. Cada interação física, cada comando, cada mudança de estado audível ou observável.

### P2 — Sample rate por tipo
Eventos discretos (touch, command_dispatched): todos persistidos. Eventos contínuos (telemetry, heartbeat): amostrados (1 em N).

### P3 — Imutáveis e ordenados
Eventos são append-only. Timestamp + `surface_id` + tipo permite reconstruir sequência.

### P4 — Estruturados, não logs
Cada evento é JSON com campos definidos. Não é "log de debug". Curator processa, Decide consulta, Memory deriva sinais.

### P5 — Privacy-aware
Eventos de captura registram que captura aconteceu, **não** o conteúdo. Conteúdo (texto STT, imagem) é registrado separadamente com retenção própria.

---

## 2. Estrutura comum de um evento

```yaml
event:
  event_id: ev-2026-05-05T14:32:11.847Z-a8f2     # Único
  timestamp: 2026-05-05T14:32:11.847Z             # Hora real
  surface_id: stackchan_main                      # Origem
  type: surface.envelope.received                 # Tipo (canônico)
  schema_version: "0.1"                           # Schema do payload
  payload: { ... }                                # Específico do tipo
  refs:                                           # Correlação
    operation_envelope_id: op-...
    decision_receipt_id: rcpt-...
    parent_event_id: ev-...
  source: stackchan-adapter                       # Quem emitiu
```

### Campo `refs`
Permite correlacionar eventos: `command.dispatched` → `command.acked` via `command_id`. `envelope.received` → todos os eventos derivados via `parent_event_id`.

---

## 3. Catálogo de eventos por categoria

### 3.1 Surface lifecycle

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `surface.connection.opened` | WS aberto e autenticado | Todo | `session_id`, `firmware_version`, `capabilities`, `client_ip` |
| `surface.connection.closed` | WS fechado normal | Todo | `session_id`, `reason`, `duration_s` |
| `surface.connection.failed` | Falha auth/handshake | Todo | `reason`, `client_ip` |
| `surface.heartbeat.received` | Heartbeat chegou | 1/10 | Telemetria completa |
| `surface.heartbeat.missed` | 90s sem heartbeat | Todo | `last_seen_at`, `expected_at` |
| `surface.degraded.entered` | Modo degraded ativado | Todo | `reason: atlas_unreachable | manual | fault` |
| `surface.degraded.exited` | Saída de degraded | Todo | `duration_s` |
| `surface.firmware.update_offered` | OTA enviada | Todo | `version`, `accepted: bool` |

### 3.2 Modo e privacy

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `surface.mode.changed` | Mudança de modo | Todo | `from`, `to`, `triggered_by`, `triggered_at` |
| `privacy.mode_changed` | Mudança privacy | Todo | `from`, `to`, `trigger`, `physical: bool` |
| `privacy.physical_mute_entered` | Entry hardware mute | Todo | `triggered_by: touch_combo`, `combo_used` |
| `privacy.physical_mute_exited` | Exit hardware mute | Todo | `duration_s`, `triggered_by` |

### 3.3 Captura e consentimento

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `privacy.capture.started` | Captura ativa (mic/cam) | Todo | `modality`, `consent_type`, `expected_duration_ms` |
| `privacy.capture.ended` | Captura encerrou | Todo | `modality`, `actual_duration_ms`, `bytes`, `outcome` |
| `privacy.capture.denied` | Captura bloqueada por policy | Todo | `modality`, `reason`, `attempted_consent` |
| `privacy.capture.indication_failed` | Indicação visual falhou (ex.: LED não acendeu) | Todo | `modality`, `details` |

### 3.4 Envelopes (input)

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `surface.envelope.received` | Envelope chegou ao adapter | Todo | `envelope_id`, `trigger.type`, `modalities[]`, `intent_hint` |
| `surface.envelope.rejected` | Validação falhou | Todo | `reason`, `validation_field` |
| `surface.envelope.translated` | Para Operation Envelope | Todo | `operation_envelope_id` |
| `surface.envelope.deduplicated` | Replay descartado | Sample 1/100 | `envelope_id` |

### 3.5 Comandos (output)

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `surface.command.dispatched` | Comando enviado ao corpo | Todo | `command_id`, `type`, `decision_receipt_id` |
| `surface.command.acked` | ACK recebido | Todo | `command_id`, `state`, `latency_ms` |
| `surface.command.failed` | ACK com state failed | Todo | `command_id`, `reason` |
| `surface.command.expired` | TTL expirou antes ACK | Todo | `command_id`, `dispatched_at`, `ttl_ms` |
| `surface.command.superseded` | Substituído por outro | Todo | `command_id`, `superseded_by` |
| `surface.bundle.dispatched` | Bundle enviado | Todo | `bundle_id`, `command_count`, `execution_mode` |

### 3.6 Triggers físicos específicos

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `physical.wake_word.detected` | Wake word disparou | Todo | `confidence`, `phrase` |
| `physical.touch.tap` | Touch curto | Todo | `pad`, `duration_ms`, `combo` |
| `physical.touch.combo` | Combo reconhecido | Todo | `combo_pads`, `held_ms` |
| `physical.nfc.read` | Tag NFC lida | Todo | `uid`, `ndef_summary` |
| `physical.imu.gesture` | Gesto detectado | Todo | `gesture`, `intensity` |
| `physical.presence.changed` | Presença alterada | Todo | `from`, `to`, `confidence` |
| `physical.proximity.detected` | Aproximação | 1/10 | `distance_cm` |
| `physical.system.boot` | Boot do firmware | Todo | `firmware_version`, `reset_reason` |
| `physical.system.thermal` | Throttling | Todo | `temp_c`, `state` |
| `physical.system.low_battery` | Bateria <20% | Todo | `battery_pct` |

### 3.7 Streaming (audio/image)

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `stream.audio.started` | Início audio stream | Todo | `stream_ref`, `direction`, `encoding` |
| `stream.audio.chunk` | Chunk recebido | **Off** (não persistir cada chunk!) | — |
| `stream.audio.ended` | Stream terminou | Todo | `stream_ref`, `duration_ms`, `bytes`, `chunks` |
| `stream.audio.cancelled` | Cancelado mid-flight | Todo | `stream_ref`, `reason` |
| `stream.tts.started` | TTS começou | Todo | `stream_ref`, `voice_profile` |
| `stream.tts.ended` | TTS terminou | Todo | `stream_ref`, `duration_ms` |
| `stream.tts.interrupted` | Usuario interrompeu | Todo | `stream_ref`, `position_ms` |
| `stream.image.captured` | Imagem capturada | Todo | `stream_ref`, `bytes`, `consent_type` |

**Importante:** chunks individuais **não** são persistidos no Ledger — volume seria absurdo. Persistimos início e fim. Conteúdo de áudio bruto não é guardado por default (ver `05-policies/01-privacidade.md`).

### 3.8 Curator e Self-Improvement (físico)

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `curator.proposal.presented_via_surface` | Proposal mostrada ao usuário | Todo | `proposal_id`, `surface_id`, `presentation_mode` |
| `curator.proposal.approved_via_touch` | Aprovado via touch | Todo | `proposal_id`, `pad`, `duration_ms` |
| `curator.proposal.rejected_via_touch` | Rejeitado via touch | Todo | `proposal_id` |
| `curator.proposal.timeout` | Não respondido em janela | Todo | `proposal_id`, `presentation_duration_ms` |

### 3.9 Quality Gates específicos

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `gate.surface_available.failed` | Surface offline ao tentar comando | Todo | `surface_id`, `command_type` |
| `gate.privacy_compliant.failed` | Comando viola privacy | Todo | `violation`, `command_type` |
| `gate.consent_present.failed` | Falta consent | Todo | `modality`, `expected_consent` |
| `gate.mode_compatible.failed` | Modo bloqueia | Todo | `current_mode`, `command_type` |
| `gate.capability_present.failed` | Capability não declarada | Todo | `missing_capability` |

### 3.10 Repair Loop físico

| Tipo | Quando | Sample rate | Payload essencial |
|---|---|---|---|
| `repair.physical.attempted` | Tentativa de repair | Todo | `command_id`, `attempt`, `strategy` |
| `repair.physical.succeeded` | Repair OK | Todo | `command_id`, `attempts_used` |
| `repair.physical.exhausted` | Limite atingido | Todo | `command_id`, `final_failure_reason` |

---

## 4. Sample rate em detalhe

### Heartbeat — 1 em 10

Razão: heartbeat é a cada 30s = 2880/dia. Sem amostragem: 86k eventos/mês só de heartbeat por surface. Amostragem 1/10 = 8.6k/mês — ainda alto, mas tolerável.

Sample inteligente:
- Sempre persistir se há **mudança significativa** (battery_pct delta > 5, thermal_state mudou, etc.).
- Amostragem random para o resto.

### Proximity — 1 em 10

Pode disparar várias vezes por minuto se mão circulando. Amostragem evita inundação.

### Stream chunks — 0

Chunks individuais nunca vão. Apenas eventos de start/end/cancel.

### Tudo discreto — todos

Touch, NFC, gesture, command — todos persistidos. Volume é manejável (dezenas a centenas/dia por surface).

---

## 5. Tipos de evento por consumidor

Quem usa quais eventos:

| Consumidor | Eventos relevantes |
|---|---|
| **Atlas Decide** | `surface.mode.changed`, `physical.presence.changed`, telemetria recente |
| **Context Builder** | Telemetria, presence, mode, last interactions |
| **Curator** | Padrões de uso (mode time, interaction frequency, proposal outcomes), drift signals |
| **Quality Gates** | Eventos de gate.failed para detectar drift |
| **Self-Improvement** | Curator proposals + outcomes |
| **Audit / Compliance** | Privacy events, command events |
| **Replay / Recovery** | Surface lifecycle, mode changes |
| **Memory** | Interaction patterns, frequency analysis |

---

## 6. Indexação e consulta

Para que Curator e Decide consultem rapidamente:

Indexes naturais:
- Por `surface_id` + tipo + timestamp.
- Por `decision_receipt_id` (rastreabilidade end-to-end).
- Por `command_id` (correlação dispatch ↔ ack).
- Por `envelope_id` (rastreabilidade input).

Queries típicas:
- "Quantas vezes usuário rejeitou proposal nos últimos 7 dias?"
- "Tempo médio entre wake_word e tts_ended (latência percebida)?"
- "Eventos de degraded.entered esta semana?"
- "Padrão de modo por hora do dia (semana inteira)?"

⚠️ **DECISÃO PENDENTE:** estrutura concreta de indexes / database fica com o Atlas core, fora do escopo aqui.

---

## 7. Retenção

Por categoria:

| Categoria | Retenção sugerida |
|---|---|
| Surface lifecycle | 90 dias |
| Modo e privacy | 1 ano (relevante para perfil) |
| Captura/consent | Permanente (audit) |
| Envelopes | 30-90 dias |
| Comandos/ACKs | 30-90 dias |
| Triggers físicos | 90 dias (sample rate já reduzido para alguns) |
| Stream metadata | 30 dias |
| Curator proposals | Permanente (relevante para learning) |
| Quality gates falhas | 1 ano (drift) |

Retenção configurável por policy. Eventos de privacy/consent são sempre permanentes.

---

## 8. Schema dos payloads — exemplos

### `surface.envelope.received`

```yaml
event:
  event_id: ev-...
  timestamp: 2026-05-05T14:32:11.847Z
  surface_id: stackchan_main
  type: surface.envelope.received
  schema_version: "0.1"
  payload:
    envelope_id: stkc-2026-05-05T14:32:11.847Z-a8f2
    schema_version_envelope: "0.1"
    trigger:
      type: wake_word
      confidence: 0.92
    modalities_summary:
      - type: voice
        duration_ms: 1574
    intent_hint:
      category: query
      confidence: 0.3
    context_snapshot:
      user_present: true
      ambient_sound: quiet
      battery_pct: 78
      mode: ambient
      privacy_mode: default
  refs:
    operation_envelope_id: null    # preenchido após translation
```

### `surface.command.acked`

```yaml
event:
  event_id: ev-...
  timestamp: 2026-05-05T14:32:13.612Z
  surface_id: stackchan_main
  type: surface.command.acked
  schema_version: "0.1"
  payload:
    command_id: cmd-...
    command_type: display.show_card
    state: executed
    latency_ms: 112
  refs:
    decision_receipt_id: rcpt-...
    parent_event_id: ev-... (do dispatched)
```

### `privacy.capture.started`

```yaml
event:
  event_id: ev-...
  timestamp: 2026-05-05T14:32:11.950Z
  surface_id: stackchan_main
  type: privacy.capture.started
  schema_version: "0.1"
  payload:
    modality: voice_streaming
    consent_type: implicit_post_wake_word
    expected_duration_ms: 30000
    indication_active: true
    indication_modality: led_red_solid
  refs:
    parent_event_id: ev-... (wake_word.detected)
```

### `physical.touch.tap`

```yaml
event:
  event_id: ev-...
  timestamp: 2026-05-05T15:01:22.103Z
  surface_id: stackchan_main
  type: physical.touch.tap
  schema_version: "0.1"
  payload:
    pad: 1
    duration_ms: 309
    combo: null
    interpretation_hint: approval     # se reflex local classificou
  refs:
    operation_envelope_id: op-... (subsequent)
```

### `surface.mode.changed`

```yaml
event:
  event_id: ev-...
  timestamp: 2026-05-05T11:30:14Z
  surface_id: stackchan_main
  type: surface.mode.changed
  schema_version: "0.1"
  payload:
    from: ambient
    to: dnd
    triggered_by: voice_command
    triggered_via_envelope_id: stkc-...
  refs:
    decision_receipt_id: rcpt-...
```

---

## 9. Privacy nos eventos

Princípio crítico: **eventos do Ledger não vazam conteúdo.**

| Evento | OK persistir | Não persistir |
|---|---|---|
| voice query | que aconteceu, duração, modo | áudio bruto, transcrição completa |
| camera capture | que aconteceu, consent | a imagem |
| touch | que aconteceu | nada sensível |
| privacy mode | mudança | nada |

Conteúdo (transcrição STT, imagem) tem **storage separado** com policies específicas (default: descartar após processamento).

---

## 10. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Persistir cada chunk de áudio | Volume absurdo, privacy ruim |
| Eventos com payload "free-form" (string blob) | Não é estruturado; Curator não consegue ler |
| Mudar schema sem version bump | Replay quebra |
| Persistir transcrição completa por padrão | Privacy violation |
| Sem `refs` (correlação perdida) | Análise impossível |
| Heartbeat 100% persistido | Inunda Ledger |
| Eventos de privacy.* sample rate < 100% | Audit incompleto |

---

## 11. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Sample rate de heartbeat — 1/10 ou ajustar dinamicamente? | Volume |
| ⚠️ Onde fica conteúdo (transcrição, imagem) — Ledger separado? | Privacy storage |
| ⚠️ Retenção configurável por categoria — UI? | Admin |
| ⚠️ Index strategy concreta (DB) | Performance Curator |
| ⚠️ Sample rate de proximity — confirmação | Volume |

---

## Próximos passos de leitura

- `05-streaming.md` — quais eventos rodeiam streaming.
- `02-arquitetura/04-integracao-kernel.md` — quem consome o quê.
- `07-integracao-atlas/04-curator-proposals.md` — como Curator usa esses eventos (a escrever).
