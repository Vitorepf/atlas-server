# 01 — Escolha de stack do firmware

> **Propósito:** decidir, com critérios explícitos, qual stack de desenvolvimento é usado para o firmware do StackChan no Atlas Embodiment. Esta decisão tem consequências de longo prazo — toolchain, libs, OTA, comunidade — então é registrada como ADR aqui em vez de presumida.
>
> **Pré-requisitos:** [README](../README.md), [01-hardware/01-componentes.md](../01-hardware/01-componentes.md), [01-hardware/02-limitacoes-fisicas.md](../01-hardware/02-limitacoes-fisicas.md).
>
> **Fora do escopo:** detalhes de implementação de cada módulo (vai pra outros docs em `06-firmware-stackchan/`).

---

## 1. Candidatos

Quatro stacks viáveis para programar o ESP32-S3 do StackChan:

| Stack | Linguagem primária | Filosofia |
|---|---|---|
| **A. PlatformIO + Arduino-ESP32 + libs M5Stack** | C++ | Maturidade, ecosystem amplo |
| **B. ESP-IDF nativo** | C / C++ | Framework oficial Espressif, controle máximo |
| **C. Moddable SDK** | JavaScript / TypeScript | Embedded JS, alto-nível |
| **D. UiFlow2** | Blocos visuais / MicroPython | Rapid prototyping |

---

## 2. Critérios de avaliação

Cada stack avaliado contra critérios explícitos, com peso indicando importância para o projeto:

| Critério | Peso | Por quê importa |
|---|---|---|
| **Maturidade** | 5 | Projeto vai durar anos; abandonware é morte |
| **Performance** | 5 | Reflex layer precisa <100ms; CPU é apertada |
| **Capacidade de WebSocket + TLS** | 5 | Transporte primário (`04-protocolos/04-transporte.md`) |
| **Acesso a hardware completo** | 5 | Servos, LEDs, mic, NFC, IR — tudo precisa funcionar |
| **Manutenibilidade longo prazo** | 4 | Vai ter código vivo por anos |
| **Asset pipeline (sprites, sons)** | 4 | Avatar, TTS playback, sons curtos |
| **OTA confiável** | 4 | Atualizações sem cabo |
| **Familiaridade do desenvolvedor** | 3 | Velocidade de implementação |
| **Comunidade Stack-chan / referência** | 3 | Pode reaproveitar conhecimento |
| **Memory footprint** | 3 | 8MB PSRAM é confortável mas não infinito |
| **Debug experience** | 3 | Serial logs, profiling |
| **Ecossistema de libs** | 2 | Não inventar roda |

---

## 3. Avaliação por stack

### 3.1 A. PlatformIO + Arduino-ESP32 + M5Stack libs

**Stack:** Arduino framework via PlatformIO, com bibliotecas oficiais M5Stack (`M5Unified`, `M5GFX`).

| Critério | Nota | Comentário |
|---|---|---|
| Maturidade | 9/10 | Arduino-ESP32 é Espressif-mantido; M5Unified é a biblioteca oficial M5Stack |
| Performance | 8/10 | Arduino tem overhead pequeno; suficiente para reflex layer |
| WebSocket + TLS | 9/10 | Libs maduras (`ArduinoWebsockets`, `WebSocketsClient`) com TLS |
| Acesso a hardware | 10/10 | M5Unified expõe tudo do CoreS3 (display, mic, IMU, etc.) |
| Manutenibilidade | 8/10 | C++ moderno; estrutura modular padrão |
| Asset pipeline | 8/10 | M5GFX é poderoso; SPIFFS/LittleFS para assets |
| OTA | 9/10 | Arduino-ESP32 OTA é battle-tested |
| Familiaridade | 9/10* | Maioria de devs embedded conhece Arduino |
| Comunidade Stack-chan | 7/10 | Comunidade ocidental usa principalmente Arduino; robo8080 usa Arduino |
| Memory footprint | 7/10 | Razoável; sobra RAM e flash para o que precisamos |
| Debug | 8/10 | Serial.print + ESP-IDF logging via Arduino core |
| Ecossistema | 10/10 | Tudo tem lib |

**Soma ponderada estimada: ~340/415 (~82%)**

**Pontos fortes:**
- Documentação M5Stack assume Arduino.
- robo8080's `M5Unified_StackChan_ChatGPT` é Arduino — referência viva.
- PlatformIO traz dependency management e CI/CD trivial.
- C++ permite código eficiente sem fricção.

**Pontos fracos:**
- Arduino "mainloop" pattern não é ideal para multitasking pesado — mas FreeRTOS está ali embaixo (acessível diretamente).
- Algumas libs Arduino são verbosas em RAM se mal escolhidas.

---

### 3.2 B. ESP-IDF nativo

**Stack:** Framework oficial Espressif, C/C++ puro, FreeRTOS direto, componentes ESP-IDF.

| Critério | Nota | Comentário |
|---|---|---|
| Maturidade | 10/10 | Oficial, atualizado constantemente |
| Performance | 10/10 | Acesso direto, sem camadas |
| WebSocket + TLS | 9/10 | `esp_websocket_client` é maduro; mbedTLS integrado |
| Acesso a hardware | 9/10 | Tudo possível; M5Stack usa ESP-IDF embaixo dos panos |
| Manutenibilidade | 7/10 | Mais verboso, mais boilerplate |
| Asset pipeline | 7/10 | SPIFFS/LittleFS direto; sem M5GFX (precisa portar ou escrever) |
| OTA | 10/10 | Suporte de classe industrial |
| Familiaridade | 5/10* | Curva de aprendizado real |
| Comunidade Stack-chan | 5/10 | Pouco código de referência neste estilo |
| Memory footprint | 9/10 | Mais compacto |
| Debug | 9/10 | JTAG + GDB suportado |
| Ecossistema | 7/10 | Componentes oficiais ótimos, mas menos drop-in libs que Arduino |

**Soma ponderada estimada: ~315/415 (~76%)**

**Pontos fortes:**
- Performance e controle máximos.
- Oficial — futuro-proof.
- Para feature como audio streaming com baixa latência, é o melhor.

**Pontos fracos:**
- Tempo de desenvolvimento maior.
- Sem M5GFX (lib gráfica do M5Stack), teria que portar ou escrever rendering próprio.
- Boilerplate grande para coisas simples (mostrar texto na tela).

---

### 3.3 C. Moddable SDK

**Stack:** Moddable runtime de JavaScript embedded, plataforma de desenvolvimento dedicada para microcontroladores.

| Critério | Nota | Comentário |
|---|---|---|
| Maturidade | 7/10 | Maduro mas nicho; comunidade menor |
| Performance | 7/10 | XS engine é eficiente, mas JS tem overhead vs C++ |
| WebSocket + TLS | 8/10 | Suportado via módulos do Moddable |
| Acesso a hardware | 8/10 | Boa cobertura, mas alguns componentes do CoreS3 podem precisar driver custom |
| Manutenibilidade | 9/10 | JS/TS são expressivos; código curto |
| Asset pipeline | 8/10 | Moddable tem ferramentas próprias para fonts/imagens |
| OTA | 8/10 | Suportado |
| Familiaridade | 6/10* | Depende — JS é familiar, Moddable patterns nem tanto |
| Comunidade Stack-chan | 10/10 | **Stack-chan original (Ishikawa) é Moddable.** |
| Memory footprint | 7/10 | XS engine consome mais que código nativo |
| Debug | 7/10 | xsbug é decente |
| Ecossistema | 6/10 | Smaller world; menos libs prontas |

**Soma ponderada estimada: ~301/415 (~73%)**

**Pontos fortes:**
- Reaproveita a arquitetura mais sofisticada da comunidade Stack-chan (assets, animações faciais, drivers de servo).
- Iteração rápida em JS.
- Stack-chan original já tem implementação de muitos componentes que precisaríamos escrever.

**Pontos fracos:**
- Curva de aprendizado de Moddable patterns.
- Performance ligeiramente inferior em workloads de baixo nível.
- Comunidade Moddable é pequena fora do círculo Stack-chan.
- Algumas features do CoreS3 (NFC, especificamente) podem não ter drivers prontos.

---

### 3.4 D. UiFlow2

**Stack:** Plataforma visual da M5Stack, blocos + MicroPython.

| Critério | Nota | Comentário |
|---|---|---|
| Maturidade | 7/10 | Em evolução |
| Performance | 4/10 | MicroPython é interpretado; reflex <100ms é difícil |
| WebSocket + TLS | 5/10 | Suportado mas com limites |
| Acesso a hardware | 8/10 | M5Stack é a empresa fazendo — boa cobertura |
| Manutenibilidade | 4/10 | Não escala para projeto deste porte |
| Asset pipeline | 6/10 | Limitado |
| OTA | 6/10 | Existe |
| Familiaridade | N/A | Não relevante para projeto sério |
| Comunidade Stack-chan | 4/10 | Pouca presença |
| Memory footprint | 5/10 | MicroPython VM é grande |
| Debug | 5/10 | Limitado |
| Ecossistema | 5/10 | Limitado |

**Soma ponderada estimada: ~225/415 (~54%)**

**Pontos fortes:**
- Rapid prototyping para ideias rápidas.
- Bom para descobrir hardware features rapidamente.

**Pontos fracos:**
- Não é stack para projeto de longo prazo.
- Performance e controle insuficientes para reflex layer rigoroso.
- Manutenibilidade ruim — não escala.

---

## 4. Decisão

### Escolha primária: **A. PlatformIO + Arduino-ESP32 + M5Unified**

**Justificativa:**

1. **Maturidade + comunidade ativa.** Arduino-ESP32 e M5Unified são bem mantidos. robo8080's referências usam essa stack.
2. **Acesso a hardware completo via M5Unified.** Sem precisar escrever drivers do zero.
3. **Performance suficiente.** Reflex layer (10-100ms) cabe sem fricção.
4. **WebSocket + TLS maduros.** Bibliotecas testadas.
5. **OTA + asset pipeline + ecossistema.** Tudo já resolvido.
6. **C++ permite engenharia séria.** RTTI, templates, smart pointers — código de produção.

**Trade-offs aceitos:**
- Não vamos reaproveitar o trabalho da comunidade Stack-chan (que é Moddable). Aceito porque nossa arquitetura é diferente o bastante (cliente fino do Atlas, não Stack-chan stand-alone).
- Mais código manual que em Moddable. Aceito em troca de comunidade maior e estabilidade.

### Stack de fallback: **B. ESP-IDF nativo** para módulos críticos

Quando um componente Arduino não for performático o suficiente (audio streaming pode ser caso), usar **componentes ESP-IDF diretamente** dentro do projeto Arduino.

PlatformIO permite mistura: `framework = arduino, espidf` no `platformio.ini`. Boa escapatória.

### Atualização após dissecação dos repositórios oficiais M5Stack

Em 2026-05-22, foram dissecados:

- `m5stack/StackChan` — firmware oficial de produto, ESP-IDF 5.5, Xiaozhi, app Flutter, servidor Go, protocolo WebSocket próprio, remote ESP-NOW.
- `m5stack/StackChan-BSP` — BSP oficial Arduino para StackChan.
- `stack-chan/stack-chan` — repo comunitário original em Moddable/TypeScript.

Impacto na decisão:

| Achado | Decisão Atlas |
|---|---|
| Firmware oficial M5 é ESP-IDF + Xiaozhi + cloud/app/server próprios | Não adotar inteiro; usar como referência de HAL, audio/camera, OTA, servo/touch/IMU. |
| BSP oficial M5 é Arduino e expõe hardware essencial | Usar como base de bring-up do firmware Atlas. |
| Repo comunitário tem arquitetura `Robot -> Driver/Renderer/TTS` e simulador | Reusar conceitos, não stack principal. |
| App M5 expõe modelos "grátis"/internos | Tratar como providers opcionais atrás do Atlas, nunca direto no firmware. |
| StackChan tem voz/persona própria valiosa | Preservar via `stackchan_default` e `StackChan Persona Adapter`. |

Decisão refinada:

> O firmware Atlas começa em **PlatformIO + Arduino-ESP32 + M5Unified + M5Stack StackChan-BSP**. O firmware oficial M5 vira fonte de port seletivo. ESP-IDF continua fallback para audio/camera/OTA quando a camada Arduino não bastar.

Primeiros módulos a portar/conferir do firmware oficial M5:

- limites conservadores de servo (`yaw -1280..1280`, `pitch 30..870`);
- zero calibration em NVS;
- head touch `Si12T` com press/release/swipe;
- IMU BMI270 shake/pickup;
- RGB via PY32 IO expander;
- padrões de heap/OTA confirmation;
- `Motion`, blink, breath, speaking e head-pet modifiers.

### Não vamos usar: C (Moddable) e D (UiFlow2)

- **Moddable:** rejeitado pela curva de aprendizado e ecossistema limitado, apesar do mérito da comunidade Stack-chan. Pode ser revisitado se nossa equipe crescer e tiver alguém com expertise em Moddable.
- **UiFlow2:** não é stack para projeto deste escopo.

---

## 5. Estrutura proposta do projeto

```
firmware/atlas-stackchan/
├── platformio.ini                  # Config PlatformIO
├── src/
│   ├── main.cpp                    # Entry, setup, loop
│   ├── atlas/
│   │   ├── atlas_body_runtime.cpp
│   │   ├── atlas_envelope.cpp
│   │   ├── command_dispatcher.cpp
│   │   ├── safety_limiter.cpp
│   │   └── telemetry_publisher.cpp
│   ├── hardware/
│   │   ├── stackchan_board.cpp      # Wrapper BSP + calibrações M5 oficiais
│   │   ├── servo_bus.cpp
│   │   ├── head_touch.cpp
│   │   ├── imu_sensor.cpp
│   │   └── battery_monitor.cpp
│   ├── config/
│   │   ├── config.h                # Compile-time config
│   │   └── runtime_config.cpp      # Persistência via NVS/microSD
│   ├── transport/
│   │   ├── websocket_client.h
│   │   ├── websocket_client.cpp
│   │   ├── frame_codec.cpp         # Encode/decode binary frames
│   │   └── reconnect.cpp
│   ├── reflex/                     # L0 — local, sem cognição
│   │   ├── idle_animation.cpp
│   │   ├── head_tracking.cpp
│   │   ├── led_idle.cpp
│   │   └── wake_word.cpp           # Wake word offline
│   ├── renderers/                  # Output Renderer físico
│   │   ├── face_renderer.cpp       # Avatar
│   │   ├── card_renderer.cpp       # Info-cards
│   │   ├── led_renderer.cpp
│   │   ├── servo_renderer.cpp
│   │   └── audio_renderer.cpp      # TTS playback + voice_profile
│   ├── sensors/                    # Captura
│   │   ├── mic_capture.cpp
│   │   ├── camera_presence.cpp
│   │   ├── touch_pads.cpp
│   │   ├── nfc_reader.cpp
│   │   ├── imu_gestures.cpp
│   │   └── ambient.cpp             # LTR-553
│   ├── envelope/                   # Interaction Envelope assembly
│   │   ├── envelope_builder.cpp
│   │   └── context_signals.cpp
│   ├── commands/                   # Output Commands dispatch
│   │   ├── command_dispatcher.cpp
│   │   └── ack_emitter.cpp
│   ├── modes/                      # Modos operacionais
│   │   ├── mode_manager.cpp
│   │   ├── degraded_mode.cpp
│   │   └── private_mode.cpp
│   ├── audit/                      # Logs locais
│   │   └── local_log.cpp
│   └── system/
│       ├── boot.cpp
│       ├── ota.cpp
│       └── health.cpp              # Telemetria, thermal, battery
├── assets/                         # Sprites, fontes, sons
│   ├── faces/
│   ├── icons/
│   └── sounds/
├── data/                           # Vai pra microSD
│   └── (provisioning, config)
└── test/
    └── (unit tests onde possível)
```

Detalhes de cada módulo nos outros docs de `06-firmware-stackchan/`.

---

## 6. Versões alvo

| Componente | Versão |
|---|---|
| PlatformIO | Latest stable |
| Arduino-ESP32 | 3.x (ESP-IDF 5 base) |
| M5Unified | Latest stable |
| M5GFX | Latest stable |
| M5Stack StackChan-BSP | Pin por commit/tag validado |
| ArduinoWebsockets ou WebSocketsClient | Latest stable |
| ArduinoJson | 7.x |

⚠️ **DECISÃO PENDENTE:** qual lib de WebSocket exata. `ArduinoWebsockets` (gilmaimon) e `WebSocketsClient` (Markus Sattler) são candidatas. Avaliação concreta na implementação.

---

## 7. Política de upgrade de versões

- **Patches** das libs aplicam automaticamente.
- **Minor versions:** aplica em janela planejada após teste em ambiente de dev.
- **Major versions** (ex: Arduino-ESP32 4.x): tratado como migração — entra como projeto separado, validação completa antes de adoção.

Lockfile: `platformio.ini` com versões pinadas; CI roda contra essas versões.

---

## 8. Decisão registrada como ADR

Esta decisão também será registrada em `00-introducao/03-historico-decisoes.md` como **ADR-001: Escolha de stack do firmware**.

Estrutura ADR (não escrita ainda):
- Status: Aceito
- Data: 2026-05-05
- Decisão: PlatformIO + Arduino-ESP32 + M5Unified
- Razão: ver este documento
- Consequências: ver este documento, seção trade-offs
- Revisão futura: revisitar em 6 meses ou se Arduino-ESP32 4.x lançar com mudanças significativas

---

## 9. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Misturar 2 stacks em paralelo (Arduino e Moddable na mesma flash) | Complicação não justificada |
| Importar libs aleatoriamente sem versionar | Quebra build; surpresas em CI |
| Esconder ESP-IDF embaixo da Arduino quando não precisa | Mais código pra ler — Arduino direto é mais curto |
| Fork de M5Unified | Manter atualizado vira problema; preferir contribuir upstream |
| Reescrever drivers que M5Unified já tem | Esforço perdido |
| Importar firmware oficial M5 inteiro | Traz Xiaozhi/cloud/app center e cria cérebro paralelo |
| Colocar seleção de LLM/TTS no firmware | Provider routing pertence ao Atlas |

---

## Próximos passos de leitura

- `02-reflex-layer.md` — implementação do L0 com este stack.
- `03-renderers.md` — Output Renderer físico.
- `04-build-flash.md` — pipeline concreto de build.
- `00-introducao/03-historico-decisoes.md` — onde esta decisão vira ADR formal (a escrever).
