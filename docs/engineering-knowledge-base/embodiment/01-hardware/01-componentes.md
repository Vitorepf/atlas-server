---
id: atlas-embodiment-01-hardware-01-componentes
type: engineering_knowledge
title: "01 — Componentes do StackChan"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 01 — Componentes do StackChan

> **Propósito:** entender cada peça do StackChan K151 e seu papel no Atlas Embodiment. Não é manual técnico do produto — é mapeamento de **capacidade física → função no kernel**.
>
> **Pré-requisitos:** [README](../README.md).
>
> **Fora do escopo:** instruções de montagem, datasheets completos (ver `10-anexos/B-referencias-tecnicas.md`).

---

## Como ler este documento

Cada componente é descrito em 4 dimensões:

1. **Spec** — o que é (resumo).
2. **Função no produto base** — para que serve no StackChan stock.
3. **Função no Atlas Embodiment** — qual papel ele assume no nosso projeto.
4. **Limitações relevantes** — o que ele *não* dá.

Os componentes estão organizados por **função**, não por categoria de spec. Isso ajuda a pensar o sistema, não o BOM.

---

## Visão geral física

```
┌─────────────────────────────────────────────────────────────┐
│  CABEÇA (CoreS3 + Expansion)                                │
│  ├─ ESP32-S3 (cérebro)                                      │
│  ├─ Display 2" + Touch (rosto)                              │
│  ├─ Câmera GC0308 (visão)                                   │
│  ├─ Mic dual + Speaker (audição/voz)                        │
│  ├─ IMU 9-axis (propriocepção)                              │
│  ├─ Touch pads x3 + NFC (pele)                              │
│  ├─ IR TX/RX (comunicação não-verbal externa)               │
│  ├─ 12 RGB LEDs (status ambiente)                           │
│  └─ Proximity / ambient light (consciência ambiental)       │
├─────────────────────────────────────────────────────────────┤
│  PESCOÇO/CORPO (servos)                                     │
│  ├─ 360° pan (horizontal, com feedback)                     │
│  └─ 90° tilt (vertical, com feedback)                       │
├─────────────────────────────────────────────────────────────┤
│  BASE                                                       │
│  ├─ Bateria 550mAh + AXP2101 (energia)                      │
│  ├─ RTC BM8563 (tempo)                                      │
│  ├─ USB-C (alimentação/dados)                               │
│  ├─ Slip ring (rotação contínua sem fios torcerem)          │
│  └─ microSD (armazenamento)                                 │
└─────────────────────────────────────────────────────────────┘
```

---

## 1. Cérebro

### 1.1 ESP32-S3 (Main Controller)

| Dimensão | Detalhe |
|---|---|
| **Spec** | Xtensa LX7 dual-core 32-bit @ 240MHz, 16MB Flash, 8MB PSRAM, Wi-Fi 2.4GHz b/g/n, BLE 5 LE |
| **Função base** | Roda firmware, processa sensores, controla atuadores, gerencia rede |
| **Função no Atlas** | **Thin client.** Captura entradas, transmite para Atlas via WebSocket, recebe Decision Receipts e renderiza. Roda o **reflex layer** local (animações idle, wake word, LED status). Não roda LLM, não decide nada cognitivo. |
| **Limitações** | Não suporta LLM full. STT/TTS só com Module-LLM externo ou nuvem. Latência da nuvem é o gargalo de UX, não a CPU local. |

**Princípio de uso:** quanto mais o reflex layer for capaz, melhor a sensação de presença mesmo com Atlas latente ou offline. Mas reflex layer **nunca toma decisão cognitiva** — só anima, pisca, sinaliza estado.

---

## 2. Sentidos (entrada)

### 2.1 Display + Touch (Rosto)

| Dimensão | Detalhe |
|---|---|
| **Spec** | 2.0" IPS LCD 320×240, 65536 cores, ILI9342C driver. Touch capacitivo multi-touch FT6336U. |
| **Função base** | Avatar facial expressivo (olhos, boca, emoções). |
| **Função no Atlas** | **Canal de saída visual primário** + entrada touch. Renderiza: face/avatar, status do pipeline, mini-displays informativos (próximo evento, métrica do dia, status de build, prompts de aprovação do Curator). |
| **Limitações** | 320×240 é apertado — UI tem que ser **radicalmente simples**. Não cabe terminal, dashboard denso, leitura longa. Texto curto, ícones grandes, info hierarquizada. |

**Decisão de design implícita:** o display tem dois modos primários — **modo facial** (avatar dominante, info mínima) e **modo informativo** (info dominante, traço facial pequeno no canto). Alternar entre eles é decisão do Output Renderer, baseada no contexto da Decision Receipt.

### 2.2 Câmera GC0308

| Dimensão | Detalhe |
|---|---|
| **Spec** | 640×480, 0.3 MP. |
| **Função base** | Captura de imagem para apps demo. |
| **Função no Atlas** | **Sinal de presença/contexto, NÃO visão computacional.** Detecção binária: usuário presente / ausente. Possivelmente: leitura de QR code para triggers, leitura de cor ambiente, contagem grosseira de pessoas. **Captura de imagem para envio ao Atlas é opt-in explícito.** |
| **Limitações** | 0.3MP é fraco para CV. Reconhecimento facial preciso, OCR, leitura de detalhes — não. Frame rate prático no ESP32-S3 é baixo. |

**Política de uso:** o default é **câmera off para captura, on para sinal binário de presença**. Captura de frame só com comando explícito do usuário ou trigger de fluxo (ex.: "tira uma foto"). Detalhes em `05-policies/01-privacidade.md`.

### 2.3 Microfones duplos + ES7210

| Dimensão | Detalhe |
|---|---|
| **Spec** | Dual mic, codec ES7210 dedicado. |
| **Função base** | Captura de áudio para conversa com AI Agent. |
| **Função no Atlas** | **Canal primário de input.** Wake word offline (DSP local) → após ativação, streaming de áudio para Atlas via STT (nuvem ou Module-LLM local). Dual mic permite beamforming básico — direção do som, redução de ruído. |
| **Limitações** | Sem cancelamento de eco avançado embutido. Em ambiente ruidoso, qualidade cai. Latência de wake word + STT é o maior fator de UX. |

**Princípio crítico:** wake word **sempre processado localmente**. Áudio só sai do robô após detecção. Isso é privacidade por design — não promessa de policy.

### 2.4 Sensor de proximidade + luz ambiente (LTR-553ALS-WA)

| Dimensão | Detalhe |
|---|---|
| **Spec** | Proximity + ambient light em um chip. |
| **Função base** | Brilho automático da tela, detecção de mão próxima. |
| **Função no Atlas** | **Sinais ambientais para o Context Builder.** Luz ambiente → "está escuro / claro" → entra no envelope. Proximity → "alguém aproximou a mão" → trigger possível para interação. Ajuste automático de brilho conforme hora do dia / ambiente. |
| **Limitações** | Proximity é curto alcance (centímetros), não detecta presença de quem está sentado a 1m. Para isso usamos câmera. |

### 2.5 IMU 9-axis (BMI270 + BMM150)

| Dimensão | Detalhe |
|---|---|
| **Spec** | Acelerômetro + giroscópio + magnetômetro. |
| **Função base** | Detecção de orientação, gestos de chacoalhar. |
| **Função no Atlas** | **Propriocepção e interação tátil.** Detecta: "alguém pegou ele", "foi virado de lado", "mesa está sendo mexida", "caiu". Cada um gera um evento no Evidence Ledger. Pode ser usado para **wake gesture** ("dar um tapinha 2x na cabeça"). |
| **Limitações** | Não substitui touch. Detecção de movimento próprio (servo) precisa ser filtrada para não gerar falsos positivos. |

### 2.6 Touch pads x3 (Si12T)

| Dimensão | Detalhe |
|---|---|
| **Spec** | 3 zonas de toque capacitivo no topo da cabeça. |
| **Função base** | Botões customizáveis. |
| **Função no Atlas** | **Atalhos físicos com semântica fixa.** Mapeamento sugerido: `[1] = ✓ aprovar / repetir`, `[2] = ✗ cancelar / mute`, `[3] = 💬 expandir / detalhes`. Combinações (ex.: 3 simultâneos por 2s) reservadas para **modo privado físico**. Toques curtos vs longos têm semântica diferente. |
| **Limitações** | Apenas 3 pads — orçamento de UX limitado. Cada combinação precisa ser memorável. Discoverability ruim sem feedback visual. |

**Decisão de design:** o mapeamento dos pads **não muda por contexto**. Consistência > flexibilidade. Reaprender significado a cada Domain quebraria a confiança.

### 2.7 NFC ST25R3916

| Dimensão | Detalhe |
|---|---|
| **Spec** | NFC full-featured (não só leitura — também emulação de tag e P2P). |
| **Função base** | Tag/cartão de identificação, pagamento, pareamento. |
| **Função no Atlas** | **Triggers físicos de fluxo.** Cartões/tags pré-definidos disparam Operation Envelopes. Exemplos: tag "FOCUS" → modo Pomodoro do Personal Dev domain; tag "REVIEW" → puxa últimas mudanças do Programming domain; tag "STANDUP" → prepara resumo do dia. **É um shortcut físico para Intent/Routing.** |
| **Limitações** | Alcance curto (cm). Usuário precisa saber qual tag faz o quê — discoverability é problema. Solução: imprimir/etiquetar as tags. |

---

## 3. Expressão (saída)

### 3.1 Speaker 1W AW88298

| Dimensão | Detalhe |
|---|---|
| **Spec** | 1W, codec I2S 16-bit AW88298. |
| **Função base** | Voz do AI Agent, sons de feedback. |
| **Função no Atlas** | **Canal verbal do Atlas.** TTS streaming para resposta. Sinais sonoros curtos (acknowledgement, alerta de quality gate, transição entre estados). Fundo ambiente opcional (foco, brown noise) — opt-in. |
| **Limitações** | 1W em sala silenciosa: OK. Sala com ar condicionado/conversa: marginal. Música decente: não. **Volume é decisão de policy** — modo noturno baixa automaticamente. |

### 3.2 12 RGB LEDs WS2812C

| Dimensão | Detalhe |
|---|---|
| **Spec** | 12 LEDs endereçáveis WS2812C. |
| **Função base** | Iluminação ambiente, efeitos visuais. |
| **Função no Atlas** | **Canal de status ambiente.** Comunica estado do pipeline sem precisar olhar a tela. Mapeamento sugerido: |

| Cor / padrão | Estado |
|---|---|
| Azul calmo respirando | Idle, presente |
| Verde estável | Quality gates passaram |
| Amarelo pulsante | Repair Loop ativo |
| Vermelho fixo | Limite de tentativas / erro requer atenção |
| Roxo respirando | Atlas offline / modo degradado |
| Laranja pulsante | Self-Improvement proposal aguardando aprovação |
| Vermelho fixo + face vermelha | Modo privado físico ativo |
| Multi-cor girando | Council de providers deliberando |

**Princípio:** LED é canal de **glance** — você olha de canto e sabe o estado em <500ms. Cores não devem mudar capricho do desenvolvedor; elas são parte da linguagem do Atlas.

### 3.3 Servos (movimento)

| Dimensão | Detalhe |
|---|---|
| **Spec** | Pan 360° contínuo + tilt 90°. **Ambos com feedback de posição.** |
| **Função base** | Animar a cabeça do robô. |
| **Função no Atlas** | **Pontuação emocional não-verbal + atenção dirigida.** Comunica: "pensando" (incline lateral), "te escutando" (vira para fonte de som via mic), "concordando" (nod), "negando" (shake), "olhando dado novo" (vira para tela quando exibe info). Feedback de posição = movimento preciso e detecção de obstrução (alguém segurou a cabeça). |
| **Limitações** | Movimento contínuo consome bateria rapidamente. Movimentos rápidos são ruidosos (servo whine). Precisão de posição é limitada (~1°). |

**Princípio de design:** **micro-movimento > macro-movimento.** Vida é tique nervoso sutil, não dança. Movimento amplo só em momentos significativos.

---

## 4. Movimento físico no mundo (IR)

### 4.1 IR TX + RX (IRM56384 receiver)

| Dimensão | Detalhe |
|---|---|
| **Spec** | Receptor IR + transmissor IR. |
| **Função base** | Controle remoto de TV/AC/etc, ou recepção de comandos. |
| **Função no Atlas** | **Atuação no mundo físico não-digital.** Atlas pode comandar luz/TV/AC via IR (Personal Dev domain — modo foco apaga luz; Self-Improvement — desliga TV no modo deep work). Recepção: pode ser controlado por remoto convencional como fallback de UX. |
| **Limitações** | Linha de visão necessária. Códigos IR variam por fabricante — precisa biblioteca/aprendizado. Não substitui Zigbee/Matter para automação real. |

---

## 5. Comunicação

### 5.1 Wi-Fi 2.4GHz

| Dimensão | Detalhe |
|---|---|
| **Spec** | IEEE 802.11 b/g/n. |
| **Função base** | Conexão à rede para AI Agent online. |
| **Função no Atlas** | **Transporte primário corpo↔alma.** WebSocket persistente com Atlas backend. Streaming de áudio (input), recebimento de comandos físicos e TTS (output). |
| **Limitações** | 2.4GHz apenas (não 5GHz) — disputa espectro com microondas/Bluetooth. Sem WPA3 garantido em todos os firmwares. **Ponto único de falha:** se Wi-Fi cai, robô vai pra modo degradado. |

### 5.2 Bluetooth 5 LE

| Dimensão | Detalhe |
|---|---|
| **Spec** | BLE 5. |
| **Função base** | Pareamento com app móvel, controle remoto via M5StickC. |
| **Função no Atlas** | **Canal secundário de provisioning e fallback.** Útil para: configuração inicial via app, descoberta de periféricos (relógio, sensores Grove BLE), controle remoto físico via M5StickC + JoyC. **Não é caminho de dados primário.** |
| **Limitações** | Bandwidth baixa para áudio streaming. Latência variável. |

### 5.3 USB-C OTG

| Dimensão | Detalhe |
|---|---|
| **Spec** | USB CDC + full-speed USB OTG. |
| **Função base** | Carga, programação, conexão direta a PC. |
| **Função no Atlas** | **Modo "tethered":** quando conectado por USB ao PC que roda Atlas, pode usar USB CDC como transporte (latência menor, sem dependência de Wi-Fi). Útil em mesa de trabalho fixa. |
| **Limitações** | Cabo. |

### 5.4 GPIO / UART / I2C (3 portas Grove)

| Dimensão | Detalhe |
|---|---|
| **Spec** | Port.A (I2C), Port.B (GPIO), Port.C (UART). 3 portas Grove. |
| **Função base** | Expansão para sensores M5Stack. |
| **Função no Atlas** | **Vetor de extensão futuro.** Possíveis adições: sensor de temperatura/umidade da sala (contexto), display secundário, módulo Module-LLM (LLM offline), módulo de áudio melhor, sensores de qualidade do ar. Cada periférico vira um sinal adicional no Context Builder. |
| **Limitações** | Apenas 3 portas. Cada expansão consome energia. Conflito potencial com firmware existente — adicionar periférico = atualizar firmware. |

---

## 6. Energia e tempo

### 6.1 Bateria 550 mAh + PMIC AXP2101

| Dimensão | Detalhe |
|---|---|
| **Spec** | 550 mAh, gerenciamento via AXP2101. |
| **Função base** | Operação portátil curta. |
| **Função no Atlas** | **Buffer de continuidade.** Permite que o robô não desligue durante troca de tomada/queda de luz curta. **Não é para operação móvel longa.** Modo de operação alvo: alimentação USB-C contínua na mesa. |
| **Limitações** | Servo ativo + Wi-Fi + display brilhante = autonomia muito curta (estimada <1h em uso pleno). Detalhes em `02-limitacoes-fisicas.md`. |

### 6.2 RTC BM8563

| Dimensão | Detalhe |
|---|---|
| **Spec** | Real-time clock com bateria backup. |
| **Função base** | Manter hora mesmo sem energia. |
| **Função no Atlas** | **Heartbeat loop autônomo.** RTC permite que o robô dispare rituais agendados (bom dia, fim do dia) mesmo se a sincronia com Atlas atrasar. Timestamps de eventos no Evidence Ledger são consistentes. |
| **Limitações** | Drift do RTC sem sync NTP — precisa ressincronizar via Wi-Fi periodicamente. |

---

## 7. Expansão e estrutura

### 7.1 microSD slot

| Dimensão | Detalhe |
|---|---|
| **Spec** | microSD card. |
| **Função base** | Configuração (`wifi.txt`, `apikey.txt`), assets, logs. |
| **Função no Atlas** | **Configuração local + cache de assets pesados.** Sprites de avatar, samples de áudio, certificados. **Não armazena Evidence Ledger** — esse vive no Atlas backend. SD pode ter logs locais para diagnóstico de modo degradado. |
| **Limitações** | SD pode corromper. Configurações sensíveis (tokens) precisam de criptografia. |

### 7.2 LEGO mounting holes

| Dimensão | Detalhe |
|---|---|
| **Spec** | Furos compatíveis com LEGO Technic na base. |
| **Função base** | Customização física pela comunidade. |
| **Função no Atlas** | **Expressão de identidade do usuário.** Permite personalização visual (chapéu, base customizada, mod estético). Identidade do Atlas é digital, mas o corpo pode refletir o usuário. |
| **Limitações** | Estética — não funcional. |

### 7.3 Slip ring

| Dimensão | Detalhe |
|---|---|
| **Spec** | Anel deslizante na base que permite rotação 360° contínua. |
| **Função base** | Permite que o pan servo gire infinitamente sem torcer cabos internos. |
| **Função no Atlas** | **Habilita comportamentos de presença ambiental** — varrer o ambiente lentamente em modo idle, virar 180° quando alguém entra atrás. Sem slip ring, rotação seria limitada e quebraria animações de "olhar ao redor". |
| **Limitações** | Componente mecânico — desgaste com uso intenso ao longo de anos. |

---

## Resumo: papéis condensados

| Componente | Papel resumido no Atlas |
|---|---|
| ESP32-S3 | Thin client + reflex layer |
| Display + touch | Rosto + UI mínima + approvals |
| Câmera | Sinal binário de presença |
| Mics | Wake word local + STT streaming |
| Proximity/ALS | Sinais de contexto ambiental |
| IMU | Propriocepção, gestos, "alguém pegou" |
| Touch pads | 3 atalhos físicos com semântica fixa |
| NFC | Triggers físicos de fluxo |
| Speaker | Voz do Atlas + sinais sonoros |
| RGB LEDs | Status do pipeline em "glance" |
| Servos | Pontuação emocional, atenção dirigida |
| IR TX/RX | Atuação no mundo físico (luz/TV/AC) |
| Wi-Fi | Transporte corpo↔alma primário |
| BLE | Provisioning, periféricos, fallback |
| USB-C | Modo tethered (latência baixa) |
| Grove x3 | Expansão futura (Module-LLM, sensores) |
| Bateria + PMIC | Buffer de continuidade |
| RTC | Heartbeat autônomo |
| microSD | Config + assets locais |
| LEGO mounts | Identidade visual do usuário |
| Slip ring | Habilita varredura ambiental |

---

## O que fica de fora (e por quê)

- **GPS** — não tem, e não precisamos. Atlas trabalha em contexto fixo de mesa.
- **Bateria grande** — não é mobile assistant. Operação alvo: tethered USB-C.
- **Display HD** — proposital. Constraint força UI minimalista, que é o certo para distância de mesa.
- **Câmera de qualidade** — proposital. CV pesado deve estar em outro lugar (PC com webcam, se necessário).
- **Microfone unidirecional** — dual mic com beamforming serve.
- **Conectividade celular** — fora de escopo. Usuário tem Wi-Fi.

---

## Próximos passos de leitura

- `02-limitacoes-fisicas.md` — orçamento de recursos: o que cabe, o que não cabe.
- `03-orcamento-recursos.md` — cálculo concreto: bateria por hora, banda por minuto, etc.
- `04-expansoes-possiveis.md` — Module-LLM, periféricos Grove, mods.
