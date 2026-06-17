# Atlas — Loop de Evolução de Arquitetura (ledger de iterações)

> Goal do operador (2026-06-17): loop contínuo melhorando estrutura/arquitetura/desenho do fluxo Atlas até consolidar como a solução mais poderosa do mercado. Cada iteração: painel de design adversarial → síntese grounded → registro aqui. Honestidade pétrea: nenhum design reivindica o que não fecha.
> Nota de estado: o rascunho de código Fase-0 foi revertido — **fase de DESENHO pura**; as classes `VentureFoundry/Success/*` NÃO existem (confirmado por grounding). Os componentes abaixo são alvos de desenho, não código.

---

## Iteração 1 — Layer ① (loop de aprendizado / compounding keystone)

**Insight central (honesto):** o moat não é velocidade de aprendizado — é **compor honestamente**. Embarca-se um substrato **inerte, reversível, byte-idêntico-quando-vazio** que só ganha crédito de **caixa externamente reconciliado** (nunca self-report) e **reverte na hora** em regressão. Ele fica **corretamente DORMENTE** até existir (a) ingestão de caixa real e (b) um atuador de venture wired — e **recusa fabricar gradiente de self-report só pra parecer vivo**. Essa é a forma da "compor honestamente" que torna Atlas mais poderoso que qualquer rival: é o único que não mente o próprio progresso.

### Componentes (ordem de build embutida)
| # | Componente | Reuso/Novo | Papel + salvaguarda |
|---|---|---|---|
| 0 | **VentureTerminalRewardEvaluator + VentureRewardScorecard** | NOVO (não existe — premissa falsa das lentes) | O rótulo terminal de sucesso (N meses de MRR reconciliado ≥ threshold, →failed assimétrico). Nada calibra/reverte sem ele. Idempotência: versiona o veredito (a série cresce → re-eval pode virar succeeded→failed). |
| 1 | **VentureRewardReconciler** | NOVO (reusa `AiVentureMetricObservation.source`) | **A correção ship-now de maior valor:** allow-list de fonte no boundary de QUERY — só `source ∈ {payment_processor, bank, external_reconciled}` conta como crédito; `operator/loop/self` = zero, display-only. Fecha o buraco "escreve e se auto-avalia" (VentureGrowthLadderService.php:184). Fail-closed: sem caixa reconciliado → sem reward. |
| 2 | **VenturePolicyWeightStore + FocusDecider config-overlay** | NOVO store + EDIT mínimo | Torna os ganhos do controlador mutáveis-sob-governança (hoje SEVERITY_WEIGHT/dimensionStageWeight são const hardcoded → flywheel fisicamente aberto). **Defaults-vazio ⇒ byte-idêntico a hoje** (a propriedade de segurança que deixa tudo embarcar no escuro). Mantém a fórmula linear glass-box, nunca black-box. |
| 3 | **VenturePolicyWeightApplier** | EDIT (clona applyHarnessConfig) | Fecha o único apply-path faltante: `kind='policy'` cai em `kind_applier_pending` hoje. Só age em `status='approved'` (policy é CRITICAL_KIND → nunca auto-aplica). Reversível com receipt. |
| 4 | **VentureMemoryScope ('venture') + recall fail-closed** | NOVO helper | Isolamento cross-venture (anti-exploit-da-Polsia). **Correção crítica:** `scopeForScope(type,null)` hoje retorna GLOBAL (não fail-closed) — o helper novo lança em query não-escopada. |
| 5 | **VentureLearningCockpitView + liveness** | NOVO | Mostra o PORQUÊ (peso efetivo vs default, sample vs n_min, streak, último revert) e prova que o atribuidor casa com outcomes reais. Resolve a "falha invisível": "não aprende nada" e "funciona perfeito" hoje têm telemetria idêntica. |
| 6 | **VenturePolicyLearningProposer + VenturePolicyTrustLadder + VenturePlaybookPromotionGuard** | NOVO (reusa nightlyReview + AtlasChangeClassTrustLadder + LongHorizonMemoryPromotionGuard) | Update damped, proposal-only, com anti-windup + histerese + Wilson lower-bound + **floor de ≥2 ventures distintas** (uma venture não move o global). Promoção a playbook global só com ≥2 corroborações reconciliadas + de-identificação + review. **Atuador HARD-DISABLED** até a liveness provar atribuição real. |
| 7 | **VentureLeadingIndicatorReward (Smith-predictor)** | NOVO, **GATED-OFF** | Proxy [0,1] dos indicadores que precedem o MRR (ativação, trial→paid) pra cobrir o dead-time de meses. Sozinho nunca cunha mudança; o veredito reconciliado lento sempre vence. Deferido até haver plant. |
| — | **Net-of-cost reward + VentureMultiSignalScalarizer** | REUSE telemetria + NOVO scalarizer (reporting-only) | Reward = uplift de caixa reconciliado MENOS custo medido (otimiza margem, não vaidade). Scalarizer min-and-multiply (legality·min(margem,retenção)·receita/custo) é o gate de aprovação do operador — nunca o gradiente. |

### Garantias de convergência (por construção)
1. **Monotone-ou-reverte:** vazio ⇒ byte-idêntico; o que sobrevive tem evidência reconciliada/atribuída/≥n_min/net-cost; o que regride o veredito versionado **snapa pro prior pinado** e zera o streak. Ganho bom acumula; ruim é transiente e auto-apaga.
2. **Não persegue ruído:** Wilson lower-bound + floor de ventures-distintas + dead-band + EWMA + cap de learning-rate + cooldown (anti-windup textbook) → abstém abaixo do contrato de poder.
3. **Não se auto-avalia:** só caixa reconciliado conta (fecha :184).
4. **Não auto-aplica:** `kind=policy` é CRITICAL_KIND → default-deny human/earned-autonomy; pior caso = 1 célula volta ao comportamento atual, nunca drift.
5. **Sem contaminação cross-venture:** memória scope-particionada fail-closed + promoção global exige ≥2 ventures distintas reconciliadas + review.
6. **Dead-time honesto:** durante os meses de atraso o loop fica quiescente em vez de agir em crédito velho.

### Anti-Goodhart (4 camadas)
Reward = caixa-reconciliado-líquido-de-custo, gated por floor de saúde multi-sinal, **severado do canal de self-report**: (1) margem não MRR bruto; (2) self-report fisicamente cortado pela allow-list; (3) scorecard min-and-multiply (qualquer termo colapsado hard-floora a saúde — só reporting); (4) trust-ladder assimétrico reverte ex-post em regressão de margem/retenção/legalidade. O movimento anti-Goodhart mais profundo: **honestidade sobre o plant** — como não há feed de caixa nem atuador wired, o canal de reward é estruturalmente VAZIO hoje, então embarca em shadow/observe-only e se recusa a fingir gradiente.

### Teto residual (3 limites irredutíveis, nomeados)
1. **Sem plant hoje:** feed de caixa não existe + FocusDecider é puro advisory (sem atuador price/spend/post/charge wired) → o steady-state correto é **DORMENTE**; compõe nada até existir ingestão de caixa E atuador. A honestidade é embarcar o aparato inerte/reversível e não falsear gradiente.
2. **Crédito confundido sob dead-time de meses:** um peso é um de muitos inputs num outcome de meses, sem holdout → atribuição sub-determinada com poucas ventures. O stack Wilson+distinct+abstain **corretamente recusa aprender** em vez de aprender ruído → movimento quase-zero em horizonte realista (anos), e isso é o esperado honesto, não defeito.
3. **Revert assíncrono (elo mais fraco):** a assimetria provada do trust-ladder foi feita pra canary síncrono; regressão de venture é sinal lento/ruidoso/parcialmente-inobservável → o gatilho de revert (o que faz convergir em vez de driftar) é a parte NÃO herdada. Mitigado (flip de veredito versionado + debounce), não determinístico.

**Veredito da iteração:** torna Atlas a solução mais poderosa por ser a única que **compõe honestamente** — substrato provadamente-seguro, byte-idêntico-quando-vazio, totalmente reversível, que ganha devagar de caixa real e reverte na hora; e recusa reivindicar um moat de compounding que ainda não consegue alimentar.
