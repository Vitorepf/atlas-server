# 05 — Monitoramento (firmware)

> **Propósito:** especificar como o **firmware reporta seu estado** para a alma — telemetria contínua, logs locais (rotacionais), crash dumps, health checks. Sem monitoramento, modo degradado vira mistério; com monitoramento, problemas são detectáveis antes de virarem incidente.
>
> **Pré-requisitos:** [README](../README.md), [01-escolha-stack.md](01-escolha-stack.md), [02-reflex-layer.md](02-reflex-layer.md), [04-protocolos/04-transporte.md](../04-protocolos/04-transporte.md), [04-protocolos/02-eventos-evidence.md](../04-protocolos/02-eventos-evidence.md).
>
> **Fora do escopo:** observabilidade do Atlas core (assumido como dado).

---

## 1. Princípios

### P1 — Telemetria barata é sempre on
Métricas que não vazam dado pessoal (battery, thermal, uptime, free RAM) são sempre coletadas e enviadas. Custo: bytes/min — irrelevante.

### P2 — Logs locais para o que não pode ir online
Crashes, eventos rápidos durante reconexão, debug de modo degradado. Persistidos em microSD com rotação.

### P3 — Sample rate ajustada
Telemetria em heartbeat: 30s. Mas alma só persiste 1 em 10 no Ledger (já documentado em `04-protocolos/02-eventos-evidence.md`). Mudanças significativas sempre persistidas.

### P4 — Privacy aware
Logs locais nunca contêm conteúdo de áudio/imagem/transcrição. Apenas eventos estruturais.

### P5 — Diagnóstico assistível
Quando algo dá errado, deve ser **possível diagnosticar remotamente** via Evidence Ledger consultando logs locais quando reconectar.

---

## 2. Telemetria — payload de heartbeat

A cada 30s, corpo envia:

```json
{
  "type": "heartbeat",
  "from": "stackchan_main",
  "at": "2026-05-05T08:42:31Z",
  "telemetry": {
    "uptime_s": 13892,
    "firmware_version": "fw-0.1.0",
    "assets_version": "assets-0.1.0",
    "battery": {
      "pct": 78,
      "is_charging": true,
      "voltage_mv": 4012,
      "discharge_rate_mah": -12   // negative = charging
    },
    "thermal": {
      "state": "normal",          // normal | warm | throttling
      "cpu_temp_c": 47
    },
    "wifi": {
      "rssi_dbm": -55,
      "ssid_hash": "abc123",      // hash, não SSID limpo
      "reconnects_since_boot": 0
    },
    "memory": {
      "free_psram_kb": 3812,
      "free_heap_kb": 142,
      "min_free_heap_kb": 98      // mínimo histórico (detecção de leak)
    },
    "storage": {
      "littlefs_used_kb": 412,
      "littlefs_total_kb": 9000,
      "microsd_present": true,
      "microsd_used_pct": 18
    },
    "queue": {
      "command_queue_depth": 0,
      "envelope_queue_depth": 0,
      "stream_open_count": 0
    },
    "voice": {
      "active_voice_profile": "stackchan_default",
      "last_voice_profile_used": "stackchan_default",
      "voice_fallback_used_since_boot": 0,
      "tts_buffer_underrun_since_boot": 0,
      "last_tts_first_audio_ms": 420
    },
    "modes": {
      "current_mode": "ambient",
      "privacy_mode": "default"
    },
    "position": {
      "pan_deg": 0,
      "tilt_deg": 0
    },
    "stats": {
      "envelopes_sent_since_boot": 142,
      "commands_received_since_boot": 287,
      "wake_word_detections_since_boot": 18,
      "wake_word_false_positives_since_boot": 1   // se Curator marcou
    }
  }
}
```

Tamanho típico: ~600 bytes JSON. Trivial em LAN.

---

## 3. Logs locais

### 3.1 Política de log

| Nível | Usar para |
|---|---|
| `ERROR` | Falhas que afetam funcionamento |
| `WARN` | Anomalias recuperáveis |
| `INFO` | Eventos significativos (mode change, command processed, etc.) |
| `DEBUG` | Útil em desenvolvimento; não em release |

Default em release: `INFO+`. Debug compilado out.

### 3.2 Localização

- **Serial UART** (115200 baud): durante desenvolvimento.
- **microSD** (rotacional, ≤500KB): persistido em production.
- **Ring buffer em RAM** (~50KB): últimos eventos sempre disponíveis para crash dumps.

### 3.3 Formato

Linha estruturada (parseable):

```
2026-05-05T08:42:31.150Z INFO  [transport] WebSocket connected to wss://atlas.local:8443
2026-05-05T08:42:31.421Z INFO  [reflex] Wake word detected (conf=0.92)
2026-05-05T08:42:33.123Z WARN  [audio] Buffer underrun on tts stream tts:atlas:...
2026-05-05T08:42:35.001Z ERROR [servo] Target not reached for cmd-... (timeout)
```

### 3.4 Rotação

- Arquivo `current.log` cresce até 100KB.
- Quando atinge: rotaciona para `1.log`, `2.log`, ... `5.log`.
- Limite total: 500KB.
- Mais antigo apaga.

---

## 4. Crash dumps

### 4.1 Trigger

ESP32 emite crash em:
- Watchdog timeout.
- Stack overflow.
- Hard fault (CPU exception).
- Heap corruption detection.

### 4.2 Captura

Handler customizado:

1. Captura task name, register dump, stack trace.
2. Inclui últimas N entradas do ring buffer de logs.
3. Inclui telemetry snapshot.
4. Salva em `crash/<timestamp>.log` na microSD.
5. Reinicia.

### 4.3 Upload diferido

Após reboot e reconexão à alma:

1. Verifica `crash/` para arquivos não enviados.
2. Para cada um: envia como evento `physical.system.crash_dump` via control channel.
3. Marca como enviado.

⚠️ Cuidado: crash dump pode ter ~10KB. Não inundar Ledger; envio com rate limit.

---

## 5. Health checks (auto)

Firmware executa periodicamente (a cada 5min):

| Check | Detecta |
|---|---|
| Free heap below threshold | Memory leak (alerta) |
| Min free heap dropped recentemente | Pico de uso anormal |
| Reconnects > N por hora | Wi-Fi instável |
| Thermal sustained warm | Ambiente quente / problema de ventilação |
| CPU usage sustained high | Loop não cedendo |
| Log file write failures | microSD com problema |
| RTC drift > N segundos | NTP sync precisa |

Cada check que falha → evento `physical.system.health_check.failed` com detalhes.

Curator (na alma) consome esses eventos para detectar drift.

---

## 6. Métricas derivadas (na alma)

A partir da telemetria recebida, alma computa:

| Métrica | Alimenta |
|---|---|
| Uptime % por dia | Saúde de infra |
| Frequência de wake_word por hora | Saúde de DSP |
| Latência média de comandos (dispatched → acked) | Saúde de transporte |
| TTFB médio de TTS (`tts_first_audio_ms`) | Saúde da voz StackChan |
| Taxa de fallback de voz | Drift do `stackchan_default` |
| Underruns de buffer TTS | Problema de rede/codec/provider |
| Taxa de heartbeat missed | Saúde de conectividade |
| Drift de RTC | Necessidade de sync |
| Battery cycles | Saúde de hardware (longo prazo) |
| Thermal throttle events / dia | Ambiente |

Disponíveis para Decide e Curator.

---

## 7. Alertas

### 7.1 Categorias

Alertas surgem quando métricas cruzam thresholds. Categoria define ação:

| Categoria | Ação |
|---|---|
| `info` | Registra no Ledger; sem notificação |
| `warning` | Card silencioso ao usuário; LED amarelo dim |
| `critical` | Pode interromper via interruption_policy |

### 7.2 Exemplos

| Alerta | Categoria |
|---|---|
| Battery < 30% | Info |
| Battery < 15% | Warning |
| Battery < 5% | Critical (face avisa) |
| Thermal throttling | Warning |
| Thermal sustained > 70°C | Critical |
| Wi-Fi reconnect storm | Warning |
| Conexão perdida > 5min | Warning |
| Conexão perdida > 30min | Critical |
| microSD quase cheia | Warning |
| microSD com erro de escrita | Critical |
| Memory leak detectado | Warning (proposal de reboot) |
| Voz padrão indisponível repetidamente | Warning |
| TTS underrun recorrente | Warning |

---

## 8. Endpoint de diagnóstico (administrativo)

Comando da alma para puxar diagnóstico completo do corpo:

```json
{
  "type": "system.request_diagnostic",
  "include": ["telemetry", "logs", "crash_dumps", "health_checks"],
  "log_lines": 200
}
```

Corpo responde com bundle de eventos contendo dados solicitados.

⚠️ Privacy: requer Receipt; comando administrativo. Logs nunca incluem conteúdo sensível por design.

---

## 9. Observabilidade interna (dev)

Durante desenvolvimento, ferramentas adicionais úteis:

### 9.1 Counters
Variáveis globais incrementadas pelos diversos subsistemas:
- `g_envelope_count`
- `g_command_processed_count`
- `g_command_failed_count`
- `g_animation_frames_dropped`
- ...

Expostas via debug command sobre serial.

### 9.2 Profiling
- Loop time tracker: cada loop reflex/render cronometrado, max/avg.
- Memory usage tracker: heap watermark.

Útil para ajuste de performance, não em release.

---

## 10. Privacy considerations

### 10.1 O que NÃO vai em logs

- Conteúdo de áudio bruto.
- Conteúdo de imagem.
- Transcrições de STT (mesmo que parciais).
- Conteúdo de cards renderizados (apenas IDs, não texto).
- SSID limpo (vai hash).
- Conteúdo de NDEF (apenas UID e tipo).

### 10.2 O que VAI em logs

- IDs de comandos, envelopes, streams.
- Estados (modo, privacy).
- Métricas (battery, thermal, RAM).
- Eventos estruturais.
- Erros e warnings com contexto técnico.

### 10.3 Auditoria

Eventos `physical.system.*` documentam toda a operação. Não há "log oculto" — tudo que afeta comportamento vai pro Ledger eventualmente.

---

## 11. Modo degradado e monitoramento

Quando offline:

- Telemetria não pode ser enviada (sem alma).
- Coletada localmente, persistida em microSD com timestamp.
- **Ao reconectar:** envia bulk de telemetria pendente (com flag `replay: true`).
- Alma persiste no Ledger marcado como replay.

Logs locais continuam normais; podem virar evidência depois.

---

## 12. Quotas e limites

| Item | Limite |
|---|---|
| Logs em RAM ring buffer | 50 KB |
| Logs em microSD (rotacional) | 500 KB |
| Crash dumps acumulados | 5 (mais velhos apagam) |
| Telemetria backlog em modo degraded | 100 entries (não infinito) |
| Alertas por hora | rate limited |

Limites previnem inundação local e da alma.

---

## 13. Integração com Curator

Curator consome:
- Health check failures: detecta drift de hardware.
- Wake word stats: detecta calibração ruim.
- Command latency: detecta degradação de transporte.
- Modo time-in: detecta drift de uso (DND virando default).
- Reconnect frequency: detecta instabilidade.

Cada padrão detectado → proposal:
- "Wi-Fi instável detectado: revisar setup?"
- "Wake word com false positives recentes: ajustar threshold?"
- "Battery degradando ao longo de meses — considerar replacement."

---

## 14. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Conteúdo de áudio em log | Privacy |
| Telemetria sem sample rate | Inunda Ledger |
| Logs sem rotação | Storage explode |
| Crash dump não enviado | Diagnóstico perdido |
| Telemetria infrequente | Detecção tardia |
| Logs em PSRAM apenas (sem persistência) | Reset apaga |
| `info` para tudo | Sinal/ruído ruim |
| Health checks sem ação derivada | Métrica inútil |

---

## 15. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Sample rate de heartbeat (atual 30s — manter?) | Operacional |
| ⚠️ Tamanho exato de logs locais | Storage |
| ⚠️ Política de upload de crash dump (urgent vs deferred) | UX |
| ⚠️ Retenção de telemetria backlog em modo degraded | Storage |
| ⚠️ Quais métricas são default no heartbeat (subset acima é razoável?) | Implementação |

---

## Próximos passos de leitura

- `04-protocolos/02-eventos-evidence.md` — eventos de telemetria.
- `07-integracao-atlas/04-curator-proposals.md` — Curator consome telemetria (a escrever neste round).
- `09-testes/` — critérios para validar monitoramento (a escrever).
