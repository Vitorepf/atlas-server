# Ciclo 45 — FunnelContinuityAuditor: VERDADE ESTRUTURAL (Eixo 6), pós meta-lição

## A virada
Os ciclos 43-44 provaram (2 painéis) que SCORE de qualidade determinístico = proxy de vocabulário. A
meta-lição: a camada determinística deve fazer **VERDADE ESTRUTURAL** — fatos preservados ou quebrados,
não qualidade subjetiva. O Ciclo 45 aplica isso ao Eixo 6 (congruência de funil): **quebra de promessa /
bait-and-switch** — o número-herói que some downstream, o "grátis" do core contradito por um preço do core.
É FATO (o número/preço sobrevive ou não), não proxy; ungameable-for-good (não dá pra enfiar palavra "boa").

## O painel CONFIRMOU a tese (≠ dos 2 scorers mortos)
Painel focado (4 agentes) rodou o motor: veredito **FIX_THEN_COMMIT**. A tese sobreviveu inteira — "é
fato, não proxy" — mas a EXTRAÇÃO v1 chorava-lobo nos funis NATIVOS do operador. Regra dispositiva:
**sub-disparar é honesto; acusar falso destrói o sinal.** Falsos-positivos pegos e CORRIGIDOS:

| funil legítimo (era falso-positivo) | agora |
|---|---|
| free-trial → pago (SaaS/assinatura) | silencioso ✓ |
| free shipping + preço do produto | silencioso ✓ |
| value-anchor ("normally worth $500, free") | silencioso ✓ |
| order-bump opcional declarado ($7 toolkit) | silencioso ✓ |
| idioma "feel free" | silencioso ✓ |
| número soletrado ("30 lbs" → "thirty pounds") | carry reconhecido ✓ |
| janela de garantia/trial ("60-day money-back") | não é promessa-herói ✓ |

Bugs reais consertados: ramo `%` era **código morto** (`\b` após `%`) → agora extrai promessas
percentuais (classe dominante em finanças/saúde); carry por substring ("30" em "2030"/"300") → trocado por
match de **fronteira de token** → não lava mais quebra real.

## Como ficou (conservador por construção)
- **dropped_promise**: só RESULT claims (peso/tempo/%/multiplicador/renda); preço NÃO é promessa-herói;
  carry = token-boundary + número soletrado. Dispara só quando o número-herói some de verdade.
- **price_scent_break**: só o caso inequívoco — free do CORE (exclui shipping/trial/anchor/idiom)
  contradito por preço do CORE (exclui optional/bonus/upsell/anchor) downstream.
- Surfado como `structural_flaws` no ConversionAuditor + CLI `atlas:ai:marketing:continuity`.

## Limites honestos (sub-dispara, NUNCA acusa falso)
Cego a: troca de mecanismo (pill→workout, número igual), promessa emocional ("never feel ashamed"),
claim só-soletrado fora do mapa. Fechar isso deterministicamente = risco Goodhart; exige verifier LLM
(fora do path por regra) ou ledger calibrado (dormant). Registrado como limite conhecido, não fingido.
320/320 verdes.
