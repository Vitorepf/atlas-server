---
id: atlas-embodiment-04-protocolos-01-interaction-envelope
type: engineering_knowledge
title: "01 — Interaction Envelope"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 01 — Interaction Envelope

> **Propósito:** definir o **schema canônico** de toda interação que entra no Atlas vinda do Embodiment. Este é o contrato mais importante entre corpo e alma — mal definido aqui, refatora 3 vezes depois.
>
> **Pré-requisitos:** [README](../README.md), [02-arquitetura/01-corpo-vs-alma.md](../02-arquitetura/01-corpo-vs-alma.md), [02-arquitetura/02-loops-temporais.md](../02-arquitetura/02-loops-temporais.md).
>
> **Fora do escopo:** comandos físicos do Atlas para o corpo (vai pra `03-comandos-fisicos.md`), transporte (vai pra `04-transporte.md`).

---

## 1. O que é o Interaction Envelope

O Atlas já tem o conceito de **Operation Envelope** — a unidade canônica de operação que sai do `Atlas Input` e atravessa o pipeline.

O **Interaction Envelope** é o **adapter de entrada** específico para o Embodiment: a forma canônica de qualquer evento físico (voz, touch, NFC, presença, gesto, etc.) virar input para o Atlas.

```
[ Sensor físico ]                         [ Atlas Kernel ]
     │                                          │
     ▼                                          │
┌──────────────────────┐                        │
│ Reflex layer (L0/L1) │                        │
│ classifica + adapta  │                        │
└──────────────────────┘                        │
     │                                          │
     ▼                                          │
┌─────────────────────────┐    WebSocket   ┌────▼─────────┐
│ INTERACTION ENVELOPE    │ ──────────────► │ Atlas Input  │
│ (este documento)        │                 │              │
└─────────────────────────┘                 │ Operation    │
                                            │ Envelope     │
                                            │ Created      │
                                            └──────────────┘
```

O Interaction Envelope **alimenta** o Operation Envelope. Não substitui — adiciona contexto físico e modalidade que outros surfaces (CLI, app) não têm.

---

## 2. Princípios de design

1. **Estável.** Versionado. Mudança quebra contrato — vira novo schema com migração.
2. **Genérico ao corpo.** Não acopla ao StackChan. Outros embodiments futuros usam o mesmo schema.
3. **Multimodal por design.** Um evento pode misturar voz + gesto + presença simultaneamente.
4. **Semântica vs estímulo.** Carrega tanto a interpretação reflex (`intent_hint`) quanto o estímulo bruto (raw streams) — alma decide qual usar.
5. **Anexos por referência.** Áudio/imagem viajam por canais separados (streaming), Envelope referencia.
6. **Auditável.** Tudo que está no Envelope pode/deve ir para o Evidence Ledger.

---

## 3. Schema (versão 0.1)

Formato canônico em JSON. YAML aceito como representação humana.

```json
{
  "schema_version": "0.1",
  "envelope_id": "stkc-2026-05-05T14:32:11.847Z-a8f2",
  "surface": {
    "id": "stackchan_main",
    "type": "stackchan",
    "version": "fw-0.1.0",
    "capabilities": ["voice", "touch", "nfc", "imu", "camera_presence", "ir"]
  },
  "timestamp": {
    "started_at": "2026-05-05T14:32:11.847Z",
    "completed_at": "2026-05-05T14:32:13.421Z",
    "rtc_synced": true
  },
  "trigger": {
    "type": "wake_word",
    "confidence": 0.92,
    "details": { "phrase": "atlas" }
  },
  "modalities": [
    {
      "type": "voice",
      "stream_ref": "audio:stkc_main:2026-05-05T14:32:11Z:a8f2",
      "duration_ms": 1574,
      "asr_local_hint": null
    }
  ],
  "context_signals": {
    "user_present": true,
    "user_attention": "focused",
    "ambient_light_lux": 320,
    "ambient_sound_level": "quiet",
    "battery_pct": 78,
    "is_charging": true,
    "thermal_state": "normal",
    "current_mode": "ambient",
    "privacy_mode": "default",
    "last_interaction_age_s": 1240,
    "robot_position": { "pan_deg": 0, "tilt_deg": 0 }
  },
  "intent_hint": {
    "category": "query",
    "confidence": 0.6,
    "tentative_domain": null
  },
  "metadata": {
    "session_id": "sess-2026-05-05-am",
    "previous_envelope_id": "stkc-2026-05-05T14:25:03Z-9c11",
    "interrupt_in_progress": false
  }
}
```

---

## 4. Campos detalhados

### 4.1 `schema_version`
String semver-like. Cada mudança incompatível incrementa. Atlas Input rejeita envelopes com versão desconhecida explicitamente — não tenta adivinhar.

### 4.2 `envelope_id`
Identificador único, gerado no corpo. Padrão sugerido: `<surface_id>-<iso8601>-<random4>`. Usado para deduplicação, replay e correlação no Evidence Ledger.

### 4.3 `surface`

| Campo | Significado |
|---|---|
| `id` | Identificador único do corpo. Permite múltiplos corpos no futuro (`stackchan_main`, `stackchan_office`). |
| `type` | Tipo de embodiment. Hoje só `stackchan`. Futuramente outros. |
| `version` | Versão de firmware. Alma pode adaptar comandos baseado nela. |
| `capabilities` | Lista de modalidades suportadas. Alma só envia comandos que cabem nelas. |

`capabilities` é importante para forward-compat: corpo novo pode anunciar `["voice", "touch", "nfc", "imu", "camera_presence", "ir", "gestures_3d"]` e a alma usa o que reconhece.

### 4.4 `timestamp`

Hora real do evento (não da chegada na alma). `rtc_synced: false` indica que o RTC do corpo perdeu sync — alma trata timestamps com desconfiança.

### 4.5 `trigger`

O que iniciou o envelope. Tipos definidos:

| `type` | Descrição |
|---|---|
| `wake_word` | Detecção de palavra de ativação (offline) |
| `touch` | Toque em pad (`details.pad: 1\|2\|3`, `details.duration_ms`, `details.combo: [1,3]`) |
| `nfc` | Tag aproximada (`details.uid`, `details.payload` se NDEF lido) |
| `gesture` | IMU detectou gesto pré-definido (`details.gesture: "shake" \| "tap_head" \| "lift"`) |
| `presence_change` | Câmera detectou mudança binária (`details.from`, `details.to`) |
| `proximity` | LTR-553 detectou aproximação (`details.distance_cm`) |
| `ir_remote` | Recebido sinal IR de controle conhecido (`details.code`) |
| `ble_peripheral` | Periférico BLE pareado mandou evento (`details.peripheral_id`, `details.data`) |
| `scheduled` | Disparado pelo Heartbeat L4 da alma — ✋ note: nesse caso o Envelope é gerado **na alma**, não no corpo |
| `manual_input` | Backdoor para testes — entrada via API direta |
| `system` | Eventos operacionais (boot, low_battery, thermal_throttle) |

`confidence` é float [0,1] quando aplicável (wake word, gestures). Para triggers determinísticos (touch, NFC), omitir ou `1.0`.

### 4.6 `modalities`

Lista de canais que carregam dados neste envelope. Cada modalidade tem schema próprio:

#### voice
```json
{
  "type": "voice",
  "stream_ref": "audio:<surface>:<ts>:<id>",
  "duration_ms": 1574,
  "asr_local_hint": null
}
```
- `stream_ref`: identificador opaco do stream de áudio. Áudio em si viaja por canal separado (chunks via WebSocket binary frames ou similar).
- `asr_local_hint`: se o corpo tiver capacidade local de STT (Module-LLM), pode mandar transcrição rápida como hint. Alma pode usar ou descartar.

#### touch
```json
{
  "type": "touch",
  "pad": 2,
  "duration_ms": 320,
  "combo": null
}
```

Para combos: `combo: [1, 3]` significa toques simultâneos.

#### nfc
```json
{
  "type": "nfc",
  "uid": "04:a3:f2:99:c1:80:00",
  "ndef_records": [...]
}
```

#### imu_gesture
```json
{
  "type": "imu_gesture",
  "gesture": "shake",
  "intensity": 0.7,
  "duration_ms": 800
}
```

#### camera_image
```json
{
  "type": "camera_image",
  "stream_ref": "img:<surface>:<ts>:<id>",
  "trigger_reason": "user_request",
  "resolution": "640x480",
  "consent_tag": "explicit"
}
```
**Nunca enviado sem consent_tag.** Captura de imagem requer consentimento explícito (gesto, comando).

#### environmental
```json
{
  "type": "environmental",
  "lux": 320,
  "sound_level_db": 38,
  "temperature_c": null
}
```
Sinal ambiental amostrado no momento do evento.

### 4.7 `context_signals`

Bloco fixo de sinais que **toda interação carrega**, independente da modalidade. Alimenta diretamente o Atlas Context Builder.

| Campo | Tipo | Descrição |
|---|---|---|
| `user_present` | bool | Detecção binária de presença |
| `user_attention` | enum | `focused` \| `distracted` \| `away` \| `unknown` (futuro — fica `unknown` até implementar) |
| `ambient_light_lux` | int | Luz ambiente atual |
| `ambient_sound_level` | enum | `silent` \| `quiet` \| `normal` \| `noisy` |
| `battery_pct` | int | Estado da bateria |
| `is_charging` | bool | Plugado em USB |
| `thermal_state` | enum | `normal` \| `warm` \| `throttling` |
| `current_mode` | enum | `ambient` \| `interaction` \| `private` \| `do_not_disturb` \| `degraded` |
| `privacy_mode` | enum | `default` \| `restricted` \| `private_physical` |
| `last_interaction_age_s` | int | Quanto tempo desde última interação significativa |
| `robot_position` | obj | Posição atual de servos |

**Princípio:** se o sinal é barato de capturar, sempre vai. Atlas decide o que olhar.

### 4.8 `intent_hint`

Classificação reflex feita no corpo (L1). É **dica**, não decisão. Atlas Decide pode ignorar.

```json
{
  "category": "query" | "command" | "approval" | "rejection" | "ambient" | "unknown",
  "confidence": 0.0-1.0,
  "tentative_domain": null | "programming" | "finance" | "personal_dev" | "marketing" | "self_improvement"
}
```

Casos onde reflex acerta com alta confiança:
- Touch pad de aprovar uma proposal pendente → `approval`, confidence 1.0
- NFC tag mapeada → `command` com `tentative_domain` baseado na tag

Casos onde reflex chuta com baixa confiança:
- Voz após wake word → `query`, confidence 0.3 (alma vai classificar de verdade)

### 4.9 `metadata`

Bloco de correlação:

| Campo | Significado |
|---|---|
| `session_id` | Sessão lógica (definida pelo corpo — ex.: dia, ou wake-to-sleep) |
| `previous_envelope_id` | Envelope anterior, para correlação conversacional |
| `interrupt_in_progress` | true se este envelope está cancelando algo em curso |

---

## 5. Streams binários — áudio e imagem

Áudio e imagem **não vão dentro do Envelope JSON**. Razões:
- Tamanho.
- Streaming (áudio chega em chunks; envelope é discreto).
- Consentimento e auditoria distintos.

### Convenção de transporte

```
WebSocket connection (corpo ↔ alma):
   ├─ Channel "control" (text frames)
   │      └─ Interaction Envelopes (JSON)
   │      └─ Output Commands (JSON)
   │      └─ Telemetria/heartbeat (JSON)
   │
   └─ Channel "media" (binary frames)
          └─ Audio chunks (referenciados por stream_ref)
          └─ Image frames (referenciados por stream_ref)
```

Cada chunk binário começa com header curto identificando seu `stream_ref`. Detalhes em `04-protocolos/04-transporte.md`.

### Lifecycle de um stream

1. Corpo envia Envelope com `modalities[*].stream_ref = "audio:..."`.
2. Corpo começa a empurrar chunks binários referenciando aquele `stream_ref`.
3. Alma processa em paralelo (STT streaming).
4. Quando captura termina, corpo envia chunk final marcado `eos: true`.
5. Alma fecha o stream e processa.

---

## 6. Exemplos canônicos

### 6.1 "Atlas, qual o status do build?"

```json
{
  "schema_version": "0.1",
  "envelope_id": "stkc-2026-05-05T14:32:11.847Z-a8f2",
  "surface": { "id": "stackchan_main", "type": "stackchan", "version": "fw-0.1.0", "capabilities": ["voice","touch","nfc","imu","camera_presence","ir"] },
  "timestamp": { "started_at": "2026-05-05T14:32:11.847Z", "completed_at": "2026-05-05T14:32:13.421Z", "rtc_synced": true },
  "trigger": { "type": "wake_word", "confidence": 0.92, "details": { "phrase": "atlas" } },
  "modalities": [
    { "type": "voice", "stream_ref": "audio:stkc_main:2026-05-05T14:32:11Z:a8f2", "duration_ms": 1574 }
  ],
  "context_signals": { "user_present": true, "user_attention": "focused", "ambient_light_lux": 320, "ambient_sound_level": "quiet", "battery_pct": 78, "is_charging": true, "thermal_state": "normal", "current_mode": "ambient", "privacy_mode": "default", "last_interaction_age_s": 1240, "robot_position": { "pan_deg": 0, "tilt_deg": 0 } },
  "intent_hint": { "category": "query", "confidence": 0.3, "tentative_domain": null }
}
```

### 6.2 Aprovação via touch pad

```json
{
  "schema_version": "0.1",
  "envelope_id": "stkc-2026-05-05T15:01:22.103Z-b7d4",
  "surface": { "id": "stackchan_main", "type": "stackchan", "version": "fw-0.1.0", "capabilities": [...] },
  "timestamp": { "started_at": "2026-05-05T15:01:22.103Z", "completed_at": "2026-05-05T15:01:22.412Z", "rtc_synced": true },
  "trigger": { "type": "touch", "details": { "pad": 1, "duration_ms": 309, "combo": null } },
  "modalities": [],
  "context_signals": { ... },
  "intent_hint": { "category": "approval", "confidence": 1.0, "tentative_domain": "self_improvement" },
  "metadata": { "previous_envelope_id": "atlas-proposal-2026-05-05T15:00:55Z" }
}
```

Note: `intent_hint.category = "approval"` com confidence 1.0 — porque a alma havia mostrado uma proposal e o pad 1 estava no contexto como "✓". Reflex classificou corretamente.

### 6.3 NFC tag "FOCUS"

```json
{
  "schema_version": "0.1",
  "envelope_id": "stkc-2026-05-05T09:00:14.508Z-3f81",
  "surface": { ... },
  "timestamp": { ... },
  "trigger": { "type": "nfc", "details": { "uid": "04:a3:f2:99:c1:80:00", "ndef_records": [{"text": "FOCUS"}] } },
  "modalities": [
    { "type": "nfc", "uid": "04:a3:f2:99:c1:80:00", "ndef_records": [{"text": "FOCUS"}] }
  ],
  "context_signals": { ... },
  "intent_hint": { "category": "command", "confidence": 0.95, "tentative_domain": "personal_dev" },
  "metadata": {}
}
```

### 6.4 Presença (usuário sentou na mesa)

```json
{
  "schema_version": "0.1",
  "envelope_id": "stkc-2026-05-05T08:42:01.001Z-1a01",
  "surface": { ... },
  "timestamp": { ... },
  "trigger": { "type": "presence_change", "details": { "from": false, "to": true } },
  "modalities": [
    { "type": "environmental", "lux": 180, "sound_level_db": 35 }
  ],
  "context_signals": { "user_present": true, "user_attention": "unknown", ..., "last_interaction_age_s": 39600 },
  "intent_hint": { "category": "ambient", "confidence": 1.0, "tentative_domain": null },
  "metadata": {}
}
```

Atlas Decide pode ignorar ou disparar ritual de bom dia (se for primeira presença do dia).

### 6.5 Heartbeat scheduled (gerado na alma)

Note que este é gerado pela alma, não pelo corpo:

```json
{
  "schema_version": "0.1",
  "envelope_id": "atlas-sched-2026-05-05T09:00:00Z-morning",
  "surface": { "id": "stackchan_main", "type": "stackchan", "version": "fw-0.1.0", "capabilities": [...] },
  "timestamp": { "started_at": "2026-05-05T09:00:00.000Z", "completed_at": "2026-05-05T09:00:00.000Z", "rtc_synced": true },
  "trigger": { "type": "scheduled", "details": { "ritual": "good_morning" } },
  "modalities": [],
  "context_signals": { ... última telemetria conhecida do corpo ... },
  "intent_hint": { "category": "command", "confidence": 1.0, "tentative_domain": "personal_dev" },
  "metadata": { "scheduler_run_id": "..." }
}
```

---

## 7. Validação

Atlas Input valida cada Envelope ao receber:

| Verificação | Falha resulta em |
|---|---|
| `schema_version` suportada | Rejeição com erro versionado para o corpo |
| `surface.id` registrado | Rejeição (corpo desconhecido) |
| `envelope_id` único (não replay) | Idempotência — descarta silenciosamente |
| Timestamp dentro de janela razoável | Warning + aceita (tolera drift) |
| `capabilities` contém modalidades referenciadas | Rejeição (corpo declarando capacidade que não tem) |
| `consent_tag` presente em camera_image | Rejeição |
| `trigger.type` válido | Rejeição |

Rejeição → evento no Evidence Ledger + comando de erro de volta ao corpo (LED roxo + face de "erro").

---

## 8. Versionamento

`schema_version` segue convenção:
- **Patch** (0.1.x): adicionar campos opcionais. Compatível.
- **Minor** (0.x.0): adicionar trigger types, modalities. Compatível para clientes mais novos; clientes antigos ignoram.
- **Major** (x.0.0): mudança incompatível. Requer migração.

Atlas Input mantém suporte a **uma versão major anterior** durante migração — período de transição definido por policy.

---

## 9. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ JSON ou MessagePack para reduzir bandwidth? | Implementação inicial |
| ⚠️ Streams binários por mesmo WS ou WS dedicado? | `04-transporte.md` |
| ⚠️ TLS obrigatório mesmo em LAN? | Policy de segurança |
| ⚠️ `session_id` definido pelo corpo ou alma? | Coordenação multi-surface no futuro |
| ⚠️ Como representar "interrupt anterior" — campo dedicado ou `previous_envelope_id` é suficiente? | Cancelamento mid-flight |
| ⚠️ Como o corpo sinaliza falha de modalidade (ex.: STT local não rodou)? | Robustez |

Lista vai pra `10-anexos/C-decisoes-pendentes.md`.

---

## 10. Anti-padrões

| Anti-padrão | Por quê é ruim |
|---|---|
| Colocar conteúdo cognitivo (resposta interpretada) no Envelope | Vira decisão no corpo |
| Embutir áudio inteiro em base64 no JSON | Quebra streaming, infla payload |
| Modalidades com nomes ad-hoc | Cada novo trigger inventa schema — vira bagunça |
| Omitir `context_signals` "porque é redundante" | Cada Envelope precisa ser auto-contido |
| Rejeitar envelope com campo desconhecido | Forward-compat exige tolerância |
| Inventar trigger.type sem documentar | Próximo dev não entende |

---

## Próximos passos de leitura

- `02-eventos-evidence.md` — quais Envelopes geram quais eventos no Ledger.
- `03-comandos-fisicos.md` — o caminho de volta (alma → corpo).
- `04-transporte.md` — WebSocket, MQTT, fallback.
- `05-streaming.md` — chunks de áudio, TTS streaming, latência.
