# Fase 1 — Voz

> **Propósito:** ativar a **modalidade de voz bidirecional** sobre a fundação da Fase 0. Wake word offline + STT streaming + pipeline cognitivo completo + TTS streaming + reprodução. Ao final da Fase 1, o Atlas atinge a **camada de vida L1 (presença plena)** — o robô responde a voz com latência percebida aceitável.
>
> **Pré-requisitos:** [README](../README.md), [Fase 0](01-fase-0-espelho.md), [03-camadas-de-vida/01-presenca.md](../03-camadas-de-vida/01-presenca.md), [04-protocolos/05-streaming.md](../04-protocolos/05-streaming.md), [05-policies/01-privacidade.md](../05-policies/01-privacidade.md).
>
> **Fora do escopo:** reatividade ambiental (Fase 2), continuidade entre sessões (Fase 3), iniciativa do Atlas (Fase 4).

---

## 1. Por que esta fase

### O risco que estamos mitigando

Fase 0 provou a fundação invisível (conexão, telemetria, output renderer básico, modo degradado). Fase 1 prova a **fundação cognitiva visível**: usuário fala, Atlas escuta, processa, responde por voz.

Sem Fase 1 sólida, todas as fases seguintes herdam latência ruim ou STT/TTS instável. Se a coluna vertebral cognitiva-vocal não está rígida, **caráter** (L5) eventualmente vai ser construído sobre areia.

### Princípio orientador

> **Latência percebida abaixo de 3s, ou nada.**

Esse é o teste central. Se uma pergunta simples leva mais de 3s até o Atlas começar a responder, a sensação de presença morre — usuário aprende a esperar, não a conversar.

---

## 2. Definição funcional

Ao final da Fase 1, o StackChan na mesa:

| Comportamento | Acontece quando |
|---|---|
| Detecta wake word offline em <100ms | Sempre que ouvido |
| Após wake word: ack visual + sonoro instantâneo | <200ms |
| Streaming de áudio para alma com <100ms de latência (LAN) | Janela de captura |
| STT streaming na alma — palavras parciais já processadas | Em paralelo à captura |
| Atlas pipeline executa (Decide → Domain → Runtime → Gates → Renderer) | <30s wallet |
| TTS streaming — primeira sílaba sai em <2s típico | Início da resposta |
| Face de avatar muda conforme estado (te ouvindo, pensando, falando) | Transições suaves |
| Indicação visual de captura ativa (LED vermelho + face) | Sempre que mic streaming on |
| Cancelamento mid-stream possível ("Atlas, espera") | Em qualquer momento |
| Resposta verbal acompanha card visual quando faz sentido | Multimodal coordenado |

### O que **NÃO** acontece nesta fase

- Câmera frame ainda **off** (chega na Fase 4 ou opcional).
- IMU gestures ainda **inativos** (chegam na Fase 2).
- Touch ainda **passivo** (sem mapeamento semântico — chega na Fase 4).
- NFC ainda **off** (Fase 2 ou 4).
- Reatividade ambiental (head tracking sonoro, presença) **não ativa** (Fase 2).
- Rituais agendados **não disparam** (Fase 3).
- Curator proposals **não chegam** (Fase 4).

Fase 1 é **só voz cognitiva ponta-a-ponta**. Nada mais.

---

## 3. Arquitetura mínima implementada

### 3.1 No corpo (firmware)

Componentes que **ativam** (em adição ao já feito na Fase 0):

| Componente | Estado em Fase 1 |
|---|---|
| Driver de mic + ES7210 | ✅ |
| Wake word offline | ✅ |
| Audio capture buffer + chunking | ✅ |
| VAD local | ✅ (encerra captura no silêncio) |
| Audio streaming (PCM 16kHz mono) via media channel | ✅ |
| Audio renderer / TTS playback streaming | ✅ |
| Face renderer com expressões: `attentive`, `thinking`, `informative` | ✅ |
| LED vermelho fixo durante captura | ✅ (privacy indication) |
| LED em modo "thinking" durante deliberação | ✅ |
| Comando `audio.tts_stream` recebido + executado | ✅ |
| Comando `audio.tts_stop` para cancelamento | ✅ |
| Reflex sonoro (~100ms ack após wake word) | ✅ |
| Catálogo mínimo de sound_ids cacheados | ✅ |
| Câmera | ⬜ (continua off) |
| NFC, IMU gestures, touch semântico | ⬜ |
| Servos como expressão | ⬜ (idle pattern só) |

### 3.2 Na alma (Atlas)

| Componente | Estado em Fase 1 |
|---|---|
| Surface adapter recebe modalidade `voice_streaming` | ✅ |
| STT provider integrado (streaming) | ✅ |
| Pipeline cognitivo recebe transcrição como input | ✅ (já existia em outras surfaces) |
| Output Renderer especializado para `stackchan` | ✅ (expandido da Fase 0) |
| TTS provider integrado (streaming) | ✅ |
| Decision Receipt com `output_intent.primary_modality = voice` | ✅ |
| Bundle de comandos para voz (face + LED + tts_stream) | ✅ |
| Gates específicos (`privacy_compliant`, `mode_compatible`) | ✅ |
| Cancelamento via stream.cancel | ✅ |
| Cache de respostas frequentes (TTL) | 🟡 opcional |
| Curator activity | ⬜ (Fase 4) |
| Heartbeat scheduler | ⬜ (Fase 3) |

### 3.3 Protocolo

Subset ativado nesta fase:

**Modalidades de input:**
- `wake_word`
- `voice_streaming`
- `system_event` (já na Fase 0)
- `telemetry` (já na Fase 0)

**Modalidades de output:**
- `face_render` (ampliado: expressões attentive/thinking/informative)
- `card_render` (já na Fase 0)
- `led_pattern` (com paletas thinking/listening)
- `audio_sound_id` (ack imediato)
- `audio_tts_stream` ⭐ novo
- `system.set_mode`, `system.cancel` (já existentes)

**Modos:**
- `ambient`, `degraded` já existem
- `interaction` ⭐ ativa nesta fase (transição automática após wake word)

**Eventos no Ledger:**
- `physical.wake_word.detected` ⭐
- `stream.audio.started/ended/cancelled` ⭐
- `stream.tts.started/ended/interrupted` ⭐
- `privacy.capture.started/ended` ⭐
- `surface.command.dispatched/acked` (continua)
- `surface.mode.changed` (com nova transição ambient ↔ interaction)

---

## 4. Critérios de aceitação

### 4.1 Latência

- [ ] Wake word detectada em <100ms.
- [ ] Ack sonoro disparado em <200ms após detecção.
- [ ] Primeiro chunk de áudio chega na alma em <100ms (LAN).
- [ ] STT primeira hipótese disponível em <800ms após wake.
- [ ] LLM TTFT (time to first token) <1500ms p95.
- [ ] TTS TTFB <500ms.
- [ ] **Latência percebida total (wake → primeira sílaba TTS) <3s típico, <5s p99.**

### 4.2 Qualidade de wake word

- [ ] Recognition rate ≥ 90% em ambiente normal.
- [ ] False positive rate < 1 por dia de uso normal.
- [ ] Threshold conservador documentado.
- [ ] Comportamento em ambiente ruidoso: gracefully degrada (mais misses, não mais false positives).

### 4.3 Qualidade de captura/transcrição

- [ ] STT WER (word error rate) razoável para voz normal próxima do robô.
- [ ] VAD encerra captura em ≤ 1.5s de silêncio.
- [ ] Captura máxima 30s (anti-runaway).
- [ ] Captura encerrada por comando explícito ("Atlas, espera") funciona.

### 4.4 Qualidade de TTS / playback

- [ ] Voz fixa configurada — provider escolhido.
- [ ] Streaming sem underruns audíveis em condições normais.
- [ ] Cancelamento mid-stream funciona — áudio para em <500ms.
- [ ] Buffer mínimo evita inicio truncado.

### 4.5 Privacy

- [ ] LED vermelho fixo aceso **antes** do primeiro chunk de áudio capturado.
- [ ] Face muda para "te ouvindo" sincronizada com ativação de captura.
- [ ] Captura encerra ao final da janela; LED vermelho apaga.
- [ ] Em modo `private`, captura é **rejeitada** com indicação visual.
- [ ] Em modo `private_physical`, mic está fisicamente desabilitado.
- [ ] Áudio bruto não persistido por padrão.
- [ ] Cada captura registrada em Evidence com timestamp e duração.

### 4.6 Coordenação multimodal

- [ ] Bundle típico (face + LED + TTS + card) renderizado em paralelo, sem desincronização visível.
- [ ] Transições de face (te ouvindo → pensando → falando) suaves.
- [ ] Cancelamento limpa todos os elementos simultaneamente.

### 4.7 Estabilidade

- [ ] 7+ dias contínuos com voz ativa: sem crashes.
- [ ] Reconexão durante captura: stream cancelado, próxima interação inicia novo.
- [ ] Reconexão durante TTS: TTS é cortado, sem áudio anômalo.
- [ ] Bateria + voz simultânea: degradação aceitável (notificar via card se baixa).

### 4.8 Anti-critérios

Garantir que **não** acontece:

- [ ] Mic streaming sem wake word precedente.
- [ ] Áudio capturado sem LED vermelho aceso.
- [ ] Conteúdo de áudio bruto persistido em microSD.
- [ ] STT/TTS sem TLS.
- [ ] Resposta sem passar por Atlas Decide (atalho).
- [ ] Cache local de respostas no corpo.

---

## 5. Trabalho concreto envolvido

### 5.1 Firmware (corpo)

1. **Wake word integration** — escolher e integrar engine. Decisão pendente em `01-hardware/02-limitacoes-fisicas.md`. Candidatos: Porcupine, ESP-Skainet.
2. **Audio capture pipeline** — driver mic + ES7210 + buffer + chunking + VAD.
3. **Audio streaming** — chunks via media channel, header binário, sequência incremental.
4. **TTS playback pipeline** — recepção de chunks, decoder (Opus se aplicável), buffer, speaker output.
5. **Face renderer expandido** — pelo menos 4 expressões (neutral, attentive, thinking, informative) com transições.
6. **LED renderer com paletas de estado** — listening, thinking, speaking, captura ativa.
7. **Reflex sonoro** — sample de ack, disparado localmente.
8. **Audio sound cache** — assets pré-cacheados em microSD.
9. **Cancellation handling** — receber `audio.tts_stop`, parar speaker, transicionar visual.

### 5.2 Atlas backend (alma)

1. **Surface adapter handler de voice_streaming** — recepção de chunks, montagem, encaminhamento.
2. **STT provider integration** — streaming, cancelamento, error handling.
3. **Output Renderer expandido para `stackchan`** — bundles que coordenam face + LED + TTS + card.
4. **TTS provider integration** — streaming, voice profile fixo.
5. **Cache de respostas frequentes** (opcional Fase 1) — intents recorrentes com TTL.
6. **Quality Gates específicos**:
   - `privacy_compliant` — mic apenas após wake word
   - `mode_compatible` — TTS bloqueado em DND/private
   - `consent_implicit_post_wake` — consent flag presente
7. **Mode manager** — transições ambient → interaction → ambient.
8. **Bundle composition logic** — Receipt → bundle de comandos coordenados.

### 5.3 Documentação adicional necessária antes de codar

- ⬜ `06-firmware-stackchan/02-reflex-layer.md` — implementação concreta do reflex layer + wake word.
- ⬜ `06-firmware-stackchan/03-renderers.md` — face/LED/audio renderers.
- ⬜ `07-integracao-atlas/02-output-renderer.md` — Output Renderer especializado.
- ⬜ ADR sobre escolha de provider STT inicial.
- ⬜ ADR sobre escolha de provider TTS inicial.
- ⬜ ADR sobre encoding (PCM vs Opus em Fase 1).

---

## 6. O que esta fase prova / valida

| Hipótese | Como é validada |
|---|---|
| Latência percebida cabe em janela aceitável | Medições reais p50/p95/p99 |
| Streaming ponta-a-ponta funciona estável | 7 dias contínuos com voz ativa |
| Wake word offline é confiável | Recognition + false positive nas faixas alvo |
| Privacy indication é robusta | LED + face nunca atrasa em relação à captura |
| Cancelamento mid-stream funciona | Teste explícito de interrupção |
| Coordenação multimodal está sincronizada | Olho humano não detecta lag |
| Modo `interaction` se comporta corretamente | Transições ambient ↔ interaction limpas |
| L1 (presença plena) é alcançável | Sensação acumulada após 1+ semana |

Falha em qualquer hipótese central = revisitar arquitetura, **não** seguir pra Fase 2.

---

## 7. Riscos da fase

### 7.1 Latência cumulativa
Cada componente individual cabe; juntos podem estourar. Solução: medir cedo, com providers reais, em ambiente real.

### 7.2 Provider STT/TTS instável
Provider externo pode degradar performance. Solução: fallback secundário, monitoramento, possibilidade de Module-LLM local em fase posterior.

### 7.3 Wake word miscalibrado
Falsos positivos (constrangedor) ou falsos negativos (frustante). Solução: priorizar baixo false positive; falsos negativos têm fallback (touch).

### 7.4 Cancelamento incompleto
Áudio TTS continua tocando após `stop` por buffer residual. Solução: limpar buffer hardware no comando de stop.

### 7.5 Buffer underrun
TTS começa a tocar e silencia mid-frase. Solução: buffer mínimo + monitor de fluxo.

### 7.6 Privacy indication atrasada
LED vermelho acende após primeiro chunk capturado (mesmo que microsegundos). Solução: ativar LED **antes** de habilitar mic capture.

### 7.7 Drift para Alexa
Risco fundador (ver `00-introducao/01-visao-geral.md`). Solução: revisão de policy a cada feature; resistir adições "porque dá".

---

## 8. Saída da fase / entrada da Fase 2

### Critério de saída
Todos os critérios de aceitação satisfeitos **em uso real de no mínimo 14 dias**.

### Antes de iniciar Fase 2:
- ✅ Critérios da Fase 1 satisfeitos.
- ✅ Documentação atualizada com lições aprendidas (provavelmente refinar latência targets, calibração de wake word).
- ✅ ADRs registrados para STT, TTS, encoding, wake word engine.
- ✅ Decisões pendentes da Fase 2 resolvidas (catálogo de gestures, head tracking calibration).

### O que Fase 2 herda
- Pipeline cognitivo-vocal estável.
- Indicações visuais e privacy bem calibrados.
- Coordenação multimodal funcional.
- Modo `interaction` operacional.

---

## 9. Anti-padrões específicos desta fase

| Anti-padrão | Como evitar |
|---|---|
| "Vamos só ativar a câmera porque está ali" | Não. Câmera frame fica off. |
| "Vamos cachear respostas no firmware pra reduzir latência" | Cache na alma; corpo só consome streaming |
| "Esse provider STT é caro, vamos rotear pelo serviço X" | Manter cognição centralizada na alma; corpo só fala com adapter |
| "Pra economizar, captura sem LED em modo silencioso" | Privacy indication é não-negociável |
| "Falar antes de TTS estar pronto" | Causa underrun; respeitar buffer |
| "Quality Gates de privacy podem ser bypassed nesta fase" | Nunca. Gates são duros desde Fase 1. |
| "Ack imediato pode ser TTS curto" | Não. Reflex sonoro é asset cacheado. TTS tem latência. |
| Fingir cognição em modo degraded | Mantém princípio: degraded é digno e silencioso |

---

## 10. Resumo

**Objetivo:** voz bidirecional ponta-a-ponta com latência aceitável.
**Camada de vida atingida:** L1 (presença plena).
**Duração estimada:** 4-6 semanas.
**Crítico de validar:** latência percebida + privacy indication.
**Saída:** Fase 2 (corpo expressivo) tem fundação cognitiva-vocal sólida.
**Tom da fase:** disciplina sobre features. Adicionar coisa nova não-essencial **agora** corrompe medições de latência.

---

## Próximos passos de leitura

- `03-fase-2-corpo.md` — próxima fase.
- `06-firmware-stackchan/02-reflex-layer.md` — implementação concreta (a escrever).
- `07-integracao-atlas/02-output-renderer.md` — coordenação multimodal (a escrever).
- `04-protocolos/05-streaming.md` — base teórica de streaming.
