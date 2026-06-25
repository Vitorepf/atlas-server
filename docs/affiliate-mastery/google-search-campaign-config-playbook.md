# Google Search — Campaign Configuration Playbook (oferta de afiliado / VSL)

> **Pilar:** configuração de campanha (Rede de Pesquisa do Google Ads).
> **Papel no funil 1% → 25%:** a config NÃO cria os 25% (isso é página + VSL + oferta + qualificação).
> O papel dela é **não-sabotar**: comprar clique barato e qualificado, cortar desperdício, e
> **alimentar o Smart Bidding com a VENDA REAL** (não um proxy). Campanha errada trava em 1%
> independente da página.
>
> Escopo de teste atual: oferta WL **OT169 / Mounjaboost**. Página que roda no Google = **white**
> (audita `greenlight` no `PolicyComplianceGate`), CTA → `https://mounjaboost.com/vsla/?aff_id=110094`.
> Estado: **rascunho vivo — preenchido tela-a-tela junto com o operador.**

---

## STEP 0 — Pré-voo (ANTES de abrir a UI) — sem isto, você otimiza às cegas

1. **Sinal de venda decidido.** Como afiliado você NÃO controla o checkout da oferta (mounjaboost),
   então não dá pra cravar tag do Google no thank-you do anunciante. O sinal de venda vem do
   **postback S2S do afiliado → Offline Conversion Import (OCI) / Enhanced Conversions** (via
   Data Manager API; migração obrigatória a partir de 2026-06-15). **Auto-tagging ON** → captura GCLID.
   - Sem isto, o melhor que você mede é o **clique-out** pra oferta = PROXY. Smart Bidding otimizando
     clique-out compra clicador barato, não comprador. (o "teto silencioso").
2. **Unit economics travada.** `Max CPA = payout × CVR_venda × (1 − refund) × (1 − margem_alvo)`.
   Dela sai o **CPC de breakeven** e o **ROAS alvo** que você alimenta no lance. Toda decisão de
   escalar/matar referencia este número.
3. **White page no ar, no SEU domínio, auditando `greenlight`.** Final URL do anúncio = white page
   (nunca hoplink cru no anúncio). White → oferta do afiliado.

---

## STEP 1 — Objetivo da campanha  ✅ DECIDIDO: **VENDAS**

Tela: *"Qual é o objetivo da sua campanha?"*

**Escolha: `Vendas`** → tipo **`Pesquisa`**.

**Por quê:** o operador tem o **sinal de venda real** — a **Blackink** (tracker próprio) recebe a
venda da rede e devolve pro Google Ads (qual clique/keyword vendeu, valor, quando). Com venda real
de volta, "Vendas" é o objetivo correto e honesto: o Smart Bidding otimiza **lucro**, não o proxy de
clique-out. (A ressalva anterior — "não escolha Vendas" — valia SÓ no cenário sem tracking de venda;
com Blackink, está resolvido.)

**O label não basta — o que faz "Vendas" funcionar é a AÇÃO DE CONVERSÃO certa** (STEP 5). A ordem à
prova de erro:
1. Criar a ação de conversão de **importação de cliques** ("Venda — Blackink") + **auto-tagging ON**
   ANTES de gastar (STEP 5).
2. Selecionar essa ação como **meta PRIMÁRIA** da campanha. Clique-out, no máximo, secundária
   (observação) — **nunca** primária.
3. Cold start em **lance manual / Maximizar cliques** (não há dado de venda no dia 1); migrar pra
   Maximize Conversions → tCPA com ≥15–30 vendas reais.

### STEP 1.5 — Telas intermediárias do fluxo "Vendas/Pesquisa"

**"Selecione um tipo de campanha":** `Pesquisar` ✅.

**"Selecione como você quer alcançar sua meta":**
- Marcar **Visitas ao site** (deixar Ligações/Visitas à loja DESmarcadas).
- A URL aqui é só destino/hint — **NÃO é a conversão de venda.** Tem que ser a **white page**
  (greenlight) que **repassa o `gclid` pra Blackink**.
- ⚠️ **Conferir a URL:** no setup atual apareceu `lipobls.site/lipo?utm_source=google...` — confirmar
  que é a landing/white correta da oferta em teste (não bater oferta errada).

**"Escolha suas metas de conversão de vendas":**
- **Compra** ✅ (é a venda).
- 🔴 **CRÍTICO — clicar em `Configurar` ("Definir como medir essa meta")** e conectar/criar a ação de
  conversão de **IMPORTAÇÃO de cliques** (a "Venda — Blackink"). É o coração do tracking; sem isto a
  campanha "Compra" não tem como medir a venda real. (detalhe da ação → STEP 5).
- Se o fluxo não oferecer "importação" aqui, criar depois em **Ferramentas → Conversões** e voltar
  pra selecionar.

**"Ativar as conversões otimizadas na sua conta" (Enhanced Conversions):**
- O que é: melhora a medição usando **dados first-party (email/telefone) que o cliente envia NO SEU
  site**. Exige você capturar esses dados + aceitar os termos de tratamento de dados.
- **No caso afiliado:** a compra acontece no mounjaboost (não no seu site) e você NÃO coleta
  email/telefone de compra na sua landing → **não há dado first-party pra alimentar isto.** Quem mede
  a venda é a **importação por `gclid` da Blackink**, não as conversões otimizadas.
- Recomendação: **inócuo deixar ligado** (é o default e fica pronto se um dia você capturar lead), mas
  ao "Concordar e continuar" você **aceita os termos de tratamento de dados** → a landing precisa ter
  **política de privacidade**. Se preferir não assumir um aceite que não vai usar agora, pode
  desmarcar — não muda nada no tracking via Blackink. **(Aceite de termos = decisão sua, você que clica.)**

---

## STEP 2 — Configurações da campanha (Redes + Locais)  🔧 definido

- **Tipo:** Pesquisa (Search).
- **Redes — DESMARCAR as 2** (o Google marca por default e chama "recomendado" = bom pra ELE):
  - 🔴 **Rede de Display = OFF** (obrigatório) — vira banner em sites/apps aleatórios, clique
    acidental, zero intenção, queima budget.
  - 🟡 **Parceiros de pesquisa = OFF** no arranque — qualidade inferior + suja a leitura de dados.
    Religar depois se quiser testar. → fica **só Pesquisa Google pura**.
- **Locais — ✅ GEO CONFIRMADO: Estados Unidos** (Locais personalizados → EUA país, 316M alcance).
  (Era o ponto crítico: oferta em inglês → público US; Brasil teria zerado a campanha.)
- **Opções de local — ✅ "Presença: pessoas que estão/frequentam a área"** (NÃO "presença ou
  interesse"). Corta tráfego de fora dos EUA.
- **⚠️ NÃO clicar nos botões "Aplicar"** dos nudges do Google ("Ampliar com parceiros" / "Inclusão da
  Rede de Display") — religam o que foi desmarcado.
- **Idioma:** **Inglês** (público US). Espanhol é teste futuro, não agora.
- **Segmentos de público-alvo: PULAR** (não adicionar nenhum). Em Search a **palavra-chave É a
  segmentação** — público só restringiria. ⚠️ Garantir "Config. de segmentação" em **Observação**
  (NÃO "Segmentação", que restringe o alcance).
- **🔴 AI Max para campanhas de pesquisa = OFF** (não ligar no arranque). AI Max = broad-match
  automática + recombinação de texto + puxa conteúdo da landing + expansão de URL final → tira o
  controle do **Keyword OS** e é **risco de compliance** (IA gera combinações de copy sem revisão)
  numa oferta WL no fio. Os "14%" são claim genérico, não vale pro caso afiliado controlado.
- **Desligar também:** **"Otimização de recursos" / expansão de URL final / ativos-textos criados
  automaticamente** (mesmos motivos: controle total da mensagem + destino) + aplicação automática de
  recomendações.

## STEP 3 — Keywords + grupos de anúncios  🔧 definido (oferta de teste = lipo bliss / OT169)

**Match type = FRASE `" "`** (não exata, não ampla). Raízes são termos COINED pela VSL → zero tráfego
genérico → frase captura todas as variações (`+ reviews / where to buy / does it work`) sem lixo.
Exata estreita demais p/ volume coined; ampla = budget incinerado.

**Keywords seguras p/ colar (frase) — as "5":**
```
"triple hormone drops protocol"        ← mecanismo coined (tier campeão, 1º dinheiro)
"triple hormone drops"                  ← mecanismo, forma curta (mais volume)
"make america skinny again"             ← slogan coined
"activates three fat-burning hormones"  ← frase-mecanismo memorizada (fingerprint da VSL)
```

**🔴 CORTAR (o motor gera, mas QUEIMAM A CONTA — filtro manual até fechar no engine):**
- Qualquer com **retatrutide / ozempic / glp-1** (ex.: "at-home retatrutide protocol") = droga de
  prescrição / termo farmacológico → **risco de SUSPENSÃO**.
- **Celebridade** (melania trump / rfk jr / oprah) + emagrecimento = endosso não-verificado → violação.

**Negativas (do `SearchTermWasteMiner`, adicionar já):** free, cheap, recipe, diy, homemade, pdf,
download, reddit, coupon, job, salary, sample, side effects, is it safe, what is, meme, shirt, news.
(nunca negativar a campeã / raiz-própria.)

**Grupos (message-match: keyword = título = H1):** Grupo "Triple Hormone Drops" (mech) + Grupo "Make
America Skinny Again" (slogan), cada um com seu RSA. Simplificar = começar só pelo grupo do mecanismo.

**⚠️ Gap do Atlas a fechar:** `QualifiedKeywordPatternEngine` ainda emite keywords com droga +
celebridade. Próximo: PolicyComplianceGate-de-keyword que bloqueia rx-drug/celebrity independe de
ancoragem (já flagado em sessão anterior). Por ora, corte manual.

## STEP 4 — Anúncios RSA  🔧 GERADO + validado greenlight (grupo Mechanism)

**15 títulos (todos greenlight, ≤30, 4 com a keyword):**
```
Triple Hormone Drops Protocol / The Triple Hormone Drops / Triple Hormone Drops Reviews
Triple Hormone Drops Method / Watch the Free Presentation / See How It Works / Discover the Method
Get the Free Briefing / For Women Over 40 / The At-Home Method / No Injection, No Needle
4 Natural Ingredients / Why Weight Shifts After 40 / Understand the Why First / A Plain-Language Explainer
```
**4 descrições (≤90, greenlight):**
```
See the at-home method women over 40 are exploring. Watch the free presentation now.
Understand why weight can shift after 40 — explained in a short, free video. Watch it.
No injection, no prescription. See how the approach works in the free presentation.
A plain-language look at the hormones tied to metabolism. Watch the free briefing.
```
- 3 levers do QS: CTR (keyword coined + CTA verbs) · relevância (4 títulos c/ keyword) · landing-exp
  (títulos "after 40" casam com a H1 da white). Atrai qualificado / repele curioso (free/40+/at-home).
- **Pin opcional:** `Triple Hormone Drops Protocol` na Pos.1 trava message-match (custa Ad Strength).
- **Final URL = white page** (greenlight) no domínio do operador; display path com tema da keyword.

**🔴 2 GAPS DO ATLAS expostos aqui (fechar depois):**
1. `MessageMatchAdForge` só gera copy **Base agressiva** (urgency/news/censorship: "Before It Is
   Taken Down", "Watch Before It Is Gone", "As Seen On The News") → falta **modo `compliant`/white**
   (espelhar o que `EliteAdvertorialRenderer` já faz). Por ora: filtro manual via gate.
2. `PolicyComplianceGate` **fura** em fake-urgency ("before it is gone") e fake-endorsement ("as seen
   on the news") → reforçar regex `fake_urgency` + nova regra de "as seen on / national news".

## STEP 5 — Tracking de conversão via **BLACKINK** (o encanamento de maior alavancagem)  🔧 definido

Fluxo do dado (tem que fechar o círculo, senão otimiza às cegas):

```
Google Ad ──(auto-tag: ?gclid=XYZ)──▶ WHITE PAGE (domínio do operador)
   │ a página DEVE repassar o gclid no clique do CTA ──▶
   ▼
BLACKINK (tracker) captura gclid + subids (campaign/adgroup/keyword) ──▶ oferta mounjaboost
   ▼ (quando vende)
rede dispara postback S2S ──▶ BLACKINK casa venda↔gclid ──▶ sobe pro Google Ads
   ▼
Google Ads recebe a VENDA (gclid + valor da comissão + timestamp) → Smart Bidding otimiza LUCRO
```

**Lado Google Ads (UI) — o que o operador configura:**
1. **Auto-tagging ON** (Config. da conta → "Marcar URLs com info de clique do Google"). Sem isso não
   existe `gclid` → nada casa. **Erro fatal #1 se esquecer.**
2. **Nova ação de conversão → Importar → "Conversões de cliques (CRMs, arquivos, outras fontes)"**
   (NÃO a tag de site — você não controla o checkout do anunciante).
   - Nome: `Venda — Blackink`.
   - Categoria: **Compra/Venda (Purchase)**.
   - Valor: **"Usar valores diferentes por conversão"** = a comissão real que a Blackink reporta
     (destrava tROAS). Moeda da oferta.
   - Contagem: **Uma** por clique (venda única de afiliado).
   - Janela de conversão: **30–90 dias** (venda de VSL + latência do OCI demoram).
   - Marcar como **meta PRIMÁRIA**.

**Lado Blackink — o que a Blackink precisa mandar (especificação; NÃO mexer na Blackink sem aprovação
do operador — é produção LIVE):**
- Capturar e persistir o `gclid` do clique de entrada (vem na URL via auto-tag).
- Carregar subids com **ValueTrack** pra responder "quem vendeu": template de URL passando
  `{gclid}` + `{campaignid}` + `{adgroupid}` + `{keyword}` + `{creative}` pra Blackink.
- No postback de venda, **subir a conversão pro Google** com `gclid` + valor da comissão + horário da
  conversão.
- ⚠️ **Data Manager API:** a partir de **2026-06-15** o caminho legado de OCI/Enhanced-for-leads foi
  migrado pra a **Data Manager API** (hoje já é pós-deadline). **Confirmar qual API a Blackink usa**
  antes de confiar — upload na API errada falha (às vezes silenciosamente). → verificar quando
  integrarmos.

**Erros fatais a evitar (todos quebram o tracking sem aviso):**
- ❌ White page não repassa o `gclid` → Blackink nunca casa a venda → Google nunca aprende.
- ❌ Auto-tagging OFF → sem `gclid`.
- ❌ Clique-out marcado como conversão primária → otimiza clicador, não comprador.
- ❌ Valor fixo em vez da comissão real → tROAS cego.
- ❌ Subir na API legada pós-15/06 → upload rejeitado.

## STEP 6 — Lance + orçamento  🔧 POSIÇÃO ESTÁVEL (tela "Lances")

**Regra-mãe (reconcilia o debate CPC vs tCPA):** _o Smart Bidding (tCPA) só fica BOM depois que existe
dado de conversão — e o CPC é o que GERA esse dado._ tCPA no cold start não "queima caixa" (ele
trava custo), mas **opera quase no escuro** nas 1ª–2ª semanas (pouca venda + latência do postback
Blackink). CPC não depende de conversão: garante entrega, mostra os search terms, gera as primeiras
vendas que vão calibrar o tCPA.

**ROTA RECOMENDADA p/ campanha NOVA (este caso):**
1. **Arranque = CPC** → "Em qual métrica focar?" = **`Cliques` (Maximizar cliques)** + marcar
   **"Definir um limite de lance máximo de CPC"**. Roda 1–2 semanas: entrega garantida, mapeia search
   terms → negativas, pega as primeiras vendas via Blackink.
2. **Regime = tCPA** → com ~15–30 vendas, migra p/ **`Conversões` + "Definir CPA desejado"**,
   começando o alvo **folgado** (no/perto do breakeven) pra não travar entrega, depois apertando.

**ROTA ALTERNATIVA — tCPA direto (válida; é a que o operador e o círculo usam):** funciona, mas troca
eficiência de arranque por conveniência (Google otimiza no escuro no início). Requisitos pra dar
certo: tracking sólido (✓ Blackink), alvo inicial **folgado** (senão trava entrega), idealmente
histórico de conta no nicho. **Não é erro — é menos eficiente no arranque de campanha nova.**

**Valores (da unit economics, não chute):**
- **Teto de CPC (rota CPC):** `EPC = payout × CVR(clique→venda)` = breakeven CPC → teto **abaixo** dele.
- **tCPA:** `breakeven CPA = payout × (1 − refund)` → inicial aqui/logo abaixo; `CPA de regime =
  breakeven × (1 − margem)`.
- _(preencher com payout + CVR + refund reais.)_

**Rampa:** apertar em passos; **nunca mudar budget >20%/passo** (reseta aprendizado). Migrar p/
**tROAS** quando os valores de comissão variam muito. Learning Search ~7–14 d.
- ☐ **"Aquisição do cliente / ajustar lances p/ novos clientes" = DESMARCADO** (e-commerce com base
  recorrente; afiliado não tem lista própria).

**Orçamento diário (tela "Selecione um tipo de orçamento"):**
- 🔴 **NÃO aceitar o "Recomendado"** (apareceu R$388/dia ≈ R$11,8k/mês) — Google maximiza gasto.
- ✅ **"Definir orçamento personalizado"** → começar **R$100–150/dia** (teto de segurança, não meta).
- Regra: orçamento ≈ **3–5× o CPA alvo** (≈1 venda a cada 1–2 dias p/ ler sinal); subir só ≤20%/passo
  após ver venda. ⚠️ Com **broad** gasta rápido no genérico → começar baixo é mais crítico ainda.
  O CPC R$1,65 que o Google mostra é fantasia de broad; tende a subir. Google pode gastar **2× o
  diário** num dia.
- **Pendente p/ cravar valor exato:** payout da oferta + se "1%" é clique→venda ou página→venda.

## STEP 7 — QA de lançamento + cadência de leitura pós-launch  ⏳

- Checklist: Display OFF, geo=presença, Final URL=white greenlight, negativas carregadas, conversão
  de venda configurada, auto-tag ON, RSA Excellent.
- Leitura: não mexer nos primeiros dias (learning); diagnosticar por sintoma→alavanca (árvore do funil),
  uma alavanca por vez.

---

## Como isto liga no Atlas (capacidade, não só doc)
- Keywords: `QualifiedKeywordPatternEngine` + `KeywordQualityIndex` + `SearchTermWasteMiner` → STEP 3/negativas.
- Anúncios: `MessageMatchAdForge` → STEP 4.
- Página white: `EliteAdvertorialRenderer(compliant)` + `PolicyComplianceGate(greenlight)` → STEP 1/4 Final URL.
- Economics + blueprint: `CampaignBlueprintService` → STEP 0/6.
- **Gap a fechar:** emitir STEP 2 (settings) e STEP 5 (tracking) como bloco determinístico no
  `CampaignBlueprintService` (`campaignSettings()` + `conversionPlan()`) — preencher conforme andamos.
