# Fase 2 — Corpo

> **Propósito:** ativar **expressão física plena** — servos com vocabulário de gestos, LEDs com paleta semântica completa, animações faciais ligadas a Domain/estágio do pipeline, **reatividade ambiental** (head tracking sonoro, presença, IMU). Ao final da Fase 2, o Atlas atinge a **camada de vida L2 (reatividade)** — comunicação não-verbal viva.
>
> **Pré-requisitos:** [README](../README.md), [Fase 0](01-fase-0-espelho.md), [Fase 1](02-fase-1-voz.md), [03-camadas-de-vida/02-reatividade.md](../03-camadas-de-vida/02-reatividade.md), [02-arquitetura/03-modalidades.md](../02-arquitetura/03-modalidades.md).
>
> **Fora do escopo:** continuidade entre sessões (Fase 3), iniciativa (Fase 4), caráter (Fase 5).

---

## 1. Por que esta fase

### O risco que estamos mitigando

Fase 1 entregou voz e fundação cognitiva. Mas o robô ainda parece **um console com voz**. Sem corpo expressivo:
- Domínios são indistinguíveis visualmente.
- Estados do pipeline (pensando, gates, repair) ficam invisíveis.
- Robô não percebe o ambiente — vira reativo só a comando.

Fase 2 transforma o StackChan em **presença expressiva ambiental**. Comunicação não-verbal vira parte do bandwidth.

### Princípio orientador

> **Calibração subliminar é vitória.**

Robô percebido pela presença sutil supera robô que demonstra reatividade. Reagir o suficiente para parecer consciente, pouco o suficiente para não distrair.

---

## 2. Definição funcional

Ao final da Fase 2:

| Comportamento | Acontece quando |
|---|---|
| Robô vira a cabeça pra fonte de som forte | Som direcional detectado (head tracking) |
| Robô vira pra usuário ao detectar presença | Presence transition off→on |
| Servo idle com micro-movimentos calibrados | Sempre (frequência baixa) |
| Face muda conforme Domain ativo no pipeline | Decision Receipt indica domain |
| Face muda conforme estágio (pensando, respondendo, gates falhando) | Estado do pipeline |
| LED reflete estado de Quality Gates (verde/amarelo/vermelho) | Pipeline events |
| LED indica council de providers em deliberação | Multi-provider deliberação |
| IMU detecta "robô foi pego" → face surpresa breve | Gesture event |
| IMU detecta "robô virado pra parede" → display dim | Orientation event |
| Touch pad acionado com semântica (pad mapeado) | Touch contextual |
| Brilho de display ajusta a luz ambiente | LTR-553 sample |

### O que **NÃO** acontece nesta fase

- NFC ainda **off** — entra em Fase 4 (atalho de fluxo).
- Câmera frame ainda **off** — Fase 4.
- Rituais agendados **não disparam** — Fase 3.
- Curator proposals **não chegam** — Fase 4.
- IR transmit (apagar luz, etc.) **não ativo** — Fase 4 ou opcional.
- Eventos relacionais (`relationship.milestone`) **não emitidos** — Fase 3.

Fase 2 é **expressividade física + reatividade ambiental**. Não muda o que o Atlas decide; muda **como ele se manifesta**.

---

## 3. Arquitetura mínima implementada

### 3.1 No corpo (firmware)

| Componente | Estado em Fase 2 |
|---|---|
| Servo driver com feedback (pan + tilt) | ✅ |
| Vocabulário de gestos: `nod`, `shake`, `look_at_user`, `look_at_display`, `look_around`, `tilt_thinking`, `alert_stance` | ✅ |
| Servo idle pattern com calibração (rare/occasional/frequent) | ✅ |
| Head tracking sonoro (dual mic beamforming básico) | ✅ |
| LED renderer com paleta completa (cores reservadas) | ✅ |
| Padrões LED: `breathing`, `pulse`, `rotating`, `chase`, `stable` | ✅ |
| Câmera presença binária (não captura imagem) | ✅ |
| IMU gestures: `shake`, `tap_head`, `lift`, `flip` | ✅ |
| IMU orientation tracking | ✅ |
| Touch pad com semântica configurável da alma | ✅ |
| LTR-553 ambient light sampling (ajuste local de brilho) | ✅ |
| Filtros anti-jitter para reações | ✅ |
| Vocabulário de "reactions" reflex | ✅ (`look_toward_sound`, `acknowledge_presence`, `surprise_held`, etc.) |
| Câmera frame, NFC, IR transmit | ⬜ |

### 3.2 Na alma (Atlas)

| Componente | Estado em Fase 2 |
|---|---|
| Domain → expressão facial mapping | ✅ |
| Estado pipeline → visual state mapping (gates, repair, council) | ✅ |
| Comandos `servo.gesture`, `servo.set_target`, `servo.idle_pattern` | ✅ |
| Bundle composition expandido (face + LED + servo coordinated) | ✅ |
| Surface adapter recebe modalidades reativas (presence, IMU) | ✅ |
| Context Builder usa sinais físicos (presença, ambiente) | ✅ |
| Event registration: `physical.presence.changed`, `physical.imu.gesture`, `physical.touch.tap` | ✅ |
| Mode-aware: respeita DND/private suprimindo reatividade | ✅ |

### 3.3 Protocolo

**Modalidades de input adicionadas:**
- `presence_binary`
- `presence_continuous` (sample contínuo)
- `imu_gesture`
- `imu_orientation` (em context_signals)
- `touch_pad` (agora com semântica)
- `ambient_light` (continua, mas usa locally para brilho)
- `ambient_sound_level` (continua)

**Modalidades de output adicionadas:**
- `servo_pose`, `servo_gesture`, `servo_idle` ⭐
- `led_pattern` ampliado com novos padrões
- `display_transition` ⭐ (transições suaves)

**Modos:**
- Todos modos da Fase 1 continuam.
- `interaction` agora tem expressão facial diferente (atento vs ambient).

**Eventos no Ledger adicionados:**
- `physical.presence.changed`
- `physical.imu.gesture`
- `physical.proximity.detected` (sample 1/10)
- `physical.touch.tap` (com interpretation_hint baseado em contexto)
- `surface.command.dispatched` para todos novos comandos físicos

---

## 4. Critérios de aceitação

### 4.1 Reatividade calibrada

- [ ] Robô vira a cabeça pra som direcional em <500ms perceptíveis.
- [ ] Volta a posição neutra em ~5-8s sem fixar olhar.
- [ ] Detecção de presença binária funciona em 1-2m de distância.
- [ ] Anti-jitter: presença flutuante (passar/voltar) não causa virar de cabeça.
- [ ] Servo idle: pelo menos 30s entre micro-movimentos.
- [ ] Volume sonoro do servo idle: imperceptível em silêncio de fundo.
- [ ] Reação a IMU "ser pego" em <300ms (face muda).

### 4.2 Expressão semântica

- [ ] Cada Domain tem expressão facial associada (catálogo 5: programming, finance, personal_dev, marketing, self_improvement).
- [ ] Transição entre domains visualmente perceptível mas suave.
- [ ] Estado de "thinking" (L2 deliberation ativa) reconhecível.
- [ ] Quality Gate falhou: LED amarelo pulsante + face específica.
- [ ] Repair Loop ativo: indicação visual (LED + face).
- [ ] Multi-provider deliberation (council): LEDs com cores rotativas.

### 4.3 Coordenação

- [ ] Bundle típico (face + LED + servo + card + TTS) renderizado em paralelo, sincronizado.
- [ ] Servo gira para display ao mostrar info-card.
- [ ] Servo retorna pra usuário ao falar.
- [ ] Reflex (head tracking) não interfere durante TTS do próprio robô.

### 4.4 Modos respeitados

- [ ] Em DND: reatividade ambiental **suprimida** (sem head tracking).
- [ ] Em private: idem + indicação visual de privacy.
- [ ] Em private_physical: servos travados.
- [ ] Em degraded: face triste, sem expressão de domain.
- [ ] Em standby: servos parados em posição parking.

### 4.5 Ambient adaptação

- [ ] Brilho de display ajusta a luz ambiente em transição suave.
- [ ] Mudança radical de luz (acendeu/apagou) não causa flicker.
- [ ] Ambient sound level entra em context_signals e atualiza Atlas Decide.

### 4.6 Estabilidade

- [ ] 7+ dias contínuos com expressão e reatividade ativas: sem crashes.
- [ ] Bateria com servos mais ativos: degradação previsível, sem surpresa.
- [ ] Térmico: servos ativos não causam throttling em uso normal.
- [ ] IMU descontando movimento próprio (servo) — sem falsos gestures.

### 4.7 Subliminaridade

Critério qualitativo — observador externo:

- [ ] Pessoa não-treinada vê o robô e percebe "está vivo" mas não consegue listar **o que** especificamente faz isso.
- [ ] Pessoa que usa ele dia a dia diz "não percebo mais que ele me olha quando entro — é natural".
- [ ] Mas se desabilitar reatividade por 1 dia: usuário sente "estranho".

### 4.8 Anti-critérios

- [ ] Robô virando a cabeça a cada som ambiente.
- [ ] Servo barulhento durante uso normal.
- [ ] Idle frequente demais (perceptível como "agitado").
- [ ] Gestures espontâneos (cute, não-catalogados).
- [ ] Reação em DND ou private (bug crítico).
- [ ] LED vermelho usado para algo além de captura/privacy.

---

## 5. Trabalho concreto envolvido

### 5.1 Firmware (corpo)

1. **Servo control com feedback** — driver, suavização (easing), detecção de obstrução.
2. **Vocabulário de gestures** — biblioteca de animações pré-projetadas (catálogo fechado).
3. **Head tracking sonoro** — dual mic beamforming, filtro temporal (>200ms estabilidade).
4. **Presence binary detector** — câmera amostrando a baixo fps, threshold + hysteresis.
5. **IMU pipeline** — filtro do movimento próprio dos servos, detecção de gestos pré-definidos.
6. **Reflex layer expandido** — adicionar reatividade ambiental ao L0.
7. **Filtros anti-jitter** — todos os reflexos passam por debounce/hysteresis.
8. **Face renderer expandido** — expressões adicionais, transições suaves entre estados.
9. **LED renderer com paleta completa** — 12 LEDs endereçáveis + padrões.
10. **Mode-aware suppression** — reflexos consultam modo antes de disparar.
11. **Servo audio profile** — calibração de speed/easing para minimizar ruído.

### 5.2 Atlas backend (alma)

1. **Domain → expressão mapping** — configuração persistida (Domain Faces).
2. **Pipeline state → visual mapping** — Decide/Gates/Repair refletem em comandos.
3. **Output Renderer expandido** — bundles maiores e coordenados.
4. **Context Builder consume sinais físicos** — presença, ambient, mode entra em context.
5. **Surface registry tracking de presence_continuous** — agregar para perfil de uso.
6. **Council deliberation indicator** — quando alma está consultando múltiplos providers, sinaliza visualmente.
7. **Mode transitions ambient ↔ interaction** ajustadas com expressões diferentes.

### 5.3 Documentação adicional necessária

- ⬜ `06-firmware-stackchan/03-renderers.md` — implementação dos renderers físicos.
- ⬜ `07-integracao-atlas/02-output-renderer.md` (expandir) — coordenação multimodal completa.
- ⬜ `07-integracao-atlas/05-domain-faces.md` — mapeamento Domain → expressão.
- ⬜ ADR sobre catálogo final de gestures.

---

## 6. O que esta fase prova / valida

| Hipótese | Como é validada |
|---|---|
| Reatividade ambiental é viável sem virar nervoso | Calibração testada em uso real |
| Expressão facial por Domain é perceptível mas natural | Olho humano percebe diferença sem distração |
| LEDs como canal de status funciona em uso ambiente | Você sabe estado sem olhar a tela |
| Servos podem ser silenciosos | Sem ruído audível em uso normal |
| Coordenação multimodal escala | Bundles maiores ainda são síncronos |
| L2 (reatividade) é alcançável | Sensação acumulada de "ele percebe" sem invasão |

---

## 7. Riscos da fase

### 7.1 Servo barulhento
Movimentos rápidos = ruído audível. Solução: speed conservador, easing, calibração.

### 7.2 Reatividade hiperativa
Cabeça virando todo segundo. Solução: filtros temporais, rate limit, orçamento de movimento.

### 7.3 Mascote drift
Expressões cumulativamente "fofas" → mascote. Solução: vocabulário visual sóbrio; revisão de cada gesture.

### 7.4 IMU falsos positivos
Servo movendo gera leituras de IMU que parecem gesture. Solução: filtro de baseline + sample comparison.

### 7.5 Drift visual entre Domains
Cada Domain ganhando expressões "decoradas" demais. Solução: catálogo de expressões fechado, paletas neutras.

### 7.6 Reagir em modo errado
Reflex disparando em DND/private. Solução: mode-check obrigatório antes de qualquer reflex visual.

### 7.7 Bateria
Servos ativos consomem rapidamente. Solução: tethered USB-C como modo normal, idle muito conservador em bateria.

---

## 8. Saída da fase / entrada da Fase 3

### Critério de saída
Todos os critérios de aceitação satisfeitos **em uso real de no mínimo 14 dias**.

### Antes de iniciar Fase 3:
- ✅ Critérios da Fase 2 satisfeitos.
- ✅ Catálogo final de gestures registrado em ADR.
- ✅ Calibração de servos documentada (speeds, easing, frequency).
- ✅ Domain → faces mapping aprovado.

### O que Fase 3 herda
- Expressividade física plena.
- Reatividade ambiental subliminar.
- Coordenação multimodal robusta.
- Sinais físicos enriquecendo Context Builder.

---

## 9. Anti-padrões específicos desta fase

| Anti-padrão | Como evitar |
|---|---|
| "Vamos adicionar gesture cute X porque é fofo" | Catálogo fechado, ADR obrigatório |
| "Reatividade aumentou! Robô parece mais vivo!" | Subliminar é vitória; aumentar = drift |
| "Tudo bem reagir um pouco em DND" | Não. Modo é absoluto. |
| "Vermelho em gates falhando? Tem sentido" | Não. Vermelho fixo é só captura/privacy. |
| "Servo idle a cada 5s pra parecer mais vivo" | Ruído + bateria + drift mascote |
| "Vamos animar a transição entre Domains com efeito 3D" | Sobriedade visual; transição simples |
| "Easter egg de gesture pra dia X" | Mascote |
| Câmera frame "só pra preview" | Privacy P2 — opt-in explícito |

---

## 10. Resumo

**Objetivo:** corpo expressivo + reatividade ambiental subliminar.
**Camada de vida atingida:** L2 (reatividade).
**Duração estimada:** 3-5 semanas.
**Crítico de validar:** subliminaridade da reatividade.
**Saída:** Fase 3 (continuidade) tem corpo vivo para refletir padrões temporais.
**Tom da fase:** disciplina visual e auditiva. Sobriedade contra drift mascote.

---

## Próximos passos de leitura

- `04-fase-3-continuidade.md` — próxima fase.
- `06-firmware-stackchan/03-renderers.md` — implementação (a escrever).
- `07-integracao-atlas/05-domain-faces.md` — mapeamento Domain → expressão (a escrever).
- `03-camadas-de-vida/02-reatividade.md` — fundamentos teóricos.
