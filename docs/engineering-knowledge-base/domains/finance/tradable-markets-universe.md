---
id: atlas-finance-tradable-markets-universe
type: engineering_knowledge
title: Atlas Finance · Universo de Mercados Negociáveis
status: active
category: reference
priority: 90
summary: Catálogo canônico e exaustivo de todo mercado, produto e instrumento onde se pode tomar posição (trade) ou arbitrar — renda variável, renda fixa, câmbio, commodities, derivativos, índices, fundos/ETPs, criptoativos/DeFi, mercados de previsão (Polymarket/Kalshi), ativos reais, alternativos/colecionáveis e mercados exóticos/ambientais. Fonte única da verdade; a área de Finanças no AtlasVault projeta este doc para navegação na Cartografia.
tags:
  - atlas-ai
  - domains
  - finance
  - reference
  - markets
  - review-only
capabilities:
  - finance_domain
  - market_research
  - tradable_markets_catalog
decisions:
  - Este catálogo é referência analítica (review-only). Listar um mercado NÃO autoriza execução, ordem, custódia ou aconselhamento personalizado.
  - Toda categoria listada precisa ser um mercado real onde um participante consegue de fato tomar posição ou arbitrar.
  - Completude é mantida por auditoria adversarial recorrente (workflow finance-markets-universe-audit) que pergunta "o que está faltando?" até esgotar.
maintenance:
  - Atualize quando surgir nova classe de ativo, venue, instrumento ou modo de arbitragem. Rode o workflow de auditoria de completude antes de declarar "completo".
  - Leia junto de docs/engineering-knowledge-base/domains/finance.md (spec do domínio) antes de alterar.
related_paths:
  - docs/engineering-knowledge-base/domains/finance.md
  - .claude/workflows/finance-markets-universe-audit.js
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-finance-tradable-markets-universe
graph_title: Atlas Finance · Universo de Mercados Negociáveis
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-ai-finance-domain
graph_status: active
graph_source: repo
macro_layer: false
human_name: Universo de Mercados Negociáveis
canonical_name: Atlas Finance Tradable Markets Universe
technical_name: atlas-finance-tradable-markets-universe
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/finance/tradable-markets-universe.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/finance/tradable-markets-universe.md
allowed_changes:
  - Adicionar/atualizar classes de ativo, venues, instrumentos e modos de arbitragem com precisão e atribuição.
forbidden_changes:
  - Transformar o catálogo em superfície de execução, sinal de compra/venda ou recomendação personalizada automática.
depends_on:
  - atlas-ai-finance-domain
flows_to:
  - atlas-cartography
unlocks:
  - ai-safe-implementation-context
governs:
  - domains
evidence:
  - docs/engineering-knowledge-base/domains/finance/tradable-markets-universe.md
  - .claude/workflows/finance-markets-universe-audit.js
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: medium
visual_tags:
  - module
  - finance
  - reference
ai_entrypoints:
  - Leia Resumo, Contratos e Regras para IA antes de usar. Este doc é referência review-only, nunca gatilho de execução.
ai_usage_notes:
  - Use como mapa de cobertura de mercados para pesquisa, valuation, risco e tese. Nunca derive ordem executável daqui.
quality_gates:
  - php artisan atlas:engineering:knowledge docs-health --json
failure_modes:
  - Tratar catálogo de referência como autorização de trade.
  - Catálogo desatualizado vs. surgimento de novas classes/venues (mitigado pelo workflow de auditoria).
observability_signals:
  - docs-health status ok
next_actions:
  - Rodar finance-markets-universe-audit periodicamente e folddar achados; manter a área 09-financas do AtlasVault sincronizada como projeção.
---
# Atlas Finance · Universo de Mercados Negociáveis

> Status: `active` · Tipo: `module` (referência) · Domínio: `finance` · Fonte: repo canônico.
> **Review-only.** Listar um mercado aqui é conhecimento de cobertura — **não** é sinal, ordem, recomendação personalizada ou autorização de execução. Live trading é hard-blocked no domínio Finance (`AtlasFinanceComplianceGate`).

## Resumo

Este é o catálogo **único e exaustivo** de tudo onde se pode *de fato* tomar posição (trade) ou arbitrar. Ele responde, de forma permanente e auditável, à pergunta do operador: *"existe mais opções? tem algo faltando? existe uma opção que não está aqui?"*.

A cobertura é organizada em **13 famílias** transversais (classe de ativo + tipo de instrumento + estrutura de venue + arbitragem), de modo que qualquer produto negociável tenha um lar claro. A completude não é uma afirmação estática: é mantida pelo workflow adversarial [`finance-markets-universe-audit`](../../../../.claude/workflows/finance-markets-universe-audit.js), que enumera por múltiplas lentes e caça o que falta até esgotar (loop-until-dry).

**Famílias:** 1) Renda Variável · 2) Renda Fixa · 3) Câmbio (FX) · 4) Commodities · 5) Derivativos (por contrato) · 6) Índices · 7) Fundos & Produtos Listados (ETPs) · 8) Criptoativos & DeFi · 9) Mercados de Previsão & Eventos · 10) Ativos Reais & Imobiliário · 11) Alternativos & Colecionáveis · 12) Mercados Exóticos & Ambientais · 13) Arbitragem & Relative Value (transversal).

## Como Navegar

- **Fonte da verdade:** este arquivo (repo canônico).
- **Navegação humana (Obsidian/Cartografia):** AtlasVault → `09-financas/` → nota raiz **`Mapa de Finanças (MOC)`**, que projeta cada família como um nó navegável ligado de volta a este doc por `canonical_doc`.
- **Auditoria de completude:** `Workflow({ name: "finance-markets-universe-audit" })` ou `/workflows`.

## Catálogo de Mercados Negociáveis

Convenção por família: **O que se negocia** (instrumentos) · **Onde** (venues/estrutura) · **Arbitragem típica**.

### 1. Renda Variável (Equities)

Participação no capital de empresas e veículos equity listados.

- **O que se negocia:** ações ordinárias (common) e preferenciais (preferred); units; recibos/depositary receipts (ADR, GDR, **BDR** no Brasil, EDR); REITs / **FIIs** (fundos imobiliários listados — tijolo/papel/híbrido); BDCs; SPACs; penny stocks / OTC / pink sheets; pré-IPO e *secondaries* de private equity; direitos e bônus de subscrição; closed-end equity funds; ETFs de ações (ver família 7).
- **Onde:** NYSE, Nasdaq, **B3** (Brasil), LSE, Euronext, Deutsche Börse, SIX, JPX, HKEX, SSE/SZSE, NSE/BSE, TSX, ASX, JSE, Tadawul; ATS/MTF, **Systematic Internalisers (SIs)**, leilões periódicos / *frequent-batch* e dark pools para execução.
- **Arbitragem típica:** dual-listing/ADR vs. local, fechamento de desconto em closed-end funds, index arbitrage, risk/merger arb, dividend/tax arb.

### 2. Renda Fixa (Fixed Income)

Promessas de fluxo de caixa / dívida.

- **O que se negocia:** soberanos (US Treasuries bills/notes/bonds, **Tesouro Direto** LTN/NTN-B/NTN-F/LFT, Bunds, Gilts, JGBs, OATs); inflation-linked (TIPS, NTN-B, linkers); municipais (munis); corporativos *investment grade* e *high yield*; securitizados (agency/non-agency MBS, ABS, CMBS, CLO, CDO e **tranches de índice de crédito / CDO sintético**, **CRI/CRA** e debêntures no Brasil); **TBA MBS** (to-be-announced) e *dollar rolls*; **covered bonds** (Pfandbriefe/cédulas hipotecárias); **MSR** (mortgage servicing rights); **whole-loans / NPL** (non-performing loans) em portfólio; conversíveis e CoCos; **SRT/CRT** (synthetic / significant risk transfer — crédito capital-relief); **trade finance** / recebíveis / supply-chain finance; **GDP-linked warrants** e value-recovery instruments (dívida soberana state-contingent); **whole-business securitization (WBS)** e ABS esotérico (franchise, fiber, data-center, solar); dívida de mercados emergentes (hard/local); money market (T-bills, commercial paper, CDs, **CDB/LCI/LCA/LC**, bankers' acceptances); repos / reverse repos; sukuk (renda fixa islâmica); green/social/sustainability bonds; bond ETFs/funds.
- **Onde:** OTC dealer/interdealer (MarketAxess, Tradeweb, Bloomberg), leilões primários (treasury auctions), Tesouro Direto, exchanges para listados.
- **Arbitragem típica:** basis (cash vs. futuro), curva/swap-spread, on-the-run vs. off-the-run, capital-structure arb (dívida vs. equity), convertible arb.

### 3. Câmbio (FX / Currencies)

O maior mercado do mundo por volume.

- **O que se negocia:** spot; **majors** (EUR/USD, USD/JPY, GBP/USD, USD/CHF, AUD/USD, USD/CAD, NZD/USD); minors/crosses; **exotics** (USD/BRL, USD/TRY, USD/ZAR, USD/MXN…); outright forwards; **NDFs** (BRL, INR, CNY…); FX swaps; opções de FX; futuros de moeda (CME); stablecoins como proxy de FX.
- **Onde:** interbancário (EBS, Refinitiv Matching), bancos/dealers, brokers de varejo, CME para futuros.
- **Arbitragem típica:** **triangular**, covered interest parity, cross-venue/latency, spot-forward, onshore vs. offshore (NDF vs. spot).

### 4. Commodities

Bens físicos padronizados, à vista e via derivativo.

- **Energia:** petróleo (WTI, Brent, Dubai), refinados (gasolina, diesel/heating oil), **NGLs** (etano, propano, butano, nafta) e **petroquímicos/plásticos** (eteno, propeno, polietileno, PVC, metanol, paraxileno), gás natural (Henry Hub, TTF, NBP), GNL, carvão, energia elétrica/power, **urânio** (U3O8 spot, futuros CME UX, conversão, enriquecimento SWU), etanol.
- **Metais preciosos:** ouro, prata, platina, paládio, ródio.
- **Metais base/industriais e críticos:** cobre, alumínio, zinco, níquel, chumbo, estanho, minério de ferro, aço, lítio, cobalto, molibdênio, **terras-raras** (rare earths), spodumene.
- **Agrícolas — grãos/oleaginosas:** milho, trigo, soja (+ farelo e óleo), arroz, aveia, canola.
- **Agrícolas — softs:** café (arábica/robusta), açúcar, cacau, algodão, suco de laranja (FCOJ), borracha.
- **Madeira & florestais:** lumber / *random-length*, painéis e produtos de madeira; **celulose/papel** (pulp NBSK/BHKP, containerboard, OCC recuperado).
- **Outros físicos & niche:** **fertilizantes** (potássio/potash, fosfato, ureia, UAN, DAP, enxofre), **hélio e gases nobres** (argônio, neônio, criptônio, xenônio), **diamantes** (índice de lapidado), sucata ferrosa/não-ferrosa.
- **Pecuária:** boi gordo (live/feeder cattle), suínos (lean hogs).
- **Compute/chips (emergente, exchange-listed):** futuros de poder computacional, GPU, chips de IA e memória/DRAM (ex.: CME Silicon Data) — contraparte cripto/descentralizada em Mercados Exóticos.
- **Onde:** CME/NYMEX/COMEX, ICE, LME, **B3** (boi, café, milho, soja), SGX (minério), Shanghai/Dalian; mercado físico/spot e OTC.
- **Arbitragem típica:** cash-and-carry, *crack spread* (energia), *crush spread* (soja), *spark/dark spread* (energia elétrica), calendar e inter-commodity spreads, geográfica.

### 5. Derivativos (por tipo de contrato)

A *forma* da posição, independente do subjacente (qualquer família acima pode ser acessada via derivativo).

- **O que se negocia:** **futuros** (commodity, financeiro, índice, single-stock, FX, juros, cripto, mini/micro); **forwards** (OTC); **opções** (vanilla call/put americana/europeia, weeklies, FLEX, sobre futuros, sobre ETFs, e **exóticas**: barreira, asiática, digital/binária, lookback, basket); **swaps** (IRS, OIS, basis, **CDS** single-name, índices de crédito **CDX/iTraxx**, **TRS** total-return, variance/volatility swaps, **dividend swaps e dividend futures** (índice e single-stock), commodity/asset swaps); **swaptions**, caps/floors e **opções de inflação**; **CFDs**; **spread betting** (UK); **warrants** / covered / turbo; **perpétuos (perps)** cripto.
- **Onde:** CME, Eurex, ICE, CBOE, B3; OTC sob ISDA; venues cripto (ver família 8).
- **Arbitragem típica:** put-call parity, volatilidade (implícita vs. realizada), basis, dispersão, conversão/reversão.

### 6. Índices (Indices)

Cestas de referência — negociadas via derivativos, ETFs, CFDs ou fundos.

- **O que se negocia:** índices de ações (**S&P 500**, Nasdaq-100, Dow, Russell 2000, **Ibovespa**, FTSE 100, DAX, Nikkei 225, Hang Seng, Euro Stoxx 50, MSCI World/EM); índices de **volatilidade** (VIX, VVIX, VXN, MOVE para bonds); índices de commodities (Bloomberg BCOM, S&P GSCI); índices de renda fixa/crédito; índices de cripto; índices customizados/temáticos/smart-beta/fatoriais/equal-weight/dividendos.
- **Onde:** futuros e opções de índice (CME, Eurex, B3, Cboe), ETFs, CFDs.
- **Arbitragem típica:** **index arbitrage** (futuro vs. cesta), rebalanceamento de índice, NAV de ETF de índice.

### 7. Fundos & Produtos Listados (Funds & ETPs)

Veículos coletivos negociáveis.

- **O que se negocia:** **ETFs** (físicos, sintéticos, alavancados/inversos, ativos, temáticos, smart-beta, buffer/defined-outcome); **ETNs**; **ETCs** (commodity); ETFs de **cripto spot** (BTC, ETH) e de futuros; fundos mútuos/abertos; **fundos fechados** (closed-end, negociam com prêmio/desconto sobre NAV); interval funds; **FIIs**, **FIAGRO**, **FI-Infra** (Brasil); UCITS (Europa); money market funds; fundos de hedge e funds-of-funds (acesso restrito).
- **Onde:** exchanges para listados; distribuidoras/plataformas para abertos.
- **Arbitragem típica:** **creation/redemption (NAV) arbitrage** de ETF, desconto de closed-end fund.

### 8. Criptoativos & DeFi (Digital Assets)

Ativos nativos de blockchain — on-chain e em exchanges.

- **O que se negocia:** coins L1/L2 (BTC, ETH, SOL…); tokens (utility, **governance**, meme); **stablecoins** (fiat-backed USDT/USDC, cripto-colateralizadas DAI, algorítmicas); derivativos cripto (**perpétuos/perps**, futuros datados, **opções** — Deribit e on-chain); **liquid staking tokens** (stETH) e **restaking** (EigenLayer); **NFTs**, ordinals/inscriptions, runes; **RWA tokenizados** (treasuries, crédito, imóveis, ações, ouro PAXG); **pontos/airdrop/pre-market** de tokens; mercados de **yield/lending** (Aave, Compound) e posições de **LP em AMM**; **DeFi option vaults (DOVs)** e taxa-fixa / yield-split (Pendle); **blockspace / blob-gas** (EIP-4844); **deposit tokens** (depósito bancário tokenizado); **fan/club & SocialFi tokens**; **perps regulados** (DCM CFTC, EUA); prediction on-chain.
- **Onde:** **CEX** (Binance, Coinbase, OKX, Bybit, Kraken); **DEX/AMM e perp DEX** (Uniswap, Curve, dYdX, Hyperliquid, GMX); Deribit (opções).
- **Arbitragem típica:** **funding-rate / perp-spot**, cross-exchange e **DEX-CEX**, **cross-chain**, *triangular* on-chain, stablecoin de-peg, MEV.

### 9. Mercados de Previsão & Eventos (Prediction & Event Markets)

Posições sobre o *resultado* de eventos do mundo real.

- **O que se negocia:** contratos binários / *outcome shares* sobre eleições, política, **economia** (CPI, decisão do Fed), esportes, clima, ciência/tecnologia e entretenimento; *event contracts* regulamentados; sports-betting **exchanges** (back/lay).
- **Onde:** **Polymarket**, **Kalshi** (regulado CFTC), PredictIt, Manifold, Augur/Zeitgeist, Betfair (sports exchange).
- **Arbitragem típica:** **cross-venue** (mesmo evento em preços diferentes), soma-de-probabilidades > 100% (lay todos os resultados), prediction vs. mercado tradicional correlato.

### 10. Ativos Reais & Imobiliário (Real Assets)

Valor ancorado em ativos físicos.

- **O que se negocia:** imóveis diretos (residencial/comercial); REITs/FIIs (listados — família 1); **real estate tokenizado / fracionado** (RealT…); crowdfunding imobiliário; **farmland** / terras agrícolas; **timberland** / florestas; **derivativos de índice de preço de imóveis** (Case-Shiller futures/options); infraestrutura (listada e privada); royalties de recursos naturais (mineração, energia).
- **Onde:** transações diretas, plataformas de fracionamento/tokenização, exchanges (para listados).
- **Arbitragem típica:** NAV vs. preço de mercado (REITs/FIIs), cap-rate vs. custo de capital.

### 11. Alternativos & Colecionáveis (Alternatives & Collectibles)

Mercados de baixa liquidez / acesso especializado.

- **O que se negocia:** private equity / venture capital (**secondaries**); hedge funds; **private credit** / direct lending; **arte** (e arte fracionada — Masterworks); **vinhos finos / whisky** (Vinovest); **relógios** de luxo; **trading cards** (esportes, Pokémon, Magic); moedas raras/numismática; **sneakers** (StockX); domínios de internet; **royalties musicais / IP** (música, patentes — Royalty Exchange) e **leilões de patentes/ativos de IP** (o ativo em si); **litigation finance**; memorabilia, LEGO, instrumentos raros.
- **Onde:** casas de leilão, marketplaces especializados, plataformas de fracionamento, secondaries privados.
- **Arbitragem típica:** geográfica/marketplace, fracionado vs. peça inteira, grading/condição.

### 12. Mercados Exóticos & Ambientais (Exotic & Environmental)

Subjacentes não convencionais que mesmo especialistas esquecem.

- **O que se negocia:** **carbono/emissões** (EU ETS, California Cap-and-Trade, RGGI, **CBAM**, créditos voluntários VCM, **CORSIA** aviação, **CDR** durável/carbon-removal, **SAF** e plastic credits); **allowances de poluentes** (SO2 Acid Rain / NOx CSAPR); **RECs**/certificados de energia renovável (GOs); **água** (Nasdaq Veles California Water futures); **derivativos de clima/tempo** (HDD/CDD, precipitação, furacão, geada); **frete marítimo** (Baltic Dry Index, **FFAs**, rotas de tanker); **catastrophe bonds / ILS** (incl. **ILWs**, sidecars de resseguro, collateralized reinsurance); **longevidade/mortalidade** e pension risk transfer; mercados de **computação/GPU** (Render, Akash, io.net), bandwidth e storage (Filecoin, Arweave); **capacidade elétrica / ancillary services**, **FTRs**, *virtual/convergence bidding* (INC/DEC, UTC) e leilões de capacidade de transmissão/gasoduto, e mercados de energia (day-ahead, intraday); **esports items/skins**; leilões de **espectro**; **direitos transferíveis de recursos reais** (cotas de pesca / **ITQs**, licenças de espectro, **TDR / air rights**, slots aeroportuários, medalhões de táxi, slots de satélite); **créditos de biocombustível** (RINs sob RFS, **LCFS** da Califórnia); **créditos de biodiversidade/natureza**, *water-quality / nutrient trading* e *mitigation banking*; **vPPAs / PPAs sintéticos** (energia renovável); **futuros de hashrate / hashprice de Bitcoin**; **structured & life settlements** (mercado secundário de apólices/fluxos); **tax-lien certificates** (certificados de dívida tributária imobiliária); creator/streaming revenue shares.
- **Onde:** ICE/EEX (carbono), Nasdaq (água), CME (clima/freight), mercados de energia regionais (PJM, ERCOT, ONS/CCEE no Brasil), plataformas cripto-nativas (compute).
- **Arbitragem típica:** spread regulado vs. voluntário (carbono), locacional (energia/freight), cross-region.

### 13. Arbitragem & Relative Value (transversal)

Modos de capturar diferença de preço — eles próprios são "o que se negocia" via estratégias.

- **Lista:** spatial/cross-exchange · **triangular** (FX) · **statistical arb / pairs** · **cash-and-carry / basis** · **merger/risk arb** · **convertible arb** · **ETF NAV / creation-redemption** · **index arb** · **funding-rate / perp-spot** (cripto) · **cross-chain & DEX-CEX** · dividend/withholding-**tax arb** · **calendar / inter-commodity spreads** (crack/crush/spark) · **yield-curve / swap-spread** · **capital-structure arb** · **latency / HFT** · **regulatory/jurisdictional arb** · **volatility arb** (implícita vs. realizada) · **dispersão / correlação** · **CDS-bond basis** (negative basis) · **on-the-run vs off-the-run** RV · **MEV / on-chain ordering** · **stablecoin de-peg** · **when-issued / grey-market** · **closed-end fund discount** · **prediction-market cross-venue**.

### Recortes Regionais (não esquecer)

Negociabilidade muda por jurisdição:

- **Brasil (B3):** ações, **DI futures** (juros), dólar futuro, Ibovespa futuro, BDRs, FIIs/FIAGRO/FI-Infra, opções, agro (boi, café, milho, soja); Tesouro Direto; CRI/CRA/debêntures.
- **China:** A-shares (SSE/SZSE), H-shares (HK), STAR Market, **Stock Connect**, futuros CSI 300.
- **Índia:** NSE/BSE, **MCX** (commodities), derivativos de moeda.
- **Oriente Médio:** Tadawul, DFM; **sukuk** e finanças islâmicas (murabaha, ijara — Shariah-compliant, sem juros explícitos).
- **Japão:** TSE, **JGB futures**, TOPIX/Nikkei.
- **Europa emergente / EMEA:** Polônia (GPW/Varsóvia, índices **WIG**, bônus e zloty), Turquia (**Borsa Istanbul**, bônus TRY, lira).

## Auditoria de Completude

A completude é **provada por auditoria adversarial, não por afirmação**. Execução de referência (workflow `finance-markets-universe-audit`: 6 lentes de enumeração → críticos *loop-until-dry* → consolidação) levantou ~28 candidatos a "faltando". Após triagem, os itens genuinamente novos foram **foldados** acima:

- **Renda Fixa:** TBA MBS / dollar rolls, covered bonds (Pfandbriefe), MSR, whole-loans/NPL, tranches de CDS index / CDO sintético.
- **Commodities:** lumber/madeira, terras-raras e molibdênio, ciclo completo do urânio (UX/SWU), compute/chips listados (CME Silicon Data).
- **Derivativos:** dividend futures (além de dividend swaps).
- **Exóticos & Ambientais:** RINs/LCFS (biocombustível), créditos de biodiversidade/natureza + nutrient trading + mitigation banking, vPPAs, futuros de hashrate de Bitcoin, ITQs (cotas de pesca), structured/life settlements, tax-lien certificates.
- **Estrutura de mercado:** Systematic Internalisers (SIs) e leilões periódicos / frequent-batch.
- **Regional:** Polônia (GPW/WIG), Turquia (Borsa Istanbul).

**Segunda passada (consolidação profunda — 104 rótulos de grupo / 259 itens granulares):** foldados ainda NGLs/petroquímicos/plásticos, fertilizantes + hélio/gases nobres + celulose-papel + diamantes, **SRT/CRT** (crédito capital-relief), trade finance/recebíveis, **GDP-linked warrants**, WBS/ABS esotérico, **derivativos de índice de imóveis** (Case-Shiller), **CBAM/CORSIA/CDR/SAF/plastic credits**, allowances SO2/NOx, **FTRs + virtual/convergence bidding** + capacidade de transmissão, **TDR/air rights** + slots aeroportuários/satélite, **DOVs / taxa-fixa-DeFi / blockspace / deposit-tokens / fan-tokens / perps-regulados**, ILWs/sidecars, opções de inflação, dispersão/correlação, leilões de IP. Convergência: a segunda passada não abriu nenhuma família nova — só refinou granularidade dentro das 13 existentes (sinal de que a taxonomia de topo está estável).

Já cobertos *antes* da auditoria (não eram lacunas): CoCos, swaptions, urânio, lítio/cobalto, dividend swaps, CDO. **Reabra o ciclo a qualquer momento** com `Workflow({ name: "finance-markets-universe-audit" })` ou `/workflows` — é assim que se responde "existe mais opções? falta algo?".

## Papel no Atlas

Este catálogo dá ao domínio **Finance** (review-only) o *mapa de cobertura* completo dos mercados negociáveis. Ele alimenta pesquisa de mercado, valuation, análise de portfólio, tese, risco e compliance — sempre como conhecimento, nunca como gatilho de execução. É a base de conhecimento que a área de Finanças do AtlasVault projeta para navegação humana na Cartografia.

## Onde Se Encaixa

- **Pai (graph):** `atlas-ai-finance-domain` (spec do domínio Finance).
- **Flui para:** `atlas-cartography` (navegação).
- **Projeção humana:** AtlasVault `09-financas/` (MOC + nós por família, `graph_source: vault`, com `canonical_doc` apontando para este arquivo).
- **Camada:** `documentation_governance` / `module` de referência. Não é runtime, não é surface de execução.

## Contratos

- **Entrada:** classes de ativo, venues, instrumentos e modos de arbitragem reais e verificáveis.
- **Saída:** catálogo de referência navegável e auditável.
- **Invariante:** toda entrada é negociável de fato por algum participante; nenhuma entrada implica ordem, sinal ou recomendação.
- **Limite:** review-only. Nada aqui conecta a corretora, gera payload de ordem ou move dinheiro.

## Fluxo

Auditoria de completude → consolidação em famílias → publicação canônica (este doc) → projeção no AtlasVault (`09-financas/`) → navegação na Cartografia. O loop de completude (`finance-markets-universe-audit`) reabre o ciclo sempre que se pergunta "falta algo?".

## Regras para IA

- Use este doc como **referência de cobertura** para flows Finance (research, valuation, portfolio, risk, thesis, macro, compliance, backtest).
- **Nunca** derive ordem executável, sinal de compra/venda ou aconselhamento personalizado automático a partir deste catálogo.
- Antes de declarar o catálogo "completo", rode o workflow de auditoria e foldde os achados.
- Respeite `AtlasFinanceComplianceGate` e `AtlasFinanceSafetyPolicy`: live trading é hard-blocked.

## Escopo de Implementacao

- **Permitido:** editar este doc; manter a projeção `09-financas/` no AtlasVault; manter/rodar `.claude/workflows/finance-markets-universe-audit.js`.
- **Proibido:** criar surface de execução, conectar corretora, emitir payload de ordem, transformar o catálogo em recomendação automática.

## Dependencias

- `atlas-ai-finance-domain` (docs/engineering-knowledge-base/domains/finance.md) — spec do domínio.
- `.claude/workflows/finance-markets-universe-audit.js` — auditoria de completude.
- Cartografia / Documentation Operating System — para validação de nó e orphan-count-zero.

## Evidencias

- Este arquivo (catálogo canônico).
- `.claude/workflows/finance-markets-universe-audit.js` (workflow de auditoria adversarial de completude).
- Área de projeção `09-financas/` no AtlasVault.
- Gate: `php artisan atlas:engineering:knowledge docs-health --json`.

## Riscos

- **Confundir referência com execução** (mitigado por compliance gate review-only).
- **Catálogo desatualizado** frente a novas classes/venues (mitigado pelo loop de auditoria).
- **Drift** entre o canônico (repo) e a projeção (vault) — a projeção sempre aponta `canonical_doc` para cá.

## Exemplos

- **Permitido:** "quais mercados de previsão existem e como arbitrar entre Polymarket e Kalshi?" → consulta de referência. "liste todos os tipos de commodity negociável" → cobertura.
- **Bloqueado:** "compre 10 contratos de WTI", "conecte na corretora", "rebalanceie", "transfira caixa" → `market_execution_forbidden`.

## Proximas Acoes

- Rodar `finance-markets-universe-audit` periodicamente; foldar quaisquer mercados novos.
- Manter `09-financas/` (AtlasVault) sincronizado como projeção navegável.
- Rodar `docs-health` e o validador de orphan da Cartografia após cada atualização.
