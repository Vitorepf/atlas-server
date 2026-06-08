# 01 — Surface adapter (registro do StackChan no Atlas)

> **Propósito:** especificar **como o StackChan se integra ao Atlas Kernel** como uma surface. É a contrapartida do firmware no lado da alma — quem aceita conexões, valida envelopes, dispatcha output, e mantém o registro de surfaces vivas.
>
> **Pré-requisitos:** [README](../README.md), [02-arquitetura/01-corpo-vs-alma.md](../02-arquitetura/01-corpo-vs-alma.md), [04-protocolos/01-interaction-envelope.md](../04-protocolos/01-interaction-envelope.md), [04-protocolos/04-transporte.md](../04-protocolos/04-transporte.md).
>
> **Fora do escopo:** Output Renderer especializado (vai pra `02-output-renderer.md`), Curator integration (vai pra `04-curator-proposals.md`).

---

## 1. Onde isso vive no Atlas

Recapitulando a arquitetura do Atlas (veja diagrama em README):

```
[ Usuário/App/CLI/API ]   ◄─── Surface Layer ─── [ Embodiment / StackChan ]
         │                                            │
         └──────────────────┬─────────────────────────┘
                            ▼
                      Atlas Input
                            ▼
                Operation Envelope Created
                            ▼
                       Atlas Intent / Routing
                            ▼
                       Atlas Decide
                            ↓
                      Decision Receipt
                            ↓
                          [ ... pipeline ... ]
                            ↓
                       Output Renderer
                            │
                            ▼
       [ devolução para a surface que originou ou outras ]
```

O **Surface Adapter `stackchan`** é o componente que:

1. Implementa o lado servidor do transporte (WebSocket, ver `04-protocolos/04-transporte.md`).
2. Aceita conexões autenticadas de StackChans.
3. Valida `Interaction Envelopes` recebidos.
4. **Empacota como `Operation Envelope`** e injeta em `Atlas Input`.
5. Recebe `Output Commands` (vindos do Output Renderer especializado) e despacha pelo WebSocket.
6. Mantém **Surface Registry** — quais StackChans estão conectados, telemetria atual, capabilities.
7. Mantém a fronteira do **StackChan Bridge**: tudo que parece conversa, modelo ou provider entra no Atlas Core; o corpo nunca decide.

O adapter é **fino**. Não decide nada. Não interpreta cognitivamente. É um tradutor de formato + roteador.

---

## 2. Responsabilidades

### O que o adapter FAZ

| Responsabilidade | Detalhe |
|---|---|
| Aceitar conexões WebSocket | Endpoint `/atlas/surface/stackchan/{surface_id}` |
| Autenticar | Bearer token, validar contra registry |
| Validar `surface.capabilities` | Recusar capabilities inválidas |
| Negociar `schema_version` | Subset suportado por ambos |
| Receber `Interaction Envelope` | Validar contra schema, registrar |
| Adaptar para `Operation Envelope` | Mapeamento canônico (ver seção 5) |
| Injetar em `Atlas Input` | Disparar pipeline do Atlas |
| Receber `Output Commands` | Empacotar como bundle |
| Despachar via WebSocket | Texto ou binário conforme tipo |
| Coletar ACKs | Registrar no Evidence Ledger |
| Manter heartbeat | Detectar queda em 90s |
| Gerenciar Surface Registry | Estado por surface |
| Registrar tudo no Evidence Ledger | Auditoria total |

### O que o adapter NÃO FAZ

| NÃO faz | Onde acontece em vez disso |
|---|---|
| Decidir intent | Atlas Intent / Routing |
| Escolher domain | Atlas Decide |
| Aplicar policy | Policy / Profile |
| Renderizar resposta | Output Renderer especializado (`07-integracao-atlas/02-output-renderer.md`) |
| Acessar providers | Runtime / Executor |
| Aprovar Curator proposals | Apenas roteia o input do toque/voz para o pipeline |
| Cachear respostas | Atlas Decide (com policy) |
| Escolher LLM/TTS provider | Policy Router / VoiceProfileResolver |
| Preservar persona final | StackChan Persona Adapter (`06-stackchan-bridge-persona.md`) |

---

## 3. Estrutura do adapter

### 3.1 Diagrama de componentes

```
┌────────────────────────────────────────────────────────────┐
│ Surface Adapter "stackchan"                                │
│                                                            │
│  ┌────────────────────────┐   ┌──────────────────────────┐ │
│  │ WS Server / Endpoint   │   │ Surface Registry         │ │
│  │ - Aceita conexões      │   │ - Lista de surfaces      │ │
│  │ - TLS + auth           │   │ - Estado por surface     │ │
│  │ - Negocia schema       │   │ - Última telemetria      │ │
│  └────────────┬───────────┘   └────────────┬─────────────┘ │
│               │                             │               │
│  ┌────────────▼─────────────────────────────▼─────────────┐ │
│  │ Connection Handler (uma instância por conexão)         │ │
│  │  - Lê frames text/binary                               │ │
│  │  - Roteia para sub-handlers                            │ │
│  └────────────┬───────────────────────────────────────────┘ │
│               │                                             │
│   ┌───────────┴────────────┐                                │
│   ▼                        ▼                                │
│  ┌────────────────────┐  ┌──────────────────────┐           │
│  │ Inbound Handler    │  │ Outbound Handler     │           │
│  │ - Valida Envelope  │  │ - Bundle → frames    │           │
│  │ - Adapta p/ Op.Env │  │ - Despacha           │           │
│  │ - Injeta Atlas In  │  │ - Coleta ACK         │           │
│  └────────────────────┘  └──────────────────────┘           │
│            │                        ▲                       │
│            ▼                        │                       │
│       Atlas Input              Output Renderer              │
│                              (especializado p/ stackchan)   │
└────────────────────────────────────────────────────────────┘
```

### 3.2 Interfaces conceituais (especificação, não código)

**Surface Registry**
- `register(surface_id, token_hash, capabilities, ...)`
- `unregister(surface_id)`
- `get(surface_id) → SurfaceState`
- `list_connected() → [SurfaceState]`
- `update_telemetry(surface_id, telemetry)`
- `set_mode(surface_id, mode)`

**Connection Handler**
- Por conexão WS aberta, instância dedicada.
- Mantém estado da sessão (session_id, último heartbeat, queue de comandos out).
- Encerra ao desconectar e atualiza Registry.

**Inbound Handler**
- Recebe `InteractionEnvelope`.
- Valida (schema, surface_id, capabilities, replay).
- Logs no Ledger.
- Adapta para `OperationEnvelope` (ver seção 5).
- Injeta no Atlas Input via interface conhecida.

**Outbound Handler**
- Subscrito a comandos físicos do Output Renderer especializado.
- Empacota como bundles (ver `04-protocolos/03-comandos-fisicos.md`).
- Aplica TTL, supersedes, etc.
- Despacha via WS.
- Aguarda ACKs.

---

## 4. Surface Registry — modelo de estado

Cada surface conectada tem entrada no Registry:

```yaml
surface_id: stackchan_main
type: stackchan
connected: true
connected_since: 2026-05-05T08:42:01Z
session_id: ws-2026-05-05T08:42:01Z-stackchan_main
schema_version: "0.1"
capabilities: [voice, touch, nfc, imu, camera_presence, ir]
voice_capabilities:
  primary_profile: stackchan_default
  local_tts_profiles: []
  accepts_tts_stream: true
  supports_mouth_sync: true
  supports_voice_fallback_report: true
firmware_version: "fw-0.1.0"
last_heartbeat: 2026-05-05T14:32:11Z
last_heartbeat_telemetry:
  battery_pct: 78
  is_charging: true
  thermal_state: normal
  wifi_rssi: -55
  uptime_s: 13892
  current_mode: ambient
  command_queue_depth: 0
  free_psram_kb: 3812
current_mode: ambient
privacy_mode: default
active_voice_profile: stackchan_default
position:
  pan_deg: 0
  tilt_deg: 0
in_flight_commands: ["cmd-...", "bnd-..."]
recent_envelopes: [ ... últimos 50 ... ]
```

### Propósitos do Registry

| Propósito | Como é usado |
|---|---|
| Atlas saber quais corpos existem | Decide pode rotear comando para corpo específico |
| Telemetria contínua para Context Builder | "Bateria do robô baixa" entra como sinal |
| Audit | Quando aconteceu desconexão, quanto tempo, etc. |
| Multi-corpo (futuro) | Coordenar entre múltiplos StackChans |
| Hot-config | Atualizar config de surface sem rebuild |

### Persistência

Registry é **majoritariamente em memória**. Persistido no Evidence Ledger:
- Eventos `surface.connected`, `surface.disconnected`.
- Snapshots periódicos para reconstrução em recovery.

---

## 5. Mapeamento Interaction Envelope → Operation Envelope

Atlas tem o conceito canônico de `Operation Envelope`. O adapter traduz `Interaction Envelope` (específico do Embodiment) para esse formato.

### Mapeamento canônico

| Campo `OperationEnvelope` | Origem em `InteractionEnvelope` |
|---|---|
| `operation_id` | Novo, gerado pelo adapter (com referência a `envelope_id`) |
| `surface` | `surface.id`, `surface.type` |
| `received_at` | `timestamp.completed_at` |
| `request_type` | Derivado de `trigger.type` + `intent_hint.category` |
| `payload` | Contém `modalities`, `trigger`, `intent_hint` raw |
| `context` | Direto de `context_signals` |
| `correlation` | `previous_envelope_id`, `session_id` |
| `metadata.source_envelope_id` | `envelope_id` original |
| `metadata.surface_capabilities` | `surface.capabilities` |

Adapter NÃO preenche:
- `intent` (quem decide é Atlas Intent / Routing)
- `domain` (quem decide é Atlas Decide)
- Qualquer campo cognitivo

### Exemplo de tradução

**InteractionEnvelope vindo do corpo:**
```json
{
  "envelope_id": "stkc-2026-05-05T14:32:11.847Z-a8f2",
  "surface": { "id": "stackchan_main", "type": "stackchan", ... },
  "trigger": { "type": "wake_word", "confidence": 0.92, ... },
  "modalities": [ { "type": "voice", "stream_ref": "audio:...", ... } ],
  "context_signals": { ... },
  "intent_hint": { "category": "query", "confidence": 0.3, ... }
}
```

**OperationEnvelope após adapter:**
```json
{
  "operation_id": "op-2026-05-05T14:32:11.900Z-7k3a",
  "surface": { "id": "stackchan_main", "type": "stackchan" },
  "received_at": "2026-05-05T14:32:13.421Z",
  "request_type": "voice_query",
  "payload": {
    "trigger": { ... },
    "modalities": [ ... ],
    "intent_hint": { ... }
  },
  "context": { /* context_signals copy */ },
  "correlation": {
    "previous_operation_id": null,
    "session_id": "sess-2026-05-05-am"
  },
  "metadata": {
    "source_envelope_id": "stkc-2026-05-05T14:32:11.847Z-a8f2",
    "source_envelope_schema": "0.1",
    "surface_capabilities": [ ... ]
  }
}
```

A partir daí, fluxo Atlas normal.

---

## 6. Fluxo do caminho de saída (Atlas → corpo)

Quando o pipeline chega no Output Renderer:

```
Decision Receipt
   ↓
Output Renderer (geral)
   ↓ (rota baseado em surface)
Output Renderer especializado "stackchan"
   ↓ (gera bundle de comandos físicos)
Surface Adapter (Outbound Handler)
   ↓ (valida, empacota, atribui IDs)
Connection Handler da surface alvo
   ↓ (envia via WS)
StackChan (corpo)
   ↓ (executa)
ACK
   ↓
Connection Handler
   ↓
Surface Adapter (registra ACK, publica evento)
   ↓
Evidence Ledger
```

### Roteamento entre múltiplas surfaces

Se houver mais de um StackChan conectado:

- Decision Receipt pode especificar `target_surface_id`.
- Sem especificação, Output Renderer escolhe baseado em policy:
  - Surface com `user_present: true`.
  - Surface mais próxima (futuro — sem implementação inicial).
  - Surface designada como "primária".

Para Fase 0 (um único corpo), trivial.

---

## 7. Evidence Ledger — eventos do adapter

O adapter é prolífico em emitir eventos. Mínimo:

| Evento | Quando |
|---|---|
| `surface.connection.opened` | WS aberto, autenticação ok |
| `surface.connection.closed` | WS fechado |
| `surface.connection.failed` | Auth falhou, schema incompatível, etc. |
| `surface.heartbeat.received` | A cada 30s (sample rate reduzido no Ledger) |
| `surface.heartbeat.missed` | Sem heartbeat em 90s |
| `surface.envelope.received` | Cada Interaction Envelope |
| `surface.envelope.rejected` | Validação falhou |
| `surface.command.dispatched` | Cada comando enviado ao corpo |
| `surface.command.acked` | ACK recebido |
| `surface.command.failed` | ACK com state failed |
| `surface.mode.changed` | Mudança de mode (ambient → degraded etc.) |
| `surface.privacy.changed` | Mudança de privacy_mode |
| `surface.degraded.entered` / `exited` | Degraded mode |
| `surface.firmware.update_offered` | OTA offer |

Tudo viável para replay e audit. Heartbeat é amostrado (1 a cada N) para evitar inundar Ledger.

---

## 8. Multi-surface — preparação para o futuro

Mesmo que Fase 0 tenha 1 corpo, o adapter precisa ser **multi-surface ready**:

- `Surface Registry` é lista, não singleton.
- Cada conexão tem `surface_id` único.
- Output Renderer recebe `target_surface_id` ou usa policy de roteamento.
- Evidence Ledger registra cada evento com `surface_id`.

Custo de fazer agora: ~zero. Custo de retrofit: alto. Decisão: implementar multi-surface ready desde o início.

---

## 9. Configuração

Surface Adapter precisa de:

| Config | Default | Notas |
|---|---|---|
| `endpoint_path` | `/atlas/surface/stackchan` | Onde WS escuta |
| `port` | 8443 | TLS porta |
| `tls_cert_path` | (arquivo) | Cert do servidor |
| `tls_key_path` | (arquivo) | Key |
| `auth.token_store_path` | (arquivo/db) | Onde tokens vivem |
| `heartbeat_timeout_s` | 90 | Detecção de queda |
| `max_envelope_size_kb` | 64 | Anti-DoS |
| `max_in_flight_commands_per_surface` | 50 | Backpressure |
| `command_default_ttl_ms` | 30000 | TTL padrão |
| `evidence_heartbeat_sample_rate` | 1/10 | 1 em cada 10 heartbeats vai pro Ledger |

Config persistida (não no código). Mudanças passam pelo Curator se afetarem comportamento crítico.

---

## 10. Surface Lifecycle (visão completa)

```
Estado: NOT_REGISTERED
    ↓ (provisioning — token gerado)
Estado: REGISTERED, OFFLINE
    ↓ (corpo conecta)
Estado: REGISTERED, ONLINE, ambient_mode
    ↔ ↔ ↔ (estado do dia: ambient, interaction, dnd, private, degraded)
    ↓ (corpo desconecta intencionalmente — session.bye)
Estado: REGISTERED, OFFLINE (planejado)
    ↓ (corpo cai sem aviso)
Estado: REGISTERED, OFFLINE (não-planejado) → alerta
    ↓ (token rotacionado para reprovisioning ou revogado)
Estado: REGISTERED, REVOKED
    ↓ (deletado do Registry)
Estado: NOT_REGISTERED
```

Cada transição vira evento no Ledger.

---

## 11. Considerações de segurança

| Risco | Mitigação |
|---|---|
| Surface impostora se conectando | Token + TLS pinning |
| Comandos sem Receipt | Adapter (lado out) refuta enviar comandos sem `decision_receipt_ref` |
| Replay de envelope | `envelope_id` deduplicado por janela curta |
| DoS por flooding de envelopes | Rate limit por surface_id |
| Memory leak de envelopes não processados | Backpressure + timeout no pipeline |
| Vazamento de telemetria sensível | Filtrar antes de exibir/exportar; Ledger é interno |

---

## 12. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Surface Adapter como microserviço separado ou módulo do Atlas? | Deployment |
| ⚠️ Linguagem/framework de implementação | Implementação inicial |
| ⚠️ Surface Registry persistente ou só-Ledger? | Recovery após restart |
| ⚠️ Como o adapter é descoberto pelo restante do Atlas (DI, registry, evento)? | Integração |
| ⚠️ Quem expõe configuração para o usuário (UI? CLI?) | UX de admin |

Lista vai pra `10-anexos/C-decisoes-pendentes.md`.

---

## 13. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Adapter "interpretando" envelopes (decidindo intent) | Vira cognição na surface — quebra princípio |
| Adapter armazenando estado cognitivo | Surface Registry é operacional; cognição vive na alma |
| Cache de respostas no adapter | Cache é Atlas Decide, não adapter |
| Adapter falando direto com providers | Atalho ilegal; tudo passa pelo pipeline |
| Reconstruir Operation Envelope perdendo info do Interaction | Sempre preservar source_envelope_id e raw payload |
| Acoplamento ao formato específico do StackChan | Adapter é específico de stackchan, mas Operation Envelope é genérico |

---

## Próximos passos de leitura

- `02-output-renderer.md` — Renderer especializado para stackchan.
- `03-personalidade-ledger.md` — eventos relacionais.
- `04-curator-proposals.md` — como Curator chega ao corpo.
- `04-protocolos/04-transporte.md` — implementação concreta do transporte.
