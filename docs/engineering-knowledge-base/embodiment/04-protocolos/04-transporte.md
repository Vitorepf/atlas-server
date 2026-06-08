# 04 — Transporte

> **Propósito:** definir o **canal de comunicação** entre corpo e alma. Como bytes saem do firmware, atravessam a rede e chegam ao Atlas — e vice-versa. Inclui autenticação, reconexão, multiplexação de canais (control vs media) e modos de fallback.
>
> **Pré-requisitos:** [README](../README.md), [01-interaction-envelope.md](01-interaction-envelope.md), [03-comandos-fisicos.md](03-comandos-fisicos.md).
>
> **Fora do escopo:** semântica de mensagens (já definida nos outros protocolos), criptografia em camadas superiores.

---

## 1. Decisão: WebSocket sobre TLS

Após considerar alternativas (MQTT, gRPC streaming, HTTP/2 server-sent + POST, sockets brutos), a escolha é **WebSocket sobre TLS (`wss://`)** como transporte primário.

### Por quê WebSocket

| Critério | WebSocket | MQTT | gRPC | HTTP polling |
|---|---|---|---|---|
| Bidirecional persistente | ✅ | ✅ | ✅ (streaming) | ❌ |
| Suporta binary frames | ✅ | ✅ | ✅ | 🟡 (base64) |
| Suporte ESP32 maduro | ✅ | ✅ | 🟡 (libs limitadas) | ✅ |
| Suporte backend (Atlas) trivial | ✅ | 🟡 (broker extra) | 🟡 (HTTP/2) | ✅ |
| Debug em ferramentas comuns (browser, curl, wscat) | ✅ | 🟡 | ❌ | ✅ |
| Sem broker intermediário | ✅ | ❌ | ✅ | ✅ |
| Overhead | Baixo | Baixo | Médio | Alto |

**WebSocket vence porque:**
- ESP32 tem libs WebSocket maduras (Arduino, ESP-IDF nativo).
- Atlas backend pode expor via qualquer framework web.
- Sem broker intermediário (MQTT exige).
- Debugável com `wscat`/`websocat` em qualquer momento.
- Suporta text + binary frames nativamente (multiplexa control + media).

**Trade-off aceito:** WebSocket é 1:1, não pub/sub. Para o cenário inicial (1 corpo, 1 alma) isso é virtude — simplicidade. Multi-corpo no futuro escala a alma com múltiplas conexões WebSocket independentes.

⚠️ **DECISÃO PENDENTE:** confirmar escolha de WS contra MQTT específicamente — MQTT poderia ser mais natural se o ecossistema Atlas já usa um broker. Ver `10-anexos/C-decisoes-pendentes.md`.

---

## 2. Topologia

```
┌────────────────────────┐          ┌────────────────────────┐
│ StackChan (corpo)      │          │ Atlas backend (alma)   │
│                        │          │                        │
│  WebSocket client ──── │ ──wss──► │ ──── WebSocket server  │
│                        │          │                        │
│  Mantém conexão        │          │  Aceita conexões       │
│  persistente           │          │  identificadas         │
└────────────────────────┘          └────────────────────────┘
```

- **Corpo é cliente.** Inicia conexão. Reconecta se cai.
- **Alma é servidor.** Aceita, autentica, mantém registro de surfaces conectadas.

### Por que cliente é o corpo

- Corpo está em rede potencialmente NAT'ed (LAN doméstica).
- Alma está em endereço estável (ou acessível via tunnel).
- NAT traversal de cliente → servidor é trivial; o contrário exige port-forwarding ou STUN.

### Modos de deployment

| Modo | Descrição | Quando |
|---|---|---|
| **LAN-direta** | Corpo e alma na mesma rede; WSS direto pro IP/hostname local | Setup doméstico (PC fixo na mesa) |
| **VPN** | Alma exposta via VPN (Tailscale, WireGuard); corpo conecta como peer | Casa + escritório, mantendo isolamento |
| **Tunnel reverso** | Cloudflare Tunnel, ngrok, ou similar; alma fica atrás | Quando alma está em laptop/máquina nômade |
| **Cloud** | Alma deployada em servidor próprio (não vamos depender disso na fase inicial) | Futuro — não é o caso agora |

**Modo padrão para Fase 0:** LAN-direta. Atlas roda no PC do usuário; StackChan está na mesma rede.

---

## 3. Multiplexação de canais

Uma única conexão WebSocket carrega **2 canais lógicos**:

```
WebSocket (uma conexão TCP/TLS)
   ├─ Canal "control" (text frames — JSON)
   │     ├─ Interaction Envelopes (corpo → alma)
   │     ├─ Output Commands / Bundles (alma → corpo)
   │     ├─ ACKs
   │     ├─ Heartbeat (ping/pong aplicação)
   │     └─ Telemetria
   │
   └─ Canal "media" (binary frames)
         ├─ Audio chunks (referenciados por stream_ref)
         ├─ TTS chunks
         └─ Image frames (raros, com consent)
```

### Como funciona a multiplexação

WebSocket distingue text frames (UTF-8 JSON) de binary frames nativamente. Não há overhead extra:

- **Text frame** → control channel.
- **Binary frame** → media channel.

### Header dos frames binários

Binary frames carregam header curto identificando seu `stream_ref`:

```
┌──────────────────────────────────────────────────────────────┐
│ Magic (1 byte) │ stream_id_len (1) │ stream_id (var) │ payload│
└──────────────────────────────────────────────────────────────┘
```

| Campo | Descrição |
|---|---|
| Magic | `0x57` (W) — marca para validação |
| stream_id_len | 0-255, length de stream_id |
| stream_id | Bytes UTF-8 de `stream_ref` |
| payload | Dados (audio chunk, image bytes) |

Frame final do stream tem flag `eos: true` no control channel (envelope JSON separado anuncia fim).

### Alternativa rejeitada: WebSocket dedicado para media

Considerei dois WS separados (control, media). Rejeitado:
- Maior complexidade de sincronização.
- Mais conexões = mais TLS handshake = mais bateria/banda.
- Mais pontos de falha.

Multiplexar num WS único é simples e funciona.

---

## 4. Autenticação

### 4.1 Identidade do corpo

Cada corpo tem um identificador persistente único:

- `surface.id` — ex: `stackchan_main`
- Armazenado em `surface_id.txt` no microSD ou flash.
- Gerado na primeira boot (random + timestamp + MAC).

### 4.2 Credencial

Cada corpo tem **um token compartilhado** com a alma:

- Gerado durante setup (provisioning).
- Armazenado encriptado no flash do corpo (NVS encrypted, ESP32 suporta).
- Apresentado em todas as conexões.

Forma de apresentar: header HTTP `Authorization: Bearer <token>` no upgrade request do WebSocket.

```http
GET /atlas/surface/stackchan HTTP/1.1
Host: atlas.local
Upgrade: websocket
Connection: Upgrade
Authorization: Bearer <token>
X-Surface-Id: stackchan_main
X-Schema-Version: 0.1
X-Capabilities: voice,touch,nfc,imu,camera_presence,ir
```

### 4.3 Provisionamento

Como o corpo recebe o token na primeira vez:

| Método | Descrição |
|---|---|
| **microSD com `provisioning.json`** | Usuário gera no Atlas, copia pra SD, plugga. Boot detecta e migra pra flash. |
| **AP mode + portal web** | Corpo cria WiFi AP "Atlas-Setup-XXXX". Usuário conecta, abre portal, configura. |
| **BLE provisioning** | App futuro escaneia BLE, faz handshake, transfere credenciais. |

**Padrão para Fase 0:** microSD. Mais simples, sem dependências.

### 4.4 Rotação de credencial

Token rotacionável via comando `system.config_update` (com Receipt). Velho fica válido por janela curta (300s) para não cortar conexão durante rotação.

### 4.5 TLS

`wss://` obrigatório. Mesmo em LAN.

- Certificado da alma: pode ser auto-assinado (LAN); corpo carrega CA root via provisioning.
- Em modo VPN/Cloudflare Tunnel: cert público da CA pública.
- Pinning: corpo verifica fingerprint do cert. Mudança = falha de conexão até reprovisioning.

⚠️ **DECISÃO PENDENTE:** TLS auto-assinado em LAN local é trade-off entre simplicidade e atrito de setup. Confirmar.

---

## 5. Lifecycle de conexão

### 5.1 Boot do corpo

```
1. Boot → ler config (Wi-Fi creds, atlas_endpoint, surface_id, token, ca_cert)
2. Conectar Wi-Fi (timeout 30s)
3. Resolver atlas_endpoint via DNS / mDNS
4. TCP+TLS handshake → wss://atlas.local/atlas/surface/stackchan
5. Validar fingerprint do cert
6. WebSocket upgrade com Authorization header
7. Receber confirmação inicial da alma (boas-vindas)
8. Iniciar heartbeat aplicação (a cada 30s)
9. Aguardar comandos / processar eventos físicos
```

### 5.2 Boas-vindas (alma → corpo)

Logo após conexão estabelecida:

```json
{
  "type": "session.welcome",
  "session_id": "ws-2026-05-05T08:42:01Z-stackchan_main",
  "atlas_version": "atlas-0.5.2",
  "schema_version_negotiated": "0.1",
  "supported_commands": ["display.*", "led.*", "servo.*", ...],
  "issued_at": "..."
}
```

Corpo confirma com `session.ack`. A partir daí, fluxo normal.

### 5.3 Heartbeat aplicação

Independente do ping/pong WS nativo (que pode ser swallowed por proxies):

```json
{
  "type": "heartbeat",
  "from": "stackchan_main",
  "at": "2026-05-05T08:42:31Z",
  "telemetry": {
    "battery_pct": 78,
    "is_charging": true,
    "thermal_state": "normal",
    "wifi_rssi": -55,
    "uptime_s": 13892,
    "current_mode": "ambient",
    "command_queue_depth": 0,
    "free_psram_kb": 3812
  }
}
```

- Corpo envia a cada **30s ± 1s**.
- Alma responde com `heartbeat_ack` (curto, só timestamp).
- Sem ack em 90s → corpo entra em **modo degradado** (presume alma morta).
- Sem heartbeat em 90s → alma marca corpo como **disconnected** no surface registry.

### 5.4 Reconexão

Quando conexão cai (TCP error, TLS error, app heartbeat fail):

```
1. Marcar conexão como morta. Cancelar todos os comandos in-flight.
2. Sinalizar L0 (Reflex) → entrar em "modo reconectando" (LED roxo pulsando).
3. Esperar backoff:
   - Tentativa 1: 1s
   - Tentativa 2: 2s
   - Tentativa 3: 4s
   - Tentativa 4: 8s
   - Tentativa N: max 30s
   - + jitter aleatório de ±20%
4. Reconectar. Se sucesso → modo ambient.
5. Se falhar repetidamente (> 1 hora): permanecer em degraded até intervenção.
```

Durante reconexão, corpo:
- Continua reflex layer (animação idle, ajuste de brilho).
- Aceita touch combinado para "modo silencioso" físico.
- **Não tenta operar sem alma.** Modo degradado é digno.

### 5.5 Replay após reconectar

Por design, **não há replay**. Quando reconecta:
- Estado da alma é a fonte da verdade.
- Alma re-envia comandos relevantes ao estado atual (display.show_face, set_mode, etc.).
- Comandos perdidos durante a queda **são perdidos**. Curator pode revisar via Ledger se algo importante foi perdido.

Razão: replay introduz complexidade (idempotência, ordering) e raramente vale a pena para comandos visuais. O que importa é o estado atual, não o histórico de comandos.

### 5.6 Shutdown gracioso

Antes de boot/reset/OTA, corpo envia:

```json
{
  "type": "session.bye",
  "reason": "ota" | "manual_reboot" | "thermal_shutdown",
  "at": "..."
}
```

Alma marca surface como offline planejado (não dispara alertas).

---

## 6. Detecção de queda

| Lado | Mecanismo |
|---|---|
| Corpo detecta queda da alma | Heartbeat sem ack em 90s |
| Alma detecta queda do corpo | Sem heartbeat recebido em 90s |
| Detecção rápida (TCP RST) | Imediata em ambos os lados |
| Wi-Fi caiu | Driver Wi-Fi do ESP32 emite evento; corpo reage em <5s |

Em todos os casos, transição para modo degradado é rápida (≤ 90s, target ≤ 30s no caso comum).

---

## 7. Throughput e latência

### Capacidade prática

| Métrica | Valor típico (LAN) |
|---|---|
| Latência control message round-trip | 10-50ms |
| Throughput sustentado | 5-10 Mbps (limite Wi-Fi 2.4GHz) |
| Latência audio chunk → alma | 30-80ms (LAN) |
| Audio bandwidth (Opus 16kHz mono) | ~24 kbps |
| Audio bandwidth (PCM 16kHz mono) | ~256 kbps |

### Compressão de áudio

⚠️ **DECISÃO PENDENTE:** Opus encoder no corpo (CPU), ou PCM raw (banda)?

- **Opus**: encode no ESP32 cabe (~5-10% CPU). Banda baixa (24 kbps). Latência adicional ~20ms.
- **PCM**: zero encode CPU. Banda alta (256 kbps em 16kHz). Latência mínima.

Em LAN local, PCM é viável. Para tunnel/VPN/cloud, Opus é melhor.

**Provisional:** começar com PCM em LAN; migrar para Opus quando necessário.

---

## 8. Backpressure

Se corpo recebe comandos mais rápido do que executa (raro mas possível):

- Corpo mantém fila de comandos (limite ~50).
- Se fila cheia: rejeita novos com ACK `state: rejected`, `reason: queue_full`.
- Telemetria reporta `command_queue_depth` continuamente.

Alma usa essa info para:
- Se queue_depth alta → reduzir taxa de envio.
- Se queue_depth alta sustentada → registrar evento, alertar (algo errado).

---

## 9. Segurança em camadas

| Camada | Proteção |
|---|---|
| Rede | LAN privada / VPN / TLS 1.3 |
| Transporte | WebSocket sobre TLS, cert pinning |
| Autenticação | Bearer token rotacionável |
| Autorização | Comandos sem `decision_receipt_ref` rejeitados |
| Auditoria | Tudo no Evidence Ledger |
| Privacy | Modo privado físico, captura controlada por policy |

Detalhes de privacy em `05-policies/01-privacidade.md`.

---

## 10. Casos de erro

| Erro | Comportamento |
|---|---|
| Wi-Fi password mudou | Reconnect falha, backoff, eventualmente entra em recovery mode (AP setup) |
| Token rejeitado | Corpo registra erro local, entra em "needs reprovisioning" (LED branco fixo + face de "?") |
| Cert fingerprint mudou | Conexão recusada. Mesmo estado anterior. |
| Schema version não suportada pela alma | Alma fecha com motivo. Corpo aguarda alma atualizar. |
| Frame malformado | Loga, descarta, **não desconecta** (resiliência) |
| Frame binary com magic errado | Idem |
| Capability advertida mas alma comanda fora dela | Alma faz validação; bug se acontecer |

---

## 11. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ MQTT vs WebSocket — confirmar | Tudo |
| ⚠️ Opus vs PCM para áudio inicial | Fase 1 |
| ⚠️ TLS auto-assinado em LAN ou CA própria | Provisioning |
| ⚠️ mDNS vs config estática para discovery | Setup UX |
| ⚠️ Replay strategy — confirmar "sem replay" | Robustez |
| ⚠️ Tamanho da fila de comandos no corpo | Implementação |

---

## 12. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| WebSocket sem TLS em LAN | "É só LAN" não justifica — padrão deve ser secure |
| Token hardcoded no firmware | Compartilhado entre unidades = nenhum segurança |
| Reconnect imediato (sem backoff) | Storm de tentativas se alma cair por horas |
| Replay de comandos antigos | Estado da alma é fonte da verdade |
| HTTP polling como fallback | Mata bateria e UX; melhor degraded mode |
| Múltiplos WS simultâneos | Multiplexar é mais simples |

---

## Próximos passos de leitura

- `02-eventos-evidence.md` — quais eventos de transporte vão pro Ledger.
- `05-streaming.md` — TTS streaming detalhado em cima deste transporte.
- `06-firmware-stackchan/02-reflex-layer.md` — implementação do reconnect/heartbeat.
