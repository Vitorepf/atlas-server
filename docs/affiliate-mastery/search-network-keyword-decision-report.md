# Relatório completo — Decisão de palavra-chave na Rede de Pesquisa (afiliado VSL)

> **Critério-mãe (operador):** a keyword traz as pessoas. Gente errada = **GASTO** (proibido). Gente certa = **INVESTIMENTO** (certeza antes de pôr dinheiro). Este relatório é o framework de decisão que separa os dois.
>
> Fontes: documentação oficial Google Ads + grandes artigos (WordStream/Search Engine Land/SEJ/Adtaxi/groas/Karooya) + obras canônicas (Perry Marshall *Ultimate Guide to Google Ads*; Brad Geddes *Advanced Google AdWords*; Eugene Schwartz *Breakthrough Advertising*) + prática de afiliado (iAmAttila, ClickBank, RedTrack). Fatos de mecânica do Google **re-verificados contra a doc oficial em 2025-2026**. Consolidado de 6 frentes de pesquisa + síntese de 5 lentes especialistas + os 2 pontos do operador.

---

## 0. O princípio em uma frase
Na rede de pesquisa, a keyword é o **único ponto onde você escolhe QUEM entra antes de pagar o leilão**. ~80% do resultado está decidido antes do anúncio. Anúncio e página amplificam; a keyword seleciona a urna. Por isso ela é a alavanca-mãe — e por isso a keyword errada não é "ineficiência", é **dinheiro queimado em gente que nunca ia comprar**.

---

## 1. Os 2 pontos do operador — confirmados e aprofundados

### 1.1 Keyword qualificada (atrair o certo / excluir o errado)
Confirmado. Mas a economia tem uma **assimetria** que muda a prioridade: sob budget cap (caso real do afiliado), **excluir o desqualificado rende MAIS que atrair o qualificado** — atrair soma 1 conversão; excluir devolve o CPC inteiro pro budget recomprar impressão na urna boa. E pior que o desperdício direto: tráfego informacional **envenena o aprendizado do Smart Bidding** (ensina o tCPA que conversão é rara → ele recolhe lance → mata até os termos bons).

### 1.2 Arbitragem de mecanismo ("Blue Salt Trick" / "Gelatin Trick") = menor CPA
Confirmado, e o porquê são **4 forças compostas** agindo juntas (não 1):
1. **Densidade de intenção** — quem digita o nome do truque é *most-aware*: já viu a VSL, voltou pra agir → CVR no teto.
2. **Vácuo competitivo** — vocabulário nativo da VSL; marca grande/clínica não dá lance → CPC baixo.
3. **Quality Score triplo-match** — keyword = headline RSA = H1 → qualidade auction-time alta → desconto de CPC.
4. **Flywheel de CTR** — match perfeito → CTR alto → qualidade sobe → CPC cai mais.

O **5:1 é multiplicativo, não aditivo**: `ROAS = CVR × payout / CPC`, com CVR no teto E CPC no chão ao mesmo tempo. Decomposição honesta do R$200→R$1000: com payout ~$40-300, isso é CPA ~$60 → vem de **CVR estruturalmente alto que aguenta CPC caro**, não de CPC barato.

**Nuance crítica:** na busca de afiliado VSL, o termo de mecanismo é **retargeting disfarçado de prospecting** — colhe demanda que o YouTube/Meta criou. "A keyword é a alavanca #1" é verdade *dentro* da busca; a exposição que fabrica a busca-do-mecanismo é a alavanca a montante. Importa pra atribuição (§6).

---

## 2. A escada de intenção canônica (o ponto 2 do operador, formalizado)

**Erro a evitar:** tratar intenção como **um eixo só**. O sinal real são **3 eixos independentes**, e o lucro escondido (e as armadilhas) está onde divergem:

```
IntentScore = 100 × (0.45·jornada + 0.30·dor/urgência + 0.25·especificidade)
ValorDoClique = IntentScore × WTP_multiplier × MessageMatch    ← MULTIPLICATIVO
```
- **jornada** (conhecer → resolver) = o teu "o que é" vs "tratamento"
- **dor/urgência** ("rápido/agora/de vez/definitivo/não aguento") = tua regra "mais dor = paga qualquer preço" entra aqui como **multiplicador de disposição-a-pagar (WTP)**, não como intenção
- **especificidade** (genérico → mecanismo/branded) — e especificidade **estreita a distribuição de intenção**, que é o que o Smart Bidding precisa
- **MessageMatch** = a query ecoa o ângulo da VSL? (multiplicador, não somando)

### A escada (decisão comprar/excluir)
| Tier | Degrau | Padrão (ex. disfunção erétil) | Schwartz | Ação |
|---|---|---|---|---|
| **T4** | Mecanismo / branded | "blue salt trick", "[produto] funciona/review" | Most-aware | **HERÓI — exact, isolar, lance máximo** |
| **T3** | Tratamento + urgência | "como acabar com X rápido", "tratamento definitivo" | Solution-aware quente | **Comprar — escala primária** |
| **T2** | Solução genérica | "tratamento para X", "remédio para X" | Solution-aware | Comprar — phrase, negativas densas |
| **T1** | Sintoma / dor nomeada | "X não passa", "não consigo" | Problem-aware | **Sweet-spot de VOLUME** se a dor é nomeada; lance baixo + bridge forte |
| **T0** | Informacional / Know-Simple | "o que é X", "causas de X" | Unaware | **Excluir / negativar** |

**Cortes:** IntentScore < 45 → exclui; > 70 → escala; 45-70 → testa controlado.
**Lei de ferro:** desça a escada (T4→T3→T2) só na velocidade que o **ROAS marginal** permite; pare quando o CPA marginal estoura o alvo. Subir pra T0/T1 nunca é "mais escala" — é diluição que mascara a morte da campanha.

### A verdade que refina a escada (pesquisa Frente 3)
- **A taxonomia-raiz não são os "4 tipos" de blog** (informational/navigational/commercial/transactional) — é a do **Google Quality Rater: Know / Know-Simple / Do / Website / Visit-in-person**, e classifica a **query, não a pessoa**. O eixo que importa pra venda é **Know → Do**. *(Search Quality Rater Guidelines, oficial)*
- **O modelo de 4-tipos REJEITA a melhor keyword da VSL:** ele rotula "como parar de sentir fome à noite" como *informational/lixo*, mas em Schwartz é **Problem-Aware = o motor de volume da VSL**. A decisão certa cruza os dois eixos: aceite informacional SE for Problem/Solution-Aware com dor nomeada; rejeite SE for Know-Simple/Unaware-vago. *(Schwartz × query, Serpstat/Breakthrough Advertising)*
- **Teto duro da classificação só-por-texto: ~74% de acerto** vs humano (Jansen, Booth & Spink, 1,5M queries, IP&M 2008). ~1 em 4 keywords recebe rótulo errado pelo texto. O rótulo "transactional" da ferramenta é **prior fraco**, não evidência pra liberar budget. **A SERP ao vivo é o oráculo** (o que o Google ranqueia AGORA prova a intenção). *(dl.acm.org/10.1016/j.ipm.2007.07.015)*
- **Intenção é DISTRIBUIÇÃO, não rótulo.** O Google serve SERP de "intenção mista" de propósito quando não há intenção dominante. Head-terms curtos ("emagrecer", "glicose") são Unaware pra uns e Most-Aware pra outros → a keyword fraturada **divide o budget entre jornadas e nenhuma converte**. Prefira a long-tail específica (distribuição estreita) mesmo com menos volume. *(Backlinko regra anti-hedge; RankDots)*
- **Modificadores não são monolíticos:** "review/funciona/vs" = comparador cético, geralmente **CONTRA o afiliado** (quer prova-de-terceiro, não a VSL); "how to" só é alta-intenção se nomear dor + protocolo acionável ("how to [resultado] at home fast"); "best [categoria]" vai pra listicle, não pra VSL; "near me/coupon/price" são enganosos pra VSL. **Classifique pelo PAR (modificador × estágio-Schwartz × tipo-de-oferta), nunca pelo modificador isolado.** *(Tomba/Ahrefs/Backlinko)*

---

## 3. Match types & estrutura de conta (2024-2026) — a maior atualização vs. o senso comum antigo

> ⚠️ **Tudo abaixo foi re-verificado na doc oficial 2025-2026.** Google Ads mudou muito; a intuição da era "broad-match-modifier" está morta.

- **Quality Score NÃO é input do leilão.** A doc oficial (6167118) diz literal: *"Quality Score is not an input in the ad auction"* — é diagnóstico. O leilão usa **qualidade auction-time** (expected CTR + ad relevance + landing page experience) recalculada a cada busca (Ad Rank, 1722122). Consequência: **não otimize o número 1-10 do painel (Goodhart); otimize o match real** termo↔anúncio↔página. A keyword é a **alavanca de PREÇO**, não só de público (keyword qualificada → qualidade alta → CPC menor).
- **Hierarquia oficial de priorização** (2756257): exact idêntica à query **>** phrase/broad idêntica (mudança de 2021) **>** relevância-AI do ad group **>** Ad Rank. **Keywords do mesmo domínio NÃO competem entre si.** → Pra a keyword de mecanismo herdar a **bridge certa**, ela tem que estar no ad group cuja LP e keywords-irmãs reforçam o mecanismo. **Estrutura temática apertada não é estética — é o que garante a LP certa.**
- **"Exact" hoje casa por SIGNIFICADO/INTENÇÃO, não literal** (9342105): `[yosemite camping]` casa "campsites in yosemite" mas NÃO "yosemite hotel" (intenção divergente quebra o match). Bom (puxa paráfrases do mesmo mecanismo, mais volume da raiz-própria) e perigoso (o Google decide o que é "mesma intenção"). **A intenção é a unidade de match, não a string** → a keyword tem que **encarnar** a intenção de compra/mecanismo de forma inequívoca.
- **Broad é o DEFAULT em campanhas novas desde jul/2024** (7478529) e *"é crítico usar Smart Bidding com broad match"*. A expansão usa sinais **além da keyword**: histórico do usuário, **conteúdo da SUA landing page**, e as **outras keywords do ad group** → uma bridge bem-casada PUXA o broad pra mecanismo adjacente (volume além do termo perecível); uma LP genérica puxa pra lixo. Google reporta +10% com broad+Smart Bidding. **Mas é recomendação CONDICIONAL** (só com bidding inteligente + sinal de conversão bom por baixo).
- **Phrase virou a armadilha do meio:** quase tão larga quanto broad, mas **sem a IA do broad pra filtrar** — "pior dos mundos". A escolha real de 2025 é **(a) broad + Smart Bidding + negativas fortes** OU **(b) exact cirúrgico**. *(WordStream query-matching 2025)*
- **O LIMIAR QUE DECIDE TUDO: ~30 conversões/mês (tCPA) / ~50 (tROAS).** Abaixo disso o Smart Bidding **não aprende a distinguir curiosidade de compra** e entra em **modo exploratório que queima 30-50% do budget**. Afiliado VSL tipicamente começa abaixo do limiar → **começa exact/phrase controlado, NÃO broad**. Broad sem volume de conversão + sem disciplina de negativas = "loteria paga". *(Adtaxi, y77, doc Smart Bidding 7065882)*
- **A falha mais cara do broad não é tráfego irrelevante — é otimizar pra CONVERSÃO ERRADA.** Se a meta é rasa (opt-in/lead em vez de VENDA), o algoritmo acha o jeito mais barato de bater ela: curiosos que dão o email e somem. **Dashboard fica verde enquanto o dinheiro vaza.** O sinal de conversão TEM que ser a venda real. *(DigiWebInsight, y77)*
- **SKAG vs STAG já se resolveu — mas pela razão oposta à antiga.** Não é mais "relevância de anúncio"; é **alimentar o Smart Bidding com dado**. SKAG fragmenta o dado → consenso virou STAG (3-20 keywords temáticas). **MAS** o termo de mecanismo nomeado é EXATAMENTE o caso "alto-valor/nicho/controle preciso" onde a estrutura granular antiga ainda ganha — **isole-o, não o dilua**. *(Search Engine Land 2024; ABCs of Account Structure oficial 14752782)*
- **Estratégia BARBELL (síntese moderna):** núcleo **exact** (mecanismo + termos provados, bottom-funnel, CPC baixo, sinal limpo) **+** uma campanha **broad + Smart Bidding** só pra descoberta/escala, com **revisão diária** do search terms. Começar broad sem essas duas defesas = gasto. *(Adtaxi/DigiWebInsight)*
- **Peel-and-stick (Perry Marshall) exige negative sculpting:** ao descascar a keyword vencedora pra ad group dedicado, adicione-a como **negative exact no grupo de origem** — senão o grupo antigo rouba a impressão. O termo de mecanismo campeão merece esse tratamento de joia isolada. *(Hive Digital)*
- **80/20 fractal (Marshall/Larry Kim):** 20% das keywords geram 80% das conversões → isole os top-20% em campanha própria com bid próprio E **exclua-os das campanhas amplas** pra não competirem contra si. *(SEJ)*
- **Expected CTR é "de longe o fator mais importante do QS"** (Brad Geddes) — a única alavanca que o anunciante controla direto pra baixar o custo é o CTR, e CTR sobe quando o anúncio espelha a keyword. O mecanismo nomeado vence em CTR E relevância → compra o tráfego certo **mais barato**.
- **Keyword Planner é guia grosseiro, não verdade:** "volume médio" é faixa arredondada que **inclui close variants**; "competition low/med/high" mede **saturação de anunciantes, NÃO dificuldade de converter**; forecasts olham ~7-10 dias. Não descarte o mecanismo por "volume baixo" (mascarado em close variants); leia o **top-of-page bid** pra preço, não o competition index. *(3022575)*
- **Search Terms Report é parcialmente CEGO:** só mostra termos buscados por "número significativo de pessoas"; o resto fica oculto por privacidade → **parte do gasto do broad é invisível e não-negativável**. Argumento mecânico a favor de match apertado + ad group temático no dinheiro real. *(2472708)*

---

## 4. Negativas & economia de exclusão (o lado "gasto não pode")

> Negativa não é faxina — é **infraestrutura de roteamento de budget**. E ela é deliberadamente **mais burra** que a keyword positiva.

- **Negativa NÃO pega close variant, sinônimo nem plural** (2453972): negativo "flowers" deixa passar "red flower" (singular). Você tem que **enumerar as formas à mão** (job/jobs/career/careers/salary/vacancy). Só pega **typo + casing** automaticamente (desde jun/2024). → A polaridade-negativa ("scam/side effects/cancelar") precisa virar **LISTA de variações**, não um termo só.
- **Match types da negativa invertem a intuição:** negativa **broad** (todos os termos, qualquer ordem) é a **mais forte**; negativa **exact** (query idêntica, sem palavra extra) é a **mais fraca** (deixa passar tudo com palavra a mais). → Junk universal vai em **negativa broad de 1 palavra**; exact/phrase só quando o termo isolado também é keyword válida que você quer manter.
- **Furo das 16 palavras:** negativa depois da 16ª palavra da query não bloqueia. Raro em busca de afiliado (queries curtas), mas real.
- **Arquitetura de exclusão em 3 camadas (limites 2025):** **conta** (≤1.000, junk universal: free/jobs/diy/torrent/reddit) → **listas compartilhadas** (20×5.000, por nicho/eixo: freebie-seeker, student, competitor) → **campanha** (≤10.000, polaridade de intenção específica). *(11396330)*
- **Negativa é aplicada ANTES da expansão de IA** → ela **PROTEGE** o Smart Bidding (define o espaço onde o broad opera), não atrapalha. Com broad default, **junk + concorrente como negativa é OBRIGATÓRIO antes de ligar**. *(Karooya AI Max; broad-match-control SEL)*
- **Mas over-block STARVA o aprendizado:** estreitar demais o funil mata o volume mínimo de conversão. Regra do equilíbrio: **bloqueie o que ENVENENA** (informacional, free, scam, concorrente); **resista a bloquear o ambíguo/mid-intent** — deixe o Smart Bidding decidir o cinza.
- **Cadência de mineração por gatilho ESTATÍSTICO, não calendário:** review semanal em gasto alto; n-gram (1/2/3-grama) agrega o sinal onde o termo isolado é estéril por falta de dado (ex.: nenhuma busca com "lifting" gastou >$13, mas o 1-grama "lifting" = $73 com zero conversão → corta). Recupera 10-20% do gasto não-brand em 90 dias. *(Wpromote/Adalysis/groas)*
- **GATE ANTI-CAMPEÃ (materializado):** antes de promover qualquer n-grama a negativa, **cross-check contra os termos que JÁ converteram** — se o candidato aparece em qualquer query campeã, é **proibido como negativa broad** (o termo de mecanismo pode CONTER uma palavra que parece junk). *(Karooya/groas)*
- **Brand exclusion ≠ negativa:** "bad query → negative keyword; bad destination → URL exclusion; bad cannibalization → brand exclusion". Marca de concorrente entra como **brand exclusion** (escala, cobre variação/idioma) + variações comuns como negativa.
- **Listas de junk por eixo (prontas, enumerar formas):** *baseline conta* (free, cheap, jobs, careers, diy, tutorial, reddit, quora, youtube, torrent, download, sample, course); *freebie* (free, coupon, discount code, cashback, cheapest, used); *prova social* (vs, review, comparison, reddit); **polaridade negativa crítica p/ saúde** (scam, complaints, lawsuit, "side effects", "does it work", refund, "is it safe", fake); *student* (assignment, thesis, calculator, "free tool").

---

## 5. Medição, atribuição e a regra CORTAR vs MANTER (o coração de "investimento, não gasto")

> Aqui é onde "certeza antes de gastar" vira **número**, não gut-feeling.

- **A "rule of three" dá o corte EXATO pra keyword com zero conversões:** com 0 vendas em *n* cliques, você está 95% confiante de que o CVR real está abaixo de **3/n**. Amarrado ao breakeven da própria oferta: `CVR_min = CPC / payout`. Se payout=$40 e CPC=$1.20 → precisa CVR>3% → **n = 3/0.03 = 100 cliques**. **100 cliques com ZERO venda = 95% de certeza de que é gasto → CORTA.** (97%→3.51/n, 99%→4.61/n.) Vale só com zero conversões; 1 venda "de sorte" NÃO certifica. *(Rule of three, Wikipedia/John Cook)*
- **Heurística da indústria:** keyword que gasta **2-3× o CPA-alvo** (pra afiliado: 2-3× o **payout líquido**) sem converter = flag; 4-5× se ticket alto/ciclo longo. **Não confunda diagnosticar com cortar** — antes de pausar, audite 5 camadas (search-term sujo→negativa; message-match quebrado→QS; estrutura; oferta; preço). Pausar com <50 cliques ou <5 conversões = falso-positivo. *(Optmyzr/HawkSEM)*
- **Conversion lag é o viés que mais mata keyword boa:** a venda de VSL não acontece no clique (assiste 20-40min, sai, volta; janela padrão 30 dias). Julgar no dia 1-2 mede ROAS imaturo. **Meça SEU bake rate** (trackeie o ROAS de um dia até estabilizar; ex.: dia-1 300% → dia-7 525%, multiplicador 1.75×) e só decida quando chegar a ~95% do final. **Pausa prematura é o erro #1.** *(SavvyRevenue)*
- **PRÉ-CONDIÇÃO de tudo — sem importação de conversão, o afiliado decide CEGO:** a venda acontece na página do anunciante (que o afiliado não controla) → o Google não vê a conversão nativamente. Fluxo: capturar **GCLID** (ou WBRAID/GBRAID em iOS) na bridge → tracker (RedTrack/Voluum) recebe **postback S2S** do anunciante → **offline conversion import** de volta pro Google. Sem isso, NENHUMA keyword tem dado de venda e o tCPA aprende com lixo. **Mudança recente:** o caminho "só-GCLID" virou legado; o recomendado é **Enhanced Conversions for Leads** (GCLID + email/telefone hasheados) porque atribui mesmo quando o GCLID se perde. **A API `UploadClickConversions` está DEPRECADA em 15/jun/2026 → migrar pra Data Manager API.** Server-side tagging deixou de ser opcional. *(docs 15081888/7012522; RedTrack; CustomerLabs)*
- **Data-Driven Attribution é o default obrigatório desde mid-2023** (sem mínimo de dados; o gate de 300 conv/3.000 interações foi removido). Sob last-click, o mecanismo leva 100% do crédito e a keyword de descoberta vira "gasto puro"; sob **DDA o crédito é fracionado** → uma keyword que sempre aparece antes da conversão ganha crédito parcial. **Compare last-click vs DDA por keyword** pra achar a subvalorizada (assistente real) e **não cortar por engano quem alimenta a campeã**. Cuidado: DDA é caixa-preta e tende a dar mais crédito ao próprio Google → use pra ranquear keyword entre si; a **verdade de lucro é o EPC/postback do tracker**, não o número do Google. *(blog.google DDA; 6259715)*
- **Bayesiano resolve "decidir com pouca amostra" (e o mecanismo TEM pouca amostra):** o frequentista exige amostra fixa e pune olhar cedo (early-stopping infla falso-positivo). O Bayesiano permite decidir em qualquer ponto: modela CVR como **Beta(α,β)**, prior informado pelo CVR médio da conta (shrinkage mata o ruído de "vendeu 1 em 8 por sorte"), e corta quando **P(keyword abaixo do breakeven) > 95%**. É a versão rigorosa do flywheel "vendeu sobe / gastou desce": o peso é o **posterior Bayesiano**, não a contagem crua. *(VWO/Convert)*
- **EPC fecha o loop numa desigualdade:** `EPC = receita / cliques`. **Keyword é INVESTIMENTO sse Net EPC > CPC**; EPC < CPC = GASTO. Supera o tCPA porque o tCPA ignora o **payout** (duas keywords com mesmo CPA têm EPC opostos se uma pega o upsell e a outra não). Janela 7-30 dias / amostra estável. *(ClickBank/LanderLab)*

### A regra de decisão final (o AND de 3 gates)
Uma keyword só é declarada **"investimento" ou "gasto"** — nunca chute — quando passa nos **três**:
1. **Significância** — `n_cliques ≥ 3/CVR_breakeven` se zero vendas, OU o posterior Bayesiano cruzou o limiar.
2. **Atribuição** — comparou DDA vs last-click (não cortar assistente) E mede a venda REAL pelo postback, não pelo número do Google.
3. **Lag** — a janela de conversão da oferta fechou (ROAS bateu ~95% do bake).

---

## 6. As duas meta-verdades que reordenam tudo

**(A) O sinal de conversão é UPSTREAM de tudo.** O maior envenenamento do Smart Bidding não é keyword ruim — é **conversão ruim** (otimizar pra opt-in/page-view em vez de venda). Nenhuma quantidade de negativa conserta um sinal de conversão podre. Match rate ideal de offline conversion: 60-70%; abaixo de 40% o sinal é fino demais. **Ordem de prioridade: (1) sinal de venda honesto → (2) exclusão do desqualificado → (3) seleção do qualificado.** Sem o (1), o resto é chute caro. *(y77)*

**(B) Sobrevivência da conta = economia de conversão (não moralismo).** Conta morta = **CPA infinito**. Os landmines que matam a conta do afiliado de saúde (o nicho do operador — OT169/Mounjaboost é GLP-1 retatrutide):
- **Restricted drug terms** (176031): sem certificação **LegitScript**, o nome do fármaco (semaglutide/tirzepatide/**retatrutide**) NÃO pode aparecer em keyword, headline, copy nem landing. hCG e ephedra são proibidos de vez. Enforcement escala até **domain flagging** (bloqueia contas novas no mesmo site). → A keyword de **maior intenção** do nicho (o nome do fármaco) é a **mais perigosa pra conta**. Por isso a **"gelatin trick"/"blue salt trick" (mecanismo nomeado-mas-não-farmacológico) é tão valiosa: contorna o restricted-drug-terms precisamente porque não nomeia a droga.**
- **Brand bidding pode estar PROIBIDO por contrato do programa** (clawback retroativo + ban): a keyword de menor CPA vira **prejuízo líquido** se a comissão é clawed back depois da venda. **Antes de rodar keyword com o nome da oferta, ler a cláusula brand-bidding do programa.**
- **DKI landmine:** se uma keyword de marca está num ad group com Dynamic Keyword Insertion, o sistema injeta a marca trademarkada no headline → disapproval → suspensão. **Isole toda keyword de marca em ad group SEM DKI.**
- **"Unacceptable business practices" + "Circumventing systems"** suspendem **na hora, sem aviso, banido pra sempre.** O exemplo LITERAL do Google é "fingir que um atleta patrocina seu produto de emagrecimento" (= o device de celebridade/before-after). Bridge/advertorial é "gateway" por design; se o Googlebot vê ≠ o user vê = **cloaking = ban**. → message-match honesto na bridge é **defesa de policy**, não só de QS.
- **A janela de alta-intenção MAIS SEGURA: "[produto comercial] reviews/funciona"** — captura o most-aware tardio sem nomear a droga nem injetar marca no copy; frequentemente o melhor par intenção × segurança × CPA. (Cheque a cláusula brand-bidding; a landing precisa parecer review de experiência real, não redirect óbvio.)

**Princípio Atlas (constituição):** o motor **conhece e constrói** todas essas mecânicas agressivas sem freio; sobre os landmines ele **expõe o risco de morte-de-conta como SINAL de conversão ao operador** (conta morta vende zero), e o operador decide rodar ou não, por cima. Risco de policy = diagnóstico que ajuda a vender (mantém CPA finito), **não** gate moral.

**(C) Limite do spy:** NENHUM spy tool mostra a keyword list de search de um concorrente (Transparency Center/SpyFu/Adplexity **inferem**, não revelam). Spy serve pra achar a **oferta/ângulo** que escala e **ripar a bridge/VSL** vencedora — a keyword de menor CPA você descobre no SEU próprio search-terms report. *(iAmAttila/SpyFu)*

---

## 7. Como isso bate no motor Atlas (gaps de construção — não construir ainda)

Já codificado (~70-75%): raiz própria × modificador, arbitragem de mecanismo (`QualifiedKeywordPatternEngine` tier 1 "gelatine trick generalized"), produto-nunca-bidado (KEEP/KILL), negativas hardcoded + `SearchTermWasteMiner` + `NegativeListMiner`, escada parcial (`intentClass` 100/85/65/45/10), flywheel (`KeywordLearningLoop`). Gaps que esta pesquisa expõe, em ordem de alavancagem:

1. **Sinal de venda real (offline conversion) é PRÉ-CONDIÇÃO e não existe** — sem GCLID+postback→import, o flywheel aprende ruído. É o gap #1 (upstream de tudo).
2. **Escada de intenção composicional** — hoje é token-spotting EN; falta gradar pelo PAR (modificador × Schwartz × oferta), multilíngue PT-BR, com SERP como validador.
3. **Gate de decisão estatístico** — rule-of-three / breakeven-CVR / posterior Bayesiano / EPC>CPC + os 3 gates (significância × atribuição × lag). Hoje o "vendeu sobe/gastou desce" é contagem crua.
4. **Camada de risco-de-conta como SINAL** — detector de restricted-drug-terms / brand-bidding-contract / DKI / cloaking, que **expõe** (não bloqueia) o risco de morte-de-conta.
5. **`SearchTermWasteMiner` desarmado** — existe e NÃO está ligado no `CampaignBlueprintService`; + gate anti-campeã materializado (cross-check vs termos que converteram).
6. **`MechanismExtractor` T4** — o `trick` da VSL dissecada não flui automático pro blueprint como keyword-herói.
7. **Bugs verificados:** colisão `recipe` (gera e negativa a mesma variante, `QualifiedKeywordPatternEngine.php:21` vs `:326`); contradição `funciona`=scam (`KeywordIntentMapper.php:68`) vs `does it work`=alta-intenção; kill global de `what is`/`how to` cegando o problem-aware.
8. **Sem sinal de volume** (Keyword Planner) — ranqueia intenção, não demanda; pode escalar pra um termo de 30 buscas/mês.

---

## Apêndice — fontes-âncora (verificadas 2025-2026)
- **Google oficial:** Quality Score 6167118 · Ad Rank 1722122 · Priorização 2756257 · Match types 7478529 · Broad 12159290 · Smart Bidding 7065882 · Negativas 2453972 · Negativas de conta 11396330 · Search Terms 2472708 · Keyword Planner 3022575 · Close variants 9342105 · ABCs of Structure 14752782 · Healthcare/restricted-drug 176031 · Unacceptable practices 15938071 · Circumventing 6008942 · Offline import 15081888.
- **Obras/artigos:** Perry Marshall *Ultimate Guide to Google Ads* · Brad Geddes *Advanced Google AdWords* / bgtheory / Adalysis n-gram · Eugene Schwartz *Breakthrough Advertising* · SEJ 80/20 · Search Engine Land SKAG-2024 / broad-match-control / phrase-broad-identical · Adtaxi broad+Smart-Bidding · DigiWebInsight barbell · groas negative-keyword-2026 · Karooya AI Max · y77 Smart Bidding 2026 · WordStream query-matching-2025 · Jansen/Booth/Spink IP&M 2008 · ClickBank EPC · RedTrack/CustomerLabs offline tracking · iAmAttila spy.
