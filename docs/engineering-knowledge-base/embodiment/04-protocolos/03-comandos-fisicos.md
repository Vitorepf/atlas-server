# 03 — Comandos físicos (alma → corpo)

> **Propósito:** definir o **schema canônico dos comandos** que a alma envia para o corpo. É o caminho de volta do `01-interaction-envelope.md`. O corpo recebe e executa; nunca interpreta cognitivamente.
>
> **Pré-requisitos:** [README](../README.md), [02-arquitetura/01-corpo-vs-alma.md](../02-arquitetura/01-corpo-vs-alma.md), [01-interaction-envelope.md](01-interaction-envelope.md).
>
> **Fora do escopo:** transporte (vai pra `04-transporte.md`), streaming detalhado (vai pra `05-streaming.md`).

---

## 1. Princípio

> **A alma fala em intenções de saída; o corpo traduz em hardware.**

O Atlas não diz "ligar GPIO 13 em PWM 30%". Diz `servo.gesture: "nod"`. O firmware traduz para sinais de hardware concretos.

Isso isola: a alma é portável entre embodiments diferentes; o corpo é trocável sem afetar a alma.

---

## 2. Anatomia de um comando

```json
{
  "command_id": "cmd-2026-05-05T14:32:13.500Z-x9k2",
  "issued_at": "2026-05-05T14:32:13.500Z",
  "decision_receipt_ref": "rcpt-2026-05-05T14:32:13.421Z-...",
  "type": "display.show_card",
  "payload": { ... },
  "execution": {
    "mode": "immediate",
    "ttl_ms": 30000,
    "supersedes": ["cmd-..."],
    "ack_required": true
  }
}
```

### Campos universais

| Campo | Descrição |
|---|---|
| `command_id` | Único por comando. Padrão: `cmd-<iso8601>-<random>`. |
| `issued_at` | Hora de emissão pela alma. Corpo compara com sua hora para detectar staleness. |
| `decision_receipt_ref` | **Princípio crítico:** todo comando físico tem que apontar para a Decision Receipt que o autorizou. Sem Receipt → corpo rejeita. |
| `type` | Tipo do comando. Lista enumerada (seção 5). |
| `payload` | Dados específicos do tipo. |
| `execution.mode` | `immediate` (executa já), `queued` (coloca em fila), `replace` (substitui qualquer comando do mesmo tipo em curso). |
| `execution.ttl_ms` | Tempo máximo de validade. Se corpo recebe atrasado, descarta. |
| `execution.supersedes` | Lista de `command_id` que esse comando cancela. |
| `execution.ack_required` | Se true, corpo manda ACK ao terminar. |

### Princípio do `decision_receipt_ref`

Sem este campo, o comando é rejeitado. Razão: princípio do Atlas — "Runtime não executa sem Decision Receipt". O corpo é runtime físico; aplica a mesma regra.

Exceção: comandos `system.*` operacionais (boot ack, modo degradado, sync de hora) podem ter `decision_receipt_ref: "system"` indicando origem operacional, não cognitiva.

---

## 3. ACK e estados

Para comandos com `ack_required: true`, o corpo emite ACK pelo control channel:

```json
{
  "ack_for": "cmd-2026-05-05T14:32:13.500Z-x9k2",
  "state": "executed",
  "at": "2026-05-05T14:32:13.612Z",
  "details": null
}
```

Estados possíveis:

| Estado | Significado |
|---|---|
| `received` | Comando chegou no corpo (opcional, pode ser implícito) |
| `started` | Início de execução (útil para comandos longos) |
| `executed` | Concluído com sucesso |
| `failed` | Falhou. `details` traz motivo. |
| `superseded` | Foi substituído antes de executar (não é falha) |
| `expired` | Chegou após TTL, descartado |
| `rejected` | Rejeitado por validação (sem Receipt, schema inválido, etc.) |

ACKs viram entrada no Evidence Ledger. Falhas viram trigger para Repair Loop.

---

## 4. Coordenação de múltiplos comandos

Um Decision Receipt típico vira **vários comandos físicos coordenados**. Exemplo: responder a "qual o status do build?" envolve simultaneamente:
- Mudar face para informativa
- Mostrar info-card no display
- Iniciar TTS streaming
- Mudar LED para verde
- Virar servo para o display

Para coordenar:

### 4.1 Modo `bundle`

Múltiplos comandos enviados num só envelope, executados como grupo:

```json
{
  "bundle_id": "bnd-2026-05-05T14:32:13.500Z-7fg9",
  "decision_receipt_ref": "rcpt-...",
  "execution": "atomic" | "sequential" | "parallel",
  "commands": [
    { "type": "display.show_face", "payload": { "expression": "informative" } },
    { "type": "led.set_pattern", "payload": { "color": "green", "pattern": "stable" } },
    { "type": "servo.gesture", "payload": { "gesture": "look_at_display" } },
    { "type": "display.show_card", "payload": { ... } },
    { "type": "audio.tts_stream", "payload": { "stream_ref": "tts:...", "voice_profile": "stackchan_default" } }
  ]
}
```

| `execution` | Significado |
|---|---|
| `atomic` | Tudo executa ou nada — se um falha, reverter (best effort) |
| `sequential` | Um após o outro, em ordem |
| `parallel` | Tudo simultaneamente (default para coordenação visual/sonora) |

### 4.2 Cancelamento

Para abortar coisas em curso:

```json
{
  "command_id": "cmd-...",
  "type": "system.cancel",
  "payload": { "targets": ["cmd-...", "bnd-...", "type:audio.tts_stream"] }
}
```

`targets` aceita IDs específicos ou wildcard por tipo (`type:audio.*`).

---

## 5. Catálogo de comandos

Versão 0.1. Cada comando tem schema definido. Adicionar novos = bump de versão (compatível se opcional).

### 5.1 Display

#### `display.show_face`
Renderiza avatar facial.

```json
{
  "expression": "neutral" | "attentive" | "thinking" | "informative" | "happy" | "sad" | "concerned" | "private",
  "palette": "default" | "focused" | "warm" | "alert" | "degraded",
  "animation": "static" | "blink" | "subtle_motion",
  "transition_ms": 200
}
```

Expressões são **vocabulário fixo** definido na alma. Não há "expressão custom" — se precisa nova, adiciona ao catálogo.

#### `display.show_card`
Renderiza um info-card.

```json
{
  "card_type": "info" | "alert" | "proposal" | "status" | "metric",
  "title": "Build status",
  "body": "Project X — passing\n12 commits hoje",
  "icon": "check_circle" | "warning" | "build" | "calendar" | "metric_chart" | null,
  "ttl_ms": 30000,
  "interaction_hint": null
}
```

`interaction_hint` (Fase 4+): indica quais touch pads estão "ativos" para esse card (ex: aprovar/rejeitar proposal).

#### `display.transition`
Força transição entre estados.

```json
{
  "kind": "fade" | "slide_left" | "slide_right" | "cut",
  "duration_ms": 300
}
```

#### `display.clear`
Volta para face neutra.

```json
{}
```

### 5.2 LED

#### `led.set_pattern`
Aplica padrão a todos os LEDs.

```json
{
  "color": "blue" | "green" | "yellow" | "red" | "purple" | "orange" | "white" | "off",
  "rgb": null,
  "pattern": "stable" | "breathing" | "pulse" | "rotating" | "chase",
  "speed": "slow" | "normal" | "fast",
  "intensity": 0.0-1.0
}
```

`color` nomeado é prioridade para garantir consistência visual. `rgb` permite override quando necessário (ex: cor do Domain ativo se for personalizada).

#### `led.set_zone`
LEDs específicos (avançado, raro).

```json
{
  "zones": [
    { "leds": [0, 1, 2], "color": "green" },
    { "leds": [3, 4, 5], "color": "yellow" }
  ]
}
```

#### `led.flash`
Pulso curto.

```json
{
  "color": "blue",
  "count": 2,
  "interval_ms": 150
}
```

### 5.3 Servo

#### `servo.set_target`
Move para posição específica.

```json
{
  "pan_deg": 0,
  "tilt_deg": 0,
  "speed": "slow" | "normal" | "fast",
  "easing": "linear" | "ease_in" | "ease_out" | "ease_in_out"
}
```

`pan_deg` é relativo à posição neutra (0 = frente). Pan 360° permite valores absolutos. Tilt limitado a [-45, 45].

#### `servo.gesture`
Gestos pré-definidos (vocabulário fechado).

```json
{
  "gesture": "nod" | "shake" | "look_at_display" | "look_at_user" | "look_around" | "tilt_thinking" | "alert_stance",
  "intensity": "subtle" | "normal" | "emphasized"
}
```

Gestos são **library da alma → renderizados pelo corpo**. Cada gesto tem implementação específica no firmware.

#### `servo.idle_pattern`
Liga/desliga micro-movimento idle (L0).

```json
{
  "enabled": true,
  "intensity": 0.0-1.0,
  "frequency": "rare" | "occasional" | "frequent"
}
```

### 5.4 Áudio

#### `audio.play_sound`
Sons curtos pré-cacheados no microSD.

```json
{
  "sound_id": "ack" | "wake_confirm" | "error" | "thinking" | "success" | "alert",
  "volume": 0.0-1.0
}
```

Catálogo de `sound_id` é fixo. Adicionar = atualizar firmware + assets.

#### `audio.tts_stream`
Inicia recepção de TTS streaming.

```json
{
  "stream_ref": "tts:atlas:2026-05-05T14:32:13Z:bx7a",
  "voice_profile": "stackchan_default",
  "fallback_voice_profile": "atlas_default_pt_br",
  "persona_style": "stackchan_default",
  "speech_rate": "normal",
  "prosody": "light_focused",
  "expected_duration_ms_hint": 3500,
  "interruptible": true
}
```

Áudio em si chega pelo media channel (binary frames). Detalhes em `05-streaming.md`.

`voice_profile` é identidade auditável, não preferência solta. O corpo deve rejeitar `audio.tts_stream` sem `decision_receipt_ref`, e deve reportar no ACK se usou fallback.

#### `audio.tts_stop`
Interrompe TTS em curso.

```json
{
  "stream_ref": "tts:..."
}
```

#### `audio.set_volume`
Volume base do speaker.

```json
{
  "volume": 0.0-1.0,
  "scope": "session" | "persistent"
}
```

### 5.5 IR

#### `ir.transmit`
Emite código IR.

```json
{
  "protocol": "nec" | "rc5" | "raw",
  "code": "0x20DF10EF",
  "device_label": "tv_living_room"
}
```

`device_label` é pra audit no Ledger ("Atlas comandou TV da sala").

### 5.6 Sistema

#### `system.set_mode`
Muda modo operacional do corpo.

```json
{
  "mode": "ambient" | "interaction" | "do_not_disturb" | "private" | "degraded" | "standby"
}
```

| Modo | Comportamento |
|---|---|
| `ambient` | Default. Idle informativo + reflex L0. |
| `interaction` | Usuário ativo. Mais responsivo, brilho normal. |
| `do_not_disturb` | Sem interrupções de L4 (Heartbeat). Display dim. |
| `private` | Captura de qualquer modalidade desligada. LED vermelho. |
| `degraded` | Sem alma. LED roxo. Sem cards. |
| `standby` | Display off, servo parado, só wake word. |

#### `system.degraded_mode`
Comando explícito para entrar/sair (também pode ser auto-detectado).

```json
{
  "enter": true,
  "reason": "atlas_unreachable" | "manual" | "fault"
}
```

#### `system.cancel`
Cancela comandos pendentes.

```json
{
  "targets": ["cmd-...", "bnd-...", "type:audio.tts_stream"]
}
```

#### `system.config_update`
Atualiza configuração persistente.

```json
{
  "key": "atlas_endpoint" | "wifi_ssid" | "voice_profile" | ...,
  "value": "...",
  "scope": "session" | "persistent"
}
```

#### `system.firmware_update_available`
Notifica corpo de update OTA.

```json
{
  "version": "fw-0.2.0",
  "url": "https://...",
  "checksum": "sha256:...",
  "auto_apply": false
}
```

`auto_apply: false` = corpo apenas sinaliza ao usuário (LED amarelo, info-card). Decisão de aplicar fica com o usuário.

#### `system.reboot`
Reinicia o corpo. Raríssimo. Requer Receipt.

```json
{
  "delay_s": 5,
  "reason": "ota" | "fault_recovery" | "manual"
}
```

---

## 6. Mapeamento Decision Receipt → bundles

Como uma Decision Receipt típica vira comandos:

```
Decision Receipt: "responder pergunta sobre build status"
   ↓
Output Renderer (na alma) interpreta:
   ↓
Bundle:
   - display.show_face (informative)
   - led.set_pattern (green, stable)
   - servo.gesture (look_at_display)
   - display.show_card (build status)
   - audio.tts_stream (resposta verbal)
   ↓
WebSocket → corpo
   ↓
Corpo executa em paralelo (execution: parallel)
   ↓
ACKs voltam pra alma
   ↓
Eventos no Evidence Ledger
```

O **Output Renderer especializado** do Atlas (em `07-integracao-atlas/02-output-renderer.md`) é quem traduz Receipts em bundles. Receipts são abstratas (intenção); bundles são concretas (instruções físicas).

---

## 7. Idempotência e replay

Princípio: **comandos são idempotentes por `command_id`**. Se o corpo recebe o mesmo `command_id` duas vezes (rede instável, retry), executa só a primeira.

Implementação no corpo: cache pequeno de `command_id` recentes (últimos 100 ou últimos 60s).

Replay protection: TTL faz comandos antigos serem descartados automaticamente.

---

## 8. Validação

O corpo valida cada comando antes de executar:

| Verificação | Falha resulta em |
|---|---|
| Schema válido | Rejeição com motivo |
| `decision_receipt_ref` presente (exceto system) | Rejeição |
| TTL não expirado | Descarte silencioso |
| Tipo conhecido | Rejeição (encaminhar erro pra alma) |
| Payload dentro de limites (ex: pan_deg em range) | Rejeição |
| Capability disponível (corpo declarou na conexão) | Rejeição |
| Modo atual permite (ex: tts em modo private = rejeita) | Rejeição |

Rejeições viram ACK com `state: rejected` + `details`. Ledger registra.

---

## 9. Versionamento

`schema_version` no envelope de transporte (não no comando individual). Mudanças:

- **Adicionar comando novo** → patch (clientes antigos rejeitam tipo desconhecido, alma lida).
- **Adicionar campo opcional em payload** → patch.
- **Mudar payload existente (breaking)** → minor com migração.
- **Remover comando** → minor após período de deprecação.

Corpo declara versão suportada na conexão (capabilities). Alma usa o subset que ambos suportam.

---

## 10. Subset da Fase 0

Para Fase 0 (Espelho), apenas estes comandos são usados:

- `display.show_face`
- `display.show_card`
- `display.clear`
- `led.set_pattern`
- `led.flash`
- `servo.gesture` (apenas `look_at_user`, `look_at_display`)
- `servo.idle_pattern`
- `audio.play_sound` (apenas `boot`, `connection_lost`, `connection_restored`)
- `system.set_mode`
- `system.degraded_mode`
- `system.cancel`
- `system.config_update`

Comandos de TTS, IR, gestures avançados, etc., chegam em fases posteriores.

---

## 11. Anti-padrões

| Anti-padrão | Por quê é ruim |
|---|---|
| Comando sem `decision_receipt_ref` (exceto system) | Corpo virou runtime sem Receipt → quebra princípio |
| Texto livre direto pro corpo (ex: "fala isso") | TTS é stream identificado, não texto inline |
| Comandos com `payload` arbitrário | Schema fechado força disciplina |
| Inventar `expression` ou `gesture` ad-hoc | Vocabulário fechado; novos vocábulos = decisão registrada |
| Mandar 50 comandos pequenos quando bundle resolve | Coordenação fica frágil |
| Corpo "interpretar" comando ("show_card de proposal vou perguntar X") | Corpo não interpreta, executa |
| ACK só para alguns comandos | Auditoria fica fragmentada |

---

## Próximos passos de leitura

- `04-transporte.md` — como esses JSONs viajam.
- `05-streaming.md` — TTS streaming detalhado.
- `07-integracao-atlas/02-output-renderer.md` — como Receipts viram bundles.
