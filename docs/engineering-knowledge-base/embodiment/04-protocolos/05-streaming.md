# 05 — Streaming (áudio, imagem, TTS)

> **Propósito:** especificar como **dados contínuos de média** atravessam a fronteira corpo↔alma. Áudio do mic, TTS de volta, imagem da câmera. Latência percebida do Embodiment depende quase inteiramente de fazer streaming corretamente.
>
> **Pré-requisitos:** [README](../README.md), [04-transporte.md](04-transporte.md), [03-comandos-fisicos.md](03-comandos-fisicos.md), [01-interaction-envelope.md](01-interaction-envelope.md).
>
> **Fora do escopo:** algoritmos de STT/TTS específicos (decisão de provider em outro doc), implementação concreta de codecs.

---

## 1. Por que streaming é crítico

Sem streaming:
- Mic captura 4s → envia tudo → STT processa 4s → LLM 4s → TTS gera 4s → reproduz 4s = **~16s de latência percebida**.

Com streaming completo:
- Mic envia chunks em tempo real → STT começa a processar a primeira palavra → LLM começa a planejar quando STT entrega parciais → TTS começa a gerar quando LLM entrega tokens → corpo começa a reproduzir TTS quando primeiro chunk chega = **~1.5-3s** até começar a falar.

Streaming não é otimização. É **a forma** que o sistema funciona.

---

## 2. Streams existentes no Embodiment

| Stream | Direção | Encoding inicial | Bandwidth típico |
|---|---|---|---|
| `audio_capture` | Corpo → Alma | PCM 16kHz mono (Fase 1); Opus depois | ~256 kbps PCM, ~24 kbps Opus |
| `audio_tts` | Alma → Corpo | Opus 16kHz mono (recomendado) ou PCM | ~24-256 kbps |
| `image_capture` | Corpo → Alma | JPEG | ~50-200 KB por frame |

Imagem não é "streaming" no sentido contínuo — é frame único. Mas atravessa o mesmo media channel.

---

## 3. Estrutura de chunk

Chunks viajam pelo **media channel** (binary frames do WebSocket — ver `04-transporte.md`).

### Header de chunk

```
┌──────────────────────────────────────────────────────────────────┐
│ Magic │ Version │ stream_id_len │ stream_id │ seq │ flags │ data │
│  1B   │   1B    │      1B       │  variable │ 4B  │  1B   │ var. │
└──────────────────────────────────────────────────────────────────┘
```

| Campo | Detalhe |
|---|---|
| Magic | `0x57` (W) — validação |
| Version | `0x01` |
| stream_id_len | Length do stream_id (max 255) |
| stream_id | UTF-8 (`audio:...:abcd`) |
| seq | uint32 — sequência incremental |
| flags | bit0 = first; bit1 = last (eos); bit2 = keyframe; outros reservados |
| data | Payload binário do chunk |

### Por que header vs envelope JSON

Headers binários minimizam overhead em frames de áudio (que são pequenos e frequentes). Envelope JSON pesado a cada 20ms seria desperdício. Estado de stream é gerenciado por mensagens **control channel** separadas.

---

## 4. Lifecycle de um stream

Cada stream tem 4 fases:

### 4.1 Anúncio (control channel)

Quem inicia anuncia primeiro pelo control channel:

```json
{
  "type": "stream.announce",
  "stream_ref": "audio:stkc_main:2026-05-05T14:32:11Z:a8f2",
  "direction": "in",
  "encoding": "pcm_s16le_16khz_mono",
  "expected_duration_ms_hint": null,
  "metadata": {
    "modality": "voice_streaming",
    "envelope_id": "stkc-..."
  }
}
```

Outro lado registra o stream e prepara recepção.

### 4.2 Streaming (media channel)

Chunks fluem com `seq` incremental, `flags.first` no primeiro, sem flag durante, `flags.last` no último.

### 4.3 Encerramento (control channel)

```json
{
  "type": "stream.end",
  "stream_ref": "audio:...:a8f2",
  "outcome": "completed" | "cancelled" | "error",
  "total_chunks": 92,
  "total_bytes": 24576,
  "duration_ms": 1574,
  "details": null
}
```

### 4.4 Cancelamento mid-flight (control channel)

```json
{
  "type": "stream.cancel",
  "stream_ref": "audio:...:a8f2",
  "reason": "user_interrupt" | "timeout" | "policy" | "error"
}
```

Após cancel, recipientes descartam buffer e qualquer chunk recém-chegado.

---

## 5. Audio capture (corpo → alma)

### 5.1 Pipeline no corpo

```
Mic ── ES7210 codec ── PCM 16-bit 16kHz mono ──┐
                                               │
                 ┌─────────────────────────────┘
                 ▼
         VAD local (detecta silêncio)
                 │
                 ▼
         Buffer chunks (~20-50ms)
                 │
                 ▼
         (Opcional: Opus encoder)
                 │
                 ▼
         Media channel chunks (seq incremental)
                 │
                 ▼
              [Alma]
```

### 5.2 Chunk size

- **20-50ms por chunk** é o sweet spot.
  - Menor → overhead de header + jitter de rede.
  - Maior → latência percebida sobe.
- 16kHz × 16-bit × 1 canal × 32ms = ~1024 bytes por chunk PCM.

### 5.3 VAD (Voice Activity Detection)

VAD local no corpo encerra o stream quando:
- N segundos de silêncio (default 1.5s).
- Duração total atinge limite (default 30s).
- Comando explícito (interrupt).

Streamar silêncio é desperdício. Mas **VAD não pode ser agressivo demais** — cortar usuário pensando virou problema clássico de assistentes.

⚠️ **DECISÃO PENDENTE:** VAD local no firmware (CPU, mais bateria) ou na alma (mais banda)? Para PCM, alma é melhor (banda em LAN não é problema). Para Opus em tunnel, local pode ser preferível.

### 5.4 Encoding

#### PCM (default Fase 1)
- Zero overhead de codec.
- Bandwidth alto (~256 kbps).
- Latência mínima.
- Funciona em LAN sem stress.

#### Opus (futuro / banda restrita)
- Compressão excelente para voz (~24 kbps).
- Latência adicional ~20ms (encoder no ESP32).
- ~5-10% CPU.
- Mais útil em VPN/tunnel/cloud.

Decisão de qual usar fica em `01-hardware/03-orcamento-recursos.md` (a escrever) e `04-transporte.md`.

### 5.5 Latência alvo

Do início da fala até primeiro chunk chegar na alma:

| Componente | Latência |
|---|---|
| Mic → buffer | ~20-50ms (chunk size) |
| Buffer → frame WS | <10ms |
| WS → alma (LAN) | ~10-30ms |
| Total (LAN) | ~40-100ms |
| Total (VPN/cloud) | ~100-300ms |

Suficiente para STT streaming começar a processar quase tempo real.

---

## 6. Audio TTS (alma → corpo)

### 6.1 Pipeline na alma

```
LLM tokens streaming ──┐
                       │
                       ▼
                 Sentence buffer (acumula até pausa natural)
                       │
                       ▼
                 TTS provider (streaming) ──── Audio chunks
                                                    │
                                                    ▼
                                            Media channel
                                                    │
                                                    ▼
                                                [Corpo]
                                                    │
                                                    ▼
                                            Speaker buffer ──── Reproduz
```

### 6.2 TTS streaming

Provider TTS (ElevenLabs, OpenAI, VOICEVOX, etc.) precisa suportar **streaming**:
- Recebe texto incremental.
- Devolve áudio chunk a chunk.

Latência típica do provider:
- TTFB (time to first byte) ~200-500ms.
- Sustentado a partir daí.

### 6.3 Chunk format TTS

Mesma estrutura de chunk que audio_capture, mas direção inversa.

```json
{
  "type": "stream.announce",
  "stream_ref": "tts:atlas:2026-05-05T14:32:13Z:bx7a",
  "direction": "out",
  "encoding": "opus_16khz_mono",
  "voice_profile": "stackchan_default",
  "fallback_voice_profile": "atlas_default_pt_br",
  "persona_style": "stackchan_default",
  "expected_duration_ms_hint": 3500,
  "metadata": {
    "decision_receipt_id": "rcpt-...",
    "interruptible": true,
    "tts_route": "atlas_resolved_provider",
    "provider_hidden_from_firmware": true
  }
}
```

O firmware não recebe o nome do LLM usado nem credenciais de provider. Se o TTS for cloud, Atlas resolve provider, gera o áudio e envia chunks. Se o TTS for local, Atlas ainda envia o comando autorizado com `voice_profile`; o corpo apenas executa a engine local declarada em `surface.capabilities`.

### 6.4 Reprodução no corpo

Speaker buffer:
- Recebe chunks.
- Bufferiza ~100-200ms antes de começar (evita underrun).
- Reproduz contínuo.
- Se buffer se exaurir mid-stream: pausa breve + log.

Decoder Opus (se Opus): no ESP32, usar lib otimizada (libopus port).

### 6.5 Cancelamento (interruption)

Usuario pode interromper TTS:
- Wake word durante TTS (raro mas possível).
- Touch deliberado (pad de "stop" se mapeado).
- Comando "Atlas, espera".

Quando cancela:
1. Corpo envia `stream.cancel`.
2. Alma para de gerar chunks.
3. Corpo limpa buffer, vai pra silêncio.
4. Face/LED transicionam.

### 6.6 Latência alvo

Do final da pergunta até primeira sílaba sair do speaker:

| Componente | Latência |
|---|---|
| LLM TTFT | ~300-1500ms (depende provider) |
| TTS TTFB | ~200-500ms |
| WS → corpo | ~30-100ms |
| Buffer + decode | ~100-200ms |
| **Total** | ~700-2300ms típico |

Combinado com reflex sonoro (~100ms ack imediato) e face de "te ouvindo" (~200ms), **a janela cognitiva fica coberta**. O usuário não sente como espera vazia.

---

## 7. Image capture (corpo → alma)

### 7.1 Quando

Frame único, sob comando explícito. Não é stream contínuo.

### 7.2 Pipeline

```
Câmera GC0308 ── frame buffer ── JPEG encode ── chunks media channel ── alma
```

### 7.3 Encoding

JPEG, qualidade ajustável (default 75 = ~50KB para 320×240; ~150KB para 640×480).

### 7.4 Chunking

Mesmo header binário. Frame inteiro vai em múltiplos chunks (~4-8 chunks de ~10-20KB cada).

### 7.5 Latência

| Componente | Latência |
|---|---|
| Capture | ~100-200ms |
| Encode | ~50-100ms |
| Transmit (LAN) | ~50-200ms |
| Total | ~200-500ms |

### 7.6 Privacy

- LED + sound shutter durante captura (`05-policies/01-privacidade.md`).
- Imagem **não persiste no corpo** (zera buffer após envio).
- Alma processa e descarta por padrão.

---

## 8. Sincronização control + media

Control channel (text frames) e media channel (binary frames) são canais lógicos da **mesma conexão WS**. Mas ordem entre eles pode variar:

- Stream announce (control) **deve chegar antes** dos primeiros chunks (media).
- Stream end (control) **pode chegar antes ou depois** do último chunk — recipiente espera ambos.

Implementação:
- Recipiente buffer chunks até announce chegar (timeout pequeno, ex.: 500ms).
- Sem announce: chunks órfãos descartados, log de warning.

⚠️ **DECISÃO PENDENTE:** garantir ordem via filas separadas dentro do WS, ou aceitar reordenação com timeout? Implementação inicial: aceitar com timeout.

---

## 9. Backpressure

### Corpo → alma (audio capture)

- Se alma não consegue processar STT rápido o bastante, buffer enche.
- Corpo monitora confirmações (heartbeat carrega `stream_received_seq` da alma).
- Se delta > N chunks (default 50), reduzir taxa ou pausa.

### Alma → corpo (TTS)

- Speaker buffer cheio → corpo manda back-pressure pelo control channel.
- Alma pausa envio de chunks.
- Quando buffer desce, alma retoma.

### Estratégias de mitigação

| Cenário | Estratégia |
|---|---|
| Corpo lento processando | Reduzir bitrate (Opus VBR low), reduzir frame rate |
| Alma lenta processando | Reduzir frequência de wake (ignora por X segundos) |
| Rede congestionada | Aumentar chunk size (menos overhead de frames WS) |

---

## 10. Múltiplos streams simultâneos

Pode haver múltiplos streams concorrentes:

- Audio capture (ouvindo) + TTS (falando) ao mesmo tempo? **Raro mas possível** (interrupting).
- Image capture + audio capture: possível (ex.: "tira foto e me diz o que vê").

Cada stream tem `stream_ref` único. Multiplexação no media channel funciona — chunks de streams diferentes intercalados, identificados pelo header.

⚠️ **DECISÃO PENDENTE:** limite de streams simultâneos (default sugerido: 2 áudio + 1 imagem max).

---

## 11. Eventos no Evidence Ledger

Conforme `02-eventos-evidence.md`:

| Quando | Evento |
|---|---|
| Stream announce | `stream.audio.started` ou `stream.tts.started` ou `stream.image.captured` |
| Stream end | `stream.audio.ended` etc. |
| Cancel | `stream.audio.cancelled` etc. |
| Backpressure ativada | `stream.backpressure.applied` |

Chunks individuais **não** vão pro Ledger.

---

## 12. Testes e validação

Para validar streaming corretamente:

| Teste | Objetivo |
|---|---|
| Latência ponta-a-ponta | Wake word até primeira sílaba TTS — deve ser < 3s típico |
| Cancelamento mid-stream | Interrompe áudio em curso, áudio sai imediato |
| Reconexão durante stream | Stream cancelado, próxima interação inicia novo |
| Backpressure | Saturação artificial; sistema degrada graciosamente |
| Privacy indication | LED + face acendem antes do primeiro chunk de áudio capturado |
| Encoding match | PCM/Opus negociados corretamente entre corpo e alma |

Detalhes em `09-testes/` (a escrever).

---

## 13. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Esperar stream completo antes de processar | Mata latência percebida |
| Chunks muito grandes (>500ms) | Latência sobe |
| Chunks muito pequenos (<10ms) | Overhead de frame WS domina |
| Sem VAD (audio capture sem fim) | Janela infinita = privacy ruim + banda |
| Sem cancelamento | Atlas falando muito tempo sem deixar interromper |
| TTS sem buffer mínimo | Underrun = áudio truncado |
| Persist chunks no Ledger | Volume + privacy |
| LED de captura aceso depois do primeiro chunk | Atrasa indicação visual |

---

## 14. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ PCM vs Opus em Fase 1 | Implementação |
| ⚠️ VAD local ou alma | CPU vs banda |
| ⚠️ Janela max de captura | UX |
| ⚠️ Tamanho de chunk em ms | Implementação |
| ⚠️ Buffer TTS mínimo antes de tocar | UX |
| ⚠️ Tolerância de reordenação control/media | Implementação |
| ⚠️ Limite de streams concorrentes | Implementação |
| ⚠️ Provider TTS inicial | Fase 1 |
| ⚠️ Provider STT inicial | Fase 1 |

---

## Próximos passos de leitura

- `04-transporte.md` — transporte abaixo dos streams.
- `02-eventos-evidence.md` — eventos disparados.
- `08-roadmap/02-fase-1-voz.md` — quando isso entra em jogo (a escrever).
- `06-firmware-stackchan/03-renderers.md` — implementação de renderers de áudio.
