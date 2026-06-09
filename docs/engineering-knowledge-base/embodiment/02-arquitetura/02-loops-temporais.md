---
id: atlas-embodiment-02-arquitetura-02-loops-temporais
type: engineering_knowledge
title: "02 — Loops temporais"
status: source_material
authority_class: design_reference
implementation_state: design_reference_no_runtime
category: physical-surface
summary: "Source material for the Atlas Embodiment/StackChan physical surface design; not current runtime authority."
canonical_owner: docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
---
# 02 — Loops temporais

> **Propósito:** especificar os **5 loops de execução** que rodam simultaneamente no Embodiment, em escalas de tempo radicalmente diferentes. A sensação de "vida" emerge da sobreposição deles, não de nenhum isolado.
>
> **Pré-requisitos:** [README](../README.md), [01-corpo-vs-alma.md](01-corpo-vs-alma.md), [01-hardware/02-limitacoes-fisicas.md](../01-hardware/02-limitacoes-fisicas.md).
>
> **Fora do escopo:** implementação concreta dos loops (vai pra `06-firmware-stackchan/02-reflex-layer.md` e `07-integracao-atlas/04-curator-proposals.md`).

---

## 1. Por que 5 loops e não 1

Robôs assistentes "mortos" tipicamente têm **um loop só**: estímulo → resposta → idle. Quando estão idle, parecem mortos. Quando respondem, há uma latência que destrói a sensação de presença.

Vida real não é um loop só. Você simultaneamente:

- **Respira** (loop de ms — você não pensa, acontece).
- **Reage** ao som da porta (loop de centenas de ms — instintivo).
- **Pensa** sobre o que vai responder (loop de segundos — deliberado).
- **Reflete** sobre o dia em background (loop de minutos — passivo).
- **Tem rituais** (loop de horas/dias — biológico, agendado).

A presença de todos ao mesmo tempo é o que sinaliza "ser vivo". Tirar qualquer um quebra a ilusão.

O Embodiment imita isso com 5 loops em camadas, cada um operando num horizonte temporal diferente, com responsabilidades diferentes e moradores diferentes (corpo vs alma).

---

## 2. Os 5 loops — visão geral

| Loop | Latência típica | Onde roda | Responsabilidade |
|---|---|---|---|
| **L0 Reflex** | 10–100 ms | Corpo (firmware) | Animação contínua, blink, micro-tremor, head tracking sonoro, LED idle |
| **L1 Reaction** | 200 ms – 2 s | Híbrido | Wake word, ack imediato, resposta a touch, mostra de info já cacheada |
| **L2 Deliberation** | 2 – 30 s | Alma | Pipeline cognitivo completo — Atlas Decide, Domain, providers |
| **L3 Contemplation** | minutos | Alma (Curator) | Análise de padrões, drift, gaps, geração de proposals |
| **L4 Heartbeat** | minutos / horas | Alma (scheduler) | Rituais agendados, sync de estado, manutenção |

```
tempo →
ms        100ms       segundos     minutos      horas
│           │             │           │           │
├─L0────────┼─────────────┼───────────┼───────────┼──── (sempre rodando, no corpo)
│           ├─L1──────────┤           │           │
│           │             ├─L2────────┤           │
│           │             │           ├─L3────────┤
│           │             │           │           ├─L4─
```

**Princípio:** loops mais externos (L0, L1) **nunca esperam** loops mais internos (L2, L3, L4). O robô não congela enquanto pensa.

---

## 3. L0 — Reflex Loop

### Especificação

| Item | Detalhe |
|---|---|
| **Latência alvo** | 10–100 ms |
| **Onde roda** | Exclusivamente no corpo (firmware ESP32-S3) |
| **Frequência** | Contínuo (~30 Hz para animação, ~1 kHz para amostragem de sensor) |
| **Estado** | Stateless quanto a cognição; mantém estado de animação local |
| **Pode ser cognitivo?** | **Não.** Nunca. |

### O que faz

- Animação idle do avatar (blink simulado, pequenos movimentos de olho)
- Micro-tremor de servo (life-like jitter, opcional)
- Head tracking sonoro: vira lentamente para fonte de som detectada por dual mic
- LED idle (respiração suave em azul)
- Brilho automático do display via sensor LTR-553
- Detecção de wake word (offline, no DSP)
- Detecção de gestos primários (chacoalhar, tap na cabeça via IMU)
- Resposta visual imediata a touch (highlight do pad tocado)

### O que **não** faz

- Não decide nada baseado no que vê/ouve. Apenas reage com movimento/cor.
- Não fala (TTS é L1 em diante).
- Não sabe quem é o usuário.
- Não consulta histórico.

### Por que existe

Sem o Reflex Loop, o robô parece morto entre L1 e L2. É a camada que mantém a **sensação de presença contínua** mesmo quando ninguém está interagindo.

### Modo de operação degradada

L0 é o **único loop que continua funcionando 100% sem Atlas**. Em modo degradado, ele:
- Continua animação (mas com paleta de cores "triste" — roxo respirando)
- Detecta wake word mas não pode processar (responde com beep + face de "desconectado")
- Aceita touch para "modo silencioso" (panic mute físico, garantia local)

---

## 4. L1 — Reaction Loop

### Especificação

| Item | Detalhe |
|---|---|
| **Latência alvo** | 200 ms – 2 s |
| **Onde roda** | Híbrido (corpo dispara, alma responde rápido ou cache resolve) |
| **Frequência** | Por evento — disparado por estímulo |
| **Estado** | Estado da interação corrente; sem persistência de longo prazo |
| **Pode ser cognitivo?** | Cognição mínima — classificação de intent rápida, lookup em cache |

### O que faz

- Após wake word: ack sonoro imediato (~100 ms) + face "te ouvindo" + LED muda
- Captura de áudio streaming → envia para alma
- Toque no touch pad: ack visual (LED do pad acende) + envia evento para alma
- NFC tap: lê UID, manda pra alma, mostra "lendo…" no display
- Resposta a perguntas com cache válido: alma responde sem pipeline completo
- Streaming de TTS: começa a falar antes de a resposta cognitiva estar inteira gerada
- Pequenas micro-confirmações ("uhum", "tá", servo de nod)

### Como interage com L0

- L1 **interrompe** animação idle de L0. Quando wake word dispara, blink continua mas a face muda para "atento".
- L0 retorna assim que L1 termina.

### Como interage com L2

- L1 inicia a captura e cobre os primeiros 200ms-2s.
- L2 (deliberation cognitiva) começa em paralelo.
- Se L2 ainda não respondeu em 2s, L1 mantém a presença com micro-feedback ("pensando…", animação de processamento).
- Quando L2 retorna, o Output Renderer transiciona suavemente.

### Cache de resposta

L1 pode resolver sem L2 quando:
- Cache hit válido (alma marcou "essa pergunta tem resposta cacheada por X horas").
- Comando puramente de controle físico ("aumenta o volume", "modo silencioso") — alma confirma instantaneamente sem deliberar.
- Aprovação/rejeição via touch de proposta já apresentada.

**Cache vive na alma.** Robô não cacheia respostas. L1 dispara um lookup rápido na alma; se hit, alma retorna direto sem invocar Decide. Isso preserva o princípio "cognição vive na alma".

### Modo de operação degradada

L1 sem alma = L0. Sem caminho para a alma, não há resposta. Reflex sonoro de "desconectado" + face triste.

---

## 5. L2 — Deliberation Loop

### Especificação

| Item | Detalhe |
|---|---|
| **Latência alvo** | 2 – 30 s |
| **Onde roda** | Alma — Atlas Kernel Pipeline completo |
| **Frequência** | Por intent que requer cognição |
| **Estado** | Tudo no Evidence Ledger |
| **Pode ser cognitivo?** | É o loop cognitivo. Tudo cabe aqui. |

### O que faz

É o **pipeline normal do Atlas**, agora com surface `stackchan`:

```
Operation Envelope (vindo do corpo)
   ↓
Atlas Intent / Routing
   ↓
Atlas Decide (escolhe domain, fluxo, modelo, budget, gates)
   ↓
Decision Receipt
   ↓
Domain Plane (programming, finance, personal_dev, marketing, self_improvement)
   ↓
Context Builder (com sinais físicos: presença, ambiente, hora, bateria)
   ↓
Policy / Profile
   ↓
Runtime / Executor (providers, super tools, harness)
   ↓
Quality Gates → Repair Loop se falha
   ↓
Output Renderer (formato físico: face + voz + servo + LED)
   ↓
Decisão final empacotada em Output Commands
   ↓
Enviada para o corpo via WebSocket
```

### Como interage com L1

- Enquanto L2 roda, L1 mantém presença ("pensando…").
- L2 streamia parciais quando possível (TTS incremental).
- Quando L2 termina, transição suave de "pensando" para "respondendo".

### Cancelamento

Usuário pode cancelar mid-flight:
- Touch combinado, ou comando "Atlas, espera" (wake word + interrupt token).
- Sinal vai pra alma, alma cancela L2 em curso, registra evento "user_cancelled" no Ledger.
- L1 transiciona para idle. L0 retoma.

### Quality Gates falham → Repair Loop

- Se Quality Gate bloqueia: LED amarelo pulsante (sinalizado pelo Output Renderer).
- Repair Loop tenta novamente (até limite de tentativas definido na policy).
- Se exceder: LED vermelho fixo + face de "preciso de você".
- Tudo registrado no Ledger.

---

## 6. L3 — Contemplation Loop

### Especificação

| Item | Detalhe |
|---|---|
| **Latência alvo** | minutos (não tem latência sentida pelo usuário) |
| **Onde roda** | Alma — Curator |
| **Frequência** | Periódica (tipicamente a cada 5-30 min) + disparada por eventos |
| **Estado** | Lê Evidence Ledger; produz proposals |
| **Pode ser cognitivo?** | É o loop reflexivo do Atlas |

### O que faz

O Curator analisa o Evidence Ledger e procura:

- **Padrões repetidos** que poderiam virar Core ("você fez isso 5x — automatizar?").
- **Drift** — comportamento divergente do esperado ("respostas estão demorando 30% mais").
- **Gaps** — coisas que parecem incompletas ("começou a revisar finanças, não terminou").
- **Bugs** — eventos que sugerem erro silencioso.
- **Melhorias** — sugestões de ajuste de policy, profile, prompt.

Tudo vira **proposals** — não auto-aplica. Princípio do Atlas: "Self-Improvement não altera comportamento crítico sem proposal/review".

### Como o Embodiment usa

- Proposals do Curator chegam ao usuário **via L4 (heartbeat)** ou **on-demand** (touch num pad específico, ou pergunta direta "tem alguma sugestão?").
- Se urgente (drift de qualidade severo), L4 pode disparar interrupção (governada por `interruption_policy`).
- Ao receber via Embodiment: face do Curator (visual distinto), voz explica, touch pads viram aprovar/rejeitar/explicar mais.

### Por que isso é loop separado

L3 é **assíncrono e contínuo**. Não responde a estímulo direto do usuário. Roda em background. Misturar com L2 (que é síncrono) confundiria responsabilidade.

---

## 7. L4 — Heartbeat Loop

### Especificação

| Item | Detalhe |
|---|---|
| **Latência alvo** | minutos / horas |
| **Onde roda** | Alma — scheduler |
| **Frequência** | Crontab-style — agendado |
| **Estado** | Calendário de rituais + estado de execução |
| **Pode ser cognitivo?** | Pode disparar L2; em si, é orquestração |

### O que faz

Dispara em horários ou em condições agendadas:

| Ritual | Quando | Domain ativado |
|---|---|---|
| Bom dia | 9h da manhã (ajustável) | Personal Dev — resumo do dia, prioridades |
| Check de foco | 12h, 16h | Personal Dev — "como está indo?" |
| Fim de dia | 18h | Personal Dev — resumo, próximos dias |
| Revisão semanal | Sextas 17h | Self-Improvement — Curator apresenta proposals da semana |
| Drift check | A cada 4h | Self-Improvement — silencioso, só dispara interrupção se sério |
| Bateria baixa | Quando < 20% | Operacional — alerta visual + voz |
| Sync NTP | A cada 6h | Operacional — corrige RTC |
| Heartbeat de presença | A cada 30s | Operacional — mantém WebSocket vivo, detecta queda |

### Disparo de L4 → L2

L4 não fala diretamente com o corpo. Quando dispara:

1. Coloca um Operation Envelope no Atlas Input com `trigger: "scheduled"`.
2. O Envelope passa pelo pipeline normal — Decide pode escolher cancelar (ex.: usuário em modo foco profundo).
3. Se Decide aprova, segue para Output Renderer → corpo.

Isso garante que **mesmo rituais agendados respeitam policy**. O ritual não interrompe se a `interruption_policy` decide que não.

### Configuração

Rituais e horários são **configuração da alma**, registrada no Ledger. Mudar horário do "bom dia" é um evento auditável, não config no firmware.

---

## 8. Interação entre os loops — regras

### Regra 1 — Loops externos não bloqueiam internos

L0 nunca espera L2. Se L2 está rodando, L0 continua animando. O robô **nunca congela**.

### Regra 2 — Loops internos podem alterar saída de loops externos

L2 pode mandar uma instrução ao L0 ("muda paleta de idle para 'concentrado'"), mas L0 **continua sendo L0** — não vira cognição. A instrução é só dado.

### Regra 3 — Cancelamento propaga para fora

Se L2 é cancelado, L1 volta para idle. Se L1 também cancela, L0 retoma sozinho.

### Regra 4 — Apenas um L2 por vez

Não há L2 paralelo. Uma deliberação cognitiva por vez por surface. Se nova interação chega enquanto L2 ativo, dependências:
- Se nova interação é "cancelar/parar": cancela atual, processa nova.
- Se nova interação é continuação ("além disso, …"): empilha como contexto adicional do mesmo L2.
- Se nova interação é tópico novo durante L2: registra como pendente, decide quando o atual termina (ou interrompe se policy permitir).

### Regra 5 — L3 e L4 nunca interrompem o usuário sem policy

Curator não fala direto. Heartbeat não dispara TTS sem passar por `interruption_policy`. Princípio: **iniciativa é privilégio governado**.

---

## 9. Como um evento atravessa os loops — exemplo

Cenário: usuário fala "Atlas, qual o status do build do projeto X?"

```
T+0ms      L0 ouve som → vira face para fonte sonora (head tracking).
T+~50ms    Wake word detectado offline → L0 transiciona pra L1.
T+~100ms   L1 ack: beep curto + face "te ouvindo" + LED azul fixo.
T+~150ms   L1 inicia streaming de áudio para alma.
T+~300ms   Alma começa a receber, dispara STT streaming.
T+~600ms   STT primeira hipótese disponível → L2 pode começar a planejar.
T+~1.2s    Usuário termina de falar, áudio completo.
T+~1.5s    L2 (Decide) classifica intent → Programming Domain → "build_status".
T+~1.7s    L2 invoca runtime/executor → consulta CI.
T+~3s      Resposta cognitiva pronta. Output Renderer empacota.
T+~3.2s    Comandos físicos chegam ao corpo:
              - face: "informativa"
              - display: gráfico de status do build
              - voz: stream de TTS começando
              - LED: verde se passou, amarelo se em progresso
              - servo: vira para o display
T+~3.3s    L1 transiciona para "respondendo". L0 ajusta paleta para verde.
T+~5s      TTS termina. L1 termina. L0 retoma idle.
T+~6s      Evento completo registrado no Evidence Ledger.

Em paralelo:
T+~3min    L3 (Curator) processa evento, nota "user perguntou status do build 4x esta semana"
           → gera proposal: "criar atalho NFC para build_status?"
           → fila de proposals (apresentada via L4 na próxima janela aprovada).
```

### O que esse exemplo mostra

- **L0 cobriu a janela cognitiva** com head tracking (~50ms) e ack (~100ms).
- **L1 foi o "respiro"** — pegou o áudio, manteve o ato vivo enquanto L2 pensava.
- **L2 fez o trabalho cognitivo** sem o usuário sentir como espera vazia.
- **L3 trabalhou em background** — o usuário nem soube.
- **L4** (não nesse exemplo) seria quem traria a proposal de volta.

---

## 10. Anti-padrões a evitar

| Anti-padrão | Sintoma | Correção |
|---|---|---|
| **Loop único serializado** | Robô congela enquanto pensa | Garantir que L0 nunca depende de L2 |
| **Reflex que pensa** | L0 toma decisão baseada em conteúdo | L0 só reage a estímulo bruto, sem semântica |
| **L2 sem cobertura de L1** | Latência aparece como silêncio constrangedor | Sempre cobrir os primeiros 2s com L1 |
| **L3/L4 disparando direto na voz** | Robô "fala sozinho" inesperadamente | Tudo passa por interruption_policy |
| **Cache de resposta no corpo** | L1 responde sem L2, mas com lógica local | Cache fica na alma; L1 só busca |
| **L4 hardcoded no firmware** | Mudar horário de ritual exige reflashar | Configuração na alma, evento no Ledger |

---

## 11. Resumo

| Loop | Pergunta que responde | Sem ele acontece |
|---|---|---|
| **L0 Reflex** | "Está vivo?" | Robô parece morto entre comandos |
| **L1 Reaction** | "Te ouvi?" | Latência cognitiva vira silêncio constrangedor |
| **L2 Deliberation** | "O que faço com isso?" | Não é o Atlas, é uma alexa |
| **L3 Contemplation** | "Está tudo certo nos padrões?" | Sem auto-melhoria, sem evolução |
| **L4 Heartbeat** | "É hora de algo?" | Sem rituais, sem continuidade temporal |

Vida = sobreposição dos cinco. Ausência de qualquer um quebra a ilusão.

---

## Próximos passos de leitura

- `03-modalidades.md` — quais canais de I/O alimentam cada loop.
- `04-integracao-kernel.md` — onde no Atlas Kernel o Embodiment se integra.
- `05-modos-operacao.md` — como os loops se comportam em modo offline/privado/foco.
- `04-protocolos/01-interaction-envelope.md` — schema do que viaja entre os loops.
