---
id: atlas-embodiment-02-arquitetura-03-modalidades
type: engineering_knowledge
title: "03 — Modalidades"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 03 — Modalidades

> **Propósito:** definir as **modalidades** — os canais individuais por onde informação atravessa a fronteira corpo↔alma — e o que cada uma carrega de informação. O design do Embodiment é multimodal por construção; este documento padroniza vocabulário, papéis e combinações.
>
> **Pré-requisitos:** [README](../README.md), [01-corpo-vs-alma.md](01-corpo-vs-alma.md), [02-loops-temporais.md](02-loops-temporais.md), [04-protocolos/01-interaction-envelope.md](../04-protocolos/01-interaction-envelope.md), [04-protocolos/03-comandos-fisicos.md](../04-protocolos/03-comandos-fisicos.md).
>
> **Fora do escopo:** detalhes de privacidade por modalidade (vai pra `05-policies/01-privacidade.md`), implementação de drivers (vai pra `06-firmware-stackchan/`).

---

## 1. Definição de modalidade

> **Modalidade** = um canal coerente de informação entre corpo e alma, com semântica e características próprias (latência, bandwidth, privacidade, direção).

Modalidade ≠ sensor. Um sensor pode alimentar várias modalidades (mic alimenta wake_word + voice_streaming + ambient_sound_level), e uma modalidade pode integrar vários sensores (presence integra câmera + IMU + proximity).

Modalidade ≠ trigger. Trigger é o evento que inicia uma interação; modalidade é o canal que carrega dados ao longo dela.

---

## 2. Eixos de classificação

Cada modalidade é caracterizada por:

| Eixo | Valores |
|---|---|
| **Direção** | input (corpo→alma) / output (alma→corpo) / bidirecional |
| **Granularidade** | discreto (evento pontual) / contínuo (stream) |
| **Bandwidth** | baixo (bytes) / médio (KB) / alto (MB) |
| **Latência tolerada** | reflex (10ms) / reactive (1s) / deliberate (10s+) |
| **Sensibilidade** | estrutural (não revela conteúdo) / conteúdo (revela) |
| **Default privacy** | sempre on / on com indicação / opt-in |
| **Camada cognitiva** | nenhuma / hint local / requer alma |

Esses eixos não são ortogonais — várias correlações naturais (alto bandwidth + alta sensibilidade tipicamente). Mas explicitar ajuda a evitar surpresa.

---

## 3. Tabela canônica de modalidades

### 3.1 Modalidades de **input** (corpo → alma)

| Modalidade | Granularidade | Bandwidth | Latência | Sensibilidade | Default privacy | Carrega |
|---|---|---|---|---|---|---|
| `wake_word` | Discreto | Baixo | Reflex | Estrutural | Sempre on | Trigger de captura |
| `voice_streaming` | Contínuo | Alto | Reactive | Conteúdo | Opt-in pós wake | Áudio bruto / texto STT |
| `voice_short_command` | Discreto | Médio | Reactive | Conteúdo | Opt-in | Comando único curto |
| `ambient_sound_level` | Contínuo | Baixo | Deliberate | Estrutural | Sempre on | Nível sonoro |
| `touch_pad` | Discreto | Baixo | Reflex | Estrutural | Sempre on | Pad + duração + combo |
| `nfc_tag` | Discreto | Baixo | Reactive | Estrutural | Opt-in | UID + payload NDEF |
| `imu_gesture` | Discreto | Baixo | Reactive | Estrutural | Opt-in | Gesto pré-definido |
| `imu_orientation` | Contínuo | Baixo | Deliberate | Estrutural | Sempre on (sample) | Orientação 3D |
| `presence_binary` | Discreto | Baixo | Reactive | Estrutural | On com indicação | Bool: presente |
| `presence_continuous` | Contínuo | Baixo | Deliberate | Estrutural | On com indicação | Confiança presença |
| `camera_image` | Discreto | Alto | Deliberate | Conteúdo | Opt-in explícito | Frame JPEG |
| `ambient_light` | Contínuo | Baixo | Deliberate | Estrutural | Sempre on | Lux |
| `proximity` | Discreto | Baixo | Reactive | Estrutural | Sempre on | Aproximação cm |
| `ir_received` | Discreto | Baixo | Reactive | Estrutural | Opt-in | Código IR |
| `ble_event` | Discreto | Baixo | Reactive | Estrutural | Opt-in | Periférico BLE |
| `system_event` | Discreto | Baixo | N/A | Estrutural | Sempre on | Boot, thermal, low_battery |
| `telemetry` | Contínuo | Baixo | N/A | Estrutural | Sempre on | Métricas hw |

### 3.2 Modalidades de **output** (alma → corpo)

| Modalidade | Granularidade | Bandwidth | Latência | Sensibilidade | Carrega |
|---|---|---|---|---|---|
| `face_render` | Discreto | Baixo | Reflex | Estrutural | Expressão + paleta |
| `card_render` | Discreto | Baixo | Reactive | Conteúdo | Title + body + ícone |
| `display_transition` | Discreto | Baixo | Reflex | Estrutural | Transição |
| `led_pattern` | Discreto | Baixo | Reflex | Estrutural | Cor + animação |
| `servo_pose` | Discreto | Baixo | Reflex | Estrutural | Posição alvo |
| `servo_gesture` | Discreto | Baixo | Reactive | Estrutural | Gesto nominal |
| `servo_idle` | Contínuo | Baixo | Reflex | Estrutural | Padrão idle |
| `audio_sound_id` | Discreto | Baixo | Reflex | Estrutural | Som pré-cacheado |
| `audio_tts_stream` | Contínuo | Médio-Alto | Reactive | Conteúdo | Áudio TTS chunks |
| `ir_transmit` | Discreto | Baixo | Reactive | Estrutural | Código IR |
| `system_command` | Discreto | Baixo | Reflex/Reactive | Estrutural | set_mode, cancel, config_update |

---

## 4. Modalidades em detalhe

### 4.1 `wake_word`
**Trigger primário de toda interação cognitiva via voz.**
- Detecção offline no DSP local (sem áudio sair do robô).
- Engine: candidatos em `01-hardware/02-limitacoes-fisicas.md` (Porcupine, ESP-Skainet, custom).
- Modelo de palavra: "atlas" (provisório).
- **Confidence threshold conservador.** Falsos positivos quebram confiança.
- Após detecção: dispara `voice_streaming` numa janela curta (default 6s sem nova fala).

### 4.2 `voice_streaming`
**Áudio bruto continuamente para a alma.**
- Encoding: PCM 16kHz mono inicial; Opus quando justificado (`04-protocolos/04-transporte.md`).
- Chunking: ~20-50ms por chunk via media channel.
- Janela máxima: configurável (default 30s).
- Detecção de silêncio (VAD) localmente: corpo encerra stream após N ms de silêncio.
- LED vermelho fixo + face "te ouvindo" durante.
- ⚠️ Detalhes em `04-protocolos/05-streaming.md`.

### 4.3 `voice_short_command`
**Comando vocal único, captura sub-segundo.**
- Útil para comandos rápidos pós-confirmação (sim/não, números).
- Janela bem menor (~1.5s).
- Pode ser auto-disparada após `voice_streaming` que pediu confirmação.

### 4.4 `ambient_sound_level`
**Sinal de contexto sonoro.**
- Não captura conteúdo. Mede apenas dB médio.
- Sample a cada N segundos.
- Quantizado: `silent`, `quiet`, `normal`, `noisy`.
- Entra em `context_signals` de cada Envelope.
- Atlas pode usar para: ajustar volume de TTS, desativar `voice_streaming` em ambiente muito ruidoso, registrar contexto.

### 4.5 `touch_pad`
**Toque em pad de cabeça.**
- 3 zonas. Cada toque carrega: pad (1/2/3), duração ms, combo (lista de pads simultâneos).
- Mapeamento padrão: `1=✓`, `2=✗`, `3=💬`. **Imutável por contexto.**
- Combos reservados: `[1,2,3] hold 2s = private_physical mute`.
- Reflex local: highlight imediato do pad tocado.
- Semântica derivada do contexto da alma (qual proposal está pendente, etc.).

### 4.6 `nfc_tag`
**Aproximação de tag NFC.**
- Carrega: UID + NDEF records (se houver).
- Mapeamento UID→intent vive na alma (Policy/Profile).
- Tags etiquetadas fisicamente para discoverability ("FOCUS", "REVIEW", "PRIVATE").
- Beep curto + face de "lendo…" como feedback.

### 4.7 `imu_gesture`
**Gestos físicos pré-definidos.**
- Catálogo fechado (não detecta gesto arbitrário): `shake`, `tap_head`, `lift`, `flip`.
- Detecção local com features simples sobre IMU.
- Cada gesto: intensity + duration + tipo.
- Filtro: descontar movimento próprio dos servos.

### 4.8 `imu_orientation`
**Orientação 3D contínua.**
- Sample a 10Hz, mas **incluído em Envelope só se relevante**.
- Útil pra contexto: "robô foi virado pra parede" → desativar display.
- Não cria evento próprio — entra em `context_signals` quando muda.

### 4.9 `presence_binary`
**Detecção binária de usuário próximo.**
- Combina câmera (presença visual grosseira) + proximity (mão próxima) + ambient (luz/som mudou).
- Resultado: `present: bool`.
- Mudanças disparam Envelope com `trigger.type = presence_change`.
- Sem captura de imagem — só sinal binário.

### 4.10 `presence_continuous`
**Confiança contínua de presença.**
- Float [0,1] ao longo do tempo.
- Útil pra: "presença estável há 10min" (entrou em foco), "presença intermitente" (passando).
- Sample no `context_signals`.

### 4.11 `camera_image`
**Captura de frame.**
- Bandwidth alto. **Opt-in explícito** sempre.
- Trigger: comando do usuário ("tira foto") ou intent que requer visão.
- Resolução: 320×240 ou 640×480.
- Encoding: JPEG (qualidade ajustável).
- LED + sound + face de "captura" obrigatórios.
- Imagem viaja pelo media channel.

### 4.12 `ambient_light`
**Lux ambiente.**
- Continuous, sample em `context_signals`.
- Usos: ajuste de brilho do display (loop local), sinal de hora do dia, "está escuro" pra modo noturno.

### 4.13 `proximity`
**Aproximação detectada.**
- Curto alcance (cm).
- Disparo: mão muito próxima → trigger `proximity` no Envelope.
- Útil pra: "petting" virtual → resposta afetiva, ou wake gesture sem voz.

### 4.14 `ir_received`
**Código IR de controle remoto.**
- Permite controle alternativo (acessibilidade, fallback).
- Códigos mapeados na alma para intents.
- Raramente usado, mas presente.

### 4.15 `ble_event`
**Evento de periférico BLE pareado.**
- Sensores Grove BLE, smartwatch, M5StickC controle, etc.
- Pareamento explícito; eventos chegam ao Envelope.

### 4.16 `system_event`
**Eventos operacionais do hardware.**
- Boot, low_battery, thermal_throttle, watchdog_reset, ota_completed.
- Disparados internamente pelo firmware.
- Vão pra alma como Envelope com `trigger.type = system`.

### 4.17 `telemetry`
**Métricas contínuas via heartbeat.**
- battery_pct, is_charging, thermal_state, wifi_rssi, uptime, queue_depth, free_psram.
- Sample 30s.
- Sample rate reduzido no Ledger (1 em 10 por padrão).

### 4.18 Output: `face_render`, `card_render`
- Display é canal visual primário.
- `face_render`: expressão + paleta (vocabulário fechado).
- `card_render`: title + body + icon + TTL + interaction_hint.
- Detalhes em `04-protocolos/03-comandos-fisicos.md`.

### 4.19 Output: `led_pattern`
- 12 LEDs como canal de status ambiente.
- Vocabulário definido em `01-hardware/01-componentes.md`.
- Cores/padrões reservados (vermelho fixo = privacy).

### 4.20 Output: `servo_pose`, `servo_gesture`, `servo_idle`
- Movimento físico como canal não-verbal.
- Vocabulário fechado de gestos.
- Idle controlado por L0 com parâmetros da alma.

### 4.21 Output: `audio_sound_id`, `audio_tts_stream`
- Canal sonoro com 2 sub-modalidades:
  - Sons curtos cacheados localmente (instantâneos).
  - TTS streaming (com latência mas conteúdo dinâmico).

### 4.22 Output: `ir_transmit`
- Atuação no mundo físico via IR.
- Códigos mapeados a dispositivos conhecidos (TV, AC, luz).

### 4.23 Output: `system_command`
- Comandos administrativos: set_mode, cancel, config_update, reboot, OTA.
- Não geram conteúdo visível, mas afetam comportamento.

---

## 5. Multimodalidade — combinações

Modalidades raramente operam isoladas. Casos canônicos:

### 5.1 Pergunta vocal típica
- Input: `wake_word` + `voice_streaming` + `presence_binary` + `ambient_sound_level` + `imu_orientation`
- Output: `face_render` + `led_pattern` + `audio_tts_stream` + `card_render` + `servo_gesture`

### 5.2 Aprovação de proposal
- Input: `touch_pad` + (sem voz, sem mais nada — interaction discreta)
- Output: `face_render` + `audio_sound_id` (ack curto) + `led_pattern`

### 5.3 Trigger NFC
- Input: `nfc_tag` + `presence_binary`
- Output: `face_render` + `card_render` + opcionalmente `audio_tts_stream` se ritual o requer

### 5.4 Foto requisitada
- Input: `voice_streaming` ("tira foto") + `camera_image` + `presence_binary`
- Output: `audio_sound_id` (shutter) + `face_render` + `card_render` (preview opcional)

### 5.5 Modo privado físico
- Input: `touch_pad` (combo 3-pad hold)
- Output: `system_command` (set_mode private) + `led_pattern` (vermelho fixo) + `face_render` (privacidade)

---

## 6. Combinação proibida

Algumas combinações são **anti-padrão** ou impossíveis por policy:

| Combinação | Por quê proibida |
|---|---|
| `voice_streaming` ativo durante `mode = private` | Privacy garantia falha |
| `camera_image` sem consent_tag explícito | Privacy P2 |
| `voice_streaming` sem `wake_word` precedente | Wake word é ponte obrigatória pra captura |
| `audio_tts_stream` em modo `do_not_disturb` | DND silencia voz por design |
| `servo_gesture` em modo `private` | Movimento é parte do silêncio |
| `face_render: alert` sem evento que justifique | Alarme falso quebra confiança |

Validação dessas combinações vive em `Quality Gates` da alma.

---

## 7. Adicionar uma nova modalidade

Roteiro padrão (ADR exigido):

1. Justificar: que problema resolve? Por que modalidades existentes não bastam?
2. Caracterizar nos eixos da seção 2.
3. Definir privacy default e indicação visual (se sensível).
4. Estender `01-interaction-envelope.md` (modalidades) ou `03-comandos-fisicos.md` (comandos).
5. Atualizar `surface.capabilities` no schema.
6. Validar que firmware suporta antes de declarar disponível.
7. Registrar evento no Ledger ao primeiro uso real.
8. Decidir mapeamento Envelope/Operation Envelope.

Não adicionar modalidade ad-hoc. Cada modalidade é compromisso de longo prazo.

---

## 8. Modalidades e loops temporais

Mapeamento natural (referência cruzada com `02-loops-temporais.md`):

| Modalidade | Loop primário | Comentário |
|---|---|---|
| `wake_word` | L0 (detecção) → L1 (transição) | Reflex local, transição rápida |
| `voice_streaming` | L1 (captura) + L2 (cognição) | L2 começa em paralelo com L1 |
| `touch_pad` | L1 (resposta direta) | Reflex visual L0, semântica L1 |
| `nfc_tag` | L1 + L2 | Lookup de mapeamento + execução |
| `presence_binary` | L1 ou L4 (heartbeat) | Disparo direto ou consolidado |
| `system_event` | L1 (detecção interna) → L2 se cognitivo | Boot/thermal puramente operacional |
| `telemetry` | L4 (heartbeat) | Sempre periódico |
| `face_render` | L0 + L1 | Renderização imediata, semântica L1 |
| `audio_tts_stream` | L1 + L2 | Streaming desde antes de L2 terminar |
| `led_pattern` | L0 | Refletor de estado |

---

## 9. Modalidades e privacy

Cada modalidade tem default próprio (`05-policies/01-privacidade.md` é fonte canônica). Resumo aqui:

| Categoria | Modalidades | Default |
|---|---|---|
| Sempre on (estrutural) | wake_word, ambient_sound_level, ambient_light, proximity, imu_orientation, telemetry, system_event, touch_pad | ON |
| On com indicação | presence_binary, presence_continuous | ON com LED indicativo |
| Opt-in pós wake | voice_streaming, voice_short_command | Janela curta após wake |
| Opt-in explícito | camera_image, ir_received, ble_event, nfc_tag, imu_gesture | Comando ou tag explícita |

---

## 10. Anti-padrões

| Anti-padrão | Por quê |
|---|---|
| Modalidade "voice_always_on" | Quebra wake word como ponte |
| Modalidade que mistura sensores ad-hoc no firmware | Modalidade é canal coerente, não bag |
| Inventar gesture sem documentar no catálogo | Vocabulário fechado |
| Output renderizando mensagem de Domain inexistente | Vocabulário emerge da alma, não do corpo |
| Múltiplas modalidades de output simultâneas conflitantes (face triste + LED verde) | Coordenação de bundle existe pra evitar |
| Camera_image sem consent_tag | Privacy P2 |

---

## 11. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Engine de wake_word | Fase 1 |
| ⚠️ VAD (voice activity detection) — local ou alma? | Fase 1 |
| ⚠️ Catálogo final de gestures (servo + IMU) | Fase 2 |
| ⚠️ Catálogo de sound_ids cacheados | Fase 0 |
| ⚠️ Mapeamento default de touch pads (1=✓ etc.) | Fase 4 |

---

## Próximos passos de leitura

- `04-integracao-kernel.md` — onde cada modalidade entra no pipeline.
- `05-modos-operacao.md` — como modos afetam modalidades.
- `04-protocolos/02-eventos-evidence.md` — quais modalidades viram que eventos.
- `04-protocolos/05-streaming.md` — modalidades contínuas em detalhe.
