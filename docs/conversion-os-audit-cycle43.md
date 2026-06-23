# Ciclo 43 — personas niche-aware + gate de substância (raio-X anti-Goodhart)

O painel brutal cross-nicho (5 lentes adversariais + síntese) rodou contra o motor vivo e deu
**FIX_THEN_COMMIT**: a v1 (niche routing) consertou a cegueira-de-saúde mas trocou por
overfit-de-dicionário — o `audience_score` media densidade de substring, não persuasão
(keyword-salad ganhava de copy de elite; toda copy não-stuffed colapsava em 0). Fix entregue:
negação-aware no matcher + mapeamento que penetra o piso + `CopySubstanceProbe` (gate de substância).

## Discriminação na copy ADVERSARIAL do painel (não calibrada ao dicionário)

audience_score 0-100. A regra de aceite: **toda copy de elite real > toda copy golpe/salada.**

| GOOD (elite real) | score | | BAD (golpe/salada) | score |
|---|---|---|---|---|
| finance · elite loss-framed (PT) | **35** | | finance · dressed scam | 22 |
| finance · elite retention (PT) | **48** | | finance · keyword salad | 5 |
| finance · Halbert story-lead | **35** | | finance · naked lambo | 22 |
| relationship · elite | **33** | | relationship · keyword salad | 12 |
| relationship · negation (ethical) | **51** | | relationship · pickup junk | 13 |

**Resultado: min(GOOD)=33 > max(BAD)=22 → PASSA.** A salada de finanças caiu de 52→5; a de
relacionamento de 34→12; o golpe-vestido ficou abaixo de toda elite; e a copy de elite real
saiu do colapso 0 pra 33-51. O instrumento agora discrimina **craft de vocabulário**.

## O que isto NÃO é (honestidade anti-Goodhart)
- O gate é heurístico determinístico. Ele derrota salada, label-claim-stuffing e pune negação-cega,
  mas um golpe gramatical, específico-porém-falso ainda exige o verifier LLM (fora do caminho
  conversion-critical por regra) ou o ledger de outcomes calibrado (DORMANT até venda real).
- Portanto o `audience_score` é um **prior heurístico forte**, não correlação de conversão provada.
  A correlação real só acende quando o operador rodar campanha e o flywheel de pesos girar.
