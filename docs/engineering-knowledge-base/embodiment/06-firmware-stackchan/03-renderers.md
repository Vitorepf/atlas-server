# 03 — Renderers (firmware)

> **Propósito:** especificar os **renderers físicos** do firmware — os módulos que traduzem comandos da alma (`Output Commands`) em ação concreta sobre hardware: face no display, padrão nos LEDs, gesture nos servos, áudio no speaker, código IR transmitido. Cada renderer é estável, focado, e segue padrão consistente.
>
> **Pré-requisitos:** [README](../README.md), [01-escolha-stack.md](01-escolha-stack.md), [02-reflex-layer.md](02-reflex-layer.md), [04-protocolos/03-comandos-fisicos.md](../04-protocolos/03-comandos-fisicos.md).
>
> **Fora do escopo:** Output Renderer especializado da alma (vai pra `07-integracao-atlas/02-output-renderer.md`); reflex local (já em `02-reflex-layer.md`).

---

## 1. Definição

> **Renderer** = módulo do firmware que recebe **um tipo de comando físico** e o materializa em hardware. Cada modalidade de saída tem seu renderer.

Tipos de renderer no StackChan:

| Renderer | Comandos que processa |
|---|---|
| FaceRenderer | `display.show_face`, `display.transition`, `display.clear` |
| CardRenderer | `display.show_card` |
| LedRenderer | `led.set_pattern`, `led.set_zone`, `led.flash` |
| ServoRenderer | `servo.set_target`, `servo.gesture`, `servo.idle_pattern` |
| AudioRenderer | `audio.play_sound`, `audio.tts_stream`, `audio.tts_stop`, `audio.set_volume` |
| IrRenderer | `ir.transmit` |
| SystemRenderer | `system.set_mode`, `system.degraded_mode`, `system.cancel`, `system.config_update`, `system.firmware_update_available`, `system.reboot` |

---

## 2. Princípios

### P1 — Renderer **executa**, não interpreta
Recebe comando bem-formado, executa, ACKa. Sem decisão. Sem fallback criativo.

### P2 — Atômico por comando
Cada comando: receber → validar → executar → ACK. Sem mid-states ambíguos.

### P3 — Coordenação via Command Dispatcher
Renderers não falam entre si. Coordenação acontece **acima** (Command Dispatcher distribui bundle aos renderers em paralelo). Cada renderer ignora os outros.

### P4 — Idempotência
Se mesmo `command_id` chega duas vezes, executa só a primeira. Cache pequeno de IDs recentes (60s).

### P5 — Reverte limpo
Se receber `system.cancel` durante execução, aborta de forma limpa (sem deixar estado inconsistente).

---

## 3. Arquitetura comum

Todos os renderers seguem padrão:

```
┌──────────────────────────────────────────────────┐
│ Command Dispatcher                               │
└──────────┬───────────────────────────────────────┘
           │ (route por command.type)
           ▼
┌──────────────────────────────────────────────────┐
│ <SpecificRenderer>                               │
│  ├─ validate_payload(cmd) → bool                 │
│  ├─ check_idempotency(cmd_id) → bool             │
│  ├─ check_mode_compatibility(cmd, current_mode)  │
│  ├─ execute(cmd) → result                        │
│  ├─ emit_ack(cmd_id, state, latency)             │
│  └─ handle_cancel(cmd_id) → void                 │
└──────────────────────────────────────────────────┘
```

Cada renderer **não bloqueia a fila do Dispatcher**. Comandos longos (TTS streaming) executam em task própria.

---

## 4. FaceRenderer

### Responsabilidade
Renderiza avatar facial no display IPS 2.0" 320×240.

### Vocabulário fechado de expressões

| Expression ID | Estado emocional/funcional |
|---|---|
| `neutral` | Default ambient |
| `attentive` | "Te ouvindo" — pós wake word |
| `thinking` | L2 deliberation ativa |
| `informative` | Mostrando info-card |
| `happy` | Sucesso, ack positivo |
| `sad` | Erro, modo degradado |
| `concerned` | Quality gate falhando |
| `surprised` | Reflex IMU "ser pego" |
| `private` | Modo private (face com indicador) |

Cada expression tem **palette modulator**: `default`, `focused`, `warm`, `alert`, `degraded`. Combinação `expression × palette` determina cor base + acento.

### Implementação

- Sprites pré-renderizados em microSD (PNG ou formato compacto).
- Buffer no PSRAM (~150KB por frame).
- Double-buffering para evitar tearing.
- Transitions: fade, slide, cut.
- Frame rate alvo: 30Hz contínuo.

### Reflex local vs comando

- Reflex local: blink, micro-motion (gerados internamente, sem comando da alma).
- Comando da alma: mudança de expression base. **Vence** sobre reflex em conflito.

### Coordenação com Card

Quando `card_render` ativo, face fica em **canto reduzido** (~80×80px). Quando card sai, face volta a tela cheia.

---

## 5. CardRenderer

### Responsabilidade
Renderiza info-cards: title + body + icon + interaction_hint.

### Layout

```
┌────────────────────────────────────┐
│ [icon] Title                       │
│                                    │
│ Body text                          │
│ multi-line if needed               │
│                                    │
│ [pad 1: ✓] [pad 2: ✗] [pad 3: 💬] │ ← interaction_hint (opcional)
└────────────────────────────────────┘
```

### Tipos de card

| `card_type` | Visual style |
|---|---|
| `info` | Default; title + body |
| `alert` | Border vermelho/amarelo, ícone destaque |
| `proposal` | Ícone laranja, interaction_hint visível |
| `status` | Métrica destacada |
| `metric` | Gráfico simples + número |
| `historical` | Timestamp explícito ("ontem", "há 3 dias") |

### Lifecycle

- TTL recebido com comando.
- Renderiza, mantém na tela.
- TTL atinge → desaparece, volta para face neutra (ou face anterior).
- Novo card chega → substitui (slide ou fade).

### Interaction_hint

Quando card tem `interaction_hint` (ex: proposal pendente), CardRenderer **publica para TouchSemantic** que pads X/Y/Z são contextuais. TouchSemantic mapeia toques.

---

## 6. LedRenderer

### Responsabilidade
12 LEDs WS2812C endereçáveis. Padrões de cor + animação.

### Padrões implementados

| Pattern | Descrição |
|---|---|
| `stable` | Cor fixa, intensity fixa |
| `breathing` | Senoidal de intensity (ciclo configurável) |
| `pulse` | Pulse curto periódico |
| `rotating` | Cor "girando" entre LEDs |
| `chase` | Faixa móvel |
| `flash` | Flashes curtos (count + interval) |

### Cores reservadas

| Cor | Reservada para |
|---|---|
| `red` fixo | Captura ativa, modo private |
| `purple` breathing | Modo degraded |
| `orange` pulse | Proposal pendente |
| `yellow` pulse | Quality Gate falhou |
| `green` stable | Sucesso, gate passou |
| `blue` breathing | Idle, ambient |
| Multi-cor rotating | Council deliberation |

Cores não-reservadas livres para usos futuros.

### Implementação

- Driver WS2812C nativo (RMT do ESP32 é eficiente).
- Loop de animação a 30Hz.
- Estado de pattern persistido até novo comando ou cancel.
- Reflex local: idle pattern (azul breathing) ativo quando sem comando.

---

## 7. ServoRenderer

### Responsabilidade
Move pan (360°) e tilt (90°) com easing.

### Comandos

#### `servo.set_target`
Move para `pan_deg, tilt_deg` específicos com `speed` e `easing`.

#### `servo.gesture`
Sequência pré-definida (catálogo fechado):

| Gesture | Sequência (resumida) |
|---|---|
| `nod` | Tilt -10° → 0° → -10° → 0° |
| `shake` | Pan +15° → -15° → +15° → 0° |
| `look_at_user` | Pan/tilt para "frente" (configurável calibração) |
| `look_at_display` | Tilt -15° (olha pra baixo onde card aparece) |
| `look_around` | Pan varredura lenta 360° |
| `tilt_thinking` | Tilt + pan curva pequena, mantém |
| `alert_stance` | Posição "atenta" — tilt 0, pan 0, levemente travado |

#### `servo.idle_pattern`
Liga/desliga micro-movimento idle (loop reflex).

### Implementação

- PWM controlado por timer hardware (preciso, não jitter).
- Easing: lookup table de curvas (linear, ease_in, ease_out, ease_in_out).
- Position feedback (servos têm encoder): valida posição alcançada.
- Detecção de obstrução: target não alcançado em tempo esperado → `command.failed` com motivo.

### Quiet operation

Servo barulhento = falha. Estratégias:
- Speeds calibrados para `slow`/`normal`/`fast` com defaults conservadores.
- Easing reduz aceleração súbita.
- Threshold de mudança mínima: movimento <2° é ignorado (não vale o ruído).

### IMU coordination

Durante movimento de servo, ServoRenderer publica "moving" flag. SensorSampler usa para descontar IMU readings (anti-falso-gesture).

---

## 8. AudioRenderer

### Responsabilidade
Reproduzir áudio: sons curtos, TTS streaming, ack imediatos.

### Comando `audio.play_sound`
- Sound IDs cacheados em microSD (catálogo fechado).
- Carrega em buffer, reproduz, libera.
- Latência: ~50ms.

### Comando `audio.tts_stream`
- Inicia recepção via media channel.
- Buffer mínimo antes de tocar (~100-200ms para evitar underrun).
- Decoder se Opus.
- Reproduz contínuo até `eos` ou `audio.tts_stop`.
- Aplica `voice_profile` recebido, quando o perfil é local; se o áudio já vem pronto do Atlas, reporta apenas o perfil usado.
- Rejeita TTS cognitivo sem `decision_receipt_ref`.
- Emite telemetria de `voice_profile_used`, `voice_fallback_used`, TTFB e underrun.

### Comando `audio.tts_stop`
- Limpa buffer hardware.
- Para reprodução em <500ms.
- Emite ACK.

### Comando `audio.set_volume`
- Ajusta volume base.
- `scope: persistent` salva em config; `session` apenas durante uso.

### Speaker driver

- AW88298 16-bit I²S.
- Sampling rate: 16kHz mono (compatível com TTS típico).
- Volume control via PMIC ou software DSP.

### Privacy considerations

- Som de "shutter" para câmera é **não-silenciável** por comando — garantia de indicação.
- Volume mínimo respeitado (não pode ser zerado por comando).
- Firmware não armazena API key de LLM/TTS e não escolhe Qwen/DeepSeek/Xiaozhi/OpenAI. Provider routing é responsabilidade da alma.

### Voice profile preservation

O perfil primário para o StackChan é `stackchan_default`. Ele representa a voz/persona sonora da superfície física, não o modelo que raciocinou.

Regras:

| Regra | Implementação |
|---|---|
| `stackchan_default` disponível localmente | Corpo executa TTS local ou perfil local equivalente. |
| `stackchan_default` depende de cloud | Atlas gera áudio e envia stream; corpo não vê credencial. |
| Perfil falhou | Corpo reporta fallback; Atlas registra `identity.voice.fallback_used`. |
| Comando sem Receipt | Corpo rejeita com ACK `rejected`. |
| Modelo externo gratuito usado | Invisível ao corpo; só Atlas registra provider route. |

---

## 9. IrRenderer

### Responsabilidade
Transmitir códigos IR para dispositivos do mundo físico (TV, AC, luz).

### Comando `ir.transmit`

```yaml
ir.transmit:
  protocol: nec | rc5 | raw
  code: "0x20DF10EF"
  device_label: "tv_living_room"
```

### Implementação

- Driver IR usa RMT do ESP32 (preciso para timings).
- Catálogo de protocolos comuns (NEC, RC5).
- `raw` permite passar timings brutos para protocolos exóticos.
- Confirmação: emissão é "fire and forget" — não há feedback do dispositivo. ACK é "transmitido".

### Audit
Cada transmissão registra evento `physical.ir.transmitted` com `device_label` para audit.

---

## 10. SystemRenderer

### Responsabilidade
Comandos administrativos do sistema.

### `system.set_mode`
- Aciona ModeManager para transição.
- ModeManager propaga para outros renderers (suprimir reflexos, ajustar paletas, etc.).

### `system.degraded_mode`
- Variante explícita; útil para forçar entrada em degraded por motivos de teste.

### `system.cancel`
- Recebe lista de targets (command IDs ou type wildcard).
- Distribui cancel para renderers afetados.
- Cada renderer cancela seu trabalho.

### `system.config_update`
- Persiste config (NVS encrypted ou microSD).
- Aplica imediatamente quando aplicável (ex: `voice_profile`).

### `system.firmware_update_available`
- Não aplica — apenas sinaliza.
- LED amarelo pulse + info-card "update disponível".
- Usuário decide aplicar (futuro).

### `system.reboot`
- Cuidado máximo — requer Receipt válido.
- Delay configurável (anuncia ao usuário).
- Reset físico após delay.

---

## 11. Coordenação entre renderers

### Bundle execution

Quando bundle chega:

```json
{
  "bundle_id": "bnd-...",
  "execution": "parallel",
  "commands": [
    { "type": "display.show_face", ... },
    { "type": "led.set_pattern", ... },
    { "type": "servo.gesture", ... },
    { "type": "audio.tts_stream", ... }
  ]
}
```

Command Dispatcher distribui aos respectivos renderers **em paralelo**. Cada renderer executa independente, ACKa quando termina.

### Modo `sequential`

Comandos executam em ordem. Renderer N só inicia após renderer N-1 ACKar.

### Modo `atomic`

Tudo executa ou nada — se um falhar, melhor esforço para reverter.

⚠️ **DECISÃO PENDENTE:** atomic é difícil em hardware (já piscou o LED, não desfaz). Provavelmente "best effort revert" + log.

---

## 12. Performance budget

Cada renderer tem orçamento de tempo de execução. Não bloqueia o pipeline geral.

| Renderer | Latência típica | Tempo máximo aceitável |
|---|---|---|
| FaceRenderer | ~30ms | 100ms |
| CardRenderer | ~50ms | 200ms |
| LedRenderer | ~10ms | 50ms |
| ServoRenderer | depende do gesture (50ms-2s) | 5s (timeout) |
| AudioRenderer (sound_id) | ~50ms | 200ms |
| AudioRenderer (tts) | streaming contínuo | N/A |
| IrRenderer | ~100ms | 500ms |
| SystemRenderer | varies | varies |

Excedeu? Comando vai pra `failed` com motivo `timeout`.

---

## 13. ACK semântica

Cada renderer ACKa de forma própria mas convergente:

- `received` (raro emitido — implícito normalmente)
- `started` (emitido para comandos longos: tts_stream, gesture, transition)
- `executed` (concluído com sucesso)
- `failed` (com `details.reason`)
- `superseded` (cancelado por outro comando antes de executar)
- `expired` (TTL passou antes de executar)
- `rejected` (validação falhou)

ACKs vão pelo control channel para a alma — registrados no Evidence Ledger.

---

## 14. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Renderer interpretando comando ("vai dizer X" → improvisa TTS) | Renderer executa, não interpreta |
| Renderer falando direto com outro (LED chamando Audio) | Coordenação é do Dispatcher |
| Comandos sem ACK | Quebra audit |
| Estado entre renderers compartilhado mutável | Acoplamento ruim |
| Renderer cacheando comandos para "otimizar" | Idempotência cobre; sem cache complexo |
| Renderer ignorando `current_mode` | Privacy/contract violation |
| Sound de captura suprimível por comando | Privacy P4 |
| Servo movendo a velocidade `fast` por padrão | Ruído |

---

## 15. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Formato de sprites de face (PNG vs proprietário) | Asset pipeline |
| ⚠️ Lib de PNG decode no ESP32 (lodepng vs PNGdec) | Performance |
| ⚠️ Calibração de speed/easing dos servos | UX (ruído) |
| ⚠️ Volume default e curve | UX |
| ⚠️ Sound_id catalog | Fase 0+1 |

---

## Próximos passos de leitura

- `04-build-flash.md` — pipeline de build/flash.
- `05-monitoramento.md` — telemetria e logs.
- `04-protocolos/03-comandos-fisicos.md` — schemas dos comandos.
- `07-integracao-atlas/02-output-renderer.md` — quem envia comandos.
