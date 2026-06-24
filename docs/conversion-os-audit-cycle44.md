# Ciclo 44 — watch-through: proxy revertido + sliver honesto + META-LIÇÃO

## O que aconteço (honesto)
Tentei originar o **NarrativeTensionScorer** — um score 0-100 de watch-through (slippery slide) lendo a
copy como SEQUÊNCIA (reveal segurado tarde, loops abrindo cedo, pull seção-a-seção). Os testes passavam
e a calibração inicial separava bem. **O painel brutal cross-nicho (6 agentes) matou: FIX_THEN_COMMIT,
4/5 lentes "overfit_or_theater".** Prova rodada no motor vivo:
- filler vazio com markers nos slots certos → **92-100 "gripping"**;
- copy de elite real SEM as frases-clichê → **12 "flat"**;
- a MESMA copy de elite + 6 frases-template enxertadas → **12 → 92**;
- o guard de beat-density caía com 1 filler por beat (bd 0.55 → 92).

Diagnóstico: era o **proxy de vocabulário do Ciclo 43 com uma coordenada de posição**. Media presença-de-
template-em-posição, não watch-through — e INVERTIA qualidade (padding > elite). Pela regra do loop
("senão REVERTO") e anti-Goodhart, **revertido**.

## O que ficou (o sliver verdadeiro)
`WatchThroughLeakDetector` — o único sinal que é VERDADEIRO independente de vocabulário: um reveal/CTA-de-
compra **literal no topo é um vazamento estrutural real** (não dá pra reter quem já recebeu o payoff;
botão de compra antes do desejo é saída dead-end). Emite **só AVISOS true-positive**, **sem score de
qualidade** — então não há o que gamear nem inverter: copy vazia não recebe flaw NEM elogio.

| copy | assessed | flaws |
|---|---|---|
| vazada (reveal+CTA no topo) | sim | premature_reveal, premature_hard_cta |
| bem-construída (reveal/CTA tardios) | sim | (nenhum) |
| filler vazio | sim | (nenhum — não acusa, não elogia) |

Conservador por design: sub-dispara (um reveal parafraseado que não vê = aviso perdido) em vez de
acusar falso. Surfado no `ConversionAuditor` como `structural_flaws` + CLI.

## META-LIÇÃO (2 painéis seguidos provaram — vale pro OS inteiro)
**Scorer determinístico de str_contains sobre léxico fixo mede VOCABULÁRIO, não QUALIDADE/conversão.**
Cycle 43 (audience_score) e Cycle 44 (tension score) caíram no mesmo trap; ambos invertiam em copy
adversarial. Consequência para a arquitetura do Conversion OS:
- A camada determinística deve fazer **VERDADE ESTRUTURAL** (congruência message-match, vazamentos,
  presença/completude de elementos do funil) — onde o fato estrutural É a verdade, não proxy de qualidade.
- **Julgamento de qualidade/força-de-persuasão** exige o **verifier LLM** (fora do path conversion-critical
  por regra do operador) ou o **ledger de outcomes calibrado** (DORMANT até venda real). Sem um desses,
  nenhum número determinístico "prova conversão" — é prior estrutural, e tem que ser rotulado assim.
- Próximos ciclos: parar de cristalizar "qualidade" em léxico-scorer; mirar verdade-estrutural OU acender
  o verifier/ledger.
