# L4 — Iniciativa

> **Propósito:** especificar a quarta camada de vida — **iniciativa**: o robô **inicia interação** quando relevante, sem ter sido endereçado primeiro. Esta é a camada **mais perigosa** do Embodiment. Mal calibrada, vira a Alexa irritante; bem calibrada, vira presença ativa e útil.
>
> **Pré-requisitos:** [README](../README.md), [01-presenca.md](01-presenca.md), [02-reatividade.md](02-reatividade.md), [03-continuidade.md](03-continuidade.md), [05-policies/01-privacidade.md](../05-policies/01-privacidade.md).
>
> **Fora do escopo:** caráter (`05-carater.md`), policy detalhada de interrupção (vai pra `05-policies/02-interrupcao.md`, a escrever).

---

## 1. Definição

> **Iniciativa** é a capacidade de **iniciar interação a partir de informação interna** (Curator, heartbeat, evento crítico) — sem trigger explícito do usuário.

A diferença operacional fundamental:

| Comportamento | Camada |
|---|---|
| Você pergunta → ele responde | L1/L2/L3 |
| Algo no ambiente acontece → ele reage visualmente | L2 |
| Curator detecta padrão → ele **traz pra você** | **L4** |
| Build quebrou → ele te avisa | **L4** |
| 18h: ele resume o dia sem pedir | L4 (via heartbeat ritual) |

L4 transforma o robô de **reativo** em **ativo**. É salto qualitativo — e onde mais projetos arruínam tudo.

---

## 2. Por que L4 é o capítulo crítico

### A faixa útil é **estreitíssima**

| Iniciativa | Resultado |
|---|---|
| **Zero** | L1+L2+L3 — útil mas passivo. Você precisa lembrar de perguntar tudo. |
| **Pouca** | Útil. Avisa só do que importa de verdade. Confiável. |
| **Média** | Começa a chatear. Você ignora algumas. |
| **Alta** | Vira distração. Você ignora a maioria. |
| **Máxima** | Você desativa. Confiança quebrada permanentemente. |

E a confiança, uma vez quebrada, **não volta fácil**. Por isso iniciativa é privilégio governado.

### Princípio fundador

> **O direito de interromper não é dado — é concedido evento por evento por uma policy revisável.**

Sem isso, robô vira Alexa.

---

## 3. Quem propõe iniciativa

3 fontes de iniciativa no Embodiment:

### 3.1 Curator (Self-Improvement)
Análise periódica detecta padrões, gaps, drifts. Gera **proposals**.
- "Você não revisa finanças há 14 dias."
- "Padrão de wake word com false positive aumentou."
- "Modo DND constante na sexta — sugiro tornar default."

Curator **nunca interrompe direto**. Ele **propõe**, e a `interruption_policy` decide se entrega.

### 3.2 Heartbeat scheduler (L4 do pipeline)
Rituais agendados:
- Bom dia 9h.
- Fim de dia 18h.
- Revisão semanal sexta 17h.

Mesmo agendados, passam por `interruption_policy`. Modo DND suprime; presença ausente adia.

### 3.3 Eventos críticos
Sinais que justificam interrupção mesmo em modo restritivo:
- Build de produção falhou.
- Backup falhou.
- Dispositivo crítico offline.
- Sinal de saúde do Atlas (drift severo).

Esses bypassam alguns gates, mas **ainda passam por policy** — só que com prioridade alta.

---

## 4. interruption_policy — coração da L4

A `interruption_policy` é a peça **mais crítica** do Embodiment. Ela decide:

```
Há sinal pra interromper.
   ↓
Quem está propondo? (Curator, Heartbeat, Critical event)
   ↓
Qual prioridade do sinal?
   ↓
Modo atual? (ambient, interaction, dnd, private)
   ↓
Privacy mode?
   ↓
Histórico recente de interrupções?
   ↓
Padrão histórico de aceitação dessa categoria?
   ↓
DECIDE: interromper agora? adiar? agendar? descartar?
```

### Estrutura conceitual da policy

```yaml
interruption_policy:
  priority_levels:
    - critical    # build prod down, security alert
    - high        # ritual important, curator urgent
    - normal      # ritual rotineiro, curator general
    - low         # nice-to-have, ambient hint
    - background  # nunca interrompe; só vira info-card

  modes:
    ambient:
      allow: [critical, high, normal, low]
      visibility: full
    interaction:
      allow: [critical]              # não interromper conversa em curso
      defer: [high, normal, low]
      visibility: full
    dnd:
      allow: [critical]
      defer: [high, normal, low]
      visibility: dim
    private:
      allow: [critical]              # só com indicação visual, sem voz
      defer: [high, normal, low]
      visibility: minimal
    private_physical:
      allow: []                      # nada interrompe
      defer: all
      visibility: none
    degraded:
      allow: []                      # sem alma, nada vem mesmo
    standby:
      allow: [critical]
      defer: [high, normal, low]

  user_pattern_adjustments:
    if_rejected_3x_in_row: lower_priority_one_step
    if_ignored_5x: pause_category_24h
    if_accepted_consistently: maintain_or_increase

  rate_limits:
    max_interruptions_per_hour: 3
    min_gap_between_interruptions_min: 5
```

⚠️ **DECISÃO PENDENTE:** estrutura concreta da policy. Esta é provisional. Detalhamento vai em `05-policies/02-interrupcao.md`.

---

## 5. Auto-tuning

L4 sem auto-tuning vira drift permanente. Mecanismo necessário:

| Sinal de drift | Reação automática |
|---|---|
| Usuário rejeita 3x em sequência uma categoria | Reduz prioridade dessa categoria por 24h |
| Usuário ignora (timeout, sem resposta) 5x | Pausa categoria 24-48h |
| Usuário aceita consistentemente | Mantém ou amplia |
| Usuário usa "modo silencioso" mais de 50% do dia | Reduz frequência geral; alerta ao Curator |
| Mesma proposal proposta 3x sem aceite | Marca como obsoleta |

Auto-tuning é **conservador** (reduz mais facilmente que aumenta). Direção segura: silêncio.

---

## 6. Apresentação física da iniciativa

Como uma iniciativa chega ao usuário fisicamente.

### 6.1 Modos de apresentação

| Modo | Quando usar |
|---|---|
| **Voz + face + card** | Crítico, urgente, requer ação imediata |
| **Voz curta + card** | Ritual normal |
| **Card silencioso + LED** | Informativo, baixa urgência |
| **LED apenas** (atenção sutil) | Background — você nota se olhar |
| **Pendente em fila** | Espera momento melhor (sai de DND, etc.) |

### 6.2 Renderização típica de uma proposal do Curator

```
T=0:   LED laranja pulsa lento (sinaliza algo pendente).
T+5s:  Se usuário não notou (sem head turn na direção):
       Continuar pulsando, sem som.
T+30s: Adicionar micro-som ambiente (ack).
T+60s: Se ainda não engajou:
       Curator pode escolher: render full (voz + face) ou render minimal (card silencioso).
       Decisão depende de prioridade.
```

Sem **flush imediato de voz** mesmo para ritual normal. Iniciativa respeita o usuário não estar pronto.

### 6.3 Touch para responder

Usuario responde via touch:
- Pad 1 (✓): aceita
- Pad 2 (✗): rejeita / dismiss
- Pad 3 (💬): "me explica mais" — abre interação

Acks são imediatos; resultado registrado no Ledger.

---

## 7. Critérios de "L4 atingida"

- [ ] Rituais (bom dia, fim de dia) acontecem nos horários certos, **respeitando modo**.
- [ ] Curator proposals chegam com cadência razoável (<3/dia em uso normal).
- [ ] Modo DND **bloqueia tudo** exceto crítico.
- [ ] Modo private **bloqueia voz**, mantém indicação visual mínima.
- [ ] Auto-tuning visivelmente reduz frequência de categorias rejeitadas.
- [ ] Eventos críticos chegam mesmo em modos restritivos (mas não em private_physical).
- [ ] Cada interrupção tem touch-response dentro de janela razoável (10-30s).
- [ ] Latência apresentação → ack <2s (touch deve ser responsivo).
- [ ] Em uso real de 30+ dias: aceitação geral > 60% (sinal de calibração saudável).
- [ ] Sensação acumulada: "iniciativa é útil, não cansa".

---

## 8. Princípios de design para L4

### 8.1 Silêncio é vitória

Ainda mais crítico aqui. Robô que interrompe muito mata confiança. Default sempre conservador.

### 8.2 Apresentação gradiente

Iniciativa começa **silenciosa** (LED, card) e escala **se a urgência justifica**. Não começa com TTS chamativo.

### 8.3 Touch é canal de baixa fricção

Aprovação/rejeição via touch é mais rápido e menos intrusivo que via voz. Curator sabe disso e prefere apresentação que acaba em touch.

### 8.4 Modo é absoluto

`private_physical` nunca interrompido. `dnd` só por crítico real. Sem exceções "só dessa vez".

### 8.5 Ack do Atlas ao usuário

Se usuário ignorou ou rejeitou, Atlas **respeita**:
- Não repete a mesma proposal logo.
- Não fica "puxando assunto" pelos cantos.
- Auto-tuning calibra para próxima.

### 8.6 Curator não é dono da iniciativa

Curator **propõe**. `interruption_policy` decide. Se Curator começa a "burlar" (urgência inflada), policy detecta drift e auto-tuning reduz peso do Curator.

---

## 9. Anti-padrões específicos da L4

| Anti-padrão | Por quê |
|---|---|
| TTS chamativo como primeiro contato | Intrusivo desnecessário |
| Repetir proposal ignorada na mesma sessão | Fica chato rápido |
| Curator alegando urgência alta para tudo | Policy detecta; mas estraga Calibração |
| Iniciativa em DND/private | Quebra contrato |
| Sem auto-tuning | Drift permanente |
| Iniciativa sem touch-response possível | Usuário não consegue dispensar |
| "Easter eggs" de iniciativa (frases fofas, jokes) | Vira mascote irritante |
| Eventos críticos sem caminho para adiar (sempre interrompem) | Quebra autonomia |
| Critério de "crítico" inflado | Tudo vira crítico → nada é crítico |
| Iniciativa que não respeita rate limit | Inundação |
| Notificação visual permanente até resolver | Stress crônico |

---

## 10. Riscos específicos da L4

### 10.1 Drift para Alexa

Risco mais alto. Sintoma: você usa "modo silencioso" cada vez mais.

**Mitigação:** auto-tuning + revisão regular de policy + critério "modo silencioso > 30% do dia = sinal de drift".

### 10.2 Confiança quebrada

Risco: uma sequência ruim de interrupções faz você nunca mais confiar — você ativa modo silencioso permanente, L4 morre na prática.

**Mitigação:** detectar isso (modo silencioso prolongado) e Curator propõe revisão completa da policy.

### 10.3 Crítico inflado

Risco: tudo vira "crítico" → categoria perde sentido.

**Mitigação:** auditoria regular de eventos críticos. Evento "verdadeiramente crítico" tem critérios objetivos definidos.

### 10.4 Iniciativa perfeitamente calibrada... pra um dia ruim

Risco: usuário em dia de raiva rejeita tudo. Auto-tuning reduz tudo. Próxima semana, mesmo coisas úteis, sumiram.

**Mitigação:** auto-tuning tem **memória curta** (24-48h). Não permite "morte súbita" de categorias úteis.

### 10.5 Modo silencioso virando default

Risco: usuário liga DND e nunca mais desliga.

**Mitigação:** detectar; Curator pergunta uma vez "DND há 5 dias — manter como default?". Se sim, ajusta policy. Sem, mantém ativação manual.

### 10.6 Iniciativa baseada em padrões equivocados

Risco: Curator concluiu padrão errado, propõe besteira.

**Mitigação:** proposals com **fonte rastreável** (eventos do Ledger que motivaram). Usuário rejeita pode ver "por que isso?".

---

## 11. Conexão com modos

Recapitulação rápida (`02-arquitetura/05-modos-operacao.md`):

| Modo | L4 ativa? |
|---|---|
| `ambient` | Plena |
| `interaction` | Suprimida — não interrompe conversa em curso |
| `dnd` | Só crítico |
| `private` | Só crítico, sem voz |
| `private_physical` | Suprimida totalmente |
| `degraded` | N/A (alma offline) |
| `standby` | Só crítico (e wake-up para ritual importante) |

---

## 12. Conexão com privacidade

Iniciativa interage com privacy:

- Iniciativa não dispara captura. Apresentação é só output.
- Mas resposta do usuário (voz para responder) pode disparar captura — só com wake word ou consent explícito.
- Em `private`/`private_physical`, iniciativa não pede resposta vocal — só touch.

---

## 13. Manifestações canônicas

### 13.1 Bom dia ritual aceito
- 9h: heartbeat dispara, modo `ambient`, presença detectada.
- Policy: ritual normal + ambient = passar.
- Apresentação: face calma, voz curta, info-card.
- Usuário ouve, vê.
- Sem touch necessário (info passiva).
- Evento `ritual.executed.morning_briefing` no Ledger.

### 13.2 Curator proposal de "automatizar X"
- Análise: você fez X 5 vezes esta semana.
- Proposal: criar atalho NFC ou domain rule.
- Apresentação: card silencioso + LED laranja.
- Usuário olha, lê, toca pad 1.
- Aprovação registrada; criação efetivada.

### 13.3 Build de produção falhou (crítico)
- Evento crítico do Programming domain.
- Policy: crítico passa em `ambient`/`interaction`/`dnd` (não `private_physical`).
- Apresentação: face alerta, voz curta direta, card com link.
- Touch dispense possível mas espera-se ação.
- Auto-tuning: build falhou e usuário ignorou? Curator analisa "por que ignorou — falso alarme?".

### 13.4 DND ativo, ritual suprimido
- 12h: heartbeat ritual de check.
- Modo DND.
- Policy: defer.
- Adicionado a fila pendente.
- Ao sair de DND: pendentes são revistos. Se tempo passou demais (>2h), descartado silenciosamente.

### 13.5 Auto-tuning reduzindo categoria
- Curator propôs "ler newsletter X" 3 vezes na semana, todas rejeitadas.
- Policy auto-tuning: reduz prioridade dessa categoria.
- Curator nota redução; eventualmente arquiva proposta como "não desejada".
- Evento auditável: `policy.auto_adjusted.category_lowered`.

---

## 14. Decisões pendentes

| Decisão | Bloqueia |
|---|---|
| ⚠️ Critérios objetivos de "evento crítico" | Implementação |
| ⚠️ Janela de auto-tuning (24h, 48h, 7d) | Implementação |
| ⚠️ Rate limits exatos por modo | Implementação |
| ⚠️ Como apresentação gradiente é parametrizada | UX |
| ⚠️ "Snooze" — adiar X minutos via touch — viável? | UX |
| ⚠️ Curator pode pedir feedback sobre rejeição | UX |
| ⚠️ Threshold de "modo silencioso virou default" | Auto-detect |

---

## 15. Resumo

| Item | Detalhe |
|---|---|
| **Camada** | L4 — Iniciativa |
| **Definição** | Inicia interação a partir de informação interna |
| **Sintoma resumo** | "Iniciativa útil sem cansar" |
| **Construído sobre** | Curator + Heartbeat + Continuidade L3 + interruption_policy |
| **Pré-requisito de** | L5 (caráter — sem iniciativa, robô é só passivo) |
| **Cobertura na fase** | Fase 4 do roadmap |
| **Risco principal** | Drift para Alexa; confiança quebrada permanente |
| **Critério de sucesso** | Em 30+ dias: aceitação > 60%, modo silencioso < 30% do tempo |

---

## Próximos passos de leitura

- `05-carater.md` — L5.
- `05-policies/02-interrupcao.md` — interruption_policy detalhada (a escrever).
- `07-integracao-atlas/04-curator-proposals.md` — fluxo completo (a escrever).
- `08-roadmap/05-fase-4-iniciativa.md` — fase de implementação (a escrever).
