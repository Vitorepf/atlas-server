# OT169 / Lipo Bliss — Config Fase 1 (lançamento frio, certeiro + barato)

> Objetivo da Fase 1: descobrir se a OFERTA converte tráfego frio ALINHADO, gastando o mínimo, sem
> risco de conta. NÃO é otimizar keyword ainda — é achar a 1ª venda. Público: US-inglês.

---

## PASSO 0 — GATE (não gaste R$1 antes disto)

1. **Tracking de conversão server-side/postback ligado e testado com 1 venda real.** Conta a venda pela
   tabela `conversions` (status=completed), NUNCA o boolean `is_converted` (vem stale).
   Sem isso, todo o teste mede o número errado = dinheiro no lixo.
2. **Bridge page limpa no ar** (advertorial leigo, domínio próprio separado), o CTA dela leva pro link do
   afiliado. A VSL agressiva fica DEPOIS do clique externo, fora do que o Google revisa. → Final URL = bridge.
3. **Pausar a campanha antiga "Sales-Search-1 wl" inteira.**

---

## PASSO 1 — CRIAR A CAMPANHA (o que clicar no painel)

| Campo | Valor |
|---|---|
| Tipo | **Pesquisa** (Search) — sem rede de Display |
| Objetivo | Vendas (sem meta de conversão automática ainda) |
| Redes | **Só Pesquisa do Google.** DESMARCAR "Rede de Pesquisa de parceiros" e "Rede de Display" |
| Locais | **United States** apenas. "Presença: pessoas que ESTÃO no local" (não "interesse") |
| Idioma | English |
| **Lances (CRÍTICO)** | **CPC manual** (não "Maximizar cliques", não Smart Bidding). Editar estratégia → "CPC manual". Deixe eCPC desligado por ora. |
| **Teto de CPC** | **R$5** no ad group (não R$20) |
| Orçamento | **R$50/dia** |
| Rotação de anúncios | "Não otimizar / girar indefinidamente" (você quer dado limpo, não o Google escolhendo cedo) |

> Por que CPC manual e não broad/Smart: você tem 0 conversões. A IA da broad e do Smart Bidding come
> conversão; sem ela, ela espalha (foi o que comprou o clique de R$19,70). Broad/tCPA voltam na Fase 2
> com 30+ conversões. Aqui você controla o gasto e COLHE a conversão limpa.

---

## PASSO 2 — AD GROUPS + KEYWORDS (copia e cola)

### AG1 — GLP1-Drops-NoInjection  ← 100% do orçamento (ATIVO)
Cruzamento de volume frio real + intenção de compra + casa com gotas + verde de política.
Cole estas como **Frase** ("..."):
```
"weight loss drops"
"glp 1 drops"
"glp 1 supplement"
"natural glp 1"
"oral glp 1"
"appetite suppressant drops"
"weight loss without ozempic"
"weight loss without injections"
"non injection weight loss"
"weight loss drops that work"
```
E estas como **Exata** ([...]):
```
[weight loss drops]
[glp 1 supplement]
[natural glp 1]
```

### ❌ NÃO bidar a MARCA / nome do produto (`lipo bliss` etc.)
Bidar o nome do produto **viola o contrato do produtor → expulsão do produto + perda da filiação.** Brand
bidding é fundo-de-funil; aqui é direct-response tráfego frio. O ângulo é SEMPRE sintoma+formato+mecanismo,
nunca a marca. (Ver memória `affiliate-no-brand-keyword-bidding`.)

### NÃO subir agora (Fase 2):
- Retatrutide (qualquer forma) → só na Fase 2, budget capado, e NUNCA `[retatrutide]` puro (gatilho de suspensão).
- Ozempic/semaglutide/tirzepatide "alternative" → Fase 2 (volume alto, mas espera a oferta provar que respira).
- Coined ("triple hormone drops", "at home retatrutide protocol") → volume-zero provado; re-adiciona quando a VSL gerar re-finders.

---

## PASSO 3 — ANÚNCIOS (RSA) — copy segura-mas-agressiva

Sem nome de fármaco, sem celebridade, sem número de perda de peso, sem "garantido". "GLP-1" como termo leigo OK.

**Títulos (15, ≤30 caractéres):**
```
GLP-1 Drops
Weight Loss Drops
Skip The Injection
No Needles, No Rx
At-Home Metabolic Reset
Triple-Hormone Drops
Natural GLP-1 Support
Appetite Control Drops
Lose Weight At Home
Drops, Not Shots
The At-Home Protocol
Support Your Metabolism
Curb Cravings Naturally
60-Day Money-Back
Made In The USA
```

**Descrições (4, ≤90 caractéres):**
```
At-home drops that support GLP-1 and appetite control. No injections, no prescription.
Skip the needle. A simple daily drop protocol to support your metabolism. 60-day guarantee.
Thousands are switching from injections to at-home drops. Free shipping on multi-bottle kits.
Support three metabolic hormones with a simple at-home protocol. Risk-free for 60 days.
```
- Caminho de exibição: `/GLP-1-Drops` `/At-Home`
- Final URL = **bridge limpa** (não a VSL).

---

## PASSO 4 — NEGATIVAS (nível de campanha)

Regra de ouro: **frase, nunca palavra solta que decepe intenção válida.** (`injection`/`ozempic` soltos
matariam "without injection"/"without ozempic" — seus melhores ângulos.)

Bloquear quem quer COMPRAR injetável/receita/pharmacy (tráfego caro, desalinhado):
```
"prescription"
"pharmacy"
"compounding pharmacy"
"telehealth"
"peptide"
"vial"
"where to inject"
"injection cost"
"injectable near me"
"savings card"
"insurance"
"fda approved"
```
Bloquear comprador de brand de injeção (deixa passar "without/alternative"):
```
"ozempic coupon"
"ozempic cost"
"mounjaro savings card"
"zepbound"
"saxenda"
"trulicity"
"rybelsus"
"compounded semaglutide"
```
Pesquisa/info (não-comprador) + lixo:
```
"reddit"
"clinical trial"
"is it safe"
"how to inject"
"research chemical"
"free"
"scam"
"lawsuit"
"recall"
"side effects"
"goodrx"
```
> NÃO ponha como negativa solta: `injection`, `ozempic`, `semaglutide`, `glp` — eles aparecem nos seus
> bons termos.

---

## PASSO 5 — REGRAS DE DECISÃO (keep / kill / escala)

Você está medindo a **OFERTA**, não a keyword. A pergunta da Fase 1: "essa VSL converte tráfego frio alinhado?"

- **Regra do primeiro-winner:** rode o AG1, despeje budget só onde aparecer a 1ª conversão. Não tente "provar"
  que cada keyword perde — ache a UMA que vende. Achar winner ≈ R$650; matar perdedora a 95% ≈ R$1.947.
  Não pague custo-de-kill em tudo.
- **Sinal de vida** = 1ª venda em ~50-77 cliques alinhados → a oferta respira. Mas **1 venda não confirma CVR**:
  espere ~2-3 vendas (~150-200 cliques) antes de declarar winner e escalar.
- **KILL da oferta** = ~200 cliques ALINHADOS no AG1, 0 venda → o problema é OFERTA/VSL/preço/bridge, não keyword.
  Volta pra VSL/bridge (mercado jaded estágio-5 exige mecanismo+lead, não claim genérico). NÃO escala.
- **Observe (não mexa cedo):** "parcela de impressão perdida (lance)". Se >60% perdida por lance → +R$1 no teto
  por vez (nunca de uma vez).

### Custo de aprender (verdade dura, payout R$650):
O custo-piso de um KILL conclusivo é fixado pelo PAYOUT, não pelo CPC: ~**R$1.046 a 80%** de confiança,
~R$1.947 a 95%. Baixar CPC não te faz aprender mais barato — só mais devagar. O lever real de gastar menos:
(a) decidir a 80% não 95%, (b) regra do primeiro-winner, (c) subir o CVR na VSL/bridge.

---

## FASE 2 — só depois da oferta dar sinal de vida (≥1 venda em ~150 cliques alinhados)
1. Sobe AG "ozempic/semaglutide alternative" (volume incremental alto), mesma bridge limpa.
2. Sobe AG retatrutide-lateral, budget capado ~15%, só cauda alinhada (`[oral retatrutide]`, `"retatrutide drops"`) — nunca `[retatrutide]` puro.
3. Com ≥30 conversões: migra pra **broad / AI Max + tCPA (ou tROAS)**. AQUI a IA do Google vira motor de escala.
4. Escala budget no que converteu (regra scaling-2026: bate ROAS-alvo ≥14 dias + perde IS por budget).
