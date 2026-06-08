# 02 — Reflex Layer (firmware)

> **Propósito:** especificar a implementação do **reflex layer** no firmware do StackChan — o L0 dos loops temporais. É a camada **estritamente local**, sem cognição, que mantém presença sustentada (animação idle, blink, head tracking sonoro, ajuste de brilho, wake word offline) e que **continua operando mesmo com o Atlas inacessível**.
>
> **Pré-requisitos:** [README](../README.md), [01-escolha-stack.md](01-escolha-stack.md), [02-arquitetura/02-loops-temporais.md](../02-arquitetura/02-loops-temporais.md), [03-camadas-de-vida/01-presenca.md](../03-camadas-de-vida/01-presenca.md), [03-camadas-de-vida/02-reatividade.md](../03-camadas-de-vida/02-reatividade.md).
>
> **Fora do escopo:** renderers de saída (vai pra `03-renderers.md`), build/flash (vai pra `04-build-flash.md`).

---

## 1. Definição operacional

> **Reflex Layer** = todo comportamento do firmware que **não depende da alma estar acessível** e **não interpreta semanticamente** estímulos. É reação física pura.

Características:
- Latência alvo: 10-100ms.
- Sem cognição: vira para som, não interpreta o som.
- Sempre rodando: idle não pausa.
- Mode-aware: respeita modos do corpo (DND, private, etc.).
- Sobrevive a queda de conexão: continua durante modo degradado.

---

## 2. Princípios

### P1 — Sem semântica
Reflex reage a **estímulo bruto**: forma de onda, vetor de IMU, evento de presença. Não interpreta conteúdo. Não decide intent. Não "entende" nada.

### P2 — Latência é o KPI
Reflex existe para parecer **vivo agora**. Se reage em 500ms, falhou. Alvo: 10-100ms.

### P3 — Sempre rodando
Animação idle, blink, monitoramento de sensores: nunca pausam. Se pausarem, robô parece morto.

### P4 — Estado mínimo
Reflex tem estado de animação local (frame atual, posição alvo de servo). Não tem estado relacional, contextual, conversacional.

### P5 — Sobrevive sem alma
Modo degradado: reflex layer continua mantendo presença mínima. Wake word ainda funciona localmente (mas sem destino — beep + face).

---

## 3. Arquitetura interna

### 3.1 Modelo de tarefas (FreeRTOS)

ESP32-S3 dual-core. Distribuição sugerida:

```
Core 0 (Wi-Fi system):
  ├─ Wi-Fi stack (kernel)
  ├─ TCP/TLS / WebSocket client (high prio)
  ├─ Audio I/O streaming (real-time prio)
  └─ Telemetry collection

Core 1 (Application):
  ├─ Reflex Loop (high prio)        ◄── ESTE DOCUMENTO
  ├─ Renderer Loop (medium prio)    ◄── 03-renderers.md
  ├─ Sensor Sampler (medium prio)
  ├─ Command Dispatcher (low prio)
  └─ Idle / housekeeping
```

### 3.2 Reflex Loop — frequência e responsabilidades

```
Reflex Loop (~30 Hz):
   ├─ Read sensor snapshots (IMU, ambient, proximity)
   ├─ Process wake word DSP (concurrent)
   ├─ Update animation frame (face blink, servo idle micro-tweak)
   ├─ Apply LED idle pattern
   ├─ Check mode → suppress reactive behaviors if DND/private
   ├─ Detect events from sensors (presence change, IMU gesture, ambient threshold)
   ├─ Dispatch event to Envelope Builder (queue para alma)
   └─ Apply reactive behaviors (head tracking, brightness adjust)
```

Cada iteração: ~33ms. Budget interno por iteração:

| Sub-tarefa | Budget |
|---|---|
| Sensor read | 1-3ms |
| Wake word inference (parcial) | 5-10ms |
| Animation frame compute | 2-5ms |
| Reactive logic | 1-3ms |
| Event dispatch | 1-2ms |
| **Folga total** | ~15-20ms |

Folga grande proposital: rede pode causar jitter; folga absorve.

### 3.3 Sensor Sampler

Tarefa separada, frequência por sensor:

| Sensor | Frequência | Razão |
|---|---|---|
| IMU | 100 Hz | Detecção de gestures rápidos |
| Mic (wake word feed) | 16 kHz contínuo | Pipeline DSP |
| Câmera presença | 1 Hz | Suficiente; economia |
| Proximity | 5 Hz | Mudanças relativamente rápidas |
| Ambient light | 1 Hz | Lento por natureza |
| Touch pads | event-driven | Interrupt nativo |
| NFC | event-driven | Interrupt |

Sensor sampler escreve em estruturas de snapshot que reflex loop lê.

### 3.4 Wake word pipeline

Pipeline contínuo:

```
Mic ── ES7210 ── PCM 16kHz mono ──┐
                                  │
                          DSP Buffer (rolling window ~1.5s)
                                  │
                          Wake word inference (pequeno modelo)
                                  │
                                  ▼
                          Confidence > threshold?
                                  │
                                  ▼
                  Trigger ── disparo de evento ── L1 transition
```

Wake word **nunca envia áudio para a alma**. Apenas dispara evento que fará L1 (Envelope Builder + audio capture streaming) começar.

⚠️ **DECISÃO PENDENTE:** engine de wake word — Porcupine, ESP-Skainet, custom. Cada um tem trade-offs (licenciamento, modelo, performance).

---

## 4. Catálogo de comportamentos reflexivos

Vocabulário fechado, documentado e calibrado:

### 4.1 Idle behaviors (sempre ativo, exceto standby/private_physical)

| Behavior | Cadência | Descrição |
|---|---|---|
| `face_blink` | A cada 4-8s aleatório | Sprite de blink rápido (~150ms) |
| `face_micro_motion` | A cada 30-60s | Pequena mudança de expressão (sutil) |
| `servo_idle_micro` | A cada 30-90s | Pan ±2°, tilt ±1°, easing lento |
| `led_breathing` | Contínuo (ciclo ~4s) | Azul calmo, intensity 30-60% |
| `display_brightness_adjust` | Contínuo | Lerp para target baseado em ambient_light |

### 4.2 Reactive behaviors (disparados por estímulo)

| Behavior | Estímulo | Resposta |
|---|---|---|
| `look_toward_sound` | Som direcional > threshold > 200ms | Pan para direção, espera ~5s, retorna |
| `acknowledge_presence` | Presence change off→on | Vira para usuário, expressão "te vejo" breve |
| `surprise_held` | IMU detecta lift forte | Face surpresa breve, retorna |
| `acknowledge_touch` | Touch pad sem mapeamento contextual | Highlight visual no pad |
| `ambient_dim_response` | Mudança radical de luz | Smooth lerp no brilho |
| `wake_word_ack` | Wake word detectada | Beep curto + face "atento" + LED muda |

### 4.3 Mode-suppression rules

Cada behavior tem matriz de modos onde executa:

| Behavior | ambient | interaction | dnd | private | private_phys | degraded | standby |
|---|---|---|---|---|---|---|---|
| `face_blink` | ✅ | ✅ | ✅ dim | ✅ dim | ❌ (face vermelha) | ✅ triste | ❌ |
| `servo_idle_micro` | ✅ | ✅ reduzido | ❌ | ❌ | ❌ | ✅ raro | ❌ |
| `look_toward_sound` | ✅ | ❌ (em conversa) | ❌ | ❌ | ❌ | ❌ | ❌ |
| `acknowledge_presence` | ✅ | ✅ | ❌ | ❌ | ❌ | ✅ | ❌ |
| `surprise_held` | ✅ | ✅ | ✅ subtle | ❌ | ❌ | ✅ | ✅ subtle |
| `wake_word_ack` | ✅ | ✅ | ✅ subtle | ✅ subtle | ❌ | ✅ degraded ack | ✅ |

Implementação: **antes de qualquer reflex visual**, função `is_behavior_allowed(behavior_id, current_mode)` é chamada. Se retornar false, behavior aborta antes de comandar hardware.

---

## 5. Anti-jitter filtros

Reflexos sem filtro = nervoso. Cada modalidade tem filtro próprio:

### 5.1 Som direcional
- Confiança da direção > N por mais de 200ms.
- Direção estável (variação < 30°) durante esses 200ms.
- Sem `look_toward_sound` recente (cooldown de 8s).

### 5.2 Presença
- Estado consistente em 3+ samples consecutivas (3s a 1Hz).
- Hysteresis: entrar requer N=3, sair requer N=5 (mais conservador para não "perder" usuário sentado quieto).

### 5.3 IMU gestures
- Pattern matching contra biblioteca de gestures.
- Filtro de movimento próprio: durante movimento de servo, sample de IMU é ignorado (~500ms após `servo.set_target`).
- Cooldown entre gestures (2s).

### 5.4 Ambient light
- Lerp contínuo em vez de degraus discretos.
- Tempo de transição: 1s (suave).
- Threshold de mudança mínima: 5% para não responder a ruído de sensor.

### 5.5 Wake word
- Confidence > threshold conservador (ajustável).
- Cooldown pós-detecção (1s) para não dupla-disparar.
- Janela de "hot" — após detecção, threshold pode baixar para 500ms para captar comando follow-up.

---

## 6. Estado interno do Reflex

Mínimo, mas precisa existir:

```c
typedef struct {
    // Animation state
    uint32_t frame_counter;
    AnimationCursor face_cursor;
    LedPatternState led_state;
    ServoIdleState servo_idle_state;

    // Reactive state
    uint32_t last_blink_at_ms;
    uint32_t last_idle_motion_at_ms;
    Direction last_sound_direction;
    uint32_t last_sound_at_ms;
    bool last_presence;
    uint32_t presence_stable_since_ms;

    // Mode awareness
    OperatingMode current_mode;
    PrivacyMode current_privacy;

    // Wake word state
    bool wake_word_active;
    float last_confidence;
    uint32_t last_wake_at_ms;
} ReflexState;
```

Estado é **per-instance** (uma instância por reflex loop). Não persistido — recria no boot.

---

## 7. Interação com o resto do firmware

```
Reflex Layer
   │
   ├─ Reads from: SensorSnapshots (IMU, ambient, etc)
   │
   ├─ Receives mode updates from: ModeManager (consumes commands from alma)
   │
   ├─ Sends events to: EnvelopeBuilder (que envia pra alma)
   │
   ├─ Sends visual commands to: LocalRenderer (face/LED/servo)
   │       ⚠️ não a Renderers expostos pela alma — esses são para comandos
   │       da alma. Reflex usa caminho local direto para latência mínima.
   │
   └─ Reads wake_word events from: WakeWordEngine
```

### Princípio: dois caminhos de renderização

- **Local**: reflex → hardware diretamente (sem Receipt, sem ACK). Para idle, blink, micro-respostas.
- **Comandado**: alma → command_dispatcher → renderers → hardware (com Receipt e ACK). Para output cognitivo.

Os dois caminhos podem coexistir — durante TTS streaming, idle local continua (blink). Mas em conflito (alma manda `face_render: thinking`, reflex queria `face_blink` no mesmo momento), **comando da alma vence**.

---

## 8. Privacy hooks

Reflex layer **respeita garantias** de privacy:

| Garantia | Implementação |
|---|---|
| Áudio nunca sai sem wake word | Wake word event é **prerequisite** do envelope builder iniciar audio_streaming |
| Modo private_physical: hardware mute | ModeManager invoca função que **desabilita codec ES7210** via I²C e **corta power da câmera** via PMIC. Reflex nem tenta usá-los. |
| Indicação visual de captura ativa | Quando audio capture inicia, reflex transiciona LED para vermelho fixo **antes** do primeiro chunk |
| Wake word não envia áudio | Pipeline DSP é puramente local; resultado (boolean) é o único output |

---

## 9. Modo degradado — comportamento

Quando alma fica inacessível:

```
Wi-Fi/conexão perdida
   ↓
ModeManager → degraded
   ↓
Reflex Layer:
   ├─ Continua animação (mas paleta "triste" — roxo respirando)
   ├─ Continua wake word DSP local
   ├─ Wake word detectada → beep + face "desconectado" + LED roxo flash
   ├─ Touch combo de mute físico continua funcionando
   ├─ Touch outros: highlight visual mas sem ação cognitiva
   ├─ Servo idle: muito reduzido (ou parado)
   └─ Telemetria local persiste em buffer (envia ao reconectar)
```

Reflex layer **não improvisa cognição** em degraded. Não tenta "responder com o que tem". Silêncio digno é melhor.

---

## 10. Testes esperados

### 10.1 Unit
- Anti-jitter filters: alimentar séries simuladas, validar saída esperada.
- Wake word: feed de áudios conhecidos, validar detection rate.
- Mode-suppression matrix: cobertura de todas as combinações.

### 10.2 Integração
- Reflex sob carga (Wi-Fi pesado, comandos chegando, sensores ativos): mantém 30 Hz?
- Modo transitions: reflex se adapta corretamente (DND, private, degraded).
- Privacy garantias: indicação visual antes de captura.

### 10.3 Operacional
- 7 dias contínuos: zero crashes, zero leaks visíveis.
- Térmico: sob throttling, reflex degrada (frame rate reduz), não trava.
- Bateria: idle apenas — autonomia esperada.

---

## 11. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Reflex que conhece "Programming Domain" | Reflex é cego a domain — domain é cognição |
| Reflex respondendo a wake word com TTS local | TTS é cognição |
| Sem cooldown entre reflexes | Vira nervoso |
| Sem mode-check antes de reflex | Bug crítico de privacy |
| Animation idle com sprite cute | Vira mascote |
| Detection threshold de wake word baixo | False positives = quebra confiança |
| Reflex sem indicação visual antes de captura | Privacy P4 violation |
| Estado relacional no reflex layer | Cognição vazando |
| Servo idle frequente | Ruído + bateria |

---

## 12. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Wake word engine final | Implementação |
| ⚠️ Threshold inicial wake word | Calibração |
| ⚠️ Cadência de blink/micro-motion | UX |
| ⚠️ Beamforming dual mic — local ou alma? | Performance |
| ⚠️ Servo idle pattern padrão (frequencies) | UX |

---

## Próximos passos de leitura

- `03-renderers.md` — quem traduz comandos em hardware.
- `04-build-flash.md` — pipeline de build.
- `02-arquitetura/02-loops-temporais.md` — visão teórica.
