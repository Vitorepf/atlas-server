---
id: atlas-embodiment-01-hardware-02-limitacoes-fisicas
type: engineering_knowledge
title: "02 — Limitações físicas"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 02 — Limitações físicas

> **Propósito:** estabelecer o que **fisicamente cabe e não cabe** no StackChan. Cada limitação aqui é um constraint que vai aparecer em decisões de arquitetura ao longo do projeto. Documentar agora evita ressuscitar a discussão depois.
>
> **Pré-requisitos:** [README](../README.md), [01-componentes.md](01-componentes.md).
>
> **Fora do escopo:** otimizações específicas de código, profiling real (vem depois da implementação).

---

## Princípio orientador

> **Não tente fazer no StackChan o que o Atlas faz melhor remoto.**
> O robô é corpo. Se uma feature precisa de mais de X recursos, ela mora no Atlas.

Toda decisão neste documento responde uma pergunta só: **o quê fica local, o quê fica remoto?**

---

## 1. CPU — ESP32-S3 dual-core LX7 @ 240MHz

### Capacidade real

- **Core 0** tipicamente reservado para Wi-Fi stack + RTOS housekeeping.
- **Core 1** disponível para aplicação.
- **Resultado prático:** ~1 core efetivo a 240MHz para o firmware.

### O que cabe

| Tarefa | Cabe? | Observação |
|---|---|---|
| Animação de avatar (sprites pré-renderizados) | ✅ | Frame rate ~30fps possível com sprites cuidados |
| Wake word offline (modelo pequeno tipo "porcupine") | ✅ | Chips ESP32-S3 têm precedente de wake word offline |
| Streaming de áudio para nuvem (Opus encode) | ✅ | Encoder leve cabe |
| Beamforming dual mic básico | 🟡 | Possível, mas come ciclos. Considerar processar na nuvem. |
| Cancelamento de eco (AEC) | 🟡 | Difícil em tempo real. Solução: AEC no Atlas backend ou Module-LLM dedicado. |
| Reconhecimento de fala completo (STT) | ❌ | Não cabe modelo digno. Vai pra nuvem ou Module-LLM. |
| TTS qualidade neural | ❌ | Idem. |
| LLM (qualquer tamanho útil) | ❌ | Sem chance. Atlas remoto ou Module-LLM (que tem NPU dedicado). |
| Detecção facial em câmera | 🟡 | OpenCV-style cabe para detecção binária. Reconhecimento, não. |
| OCR | ❌ | Não. |
| Servo control + LED + display + Wi-Fi simultâneos | ✅ | É o caso de uso normal. Mas precisa cuidado com prioridades. |

### Implicação para o projeto

- **Reflex layer** é o teto do que faz sentido localmente.
- Tudo cognitivo passa por Wi-Fi. Latência de rede é o gargalo de UX, não a CPU.
- Otimização premature de CPU no robô é tempo perdido. Otimização da **latência percebida** (streaming, micro-resposta local antes da resposta cognitiva) é onde o esforço rende.

---

## 2. Memória — 8MB PSRAM + 16MB Flash

### PSRAM (8MB)

Onde mora:
- Buffer de display (320×240×2 bytes = 150KB por frame; double buffer = 300KB)
- Buffers de áudio (mic capture + speaker playback + encoder)
- Stack de tarefas RTOS
- Sprites/assets carregados de SD
- Stack TCP/TLS

**Sobra prática útil:** ~3-4MB para aplicação. Não é nada num mundo de gigabytes, mas é confortável para o que vamos pedir.

### Flash (16MB)

Onde mora:
- Firmware (binário compilado)
- File system (SPIFFS/LittleFS) com configurações
- OTA (precisa reservar espaço para imagem em standby — ~metade do flash)

**Sobra prática útil:** ~5-6MB para assets persistentes (avatares, sons curtos). Assets pesados vão em microSD.

### Limites concretos

| Item | Limite prático |
|---|---|
| Modelo de ML local | <1MB para caber junto com o resto |
| Sprites totais (RAM) | ~2MB carregados simultaneamente |
| Áudio em RAM | ~1MB de buffer (uns ~10s de PCM mono 16kHz) |
| Logs/Evidence local | <500KB rotacional — só para diagnóstico de modo degradado |
| Cache de TTS | Pequeno (poucas frases curtas) — TTS streaming evita cache pesado |

### Implicação para o projeto

- Avatares precisam ser **sprites compactos** ou geração procedural simples.
- Não dá pra carregar muitos personagens/temas simultâneos.
- Evidence Ledger **não vive no robô**. Ele é fonte de verdade no Atlas backend.

---

## 3. Wi-Fi — 2.4GHz b/g/n

### Capacidade real

- Throughput sustentado prático: ~5-10 Mbps em ambiente típico.
- Latência para servidor local na mesma rede: 5-30ms.
- Latência para servidor remoto (cloud): 30-200ms variável.
- **Sem 5GHz**, sem MIMO — competindo no espectro lotado de qualquer casa moderna.

### O que isso significa para UX

Latência percebida do "Atlas, …" até primeira sílaba de resposta:

```
Wake word local:        ~100ms  (offline)
Audio streaming:        contínuo
ASR (nuvem):            300-1000ms até primeira palavra detectada
LLM TTFT:               300-2000ms
TTS streaming start:    200-500ms após primeira palavra LLM
Wi-Fi roundtrip × N:    +50-200ms total
─────────────────────────────────
Total típico:           1-3 segundos até começar a falar
```

**Inaceitável sem mitigação.** Soluções:

1. **Reflex sonoro imediato** (~100ms) — som curto de "ok" / micro-movimento — cobre a janela cognitiva enquanto Atlas pensa.
2. **Streaming everything** — STT, LLM, TTS streaming. Robô começa a falar antes da resposta inteira existir.
3. **Cache de respostas frequentes** — intents recorrentes (ex.: "que horas são?") podem responder direto.

### Falhas de rede

| Falha | Comportamento |
|---|---|
| Wi-Fi caiu | Modo degradado. LED roxo. Face triste. Reflex layer responde a presença mas avisa "estou desconectado". |
| Atlas backend caiu (Wi-Fi OK) | Mesma resposta — degradação visível. |
| Latência alta (>3s) | Indicar visualmente que está pensando (LED girando, face de "hm…"). Não fingir que está rápido. |

---

## 4. Bateria — 550 mAh

### Estimativa de consumo

Valores aproximados (precisam validação empírica):

| Atividade | Consumo estimado |
|---|---|
| Idle (display dim, sem servo, Wi-Fi conectado) | ~150-200 mA |
| Display ativo, sem servo | ~250-300 mA |
| Servo em movimento | +200-400 mA pico |
| Wi-Fi TX ativo | +100-200 mA pico |
| Speaker em volume médio | +100 mA |
| Tudo simultâneo (uso pleno) | ~600-800 mA pico |

### Autonomia prática

| Modo | Estimativa |
|---|---|
| Idle informativo | 2-3h |
| Conversação ativa contínua | 30-60 min |
| Uso típico (idle + interações esporádicas) | 1-2h |

### Implicação para o projeto

- **Operação alvo: tethered USB-C contínua.** Bateria é buffer.
- Modos de economia agressivos quando em bateria: dim display, reduzir frame rate de animações, suspender heartbeat loop, suspender câmera.
- Apresentar bateria como sinal no Context Builder (Atlas pode evitar interrupções demoradas se bateria baixa).

---

## 5. Áudio

### Captura (mic)

- Dual mic com codec dedicado é **bom**.
- Beamforming básico viável.
- Cancelamento de eco em tempo real é **difícil** — música/voz própria do speaker contamina captura.

**Decisão de design implícita:** quando o speaker está falando, mic em modo "ouvir-só-comandos-de-interrupção" (palavra-chave de stop). Conversação overlap completa é fora de escopo inicial.

### Reprodução (speaker 1W)

- 1W em sala silenciosa (escritório/quarto fechado): adequado.
- Sala com ar condicionado, ventilador, conversa: marginal.
- Música ambiente: speaker pequeno, qualidade limitada — não é use case primário.

### TTS

- TTS local: não cabe modelo neural. Síntese paramétrica simples cabe mas qualidade ruim.
- **TTS remoto streaming** é o caminho. ElevenLabs, OpenAI TTS, VOICEVOX — referências usadas pela comunidade.
- Latência TTS: 200-500ms até primeiro byte é alcançável com streaming.

---

## 6. Câmera GC0308 — 0.3MP

### Capacidade real

- 640×480 máximo.
- Frame rate prático no ESP32-S3: ~5-15 fps depending no processamento.
- Sensibilidade em pouca luz: ruim.

### O que faz sentido

| Tarefa | Faz sentido? |
|---|---|
| Detecção binária presença/ausência | ✅ |
| Detecção de movimento grosseira | ✅ |
| Leitura de QR code | ✅ (quando bem iluminado, próximo) |
| Estimativa de luz ambiente como sinal | ✅ |
| Reconhecimento facial | ❌ Não confiável |
| OCR de texto | ❌ |
| Reconhecimento de objeto | ❌ |
| Vídeo streaming | 🟡 Cabe baixa-res, baixa-fps; mas drena bateria |

### Implicação para o projeto

A câmera é **sensor de contexto**, não fonte de visão. Se um caso de uso futuro precisar de visão real, solução é desviar para webcam do PC com Atlas processando.

---

## 7. Servos — pan 360° + tilt 90°

### Capacidade real

- Feedback de posição: bom para movimento preciso.
- Velocidade: configurável, mas movimento rápido = ruidoso.
- Precisão prática: ~1-2° (suficiente para expressão).
- Stall torque limitado — segurar a cabeça com a mão pára o movimento e gera evento (útil).

### Limites práticos

- Movimento contínuo prolongado **drena bateria rápido**.
- Movimento muito frequente **gera ruído de servo** que polui o ambiente sonoro.
- Pan 360° via slip ring permite rotação infinita — mas é mecânica, com vida útil finita.

### Implicação para o projeto

- **Vocabulário de movimento limitado e bem definido.** Não improvisa animações; usa biblioteca de gestos pré-projetados.
- Movimentos amplos só em momentos significativos (ritual, reação, alerta).
- Idle = micro-movimento (tique sutil, blink simulado por servo se quiser).

---

## 8. Térmico

### O ESP32-S3 esquenta

- Em uso pesado contínuo (Wi-Fi + display + servo + câmera) chip pode chegar a 60-70°C.
- Throttling de CPU acontece em temperaturas altas — performance degrada.
- O case do StackChan tem ventilação limitada.

### Implicação para o projeto

- Não rodar workloads pesados contínuos no ESP32 (mais um motivo pra reflex layer ser leve).
- Em ambiente quente (sem AC, verão), considerar reduzir frame rate / suspender camera para evitar throttle.
- **Detectar throttle e degradar graciosamente** — Atlas avisa "estou esquentando, reduzindo atividade".

---

## 9. IO concorrente

### Conflitos potenciais

| Combinação | Risco |
|---|---|
| Wi-Fi TX + servo simultâneo | Pico de corrente — pode resetar dispositivo se PSU marginal |
| USB OTG + carga rápida + display brilho máximo | Fonte saturada |
| Áudio I2S + Wi-Fi pesado | Glitches no áudio (jitter) |
| Servo movimento + IMU read | IMU vê o próprio movimento — precisa filtrar |

### Implicação para o projeto

- Priorizar tarefas no firmware: áudio > rede > display > servo > LEDs.
- Usar fonte USB-C de boa qualidade (>2A), não conectores baratos.
- IMU precisa de janela de calibração ou filtro de baseline para descontar movimento próprio.

---

## 10. Ambiente sonoro real

### Problema invisível na spec sheet

Em laboratório o robô é incrível. **Em sala real** com:
- Ventilador / AC
- Conversa de outras pessoas
- Música ambiente
- Cachorro/gato

O wake word vai falhar mais. STT vai degradar. Confiança do usuário no robô cai rápido se ele frequentemente entende errado.

### Implicação para o projeto

- **Wake word com false-positive baixo** é mais importante que false-negative baixo. Falha de não-acionamento é frustrante mas recuperável; ativação por engano é constrangedora e quebra confiança.
- Quando STT confiança < threshold, **pedir confirmação explicitamente**. Melhor "você quis dizer X?" do que executar errado.
- Em ambientes ruidosos, oferecer modo touch-only.

---

## 11. Resumo: o que isso impõe ao design

| Constraint | Consequência de design |
|---|---|
| ~1 core de CPU prático | Tudo cognitivo é remoto |
| 3-4MB PSRAM útil | Sem LLM local, sem CV pesado |
| Wi-Fi único caminho de dados | Modo degradado precisa ser de primeira classe |
| Bateria <2h em uso | Operação tethered é o normal |
| Speaker 1W | Voz prioriza clareza, não volume |
| Câmera 0.3MP | Sensor de contexto, não visão |
| Servo barulhento | Vocabulário de movimento limitado e raro |
| Latência de rede | Streaming em tudo + reflex local que cobre a janela cognitiva |
| Térmico marginal | Workloads contínuos pesados não cabem |
| Ambiente sonoro real | Wake word conservador + fallback touch |

---

## 12. Decisões pendentes que dependem deste documento

Lista vai pra `10-anexos/C-decisoes-pendentes.md`. Resumo:

- ⚠️ **Wake word: qual engine offline?** (Porcupine, ESP-Skainet, custom)
- ⚠️ **STT/TTS: nuvem ou Module-LLM local?** Module-LLM tem NPU dedicado, custa mais, melhora privacidade e latência.
- ⚠️ **Beamforming: local ou remoto?** Local economiza banda; remoto economiza CPU.
- ⚠️ **Frame rate de animação alvo:** 30fps cinematográfico ou 15fps econômico?
- ⚠️ **Profile de bateria do modo idle:** quanto a animação idle pode consumir sem virar problema?

Cada decisão precisa de seu próprio documento de ADR em `00-introducao/03-historico-decisoes.md`.

---

## Próximos passos de leitura

- `03-orcamento-recursos.md` — números concretos por feature.
- `04-expansoes-possiveis.md` — Module-LLM e outras formas de remover constraints.
- `02-arquitetura/01-corpo-vs-alma.md` — como esses limites moldam a separação.
