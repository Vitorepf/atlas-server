# Atlas v2 — Revisão de Suficiência (adversarial, grounded no código)

> Método: workflow multi-agente, 92 agentes, ~1.66k leituras de arquivo, ~1.77M tokens. 4 leitores grounded (claimed-vs-as-built) → 8 lentes adversariais tentando QUEBRAR "v2 é suficiente" → cada falha verificada contra o código (anti-alarme-falso) → síntese. 2026-06-17.
> **77 gaps confirmados** (50 real_gap, 27 partial · 53 high, 24 medium, **0 critical**). Veja `docs/atlas-autonomous-company-architecture-v2.md` (o desenho revisado) e `atlas-company-success-engine-buildout.md` (os slices).

---

## Veredito: INSUFFICIENT (honesto)

**Como desenhada E construída hoje, a v2 NÃO consegue operar autonomamente uma empresa de NENHUMA complexidade.** Ela **mede e sugere** — todo o caminho de execução (Fase 2) está **0% construído**: o despachante de decisão, o oráculo de qualidade por ação, o trust-ladder de venture, a contenção por venture e o loop de aprendizado fechado **não existem no disco**. E — o ponto que mais importa — **não é só falta de implementação: o próprio DESENHO omite peças load-bearing.**

### Os buracos que são de DESENHO, não só de implementação
1. **A métrica de sucesso é auto-reportável.** `VentureSuccessEvaluator` lê `metric_key='mrr'` cujo `source` default é `'operator'` — o loop **escreve e é avaliado pelo mesmo número**. Enquanto o MRR não for amarrado a **caixa liquidado e verificado externamente** (reconciliação payment-processor/banco, líquido de reembolso), toda a postura anti-Goodhart é **decorativa**. (É o fix nº1.)
2. **Sucesso é unidimensional (MRR bruto).** Ignora solvência/margem de contribuição, churn/NRR, concentração de cliente, legalidade, autenticidade de receita, obrigações a entregar. **Dá pra bater 70% com empresas insolventes, ilegais, fraudulentas ou em churn.**
3. **Layer ① (loop de aprendizado) está subespecificado, não só não-construído.** Sem credit-assignment sob delay de 3 meses, sem exploração/exploração, sem anti-oscilação, sem recompensa multi-sinal. O doc sequencia ① "por último, como wiring" — mas é **invenção, não wiring**.
4. **Layer ④ (qualidade por ação) não tem oráculo fora de código.** ADEP iterate-to-green só vale onde há ground-truth (testes). Para copy de marketing/vendas/jurídico/preço/resposta a cliente **não há oráculo** — sem ele o gate carimba.
5. **Cobertura de funções de empresa ausente como órgão:** sem contabilidade/ledger financeiro real (caixa/COGS/runway/solvência), sem incorporação/banking/tax/contratos/seguro, sem customer-success/retenção, sem roadmap de produto. Existem só como **perguntas advisory** do Assessment, que não gateiam nada.
6. **Layer ③ (orquestração) não existe:** `DomainHandoffService` é um escritor de status ponto-a-ponto, não um motor de processo. B2B não flui.
7. **O §4 do doc v2 ("majoritariamente wiring, não invenção") está parcialmente ERRADO** — Layers ④/⑤ estão mais perto de invenção; Layer ② (despachante) não existe. (Corrigido no doc.)

---

## Até que nível de empresa dá pra chegar (a escada honesta)

| Tier | Empresa | Autonomia | Estado |
|---|---|---|---|
| **0 — Medir & Aconselhar** | qualquer venture (ex.: Blackink gerida) | **~30%** | **ÚNICO que roda fim-a-fim HOJE.** Ingere MRR, assessment 12-dim, foco, escada S0-S5, scorecard triplo, missões DRAFT suggest-only. Nada toca o mundo; tudo operator-gated. |
| **1 — Operar 1 SaaS reversível sob aprovação por ação** | micro-SaaS B2C self-serve | **~40-50%** *se* O1 for construído | 1ª atuação, mas toda ação externa fica human-approved. Trava: sem trust-ladder de venture (⑤), sem oráculo de qualidade não-código (④), sem contenção por venture (⑥). |
| **2 — 24/7 sem supervisão, 1 empresa thesis-validada, dinheiro/clientes reais** | content/SEO ou micro-SaaS R$1-10k MRR | **~0% hoje; ~60-70%** só após O1 + ⑤ + ④ + ⑥ | **Onde mora a promessa "24/7 autônomo".** Estruturalmente bloqueado: ① não tem atuador (pesos do FocusDecider são const hardcoded, nada lê MRR pra ajustar); NightShift hardcoda áreas-do-Atlas, externa exige receipt NS-v1→v2 inexistente. |
| **3 — B2B SaaS com funil de vendas + handoffs** | B2B com onboarding/suporte/billing | **~0%; capado abaixo do Tier 2** mesmo com a stack | Precisa do Layer ③ (orquestrador end-to-end), + deliverability/sender-reputation, + runtime de customer-success/retenção, + função jurídica/contratos, + órgão de roadmap. |
| **4 — Atlas ORIGINA / multi-mercado / regulada** | criar empresa nova, multi-moeda, regulada | **~0%; teto por desenho** | Escolher vencedor, estratégia nova, pivot de crise, existência legal (incorporação/banking/tax). Sem mecanismo load-bearing; originação grounding-locked; teto de capacidade do modelo. |

**Resposta de uma linha:** **hoje = Tier 0 (mede e aconselha, ~30%, nada toca o mundo).** Com a Fase 2 + os fixes de desenho construídos: **realista chegar ao Tier 2** — uma única empresa thesis-validada, de baixo risco/reversível (micro-SaaS ou content-SEO ~R$1-10k MRR), rodando 24/7 a ~60-70% de autonomia, com dinheiro contido e o irreversível atrás de mandato. **Tier 3 (B2B com vendas)** é build adicional grande e capa mais baixo. **Tier 4 (originar/regulada)** é teto-limitado (soberania + capacidade), majoritariamente NÃO-autônomo.

---

## Must-add (ranqueado por alavancagem)

1. **MRR-02 — amarrar o sucesso a caixa liquidado verificado externamente** (reconciliação processador/banco, líquido de reembolso); rejeitar `source='operator'` para o veredito `succeeded`. Sem ground-truth externo, o loop escreve e é avaliado no mesmo número → tudo a jusante é exploitável.
2. **Layer ② — despachante de ação (Slice O1 `VentureOperationRuntime`)** — o pré-requisito load-bearing único; ④⑤⑥① todos envolvem um dispatch que não existe. Primeiro a aterrissar, default-OFF, byte-identical-OFF, provado em sandbox.
3. **Gate de saúde multi-sinal** — alargar `succeeded` além de MRR bruto: margem de contribuição/solvência, piso de churn/NRR, teto de concentração de cliente, pré-condição de legalidade; estados failed-by-insolvency/failed-by-legal. **Antes** de armar o realocador econômico (B2), senão dobra a aposta nas empresas mais destrutivas de caixa.
4. **Layer ④ — oráculo de qualidade não-código** (rubrica-frozen/LLM-judge com guarda de falha-correlacionada) p/ copy/vendas/preço/resposta. NÃO reusar `AiQualityEvaluator` (só higiene).
5. **Layer ⑥ — contenção agregada por venture** (spend-cap, circuit-breaker que pausa a venture, kill-switch, classificador de reversibilidade por TIPO de ação). Stateful/janelado (morte-por-mil-cortes). Antes/junto de qualquer mandato de gasto real.
6. **Layer ⑤ — trust-ladder de AÇÃO de venture** (irmão do `AtlasChangeClassTrustLadder`, chaveado por classe de ação — post/price/spend/charge), alimentado por evidência de outcome + receipt NS-v1→v2.
7. **Layer ① — loop de aprendizado com salvaguardas de controle** (MRR→update auditável/reversível dos pesos via Compounding), com credit-assignment sob delay de 3 meses, orçamento de exploração, anti-oscilação, sample mínimo. Construir C2 (custo-por-sucesso) primeiro p/ termo de lucro contrabalançar MRR.
8. **Pré-condição de formação/back-office + Layer ③** — gate de existência-de-entidade/banking/legal no caminho Created + campo em `AiVenture` registrando qual entidade legal coleta o MRR avaliado; + orquestrador end-to-end + deliverability + customer-success p/ B2B.

---

## Fronteira irredutível (o que nenhum desenho conserta)
1. **Por soberania (escolha):** formação de entidade, abrir banco/merchant, assinar contratos, mover dinheiro real, clearance regulatório, contratar humanos, seguro — ficam atrás de **mandato do operador**. O defeito a corrigir é deixar isso IMPLÍCITO (sem gate de pré-condição explícito, sem campo registrando a entidade que coleta o MRR), não a fronteira em si.
2. **Por capacidade do modelo:** originação greenfield genuína (escolher vencedor antes de evidência de mercado, estratégia nova-e-correta, pivot de crise) tem teto. O grounding-lock que (corretamente) dropa movimentos não-fundamentados pra evitar alucinação **estruturalmente proíbe originar uma tese ainda-não-verdadeira**. O construível é wiring + calibração por domínio + falsificação de demanda externa, não um escolhedor-de-vencedor onisciente.

---

## A leitura final
- **0 gaps critical** — nada está fundamentalmente quebrado; o Tier 0 é real e sólido, anti-alucinação, fail-closed. A fundação está certa.
- **Mas o desenho NÃO é suficiente como está** — nem como implementação (Fase 2 = 0%) nem como design (7 omissões load-bearing acima).
- **Bom:** os fixes são concretos, ranqueados e em ordem; e o caminho até o Tier 2 (a promessa "24/7 autônomo" para 1 empresa de baixo risco) é claro e construível. O que ele NÃO é: "majoritariamente wiring rápido e seguro" — Layers ①④ são invenção e exigem cuidado de teoria de controle e oráculo de qualidade.
