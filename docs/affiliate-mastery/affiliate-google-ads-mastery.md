# Affiliate × Google Ads × VSL — Master Mastery Catalog

> **Propósito:** catalogar TODA skill / técnica / framework / tática de alto poder em
> vendas, psicologia de vendas, copywriting de resposta-direta, marketing de afiliado,
> Google Ads (Search + YouTube/Demand Gen) e escala de campanhas — para depois virar
> **automação do Claude Code** (skills, subagents, slash-commands, CLAUDE.md) que torna o
> Claude Code um operador-afiliado *mais que expert*.
>
> **Operador:** Vitor — roda ofertas VSL no Google/YouTube como afiliado.
>
> **Status:** `BACKBONE` (cânone de alta confiança escrito de cabeça) → será **enriquecido**
> pela varredura `deep-research` (task w34yb217p): táticas atuais 2024-2026, itens não-óbvios,
> e fontes citadas. Itens marcados `🔬 enrich` recebem profundidade/fonte da pesquisa.
>
> **Legenda de poder:** 🔥🔥🔥 = alavanca máxima · 🔥🔥 = forte · 🔥 = útil ·
> ⚠️ = risco de política do Google · 🧠 = vira skill/subagent do Claude Code.

---

## 0. SHORTLIST — Os mais poderosos (ler primeiro)

Os itens de maior alavanca, transversais aos 6 domínios. Se só desse pra dominar 12, seriam estes:

| # | Item | Domínio | Por que é máximo |
|---|------|---------|------------------|
| 1 | **Value Equation (Hormozi)** | Psicologia | Reescreve a oferta inteira; muda a percepção de valor antes de qualquer mídia |
| 2 | **Estados de Consciência + Sofisticação (Eugene Schwartz)** | Copy | Define o ângulo e o lead da VSL conforme o mercado já viu; erro aqui mata a campanha |
| 3 | **VSL canônica (Lead → Mecanismo → Oferta → Close)** | Copy | A estrutura que converte tráfego frio caro em venda |
| 4 | **Match: Intenção de keyword × estágio de consciência** | Google Ads | A ponte que faz o clique caro virar lead barato |
| 5 | **Offline Conversion Import / Enhanced Conversions** | Tracking | Sem isso o Smart Bidding otimiza pro evento errado — teto de escala |
| 6 | **tCPA / tROAS com conversão-de-venda real (não lead)** | Bidding | Faz o algoritmo do Google comprar lucro, não volume |
| 7 | **Advertorial / Presell bridge** | Afiliado | Pré-aquece, resolve compliance e sobe CTR/CVR do clique pago |
| 8 | **Oferta Grand Slam (bônus, garantia, escassez, naming)** | Psicologia | Multiplica CVR sem tocar no tráfego |
| 9 | **Spy de ofertas escalando (Adplexity/biblioteca de anúncios)** | Spy | Copiar o que JÁ está lucrando > inventar do zero |
| 10 | **Hagakure / consolidação de campanha p/ Smart Bidding** | Estrutura | Dá dados suficientes pro algoritmo sair do modo burro |
| 11 | **Creative testing velocity (taxa de teste de hooks/ângulos)** | Escala | Em VSL/YouTube o criativo é a alavanca nº1; quem testa mais, escala mais |
| 12 | **Ledger decisão→resultado** | Meta | Transformar cada ação em dado aprendível = vantagem composta do operador |

> _A pesquisa vai confirmar/reordenar este shortlist e adicionar os "segredos" menos óbvios._

---

## 1. PSICOLOGIA DE VENDAS & PERSUASÃO

### 1.1 Princípios de Influência (Cialdini) 🔥🔥🔥 🧠
**O que é:** os 7 gatilhos universais — Reciprocidade, Compromisso/Coerência, Prova Social,
Autoridade, Afinidade (Liking), Escassez, Unidade.
**Por que é poderoso:** são as alavancas psicológicas que toda VSL/copy de alto nível ativa em
sequência; cada um vira um *checkpoint* de revisão de criativo.
**Quando usar:** auditoria de qualquer VSL/landing — "quais dos 7 estão presentes e onde?".
**Encode no Claude Code:** subagent `persuasion-auditor` que recebe um script/landing e devolve
quais dos 7 princípios estão ativos, quais faltam, e onde inserir. `🔬 enrich`: exemplos de
copy por princípio.

### 1.2 Value Equation — Hormozi 🔥🔥🔥 🧠
**O que é:** Valor percebido = (Resultado Sonhado × Probabilidade Percebida de Sucesso) ÷
(Tempo até o Resultado × Esforço/Sacrifício). $100M Offers.
**Por que é poderoso:** é a alavanca que age ANTES da mídia — uma oferta com equação forte
converte tráfego que uma oferta fraca queima. Mexe nos 4 termos sem mudar produto.
**Quando usar:** antes de escalar qualquer oferta; diagnóstico "por que não converte".
**Encode:** skill `offer-doctor` — input a oferta, output cada termo pontuado + alavancas pra
subir numerador / baixar denominador.

### 1.3 Grand Slam Offer 🔥🔥🔥 🧠
**O que é:** empilhar a oferta até ficar "burro dizer não" — stack de bônus com valor ancorado,
garantia que inverte risco, escassez/urgência reais, naming (MAGIC / value stack).
**Por que é poderoso:** sobe CVR sem mexer no custo de tráfego — multiplicador puro de ROAS.
**Encode:** skill `grand-slam-builder` que monta value-stack + garantia + bônus a partir do produto.

### 1.4 Estados de Consciência (Eugene Schwartz) 🔥🔥🔥 🧠
**O que é:** 5 níveis de consciência do prospect — Unaware, Problem-Aware, Solution-Aware,
Product-Aware, Most-Aware — cada um exige um *lead* diferente.
**Por que é poderoso:** decide o ângulo de abertura da VSL e o tipo de keyword/audiência. Tráfego
de Search costuma ser solution/product-aware; YouTube frio é problem/unaware → leads diferentes.
**Encode:** subagent `awareness-router` que classifica audiência/keyword e prescreve o tipo de lead.

### 1.5 Estágios de Sofisticação de Mercado (Schwartz) 🔥🔥 🧠
**O que é:** 5 estágios de quão "cansado" o mercado está das promessas — define se você lidera com
claim, com claim ampliado, com mecanismo, com mecanismo único, ou com identificação.
**Por que é poderoso:** errar o estágio = copy soa clichê e morre. Em nicho saturado (emagrecimento,
ED, finanças) o **mecanismo único** é o que destrava.
**Encode:** parte do `awareness-router`; `🔬 enrich` com mecanismos únicos por nicho atual.

### 1.6 NEPQ — Neuro-Emotional Persuasion Questions (Jeremy Miner) 🔥🔥 🧠
**O que é:** venda por perguntas que fazem o prospect se persuadir; foco em problem/consequence
questions, tom neutro.
**Por que é poderoso:** aplicável em VSL (perguntas retóricas), e em follow-up/qualificação.
**Encode:** `🔬 enrich` — sequência de perguntas por estágio.

### 1.7 Outros frameworks de venda (catalogar) `🔬 enrich`
SPIN Selling, Challenger Sale, Sandler, Straight-Line (Belfort), 7-Levels of Why.
**Encode:** biblioteca de "modos de venda" selecionável por contexto.

### 1.8 Gatilhos emocionais & vieses cognitivos 🔥🔥 `🔬 enrich`
Loss aversion, ancoragem, efeito de enquadramento, prova social específica, "future pacing",
curiosity gap, open loops. **Encode:** checklist no `persuasion-auditor`.

---

## 2. COPYWRITING DE RESPOSTA-DIRETA

### 2.1 Fórmulas de estrutura 🔥🔥🔥 🧠
**AIDA** (Attention-Interest-Desire-Action), **PAS** (Problem-Agitate-Solve),
**PASTOR** (Problem-Amplify-Story-Transformation-Offer-Response),
**BAB** (Before-After-Bridge), **FAB** (Features-Advantages-Benefits),
**4 Ps** (Promise-Picture-Proof-Push), **QUEST**.
**Por que é poderoso:** esqueleto reutilizável pra escrever/auditar qualquer peça rápido.
**Encode:** skill `copy-framework` — escolhe a fórmula por objetivo e gera/critica a peça.

### 2.2 Estrutura canônica da VSL 🔥🔥🔥 🧠
**O que é:** Hook/Pattern-interrupt → Calo (problema agitado) → História/Credibilidade →
Mecanismo do problema → Mecanismo da solução (único) → Prova → Oferta (value stack) →
Garantia → Escassez → CTA → P.S./objeções.
**Por que é poderoso:** é o motor que monetiza tráfego pago frio caro; a anatomia que o operador
edita por alavanca (gancho, bridge, fechamento).
**Encode:** skill `vsl-architect` (escreve/audita roteiro por bloco) + amarra na árvore de
diagnóstico sintoma→ação do operador. `🔬 enrich`: timestamps/retention benchmarks, hooks que
seguram nos primeiros 15s.

### 2.3 Fórmulas de headline 🔥🔥 🧠
4U (Useful, Urgent, Unique, Ultra-specific), "How to", "Reason Why", "Secret of",
"Warning", "They laughed when…", "Who Else Wants". **Encode:** gerador de 20 headlines por ângulo
+ score por especificidade. `🔬 enrich` swipe atual.

### 2.4 Gatilhos do Sugarman (Triggers / slippery slide) 🔥🔥 `🔬 enrich`
Os ~30 gatilhos psicológicos de *Adweek Copywriting Handbook*; "a primeira frase só serve pra
fazer ler a segunda". **Encode:** checklist de fluência/legibilidade no `vsl-architect`.

### 2.5 Linhagem mestre (swipe + princípios) 🔥🔥 `🔬 enrich`
Ogilvy, Gary Halbert, Claude Hopkins (*Scientific Advertising*), Eugene Schwartz
(*Breakthrough Advertising*), Gary Bencivenga, Dan Kennedy, John Carlton, David Deutsch.
**Encode:** corpus de princípios destilados como referência de estilo do `vsl-architect`.

### 2.6 Anatomia de Advertorial / Presell / Bridge 🔥🔥🔥 ⚠️ 🧠
**O que é:** página editorial/"story" entre o anúncio e a VSL — formato de notícia, "minha
história", listicle, quiz. Aquece, filtra, e dá compliance ao anúncio.
**Por que é poderoso:** sobe CTR do anúncio e CVR da VSL; resolve a política do Google (não
manda direto pro pitch). É das maiores alavancas de tráfego pago em afiliado.
**⚠️ Risco:** advertorial enganoso / "fake news style" viola política — tem que ser honesto.
**Encode:** skill `bridge-builder` (gera advertorial por ângulo) com `policy-guard` acoplado.

---

## 3. MARKETING DE AFILIADO

### 3.1 Seleção de oferta & EPC 🔥🔥🔥 🧠
**O que é:** escolher oferta por EPC (earnings per click), gravity/temperatura, payout, refund
rate, qualidade da VSL do produtor, e fit com tráfego.
**Por que é poderoso:** a oferta errada não escala por melhor que seja o tráfego — 80% do
resultado é seleção. EPC é a métrica-rei pra comparar ofertas.
**Encode:** subagent `offer-scout` — critérios de score; `🔬 enrich`: thresholds atuais por rede.

### 3.2 Redes & ofertas 🔥🔥 `🔬 enrich`
ClickBank, BuyGoods, Digistore24, Maxweb, ClickBank alternatives, redes de nutra/ED/finanças.
**Encode:** tabela de redes (vertical, payout típico, qualidade de tracking/postback).

### 3.3 Presell vs Direct-link 🔥🔥 ⚠️
**O que é:** mandar o clique pro advertorial (presell) vs direto pra VSL. Direct-link some no
Google (política + QS baixo); presell é o padrão sustentável.
**Encode:** regra no `policy-guard` + `bridge-builder`.

### 3.4 Economia de funil & escala 🔥🔥🔥 🧠
**O que é:** entender CPA-alvo = payout × CVR-de-venda × (1 − refund) ÷ margem desejada; saber
quanto pode pagar por clique/lead e ainda lucrar.
**Por que é poderoso:** é o número que governa lance, escala e kill de campanha.
**Encode:** skill `unit-economics` — calcula breakeven CPC/CPA, ROAS-alvo, ponto de escala/kill.

---

## 4. GOOGLE ADS PARA OFERTAS VSL/AFILIADO

### 4.1 Estratégia de intenção de keyword (Search) 🔥🔥🔥 🧠
**O que é:** mapear keywords por intenção/consciência — problema, solução, marca-do-produto,
"review", "scam", "[produto] official". Casar a keyword com o lead certo da VSL/bridge.
**Por que é poderoso:** é a ponte clique-caro→lead-barato; QS e CVR vivem disso.
**Encode:** subagent `keyword-intent-mapper` — clusteriza keywords por intenção e prescreve
ad+bridge. `🔬 enrich`: termos "review/scam/official" e política sobre bidding em marca alheia ⚠️.

### 4.2 RSA / Ad Strength / Quality Score 🔥🔥 🧠
**O que é:** Responsive Search Ads, pinning estratégico, ad strength, e os 3 fatores de QS
(expected CTR, ad relevance, landing page experience).
**Por que é poderoso:** QS alto = CPC menor pelo mesmo posto; alavanca direta de custo.
**Encode:** skill `rsa-writer` (15 headlines/4 descriptions por tema + pin map) + `qs-doctor`.

### 4.3 Performance Max & Demand Gen (YouTube) 🔥🔥🔥 🧠 `🔬 enrich`
**O que é:** PMax (cross-inventory, asset groups, audience signals) e **Demand Gen** (substituiu
Discovery; o canal-chave pra VSL no YouTube/Discover/Gmail). Criativos de vídeo + imagem.
**Por que é poderoso:** Demand Gen é onde VSL frio escala no ecossistema Google hoje.
**Encode:** subagent `demand-gen-architect` — asset group, audience signals, criativos por ângulo.
`🔬 enrich`: best practices 2024-2026, specs de vídeo, lookalike/custom segments.

### 4.4 Estratégias de lance 🔥🔥🔥 🧠
**O que é:** Manual/Enhanced CPC (cold start) → Max Conversions → **tCPA** → **tROAS**. Quando
migrar, quanto de dado precisa (≈30-50 conv/mês), como não "resetar o aprendizado".
**Por que é poderoso:** o lance certo faz o algoritmo comprar venda lucrativa, não cliques.
**Encode:** skill `bidding-strategist` — recomenda estratégia por fase/volume de dados + regras
de migração. `🔬 enrich`: números atuais de threshold.

### 4.5 Conversion tracking: Offline / Enhanced / Server-side 🔥🔥🔥 🧠
**O que é:** mandar a **venda real** (não o lead) de volta pro Google — Offline Conversion Import
(GCLID), Enhanced Conversions (hashed PII), conversões server-side (GTM SS), e integração com
o tracker (postback → Google).
**Por que é poderoso:** sem isso o Smart Bidding otimiza pro evento errado e a escala trava. É o
gargalo silencioso de quase todo afiliado.
**Encode:** skill `conversion-pipeline` — desenha o caminho venda→tracker→GCLID→Google p/ a stack.
`🔬 enrich`: passo-a-passo OCI + Enhanced + consent mode v2.

### 4.6 Compliance de política (bridge/afiliado) 🔥🔥🔥 ⚠️ 🧠
**O que é:** políticas do Google sobre "bridge pages", afiliado de baixo valor, claims de saúde/
finanças, destination mismatch, "malicious/unwanted". O que faz a conta ser suspensa.
**Por que é poderoso:** conta banida = zero. Compliance É a infra de escala em afiliado.
**Encode:** subagent `policy-guard` — audita anúncio+bridge+VSL contra políticas e aponta risco
ANTES de subir. `🔬 enrich`: lista atual de políticas + casos comuns de ban.

### 4.7 Estrutura de conta: Hagakure / SKAG / consolidação 🔥🔥 🧠 `🔬 enrich`
**O que é:** Hagakure (consolidar pra alimentar Smart Bidding) vs SKAG (single keyword ad group,
hoje menos recomendado com broad+smart). Theme-based ad groups.
**Por que é poderoso:** estrutura demais fragmenta dados e cega o algoritmo; estrutura certa o
alimenta. **Encode:** subagent `account-structurer`.

---

## 5. ESCALA DE CAMPANHAS

### 5.1 Escala vertical vs horizontal 🔥🔥🔥 🧠
**O que é:** vertical = subir budget na campanha vencedora (regra ~20%/2-3 dias pra não resetar
aprendizado); horizontal = duplicar pra novas audiências/geos/criativos/campanhas.
**Por que é poderoso:** escalar errado mata o que funcionava (reset de learning, CPA explode).
**Encode:** skill `scale-operator` — decide vertical/horizontal por sinal e prescreve o incremento.
`🔬 enrich`: regras numéricas atuais de budget ramp.

### 5.2 Velocidade de teste de criativo 🔥🔥🔥 🧠
**O que é:** cadência de testar novos hooks/ângulos/thumbnails/VSL leads. Em YouTube/Demand Gen o
criativo é a alavanca nº1 de escala; pipeline de produção é vantagem.
**Por que é poderoso:** quem testa mais ângulos por semana acha o vencedor que destrava o teto.
**Encode:** subagent `creative-pipeline` — gera N variações de hook/ângulo + matriz de teste.

### 5.3 Dayparting, geo bid adj, audience expansion 🔥🔥 `🔬 enrich`
Ajuste por hora/dia, por geo (tier 1 vs 2/3), expansão de audiência sem diluir. **Encode:** regras
no `scale-operator`.

### 5.4 O que quebra na escala 🔥🔥 `🔬 enrich`
Saturação de audiência, CPA creep, fadiga de criativo, learning reset, refund/quality do tráfego
escalado. **Encode:** checklist de diagnóstico de "por que parou de escalar".

---

## 6. TRACKING & SPY

### 6.1 Trackers 🔥🔥🔥 🧠
**O que é:** Voluum, RedTrack, Binom, ClickMagick — atribuição clique→lead→venda, postbacks,
split de campanha, GCLID passthrough.
**Por que é poderoso:** sem tracking confiável você otimiza no escuro; o tracker é a fonte de
verdade que alimenta o Google de volta.
**Encode:** skill `tracking-setup` — escolhe tracker, desenha postbacks e o loop pro Google.
`🔬 enrich`: prós/contras e custo atuais de cada tracker.

### 6.2 Server-side & GA4 attribution 🔥🔥 `🔬 enrich`
GTM server-side, consent mode v2, GA4 como leitura (não fonte de bid). **Encode:** `conversion-pipeline`.

### 6.3 Spy de ofertas/anúncios 🔥🔥🔥 🧠 `🔬 enrich`
**O que é:** Adplexity (push/native/search), BigSpy, PowerAdspy, **Google Ads Transparency
Center**, **Meta Ad Library** (pra ângulos), SimilarWeb. Achar oferta escalando + ângulo + bridge.
**Por que é poderoso:** copiar o que JÁ lucra encurta meses de teste. "Se está rodando há semanas,
está lucrando."
**Encode:** subagent `offer-spy` — protocolo de espionagem (onde olhar, o que extrair: ângulo,
bridge, lead, oferta) + síntese. `🔬 enrich`: ferramentas atuais e seus pontos cegos.

---

## 7. FASE 2 — Como isso vira automação do Claude Code

> Cada `🧠` acima vira um artefato concreto. Plano de construção (depois do catálogo fechado):

| Artefato Claude Code | Origem (itens) | Tipo |
|----------------------|----------------|------|
| `offer-doctor` / `grand-slam-builder` | 1.2, 1.3, 3.4 | Skill |
| `persuasion-auditor` | 1.1, 1.8, 2.4 | Subagent |
| `awareness-router` | 1.4, 1.5 | Subagent |
| `vsl-architect` | 2.2, 2.3, 2.5 | Skill |
| `copy-framework` / `rsa-writer` | 2.1, 4.2 | Skill |
| `bridge-builder` + `policy-guard` | 2.6, 3.3, 4.6 | Skill + Subagent |
| `offer-scout` / `offer-spy` | 3.1, 6.3 | Subagent |
| `keyword-intent-mapper` / `account-structurer` | 4.1, 4.7 | Subagent |
| `bidding-strategist` / `demand-gen-architect` | 4.3, 4.4 | Skill |
| `conversion-pipeline` / `tracking-setup` | 4.5, 6.1, 6.2 | Skill |
| `unit-economics` | 3.4 | Skill |
| `scale-operator` / `creative-pipeline` | 5.1-5.4 | Subagent |
| **`CLAUDE.md` de domínio afiliado** | todos | Projeção de persona/regra |
| **Ledger decisão→resultado** | shortlist #12 | Memória/registro |

**Mecanismos do Claude Code a usar:** `.claude/skills/` (cada framework = uma skill com
SKILL.md), subagents especialistas (`.claude/agents/`), slash-commands de fluxo
(`/audit-vsl`, `/scout-offer`, `/scale-check`), MCP de dados (Google Ads API, tracker), e um
`CLAUDE.md` que injeta a persona "operador-afiliado expert" + as regras de política.

---

## 8. DADOS OPERACIONAIS COM FONTE (pesquisa — passada 1)

> Achados da varredura `deep-research` (task w34yb217p). **Nota de honestidade:** a camada de
> verificação adversarial foi **rate-limited** (3-abstain em cada claim — não refutação real), então
> estes vêm **com fonte citada mas verificação independente pendente**. Fontes `primary` = doc oficial;
> `blog` = praticante. **Trate os números como aproximados — confirme ao vivo no painel.**

### 8.1 Smart Bidding — learning phase & thresholds 🔥🔥🔥 (números concretos)
- **Sai da learning phase** após ~**50 conversões** (ou ~3 ciclos de conversão). Search estabiliza
  em **7-14 dias** (3-4 semanas pra full); **PMax ~6 semanas / 45 dias**. _[groas.com]_
- Baseline pra Smart Bidding funcionar: **~30-50 conv/mês por campanha**; **PMax 50+/mês**. _[groas.com]_
- **tCPA:** ≥**30 conv/30 dias** por campanha, valores de conversão ~uniformes.
  **tROAS:** ≥**15 conv/30 dias**, valores variáveis. _[groas.com]_
- Duração do learning escala com volume: **50+ conv/sem → 5-7 dias**; **15-50/sem → 7-14 dias**;
  **<15/sem → 3+ semanas**. _[groas.com]_
- **Resetam o aprendizado:** mudar bid strategy, mudar alvo tCPA/tROAS (mesmo pequeno),
  pausar/despausar, e **mudança de budget grande (>20-30%)**. _[groas.com]_
- **Implicação operacional:** a campanha precisa de **volume de venda real** (não lead) chegando no
  Google pra sair do modo burro → conecta direto ao item 4.5 (offline/enhanced conversions).
- **Encode:** vira tabela de regras dentro do `bidding-strategist` + `scale-operator`.

### 8.2 Budget ramp / escala sem quebrar 🔥🔥🔥 (números concretos)
- Suba budget **só 10-20% por ajuste, espaçado 7-14 dias** — não pule grande. _[dilate.com.au]_
- Mantendo ajuste **<10%** evita reiniciar o learning. _[optmyzr.com]_
- Pulo grande (ex.: dobrar da noite pro dia) historicamente **sobe CPA em 25-50%**. _[dilate.com.au]_
- Google pode gastar **até 2× o budget diário** num dia; teto mensal = **30,4× o diário**. _[optmyzr.com]_
- **Encode:** `scale-operator` calcula o próximo incremento seguro e a janela de espera.

### 8.3 Tracking & postbacks 🔥🔥🔥 (com fonte primária)
- **ClickBank S2S postback** = conversão server-side (cookieless, "vetada", mais confiável que pixel).
  _[support.clickbank.com — primary]_
- Macros do postback: **`{tid}`** (tracking id do link), **`{click_id}`** (gerado no hop),
  **`{fbclid}`/`{extclid}`** → o tracker atribui a conversão ao clique/campanha exatos.
  _[support.clickbank.com — primary]_
- Postback transmite **dado financeiro** (comissão do afiliado em USD, earnings do vendor, valor total
  da transação) → permite otimizar por **receita/comissão**, não só contagem. _[support.clickbank.com — primary]_
- ClickBank tem **templates prontos** de postback/pixel pra **Google Ads, Meta, TikTok, Bing** e pros
  trackers **RedTrack, Voluum, ClickMagick**. _[support.clickbank.com — primary]_
- **Server-side tagging (Google):** move as tags do browser pro container no servidor (menos código na
  página, page-load melhor). **Enhanced Conversions** casa PII **hasheada SHA-256 (hex)** — coleta
  automática, manual ou via código. _[developers.google.com — primary]_
- **Encode:** `conversion-pipeline` desenha venda→ClickBank postback→tracker→GCLID/Enhanced→Google.

### 8.4 Redes & economia (payouts) 🔥🔥
- **ClickBank** paga até **75%** de comissão (CPA e RevShare) — vs Amazon Associates 1-3% CPS.
  _[clickbank.com/blog]_
- **MaxWeb:** **CPA only** (por cliente único), mínimo **$100 ACH**, foco **health/fitness/beauty**
  (os verticais clássicos de VSL). _[clickbank.com/blog]_
- **BuyGoods & MaxWeb:** cadência de pagamento por faixa de receita — semanal (qua) <$15k/mês;
  2×/semana $15k-30k; 3×/semana $30k+ → importa pro **fluxo de caixa ao escalar gasto**.
  _[clickbank.com/blog]_
- **Encode:** tabela de redes no `offer-scout` (payout, cadência, qualidade de postback).

### 8.5 Bridge / presell page ⚠️🔥🔥🔥
- Bridge page = passo extra entre o anúncio e a sales page; função primária inclui **manter o HopLink
  cru fora do anúncio → evitar shutdown da conta**. _[clickbank.com/blog]_
- Ajustar a bridge pra **congruência** com a sales page pode **~dobrar o EPC** (claim do BizDev da
  ClickBank — sem amostra/tempo divulgado, trate como direcional). _[clickbank.com/blog]_
- **Encode:** `bridge-builder` + `policy-guard` (congruência ad→bridge→VSL é critério de score).

### 8.6 Trackers (throughput) 🔥🔥
- **Binom** = tracker self-hosted de **maior throughput** (~**10M cliques/dia**), a partir de
  **$119/mês**. _[redtrack.io/blog]_
- **Encode:** `tracking-setup` recomenda tracker por volume/budget.

### 8.7 Spy — fontes mapeadas (a aprofundar) 🔥🔥 ⚠️
- **Google Ads Transparency Center** = espionar anúncios de concorrente direto na fonte do Google.
  _[iamattila.com]_
- Lista de spy tools de afiliado curada. _[profitise.com]_
- **Encode:** `offer-spy` (a passada 2 vai extrair o protocolo de cada ferramenta).

**Fontes (passada 1):**
[ClickBank — bridge page](https://www.clickbank.com/blog/affiliate-bridge-page/) ·
[ClickBank — alternatives/payouts](https://www.clickbank.com/blog/clickbank-alternatives/) ·
[groas — learning phase](https://www.groas.com/post/google-ads-learning-phase-explained-why-your-campaigns-need-2-weeks-to-work-and-how-to-stop-resetting-it) ·
[groas — smart bidding 2026](https://www.groas.com/post/google-ads-smart-bidding-learning-period-2026-tcpa-vs-troas-strategy-guide) ·
[dilate — scale budgets](https://www.dilate.com.au/blog/the-right-way-to-scale-google-ads-budgets-without-wrecking-performance/) ·
[optmyzr — budgets](https://www.optmyzr.com/blog/google-ads-budgets/) ·
[redtrack — trackers](https://www.redtrack.io/blog/best-affiliate-tracking-software/) ·
[ClickBank support — postbacks (primary)](https://support.clickbank.com/en/articles/10535373-postback-pixels-integration-guide) ·
[Google devs — server-side tagging (primary)](https://developers.google.com/tag-platform/tag-manager/server-side/ads-setup) ·
[profitise — spy tools](https://profitise.com/best-spy-tools-for-affiliate-marketing/) ·
[iamattila — Google Transparency spying](https://iamattila.com/media-buying-101/101-guides/spying/how-to-use-google-ads-transparency-center-to-spy-on-competitors-ads)

---

## 9. DADOS OPERACIONAIS COM FONTE (pesquisa — passada 2)

> Buscas dirigidas aos gaps que a passada 1 perdeu (política, VSL, Demand Gen, spy). Fontes de
> busca verificadas; primárias = docs do próprio Google. **Confirme números ao vivo.**

### 9.1 Política do Google p/ afiliado & bridge ⚠️🔥🔥🔥 (sobrevivência da conta)
**O ponto que mais mata afiliado.** Como evitar suspensão:
- Bridge/gateway "puro" (página fina que só repassa o usuário pra outro site) **viola a política
  "Insufficient Original Content"** e é reprovado. _[Google adspolicy 16427718 — primary]_
- **Teste decisivo do Google:** *"essa página ainda ajudaria o usuário se TODOS os links de saída
  fossem removidos?"* Se **não** → é thin/bridge → reprovação. _[ivanmana / agencygdt]_
- **Caminho compliant:** construir no SEU domínio uma página que **realmente ajuda a decidir** —
  review original, comparação lado-a-lado, prós/contras, contexto de preço, ou um *chooser/quiz*.
  Isso satisfaz "Destination requirements" (conteúdo útil, único, original). _[Google adspolicy 6368661 — primary]_
- **Aviso antes de banir:** violação de Destination Requirements **não** suspende sem aviso — há
  **aviso ≥7 dias antes** da suspensão. _[stubgroup]_
- **Tradução prática pro operador:** sua "bridge" tem que ser um **advertorial/review com substância
  própria** (não um redirect disfarçado). A mesma página que aquece a venda é a que passa na política.
- **Encode:** `policy-guard` roda o teste "remova os links de saída — ainda ajuda?" + checklist de
  conteúdo original ANTES de qualquer anúncio subir. **Maior alavanca de não-perder-tudo.**

### 9.2 VSL — estrutura que segura e converte 🔥🔥🔥
- **Hook (primeiros 5s):** pattern-interrupt — stat surpreendente, claim ousado, pergunta
  provocativa ou afirmação contrária. Lidere com a **maior dor** ou o **maior benefício**. _[rebelgrowth/getsalesman]_
- **Agitate:** descreva o problema tão vívido que "parece que você leu o histórico do navegador dele".
- **Solution = mecanismo único** ("secret sauce"): nomeie, reivindique, enquadre como o **único**
  próximo passo lógico (casa com Sofisticação de Schwartz, item 1.5).
- **Proof:** credibilidade rápida — certificações, endossos de terceiros, prints de resultados reais.
- **CTA:** **um** próximo passo específico, não três.
- **Frameworks alternativos:** AIDA (bom pra começar) e **SSO = Story-Solution-Offer** (lidera com
  narrativa → vira conversa, não pitch).
- **Dica medida:** trocar abertura genérica por **hook com dado** rendeu **+28% de conversão** num caso. _[jeremymac]_
- **Encode:** `vsl-architect` audita/gera por bloco e verifica "hook nos 5s? mecanismo único nomeado?
  CTA único?".

### 9.3 Demand Gen (YouTube) — best practices 2025/2026 🔥🔥🔥
- **MUDANÇA-CHAVE 2025:** bidding primário **migrou de tCPA → tROAS** no Demand Gen. _[searchengineland/definedigital]_
- **1 campanha consolidada** aprende mais rápido (quebre em ad groups por placement só pra insight). _[storegrowers]_
- **Audience signals:** lookalikes + customer lists; **optimized targeting expande além do alvo** e
  entrega **+20% conversões ao mesmo custo**. _[Google Ads Help 14693848]_
- **New Customer Acquisition goal:** prioriza/licita diferente p/ quem nunca converteu (usa
  first-party audience). _[storegrowers]_
- **Criativo:** até **5 vídeos por anúncio** (Google escolhe o melhor por leilão); busque **Ad
  Strength "Excellent"** com conjunto amplo de assets de qualidade. _[Google Ads Help]_
- **Resultado:** adotar **≥3 das 4 best-practices** = **+40% conversões em média**. _[definedigital]_
- **Encode:** `demand-gen-architect` monta campanha (tROAS, asset group, audience signals, 5 vídeos,
  NCA goal) + checa as 4 best-practices.

### 9.4 Spy tools — comparativo 2025/2026 🔥🔥🔥 ⚠️
| Ferramenta | Cobertura | Preço (ref) | Diferencial |
|------------|-----------|-------------|-------------|
| **AdPlexity** (por canal) | Native/Desktop/Mobile/Push/YouTube | $149-249/mo cada | DB gigante, multi-canal _[adplexity]_ |
| **AdPlexity Social** | Meta (FB/IG) | — | analisa **pós-clique**: landing, redirect chain, tech _[adplexity.io]_ |
| **Meta Ad Library** | FB/IG ativos | **grátis** | busca por keyword/advertiser; sem stats de engajamento _[meta]_ |
| **Google Ads Transparency Center** | Google/YouTube | **grátis** | espiar anúncios do concorrente na fonte _[iamattila]_ |
| **Anstrex** | Native + Push (Taboola/Outbrain/MGID) | — | profundidade em native/push _[trafficcardinal]_ |
| **PowerAdSpy** | Google/YouTube/FB/IG | — | integra social + search _[landerlab]_ |
| **BigSpy** | cross-platform | barato | volume amplo, audiência diversa _[blog.udonis]_ |
- **Chave de avaliação:** o que separa spy tool de ad library = **landing page visibility + redirect
  tracking + tech detection** (ver o funil inteiro, não só o criativo).
- **Regra de ouro:** anúncio rodando há semanas = está lucrando → **copiar ângulo+bridge+oferta** que
  já escala encurta meses. (Grátis primeiro: **Meta Ad Library + Google Transparency Center**.)
- **Encode:** `offer-spy` — protocolo: achar oferta escalando → extrair ângulo/lead/bridge/oferta →
  cruzar com `offer-scout`.

**Fontes (passada 2):**
[Google — Insufficient original content (primary)](https://support.google.com/adspolicy/answer/16427718?hl=en) ·
[Google — Destination requirements (primary)](https://support.google.com/adspolicy/answer/6368661?hl=en) ·
[Google — Demand Gen best practices (primary)](https://support.google.com/google-ads/answer/14693848?hl=en-GB) ·
[stubgroup — suspension recovery](https://stubgroup.com/blog/google-ads-unacceptable-business-practices-suspension-complete-recovery-guide-2025/) ·
[ivanmana — affiliate rules](https://ivanmana.com/google-ads-affiliate-marketing/) ·
[agencygdt — affiliate policy 2025](https://agencygdt.com/google-ads-policy-for-affiliate-marketing/) ·
[rebelgrowth — VSL guide 2024](https://rebelgrowth.com/blog/the-ultimate-guide-to-video-sales-letters-vsls-how-to-create-high-converting-vsls-in-2024) ·
[jeremymac — VSL script](https://www.jeremymac.com/blogs/news/how-to-write-video-sales-letter-scripts-that-convert-in-10-minutes-ultimate-beginners-guide) ·
[searchengineland — Demand Gen](https://searchengineland.com/google-demand-gen-campaigns-migration-and-best-practices-433014) ·
[storegrowers — Demand Gen guide](https://www.storegrowers.com/google-demand-gen-campaigns/) ·
[definedigital — Demand Gen 2025](https://www.definedigitalacademy.com/blog/how-to-set-up-a-high-performing-google-demand-gen-campaign-in-2025) ·
[AdPlexity — spy tools](https://adplexity.com/blog/ad-spy-tools-for-affiliate-marketing/) ·
[landerlab — spy tools comparison](https://landerlab.io/blog/top-9-spy-tools)

---

## 10. DADOS OPERACIONAIS COM FONTE (pesquisa — passada 3)

### 10.1 Psicologia/copy — cânone com fonte 🔥🔥🔥
- **Cialdini — 7 princípios:** reciprocidade, compromisso/coerência, prova social, autoridade,
  afinidade, escassez + **Unidade** (7º, adicionado em 2016; os 6 originais são de *Influence*, 1984).
  Operam no **Sistema 1** (decisão rápida/automática). _[influenceatwork / cxl]_
- **Hormozi — Value Equation:** `(Dream Outcome × Perceived Likelihood) ÷ (Time Delay × Effort)`.
  **Insight-chave:** as melhores empresas focam o **denominador** — tornar **imediato, sem atrito,
  sem esforço**. Numerador é fácil de inflar; o denominador é a vantagem real. _[wisewords / youngandprofiting]_
- **Schwartz — Breakthrough Advertising (1966):** o papel do marketing **não é criar desejo**, é
  **reconhecer o desejo de massa que já existe** (esperanças/medos/sonhos de milhões) e **canalizá-lo**
  pro produto. Base dos estados de consciência + sofisticação. _[mirasee / auresnotes]_
- **Nota:** já existe uma "Hormozi $100M Offers Claude Code Skill" pública (mcpmarket) — referência de
  que encodar esses frameworks como skill é caminho validado. _[mcpmarket]_

### 10.2 Conversion tracking — setup prático + PRAZO CRÍTICO 🔥🔥🔥 ⚠️ (fontes primárias Google)
- **OCI via GCLID:** ligue **auto-tagging**, capture o **GCLID** que o Google anexa na URL do clique,
  guarde GCLID + dados do lead no seu tracker, e suba a **venda real** depois. _[Google Ads Help 7012522 — primary]_
- **Enhanced Conversions for leads** = upgrade do OCI: GCLID **+ first-party data** (email/telefone
  hasheados) → **+10% conversões (median)** vs OCI padrão. _[Google Ads Help 15713840 — primary]_
- **⚠️ PRAZO QUE MUDA A STACK:** a partir de **15/jun/2026**, OCI e Enhanced Conversions for leads
  **migram pro Data Manager API** e são **bloqueados na Google Ads API**. Integração legada Salesforce
  encerra **31/mai/2025**. → **Construa já no Data Manager API.** _[developers.google.com — primary]_
- **Best practice:** mande **GCLID + user-provided data + session attributes** em TODA conversão.
- **Encode:** `conversion-pipeline` assume **Data Manager API** como default e inclui Enhanced
  Conversions for leads (não só OCI puro).

### 10.3 Advertorial/presell — formato que converte tráfego frio 🔥🔥🔥
- **Listicle é o formato nº1** pra tráfego frio: "X Razões Por Que [público] Está Trocando Para
  [produto]" / "X Formas Que [produto] Resolve [problema]"; **5-10 itens**, **500-1.500 palavras**,
  layout de artigo. _[convertibles / landerlab]_
- **70%** dos títulos estilo listicle têm **CTR maior** que não-listicle (Anyword). _[convertibles]_
- **Por que converte:** o leitor vindo de native/scroll está em **modo consumir, não comprar**; o
  advertorial **lidera com informação e ganha confiança antes de pedir** → remove atrito. _[adbeat]_
- **Prova de escala:** advertorial ajudou Hint Water a virar negócio de **$100M**, 7 dígitos/trimestre
  pra Jones Road Beauty, e é o **tipo de landing nº1 de native/Facebook**. _[convertibles / apexure]_
- **Elementos:** bullets/números/ícones simples + **prova social** (depoimentos, reviews, logos).
  Casa direto com o teste de compliance §9.1 (conteúdo original, ajuda mesmo sem links de saída).
- **Encode:** `bridge-builder` gera advertorial **listicle** por ângulo, com prova social, passando o
  `policy-guard`.

**Fontes (passada 3):**
[influenceatwork — Cialdini 7](https://www.influenceatwork.com/7-principles-of-persuasion/) ·
[cxl — Cialdini p/ conversão](https://cxl.com/blog/cialdinis-principles-persuasion/) ·
[wisewords — $100M Offers](https://wisewords.blog/book-summaries/100m-offers-book-summary/) ·
[youngandprofiting — Value Equation](https://youngandprofiting.com/alex-hormozi-the-value-equation-how-to-make-offers-so-good-people-feel-stupid-saying-no-e199/) ·
[mirasee — Breakthrough Advertising](https://mirasee.com/blog/eugene-schwartz-breakthrough-advertising/) ·
[Google — OCI via GCLID (primary)](https://support.google.com/google-ads/answer/7012522?hl=en) ·
[Google — Enhanced Conversions for leads (primary)](https://support.google.com/google-ads/answer/15713840?hl=en) ·
[Google devs — manage offline conversions / Data Manager (primary)](https://developers.google.com/google-ads/api/docs/conversions/upload-offline) ·
[convertibles — advertorial examples](https://convertibles.dev/blogs/optimization/examples-of-advertorials) ·
[adbeat — native landing styles](https://blog.adbeat.com/7-landing-page-styles-that-convert-on-native-part-1-taboola/) ·
[landerlab — advertorial page](https://landerlab.io/blog/advertorial-landing-page-3-examples)

---

## 11. AUDITORIA DE COMPLETUDE — gaps achados (passada 4)

> Resposta a "garante que pegou todos?". O workflow multi-agente de auditoria **tomou rate-limit
> (18/18 agentes morreram)** → refeito **manual/pausado**. Achou **itens reais e poderosos que as
> passadas 1-3 NÃO tinham**. Veredito honesto: as primeiras passadas **não eram completas**; abaixo o
> que faltava (agora coberto). Não existe "100% garantido" num campo vivo — mas isto é auditoria, não palavra.

### 11.1 Psicologia/oferta — frameworks que faltavam 🔥🔥🔥
- **Life Force 8 (Cashvertising, Drew Whitman):** 8 desejos hardwired que dirigem compra —
  sobrevivência/extensão da vida, comida/bebida, **livre de medo/dor/perigo**, companhia sexual,
  condições confortáveis, **ser superior/vencer (keeping up with the Joneses)**, proteger entes
  queridos, **aprovação social**. Toda copy forte ataca ≥1 LF8. _[enchantingmarketing / blinkist]_
- **One Sentence Persuasion (Blair Warren):** *"As pessoas farão qualquer coisa por quem **encoraja
  seus sonhos, justifica seus fracassos, acalma seus medos, confirma suas suspeitas e ajuda a jogar
  pedras nos seus inimigos**."* Molde emocional de VSL/advertorial de altíssima conversão. _[blairwarren]_
- **Robert Collier — "entre na conversa já existente na mente do prospect":** a peça que converte
  endereça a preocupação/desejo que JÁ está rodando na cabeça dele (casa com awareness de Schwartz). _[breakthroughmarketingsecrets]_
- **Psicologia de preço (sistema):** **Decoy Effect / dominância assimétrica** (3ª opção faz uma das
  2 parecer melhor — usar em order bump/pricing table), **charm pricing** ($9,99 = left-digit bias),
  **anchoring** (mostrar preço alto antes), **risk-reversal** (garantia tira o medo de perda). _[simon-kucher / capitaloneshopping]_
- **Encode:** `offer-doctor` ganha LF8 + pricing-psychology; `persuasion-auditor` ganha a frase de Warren.

### 11.2 Copywriting/VSL — masters que faltavam 🔥🔥🔥
- **Great Leads — os 6 tipos de lead (Michael Masterson / Mark Ford + John Forde):** **Offer,
  Promise, Problem-Solution, Big Secret, Proclamation, Story** — como abrir QUALQUER mensagem de venda,
  escolhido pelo nível de consciência. **O framework de abertura de VSL que faltava** (Schwartz diz o
  estado; Great Leads diz qual lead usar). _[markford.net / Great Leads]_
- **Jon Benson — o INVENTOR da VSL:** "reluctant hero formula" (herói relutante: o copy mostra que é
  igual à audiência + conta história dramática) + **3X VSL Formula** (>$1B gerado). Fonte canônica de
  estrutura de VSL. _[jonbenson.com / borntoinfluence]_
- **Encode:** `vsl-architect` escolhe entre os 6 leads por awareness + aplica reluctant-hero.

### 11.3 Email/backend — o arco que faltava 🔥🔥
- **Soap Opera Sequence (André Chaperon, *Autoresponder Madness*):** sequência de **7-10 dias** de
  onboarding com "personagem atraente" + open loops entre emails (ele fez $70k de uma lista de 1.000).
  → **Seinfeld emails (Ben Settle):** daily broadcast depois do SOS. O arco: **SOS → daily Seinfeld**.
  _[joshthecopywriter / marketingsecrets]_
- **Por que importa:** backend/lista é onde o afiliado captura o lead que não comprou na 1ª e monetiza
  de novo — multiplica o EPC sem mais tráfego. **Encode:** skill `email-arc` (SOS + Seinfeld).

### 11.4 Google Ads — meta moderno que faltava 🔥🔥🔥
- **Broad match + Smart Bidding = o meta atual** (62% dos que usam Smart Bidding usam broad como
  match principal) — com conversão bem medida, broad dá mais alcance dentro da meta. _[Google / searchscientists]_
- **Value-Based Bidding (tROAS):** trocar tCPA→tROAS = **+14% conversion value** no mesmo ROAS médio. _[optmyzr]_
- **Seasonality Adjustments:** prever mudança de conv-rate em **eventos de 1-7 dias** (sale, lançamento);
  **erro comum = calibrar pra conv-rate em vez do CPC esperado** → drena budget. _[Google / jumpfly]_
- **Data Exclusions:** mandar o Smart Bidding **ignorar dias com tracking quebrado** (estenda a janela
  pelo seu conversion-delay) — crítico pra afiliado quando o postback cai. _[growmyads]_
- **Estruturas nomeadas:** **Alpha/Beta** (3Q Digital: Alpha = exatas vencedoras / Beta = broad de
  descoberta com as Alpha como exact-negatives) + **STAG** (single-theme ad group, sucessor do SKAG)
  + **n-gram analysis** de search terms pra minerar/agrupar. _[jordandigitalmarketing / datafeedwatch]_
- **Audiências como sinal:** **RLSA** (cart/abandoners), **Customer Match**, **Custom/In-market/Affinity
  segments** por tema. _[Google / leadsbridge]_
- **Encode:** `bidding-strategist` (broad+VBB+seasonality+data-exclusions), `account-structurer` (Alpha/Beta+STAG+n-gram), `keyword-intent-mapper` (audiences).

### 11.5 Tracking/atribuição — stacks que faltavam 🔥🔥🔥
- **Atribuição pós-iOS14:** **Hyros** (matching determinístico, high-ticket/long-cycle), **TripleWhale**/
  **Polar** (DTC <$50k/mo, MTA, UI limpa), **Wicked Reports** (plan-based, Meta CAPI), **Northbeam**
  (first-party server-side). **GA4 é suplementar, não fonte primária** (last-click, perde view-through,
  ATT corta cobertura). Parear sempre com **CAPI**. _[adlibrary / admanage]_
- **Consent Mode V2** (obrigatório na EU) alarga o gap de dados → reforça first-party/server-side. _[tracklution]_
- **Encode:** `tracking-setup` recomenda stack por ticket/volume; `conversion-pipeline` assume CAPI+server-side.

### 11.6 Claude Code — automação que faltava (LIGA À SUA CONTA REAL) 🔥🔥🔥 🧠
- **Google Ads MCP para Claude** — o salto além da skill de conhecimento: conecta o Claude Code à sua
  **conta Google Ads de verdade**.
  - **Oficial do Google** (read-only): `list_accessible_customers`, `search` via **GAQL**,
    `get_resource_metadata`. _[developers.google.com/.../mcp-server]_
  - **`cohnen/mcp-google-ads`** (GitHub) + **Composio** — conectam Ads ao Claude/Cursor por linguagem natural. _[github / composio]_
  - **Write-access draft-first** (cria draft, nada vai ao ar sem aprovação): **Windsor**, **Markifact**,
    **AdKit** — criar campanha, ajustar budget/lance, negative keywords, editar ad. _[markifact / adkit]_
- **Por que é o item nº1 de "automatizar":** transforma a skill `affiliate-google-ads-operator` de
  conselheiro em **operador que lê métricas reais e propõe/aplica mudanças** (com sua aprovação).
- **Encode:** adicionar um MCP server de Google Ads ao `~/.claude.json` (precisa OAuth + developer
  token). **Próximo passo concreto se você quiser ligar.**

**Fontes (passada 4 / auditoria):**
[markford — Great Leads](https://www.markford.net/great-leads/) ·
[Jon Benson — what is a VSL](https://jonbenson.com/what-is-a-vsl/) ·
[Cashvertising LF8](https://www.enchantingmarketing.com/8-life-forces/) ·
[Blair Warren — One Sentence Persuasion](https://blairwarren.com/one-sentence-persuasion/) ·
[Robert Collier — enter the conversation](https://www.breakthroughmarketingsecrets.com/blog/enter-the-conversation-in-your-prospects-head-robert-collier-roy-furr/) ·
[André Chaperon — Soap Opera Sequence](https://joshthecopywriter.com/soap-opera-sequence/) ·
[Google — broad match + Smart Bidding](https://support.google.com/google-ads/answer/10195720?hl=en) ·
[optmyzr — value-based bidding](https://www.optmyzr.com/blog/value-based-bidding-guide/) ·
[Google — seasonality adjustments](https://support.google.com/google-ads/answer/10369906?hl=en) ·
[growmyads — data exclusions](https://growmyads.com/google-ads-data-exclusions/) ·
[Jordan DM — Alpha-Beta evolved](https://www.jordandigitalmarketing.com/blog/google-ads-evolved-the-alpha-beta-process-has-to-evolve-too) ·
[adlibrary — attribution tools compared](https://adlibrary.com/posts/meta-ad-attribution-tracking-tool) ·
[Google — Ads MCP server (primary)](https://developers.google.com/google-ads/api/docs/developer-toolkit/mcp-server) ·
[cohnen/mcp-google-ads](https://github.com/cohnen/mcp-google-ads) ·
[Composio — Google Ads MCP for Claude Code](https://composio.dev/toolkits/googleads/framework/claude-code)

---

## 12. CRIAÇÃO DE PÁGINAS DE AFILIADO (build craft) 🔥🔥🔥 🧠

> Gap fechado (passada 5). As passadas 1-4 cobriam a COPY/persuasão da página; isto é o **CRAFT de
> construir a página que converte**. Fontes de busca verificadas. `🧠` = vira skill/subagent.

### 12.1 Message match / Ad Scent — a alavanca nº1 de conversão de página 🔥🔥🔥
**O que é:** congruência ad→página em 4 dimensões — **visual match** (design ecoa o criativo),
**message match** (o H1 espelha a headline do anúncio), **information scent** (mesmas keywords/dores
visíveis no 1º viewport), **tone consistency** (parece escrito pela mesma pessoa do anúncio).
**Por que é poderoso:** rodando 5 campanhas pra mesma página, a que tem ad mais alinhado **converte
mais**; a que promete algo que a página não cumpre converte menos. **68% dos anunciantes mandam
tráfego pago pra home genérica** — erro que joga conversão fora. _[adalign / cxl / digitalmarketer]_
**Encode:** `policy-guard`/`bridge-builder` valida message-match ANTES de subir (H1 = headline do ad?).

### 12.2 Anatomia da página de alta conversão 🔥🔥🔥
- **Above-the-fold (3-5s pra convencer):** value-prop **específico e numérico** ("corte o relatório
  semanal de 4h pra 15min", não "otimize seu fluxo") + CTA claro + screenshot/demo/vídeo. _[cxl / analyticsliv]_
- **CTA:** **2-4 colocações** (topo/meio/fim), action-focused; **sem cor mágica — regra é CONTRASTE**
  (site azul → botão laranja). _[lairedigital]_
- **Trust abaixo do hero (antes de rolar):** logos, star-rating, métrica-chave, depoimentos → mata a
  ansiedade "isso é legítimo?". _[cxl]_
- **5 alavancas de maior impacto:** (1) headline = anúncio (message match), (2) **menos campos** no
  form, (3) load **<2,5s**, (4) prova social **perto do CTA**, (5) **1 objetivo de conversão por
  página**. _[apexure]_
- **Benchmark:** mediana de conversão de landing **6,6%** (Unbounce Q4 2024); **>10% = top quartile**.
- **Encode:** `page-architect` gera/audita a página por esses blocos.

### 12.3 Quiz funnel — a página de captura mais poderosa hoje 🔥🔥🔥
- **Conversão:** quiz funnels veem **30%+** (vs média de site 2-3%); **~40,1%** de quem começa o quiz
  vira lead. _[personizely / typebot]_
- **Mecânica:** capture o lead **cedo** — opt-in (email/telefone) **antes de revelar o resultado**
  (o usuário quer ver a resposta personalizada → entrega o contato). **Exit-intent popup** recupera
  quem abandona ("Complete pra desbloquear 20%"). _[popupsmart]_
- **Tamanho:** **3-7 perguntas = 65-85% de conclusão**; máx 7-10 com promessa clara no título. _[perspective]_
- **Ferramentas:** ScoreApp, Typeform, involve.me, ConvertFlow, Perspective.
- **Encode:** `quiz-funnel-builder` (gera quiz por ângulo de oferta + pontos de captura).

### 12.4 Arquitetura de funil — Value Ladder (Russell Brunson, *DotCom Secrets*) 🔥🔥🔥
- **Value Ladder:** sequência de ofertas ascendente (grátis/barato → high-ticket); levou a ClickFunnels
  de 0→$10M/ano em 1 ano. _[fuelyourdigital]_
- **Tipos de página:** **Squeeze page** (1 objetivo: email, sem navegação/distração) → **Sales page**
  → **Order Bump** (checkbox no checkout, margem alta) → **OTO/One-Time Offer** → **Upsell/Downsell**.
  Tráfego controlado (ads) → squeeze; não-controlado (blog) → captura no topo. _[clickfunnels / dansilvestre]_
- **Pro afiliado:** você constrói **advertorial/quiz/squeeze** (suas páginas) e entrega pro checkout da
  oferta; o backbone do funil é seu (captura + pré-venda), o produto é do produtor.
- **Encode:** `funnel-architect` desenha o funil (advertorial → squeeze → oferta) + value ladder.

### 12.5 Velocidade / Mobile / Core Web Vitals 🔥🔥🔥 (afeta conversão E Quality Score)
- **Velocidade converte:** página **<2s converte +47%**; Vodafone teve **+8% vendas** com LCP **31%
  melhor** (página visualmente idêntica). _[conductor / web.dev]_
- **Mobile manda:** **83% do tráfego é mobile**, mas converte ~**metade** do desktop; **53% abandonam
  mobile que carrega >3s**. → mobile-first + velocidade é obrigatório. _[tuffgrowth]_
- **CWV (LCP/INP/CLS)** também alimentam o **landing-page experience** do Quality Score (CPC menor).
- **Encode:** `page-architect` checa peso/LCP e mobile; usar builder rápido (Convertri/Carrd).

### 12.6 A/B testing de página — metodologia 🔥🔥
**Measure** (CWV + GA4 + session replay) → **Diagnose** (mapear contra categorias) → **Prioritize**
(esforço × lift esperado) → **Test** (sample size adequado, server-side pra não pesar) → **Deploy**
(implementar vencedor). Teste **um elemento de alto impacto por vez** (headline, hero, CTA, oferta). _[apexure]_
**Encode:** `cro-tester` prioriza hipóteses por ICE/PIE e desenha o teste.

### 12.7 Page builders / ferramentas 🔥🔥
| Builder | Forte em | Preço (ref) |
|---------|----------|-------------|
| **ClickFunnels** | funis complexos (bridge + upsell/downsell), líder | $97 / $297/mo |
| **Systeme.io** | all-in-one (funil+email+afiliado), barato | free / $27/mo |
| **Convertri** | **velocidade** de página (LCP), drag-drop | acessível |
| **OptimizePress / FunnelKit** | WordPress | plugin |
| **Carrd** | landing simples ultrarrápida | ~$19/ano |
| Leadpages / Kartra / Builderall / Swipe Pages / GetResponse | alternativas | varia |
- **Regra pra afiliado/VSL:** priorize **velocidade** (Convertri/Carrd) — LCP é conversão + QS.
- **Encode:** `page-architect` recomenda builder por necessidade (velocidade vs funil complexo).

**Fontes (passada 5 — criação de página):**
[cxl — anatomia de landing](https://cxl.com/blog/how-to-build-a-high-converting-landing-page/) ·
[apexure — CRO best practices](https://www.apexure.com/blog/landing-page-optimization-best-practices-with-examples) ·
[lairedigital — CRO checklist](https://www.lairedigital.com/blog/the-only-cro-checklist-you-need-for-landing-pages-that-convert) ·
[adalign — ad-to-page congruence](https://www.adalign.io/articles/why-ads-get-clicks-but-landing-pages-dont-convert) ·
[cxl — ad scent](https://cxl.com/blog/give-your-advertising-roi-a-serious-boost-by-maintaining-scent/) ·
[personizely — quiz funnel](https://www.personizely.net/blog/quiz-funnel) ·
[typebot — quiz funnel examples](https://typebot.com/blog/quiz-funnel-examples) ·
[perspective — quiz software](https://www.perspective.co/article/quiz-funnel-software) ·
[fuelyourdigital — value ladder](https://fuelyourdigital.com/post/russell-brunson-value-ladder-explained-sales-funnel-framework/) ·
[clickfunnels — funnel types](https://www.clickfunnels.com/blog/types-of-sales-funnels/) ·
[conductor — page speed case studies](https://www.conductor.com/academy/page-speed-resources/) ·
[web.dev — CWV business impact](https://web.dev/case-studies/vitals-business-impact) ·
[markinblog — funnel builders](https://www.markinblog.com/funnel-builder-for-affiliate-marketing/)

---

## 13. CRIATIVO DE VÍDEO, VERTICAL, TESTE, SOBREVIVÊNCIA & EMAIL (passada 6)

> Gaps achados ao perguntar "tem algo que falta?". Os maiores pro operador de VSL no YouTube/Google.

### 13.1 Criativo de VÍDEO pro YouTube — o anúncio que para o skip 🔥🔥🔥 🧠
- **Estrutura do anúncio (não confundir com a VSL inteira):** **Hook (0-5s)** para o skip → **Amplify
  (5-20s)** aprofunda dor/desejo → **Bridge (20-45s)** entra o produto + prova → **CTA (10-15s final)**. _[jetfuel / adcrafty]_
- **Primeiros 2s SEM logo/marca/produto** (sinaliza "ad" → gatilho de skip); abra com pergunta ousada,
  visual impactante ou dor relatável. Decisão de skip acontece nos **primeiros 5s** (VSL: 3s). _[creatify]_
- **UGC-style bate polido:** handheld, 1ª pessoa, edição mínima — "nativo" não dispara ad-blindness;
  criativo nativo supera vídeo landscape reaproveitado em **até 3×** de completion. _[digitalapplied]_
- **Retenção:** **double hook** (2º pico de curiosidade em 5-10s contra o drop), mudança visual a cada
  poucos segundos, **pattern interrupt no 4s reduz skip 15-25%**. DR com arco de persuasão claro
  converte **2-3×** mais que awareness. _[jetfuel]_
- **Encode:** `video-ad-architect` escreve o roteiro do ANÚNCIO (hook→amplify→bridge→CTA) + checklist de retenção.

### 13.2 IA de criação de vídeo/criativo 🔥🔥🔥 🧠
| Ferramenta | Forte em | Preço (ref) |
|---|---|---|
| **HeyGen** | **avatares multilíngue + lip-sync** (ótimo p/ VSL em PT-BR), corporativo | free / $24 / $149/mo |
| **Arcads** | **1.000+ atores IA + vozes ElevenLabs**, batch UGC p/ testar **50+ hooks/semana** (feito p/ DR) | ~$110/mo (~$11/vídeo) |
| **Creatify** | **URL→vídeo** (cola a página → gera variações em lote), ecommerce | varia |
| **Runway / ElevenLabs** | vídeo cinematográfico / voz IA | varia |
- **Por que é poderoso:** o gargalo de escala no YouTube é **volume de criativo**; IA permite testar
  dezenas de hooks/ângulos por semana — a velocidade de teste (item 5.2) destravada. **HeyGen
  multilíngue** é alavanca direta pro operador BR (VSL em português com avatar).
- **Encode:** `creative-pipeline` usa esses tools p/ gerar N variações por ângulo.

### 13.3 Compliance por VERTICAL (nutra/health/finance) + FTC ⚠️🔥🔥🔥
- **Nutra** (supplements/weight-loss/men's health/skin/vitaminas): mercado **$458B (2024) → $986B (2032)**
  — o vertical clássico de VSL. _[richads / clickbank]_
- **FTC (EUA):** health claims precisam de **substanciação científica**; testemunho com resultado mais
  dramático que o típico = **enganoso**, e **"resultados não típicos" NÃO cura o engano** — precisa
  **disclosure clara do resultado que o consumidor típico obtém**. (Teami: multa **$15.2M**.) _[ftc.gov]_
- **Plataforma:** weight-loss/fertility/telehealth exigem **autorização FDA/EMA**; proibido prometer
  corpo irreal ou cura sem evidência reconhecida. _[influencermarketinghub]_
- **Ângulo vencedor:** estude a VSL/criativos/email-swipes da oferta que JÁ ganha — *qual o mecanismo
  único? que dor agita? que transformação promete?* (liga ao `offer-spy`, item 6.3). _[clickbank]_
- **Encode:** `policy-guard` ganha camada por-vertical (claims de saúde/finanças + disclosure FTC).

### 13.4 Matemática de teste/decisão — quando matar/escalar 🔥🔥🔥 🧠
- **Significância:** decida só com **≥95% de confiança** (5% de chance de ser ruído). _[convert / vxtx]_
- **Sample size:** mín **~50 conversões/variação** + **7 dias**; pra detectar lift de **20%** precisa
  **~400 conv/variante**, lift de **10%** precisa **~1.600/variante**. _[thread-transfer]_
- **Regras:** vencedor com 95% → aplique em **3-5 dias** (atraso destrói ganho composto); perdedor/
  inconclusivo → descarte e teste a próxima hipótese. **Nunca corte o teste cedo** (= agir em ruído). _[dataslayer]_
- **Encode:** `cro-tester`/`scale-operator` calcula se o teste atingiu significância antes de decidir.

### 13.5 Sobrevivência de conta — o que mantém você no ar ⚠️🔥🔥🔥 (fonte primária Google)
- **Circumventing Systems = pena de morte:** suspensão **na detecção, SEM aviso, banido pra sempre** —
  inclui cloaking, redirects enganosos, **relações entre contas, e re-entrar com conta nova após
  enforcement**. _[Google adspolicy 15938075 — primary]_
- **Multi-conta após suspensão = ban permanente em todas:** o Google liga as contas por **payment, IP,
  email, domínio** — conta "limpa" nova é conectada às violações antigas. _[jupplee]_
- **Agência/MCC:** muita suspensão vem de **mismatch de billing** entre MCC e conta do cliente. _[almcorp]_
- **Realidade 2024:** Google fez +50 updates de IA/enforcement; suspensões **+300% YoY** (**39M contas**
  suspensas). A barra subiu — compliance É a infra. _[almcorp]_
- **Appeal:** **6 meses** pra apelar; **appeal documentado = 85-90% de sucesso** vs genérico **<30%**.
  → guarde evidência (página compliant, substanciação, screenshots) ANTES de precisar. _[Google adspolicy 9841640 — primary]_
- **Encode:** `policy-guard` bloqueia qualquer tática de circumventing; `appeal-builder` monta appeal documentado.

### 13.6 Email deliverability (pro arco SOS→Seinfeld chegar na inbox) 🔥🔥
- **Autenticação obrigatória:** **SPF + DKIM + DMARC** (Gmail exige p/ bulk desde **01/02/2024**;
  Outlook desde **05/05/2025**). Full auth = **2.7× mais inbox**; faltar DKIM custa **10-15%**. _[smartlead / trulyinbox]_
- **Warmup:** **2-3 semanas** (3-6 ideal) por domínio/mailbox — comece com **3 emails/dia**, suba 2-3/
  dia, **cap 30/dia**; constrói reputação. _[superhumanprospecting]_
- **Métricas:** bounce **<2%**, hard bounce **≤1%**, spam complaints **<0.1%**, volume diário consistente. _[mailead]_
- **Encode:** `email-arc` inclui o setup de deliverability antes de disparar a sequência.

**Fontes (passada 6):**
[jetfuel — YouTube ads guide](https://jetfuel.agency/youtube-ads-guide-2026/) ·
[creatify — YT ads that convert](https://creatify.ai/blog/youtube-ads-how-to-create-video-ads-that-convert-in-2026) ·
[adcrafty — short-form VSL](https://adcrafty.ai/blog/short-form-vsl-ads-social-media-2026) ·
[HeyGen — best AI video tools](https://www.heygen.com/blog/best-ai-video-tools-marketing-ads-2026) ·
[vidau — HeyGen vs Arcads](https://www.vidau.ai/heygen-vs-arcads-the-best-ai-video-ad-tool-right-now/) ·
[FTC — health products compliance](https://www.ftc.gov/business-guidance/resources/health-products-compliance-guidance) ·
[ClickBank — nutra vertical](https://clickbank.com/blog/health-fitness-affiliate-marketing/) ·
[convert — statistical significance](https://www.convert.com/blog/a-b-testing/statistical-significance/) ·
[thread-transfer — A/B sample size](https://thread-transfer.com/blog/2025-08-03-meta-ads-ab-testing/) ·
[Google — circumventing systems (primary)](https://support.google.com/adspolicy/answer/15938075?hl=en) ·
[Google — suspensions overview (primary)](https://support.google.com/adspolicy/answer/9841640?hl=en) ·
[almcorp — suspensions/appeals](https://almcorp.com/blog/google-ads-account-suspensions/) ·
[smartlead — deliverability guide](https://www.smartlead.ai/blog/email-deliverability-guide) ·
[trulyinbox — SPF/DKIM/DMARC impact](https://www.trulyinbox.com/blog/spf-dkim-dmarc-email-deliverability/)

---

## Apêndice — Log da pesquisa

- **Backbone** escrito de cânone consagrado (alta confiança, não-contencioso).
- **deep-research task `w34yb217p`** rodando: vai preencher os `🔬 enrich`, adicionar itens
  não-óbvios/atuais (2024-2026), e anexar fontes citadas + um shortlist verificado.
- Próximas passadas planejadas se a 1ª não esgotar: deep-dives por domínio (Google Ads policy
  atual; Demand Gen 2025; OCI/Enhanced Conversions passo-a-passo; spy tools).
