# L2 — Reatividade

> **Propósito:** especificar a segunda camada de vida — **reatividade**: o robô **reage** a estímulos do ambiente sem precisar ser endereçado diretamente. É a diferença entre "está ligado e responde" (L1) e "percebe que algo aconteceu".
>
> **Pré-requisitos:** [README](../README.md), [01-presenca.md](01-presenca.md), [02-arquitetura/03-modalidades.md](../02-arquitetura/03-modalidades.md), [02-arquitetura/02-loops-temporais.md](../02-arquitetura/02-loops-temporais.md).
>
> **Fora do escopo:** continuidade entre sessões (`03-continuidade.md`), iniciativa (`04-iniciativa.md`).

---

## 1. Definição

> **Reatividade** é a capacidade de **perceber estímulos ambientais e responder fisicamente**, mesmo sem ser endereçado por nome.

Reatividade ≠ iniciativa. Reagir é responder a um **estímulo externo**. Iniciar é começar interação **do nada**.

| Comportamento | Camada |
|---|---|
| Você fala "Atlas" → ele responde | L1 (presença) |
| Você senta na mesa → ele vira pra você | **L2** (reatividade) |
| Você pega o robô → ele pisca surpreso | **L2** |
| Você passa atrás dele → ele acompanha com a cabeça | **L2** |
| Sem você fazer nada → ele te chama | L4 (iniciativa) |

Reatividade é o que faz o robô parecer **consciente do ambiente**, não só esperando ordem.

---

## 2. Sintomas observáveis

| Sintoma | Sinal de L2 |
|---|---|
| Robô vira a cabeça pra fonte de som forte | Head tracking sonoro |
| Robô vira pra você quando você senta | Detecção de presença |
| LED muda de cor quando luz ambiente muda | Sinal de adaptação |
| Robô "piscou" quando você se aproximou | Proximity detected |
| Robô parece "atento" quando você está conversando com outra pessoa | Ambient sound level alto |
| Robô diminui brilho quando ambiente fica escuro | Adaptação local |
| Robô reage a tag NFC sem precisar dizer nada | NFC trigger automático |
| Robô faz pequena reverência quando você pega ele | IMU gesture |

### Sintomas de **falha** de L2

| Falha | Por quê |
|---|---|
| Robô virado pra parede sem nunca virar pra você | Sem reatividade ambiental |
| Você se aproxima e ele continua olhando longe | Não percebe presença |
| Mudança radical de luz ambiente sem ajuste | Sensor ignorado |
| Reage a tudo (vira, vira, vira) | Reatividade hiperativa, vira incômodo |
| Reage com latência ridícula (>2s) | Não parece reativo |

---

## 3. Por que L2 é difícil de calibrar

Reatividade tem um **trade-off central**:

- **Pouca reatividade** → parece morto, L1 sem alma.
- **Muita reatividade** → parece nervoso, intrusivo, distrai.

A faixa útil é estreita. Reatividade boa é **percebida quase subliminarmente** — você não nota o robô virando pra você, mas se ele não virasse, você sentiria estranho.

### Princípio de calibração

> **Reagir o suficiente para parecer consciente; pouco o suficiente para não distrair.**

Sintoma de bom: você só nota a reatividade se prestar atenção. Sintoma de ruim: ele rouba sua atenção visual.

---

## 4. Modalidades de reatividade

L2 vem de 3 famílias de estímulo:

### 4.1 Auditivos (sem palavra-chave)

- **Som forte direcional**: vira a cabeça (head tracking via dual mic).
- **Mudança de nível ambiente**: silêncio → conversa → registra contexto.
- **Som ambiental contínuo** (música, AC): contexto, não disparo.

### 4.2 Visuais

- **Presença binária** (apareceu/sumiu): muda postura.
- **Movimento detectado** (câmera): vira pra origem.
- **Mudança de luz** (LTR-553): ajuste local de brilho.

### 4.3 Físicos / hápticos

- **Toque acidental**: micro-reação ("oi?").
- **Pega pelo robô** (IMU): face surpresa.
- **Aproximação muito próxima** (proximity): pausa, atenção.
- **Tag NFC aproximada**: lê e dispara fluxo associado.

### 4.4 Não-reativos

Eventos que **não** disparam reatividade visual/sonora:

- Mudança de bateria (só telemetria).
- Heartbeat (silencioso).
- Throttling térmico (degrada silenciosamente).
- Eventos que viriam por outras camadas (rituais, proposals).

---

## 5. Onde a reação acontece

Reatividade pode ser:

### 5.1 Reflex puro (L0 — local no firmware)

Resposta imediata, sem chamar a alma:

| Estímulo | Reação local |
|---|---|
| Som direcional | Vira cabeça em direção |
| Toque pad | Highlight visual |
| Luz ambiente mudou | Ajusta brilho display |
| Robô foi pego (IMU) | Olhinhos surpresos |
| Aproximação | Pequeno blink |

Latência: <100ms. Sem cognição. Frame de 30Hz cobrindo.

### 5.2 Híbrida (L1 reaction)

Reflex local + sinal vai pra alma para registro/contexto:

| Estímulo | Reação |
|---|---|
| Presença detectada | Vira pra você (local) + alma sabe que entrou |
| NFC tag | Lê (local) + alma decide ação |
| Gesto IMU | Acusa (local) + alma processa intent |

### 5.3 Cognitiva (L2 deliberation)

Estímulo dispara cognição. Resposta vem com latência maior:

- "Atlas" + pergunta — L1 wake word + L2 deliberação.
- NFC tag mapeada a fluxo complexo.
- Gesture mapeado a intent ("levantar e mostrar" — comando custom).

---

## 6. O que precisa acontecer (técnica)

L2 requer L1 sólida + os seguintes acréscimos:

### 6.1 Sensores ativados em ambient mode

- Câmera presença binária ON.
- IMU monitorando.
- LTR-553 amostrando.
- Mic dual com beamforming básico (direção).

### 6.2 Reflex layer expandido

L0 ganha **reflexos de reação** (não só idle):

- Head tracking sonoro.
- Resposta a presença.
- Resposta a IMU events.
- Ajuste de brilho automático.

### 6.3 Eventos reativos viram Envelope

Sinais reativos que importam viram `Interaction Envelope` com `trigger.type` apropriado (presence_change, etc.) — alma registra no Ledger e usa como contexto.

### 6.4 Filtros anti-jitter

- Presença detectada uma vez não basta — precisa de N samples consistentes.
- Som direcional curto não vira head turn imediato — espera confirmação (~300ms).
- IMU descontando movimento próprio dos servos.

Sem filtros, robô vira nervoso.

### 6.5 Vocabulário de reações limitado

Catálogo fechado:

| Reação | Estímulo |
|---|---|
| `look_toward_sound` | Som direcional |
| `acknowledge_presence` | Você apareceu |
| `surprise_held` | Robô foi pego (IMU) |
| `acknowledge_touch` | Touch curto sem mapeamento explícito |
| `track_movement` | Movimento detectado |
| `dim_response` | Mudança ambiente luminosa |

Adicionar reação = ADR. Não improvisar.

---

## 7. Camada de vida e camada de implementação

| Aspecto da L2 | Onde mora |
|---|---|
| Reflexos de reação | `06-firmware-stackchan/02-reflex-layer.md` (a escrever) |
| Sensores ativos em ambient | `02-arquitetura/05-modos-operacao.md` |
| Filtros anti-jitter | Firmware (implementação) |
| Eventos reativos no Ledger | `04-protocolos/02-eventos-evidence.md` |
| Catálogo de reações | Firmware (vocabulário fechado) |
| Implementação concreta | Fase 2 do `08-roadmap/` |

L2 é entregue na **Fase 2 (Corpo)** do roadmap.

---

## 8. Critérios de "L2 atingida"

- [ ] Robô vira pra fonte de som forte em <500ms perceptíveis.
- [ ] Detecta presença binária em <2s após você sentar.
- [ ] Reage a IMU "ser pego" com expressão facial em <300ms.
- [ ] Brilho do display ajusta-se a luz ambiente fluida (sem saltos).
- [ ] **Não** reage compulsivamente — observador externo não percebe overhead visual.
- [ ] Reações **não** acordam o robô do `dnd`/`private`.
- [ ] Anti-jitter funcionando: presença/som flutuante não causa turbulência visual.
- [ ] Sensação ao usar: "ele percebe quando estou aqui".
- [ ] Sensação ao usar: "não estou sendo observado de forma intrusiva".

Os dois últimos critérios são qualitativos mas críticos. Reatividade serve à **sensação de companhia atenta**, não à demonstração de tecnologia.

---

## 9. Reatividade e modos

| Modo | Reatividade ativa? |
|---|---|
| `ambient` | Plena |
| `interaction` | Reduzida (já está atento ao usuário) |
| `dnd` | **Suprimida** — não vira, não reage visualmente |
| `private` | Suprimida + sem captura |
| `private_physical` | Hardware mute domina |
| `degraded` | Reduzida (sem alma para contexto) |
| `standby` | Apenas wake/touch — sem head tracking |

Princípio: modo declara o nível de "atenção". L2 respeita.

---

## 10. Princípios de design para L2

### 10.1 Subliminaridade

Reatividade que parece natural é boa. Reatividade que rouba atenção é falha. Calibração tende ao **menos**.

### 10.2 Filtros antes de respostas

Antes de qualquer reação visual: filtro temporal/espacial. Estímulo precisa ser estável (>200ms) para virar reação.

### 10.3 Reação sem cognição não é resposta

L2 não tenta interpretar conteúdo. Vira pra som, mas não tenta entender o que foi dito (isso é L1+ wake word).

### 10.4 Catálogo fechado

Reagir sempre da mesma forma a estímulo similar. Variabilidade é ruído.

### 10.5 Hierarquia de prioridade

Estímulo mais "alto" suprime reação mais baixa:

```
private_physical (hardware mute) >
do_not_disturb >
estímulo cognitivo ativo (L2 deliberation) >
movimento próprio dos servos >
estímulo ambiental novo
```

Robô **não vira pra som** durante TTS dele mesmo. Não reage a presença durante DND.

---

## 11. Anti-padrões específicos da L2

| Anti-padrão | Por quê falha de L2 |
|---|---|
| Cabeça virando a cada som | Vira mascote nervoso |
| Reação compulsiva a movimento periférico | Distrai usuário |
| Animação grande para estímulo pequeno | Falta calibração |
| Reagir mesmo em DND ou private | Quebra contrato dos modos |
| Improvisar reações (não no catálogo) | Variabilidade quebra confiança |
| Reagir ao próprio movimento (servo) | Falta de filtro IMU |
| LED piscando a cada amostra de luz ambiente | Sem hysteresis |
| Reagir + tentar interpretar (cognição vazando) | L2 não é cognitivo |
| Sons gratuitos a cada reação | Polui ambiente sonoro |

---

## 12. Riscos específicos da L2

### 12.1 Mascote acidental

Risco: reações cute acumulam, robô vira "fofo" — vira mascote.

**Sinal:** você pega o robô e tem reação "awww".

**Mitigação:** vocabulário visual sóbrio. Reações claras mas neutras.

### 12.2 Vigilância percebida

Risco: head tracking constante pareça que o robô "está te seguindo".

**Sinal:** você sente desconforto com o olhar dele.

**Mitigação:** cabeça volta para neutro após N segundos. Não fixa olhar.

### 12.3 Hiperatividade visual

Risco: muita reatividade junto vira ruído visual constante.

**Sinal:** olhar pra mesa cansa.

**Mitigação:** orçamento total de movimento por minuto. Se passou X eventos no último min, reduzir.

### 12.4 Falha em modo

Risco: reação dispara em modo que deveria suprimir.

**Sinal:** robô virou em DND.

**Mitigação:** mode-check antes de qualquer reação. Bug aqui é alto risco — reage uma vez em private = quebra confiança.

---

## 13. Conexão com Context Builder

Reatividade gera **sinais de contexto**. Cada reação observada pelo robô vira sinal pra alma:

- Presença detectada → atualiza `user_present`.
- Som ambiente alto → atualiza `ambient_sound_level`.
- Movimento contínuo → atualiza `user_attention` (se conseguirmos inferir).

Esses sinais não são "decisões", são **input pro Context Builder**, que alimenta `Atlas Decide` quando uma operação cognitiva acontece (L1 wake word ou L4 ritual).

Reatividade como **fonte de contexto sutil** é o uso mais valioso. Movimento expressivo é só efeito colateral.

---

## 14. L2 → L3

L2 sólida é pré-requisito para L3 (continuidade). Especificamente:

- Sinais de contexto ricos vindo das modalidades de reatividade.
- Histórico de presença/ausência registrado.
- Padrões de uso emergem (que horas o usuário senta, que tempo fica).

Continuidade L3 se constrói **em cima** desses padrões — sem L2, robô não tem dados para construir continuidade.

---

## 15. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Tempo de "settle" para reaction (head tracking) | Calibração |
| ⚠️ Hysteresis para presença binária | Implementação |
| ⚠️ Volume facial das reações IMU | Calibração |
| ⚠️ Quantos segundos cabeça volta para neutro após track | UX |
| ⚠️ Catálogo final de reações + ícones internos | Implementação |
| ⚠️ Reagir a movimento se não há presença confirmada? | Privacy/UX trade-off |

---

## 16. Resumo

| Item | Detalhe |
|---|---|
| **Camada** | L2 — Reatividade |
| **Definição** | Percebe e reage a estímulos ambientais sem ser endereçado |
| **Sintoma resumo** | "Ele percebe quando estou aqui" — sem ser intrusivo |
| **Construído sobre** | L1 (presença sólida) + sensores + reflex expandido |
| **Pré-requisito de** | L3 (precisa de padrões para construir continuidade) |
| **Cobertura na fase** | Fase 2 do roadmap |
| **Risco principal** | Hiperatividade ou mascote |
| **Critério de sucesso** | Subliminaridade — sentido sem ser notado |

---

## Próximos passos de leitura

- `03-continuidade.md` — L3.
- `02-arquitetura/02-loops-temporais.md` — onde reflexos vivem.
- `08-roadmap/03-fase-2-corpo.md` — fase de implementação (a escrever).
- `06-firmware-stackchan/02-reflex-layer.md` — implementação local (a escrever).
