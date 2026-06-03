export const meta = {
  name: 'finance-markets-universe-audit',
  description: 'Exhaustively enumerate every tradable market / product / venue / instrument and arbitrage mode, adversarially hunt for anything missing (loop-until-dry), then consolidate one canonical taxonomy. Re-run anytime to answer "is anything missing from the catalog?".',
  phases: [
    { title: 'Enumerate', detail: 'one agent per discovery lens (asset class, instrument type, venue/structure, emerging/exotic, arbitrage, regional)' },
    { title: 'Critique', detail: 'completeness critics hunt for absent markets; loop until a dry round' },
    { title: 'Consolidate', detail: 'merge + dedup all lenses + missing into one canonical taxonomy tree' },
  ],
}

// ---- schemas ---------------------------------------------------------------
const ENUM_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['lens', 'categories'],
  properties: {
    lens: { type: 'string' },
    categories: {
      type: 'array',
      items: {
        type: 'object', additionalProperties: false,
        required: ['name', 'group', 'description', 'instruments', 'example_venues', 'arbitrage_modes', 'tradability_notes'],
        properties: {
          name: { type: 'string' },
          group: { type: 'string' },
          description: { type: 'string' },
          instruments: { type: 'array', items: { type: 'string' } },
          example_venues: { type: 'array', items: { type: 'string' } },
          arbitrage_modes: { type: 'array', items: { type: 'string' } },
          tradability_notes: { type: 'string' },
        },
      },
    },
  },
}

const CRITIC_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['missing', 'is_dry', 'assessment'],
  properties: {
    is_dry: { type: 'boolean' },
    assessment: { type: 'string' },
    missing: {
      type: 'array',
      items: {
        type: 'object', additionalProperties: false,
        required: ['name', 'group', 'why_missing', 'example_venues'],
        properties: {
          name: { type: 'string' },
          group: { type: 'string' },
          why_missing: { type: 'string' },
          example_venues: { type: 'array', items: { type: 'string' } },
        },
      },
    },
  },
}

const CONSOLIDATE_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['groups', 'arbitrage_taxonomy', 'total_categories'],
  properties: {
    total_categories: { type: 'integer' },
    groups: {
      type: 'array',
      items: {
        type: 'object', additionalProperties: false,
        required: ['group', 'summary', 'categories'],
        properties: {
          group: { type: 'string' },
          summary: { type: 'string' },
          categories: {
            type: 'array',
            items: {
              type: 'object', additionalProperties: false,
              required: ['name', 'description', 'instruments', 'venues', 'arbitrage_modes', 'notes'],
              properties: {
                name: { type: 'string' },
                description: { type: 'string' },
                instruments: { type: 'array', items: { type: 'string' } },
                venues: { type: 'array', items: { type: 'string' } },
                arbitrage_modes: { type: 'array', items: { type: 'string' } },
                notes: { type: 'string' },
              },
            },
          },
        },
      },
    },
    arbitrage_taxonomy: {
      type: 'array',
      items: {
        type: 'object', additionalProperties: false,
        required: ['name', 'description', 'markets_involved'],
        properties: {
          name: { type: 'string' },
          description: { type: 'string' },
          markets_involved: { type: 'array', items: { type: 'string' } },
        },
      },
    },
  },
}

// ---- discovery lenses ------------------------------------------------------
const LENSES = [
  { key: 'asset-class', prompt: 'Enumerate tradable markets by ASSET CLASS. Cover, exhaustively within each: equities (common/preferred shares, ADR/GDR, ETFs on equity, REITs, BDCs, SPACs), fixed income (sovereign/treasuries, municipals, corporates IG/HY, agency/MBS/ABS, convertibles, inflation-linked, EM debt, money-market instruments, repos), FX (majors, minors, exotics, NDFs, stablecoins as FX), commodities (energy: crude/brent/WTI/natgas/LNG/power/coal; metals: precious gold/silver/platinum/palladium and base copper/aluminium/zinc/nickel; agriculture: grains/oilseeds/softs coffee-cocoa-sugar-cotton; livestock), real assets / real estate (direct, REITs, RE tokens, farmland, timber, infrastructure), digital assets / crypto (L1/L2 coins, tokens, stablecoins), cash & money markets, and alternatives (private equity/VC secondaries, hedge funds, collectibles).' },
  { key: 'instrument-type', prompt: 'Enumerate tradable markets by INSTRUMENT / CONTRACT TYPE (the form of the position, independent of underlying): spot/cash, forwards, futures (commodity, financial, index, single-stock, FX, interest-rate, crypto), options (vanilla calls/puts, American/European, exotics: barrier/asian/digital/lookback, FLEX, weeklys), swaps (interest-rate IRS/OIS, credit default CDS & index CDX/iTraxx, total-return TRS, FX swaps, basis, variance/volatility swaps, commodity swaps), CFDs, spread bets, warrants & covered warrants, structured products / structured notes (autocallables, reverse convertibles, certificates), ETPs (ETF, ETN, ETC, leveraged/inverse), funds (mutual/open-end, closed-end, interval, UCITS), depositary receipts (ADR/GDR), perpetual swaps / perps, repos & reverse repos, securities lending, when-issued / grey market, rights/units/subscription, contracts-for-future-delivery, and prediction/event contracts (binary outcome shares).' },
  { key: 'venue-structure', prompt: 'Enumerate tradable markets by VENUE & MARKET STRUCTURE, naming notable real venues globally per type: regulated securities & derivatives exchanges (NYSE, Nasdaq, CME, CBOE, ICE, Eurex, LSE, JPX, HKEX, B3, NSE/BSE, SGX, etc.), MTFs / ATS, OTC dealer / interdealer markets, dark pools, ECNs, primary issuance / auctions (treasury auctions, IPO/DPO book-building), centralized crypto exchanges (Binance, Coinbase, OKX, Bybit, Kraken), decentralized exchanges / AMMs (Uniswap, Curve, dYdX, Hyperliquid, GMX), perp DEXes, prediction-market venues (Polymarket, Kalshi, PredictIt, Manifold, Augur-style), peer-to-peer markets, interbank FX & money markets, and physical/spot commodity markets. For each structure note what is traded there.' },
  { key: 'emerging-exotic', prompt: 'Enumerate EMERGING, EXOTIC and ALTERNATIVE tradable markets that generalists forget. Include: prediction / event markets (Polymarket, Kalshi, sports/elections/econ outcomes), tokenized real-world assets (RWA: tokenized treasuries, private credit, real estate, equities), carbon & emissions allowances (EU ETS, voluntary carbon credits), renewable energy certificates (RECs/GOs), water rights/futures (Nasdaq Veles), weather derivatives (HDD/CDD, rainfall, hurricane), freight & shipping (Baltic Dry, FFAs, tanker routes), volatility products (VIX futures/options, variance, VVIX), crypto-native (perpetual futures, on-chain options, liquid staking tokens, restaking, basis/funding, NFTs, ordinals/inscriptions, memecoins, governance tokens, points/airdrop markets, prediction), collectibles & passion assets (fine art & fractional art, wine & whisky, watches, trading cards, rare coins, sneakers, domains), intellectual property / music royalties, compute & GPU / bandwidth / storage markets, electricity & capacity / ancillary-services power markets, longevity / mortality / catastrophe bonds & ILS, esports items / skins, fractionalized & crowdfunded assets, and litigation finance. You SHOULD load WebSearch via ToolSearch to verify the very latest venues and instruments (2025-2026), especially prediction markets, tokenized RWA, and crypto.' },
  { key: 'arbitrage-relval', prompt: 'Enumerate ARBITRAGE and RELATIVE-VALUE modes that are themselves tradable strategies, and name the markets each spans: spatial / cross-exchange arbitrage, triangular FX arbitrage, statistical arbitrage & pairs trading, cash-and-carry / basis trade (futures vs spot), merger / risk arbitrage, convertible arbitrage, ETF NAV / creation-redemption arbitrage, index arbitrage (futures vs basket), funding-rate / perp-spot arbitrage (crypto), cross-chain & DEX-CEX arbitrage, dividend / withholding-tax arbitrage, calendar / inter-commodity spreads, yield-curve & swap-spread trades, capital-structure arbitrage, latency / HFT arbitrage, regulatory & jurisdictional arbitrage, and prediction-market cross-venue arbitrage. For each, list markets_involved and a one-line description in tradability_notes.' },
  { key: 'regional-jurisdiction', prompt: 'Enumerate by GEOGRAPHY / JURISDICTION to catch region-specific tradable markets that a US/EU-centric list misses: US & Canada, Latin America (Brazil B3 — Ibovespa, DI futures, BDRs; Mexico, Chile, Argentina), UK, EU & Nordics, Switzerland, Middle East (Tadawul, DFM, sukuk / Islamic finance murabaha/ijara), Africa (JSE South Africa, Nigeria, Kenya), India (NSE/BSE — SEBI products, currency derivatives, commodity MCX), China (A-shares, H-shares, STAR Market, Stock Connect, CSI futures), Japan (TSE, JGB futures, TOPIX/Nikkei), South Korea, Southeast Asia (SGX, Bursa, IDX, SET, PSE), and Australia/NZ (ASX, electricity). Include region-specific instruments and any Shariah-compliant structures.' },
]

// ---- run -------------------------------------------------------------------
phase('Enumerate')
const enums = (await parallel(LENSES.map((l) => () => agent(
  `You are a senior markets taxonomist building the definitive catalog of everything a participant can take a position in.\n\n${l.prompt}\n\nRules: every category must be a real market/product where someone can actually trade (or arbitrage). Prefer breadth + precision over prose. Be exhaustive — assume an adversarial reviewer will hunt for anything you omit.`,
  { label: `enum:${l.key}`, phase: 'Enumerate', schema: ENUM_SCHEMA },
)))).filter(Boolean)

const totalCats = enums.reduce((n, e) => n + e.categories.length, 0)
log(`Enumerated ${totalCats} categories across ${enums.length} lenses`)

// completeness loop-until-dry: max 3 rounds, 3 critics per round, stop on first dry round
phase('Critique')
const missing = []
const norm = (s) => String(s || '').toLowerCase().trim()
const known = () => new Set(
  enums.flatMap((e) => e.categories.map((c) => norm(c.name)))
    .concat(missing.map((m) => norm(m.name))),
)
for (let round = 0; round < 3; round++) {
  const seen = Array.from(known()).sort().join(', ')
  const critics = (await parallel([1, 2, 3].map((i) => () => agent(
    `Completeness audit — critic #${i}, round ${round + 1}. The catalog currently covers these tradable-market categories:\n\n${seen}\n\nAsk relentlessly: what is MISSING? Name any market, product, venue, instrument, or arbitrage that a real participant can take a position in but that is NOT already in the list above. Think exotic, regional, institutional-only, OTC, newly launched, crypto-native. Report ONLY items genuinely absent. If you can find nothing new, set is_dry=true and return an empty missing array.`,
    { label: `critic:r${round + 1}-${i}`, phase: 'Critique', schema: CRITIC_SCHEMA },
  )))).filter(Boolean)

  const fresh = []
  const k = known()
  for (const c of critics) {
    for (const m of c.missing) {
      if (!k.has(norm(m.name)) && !fresh.some((f) => norm(f.name) === norm(m.name))) {
        fresh.push(m)
      }
    }
  }
  if (fresh.length === 0) { log(`Round ${round + 1}: DRY — no new markets found, stopping critique`); break }
  missing.push(...fresh)
  log(`Round ${round + 1}: +${fresh.length} missing markets found (running total missing: ${missing.length})`)
}

phase('Consolidate')
const taxonomy = await agent(
  `Consolidate this raw multi-lens enumeration of tradable markets into ONE clean, canonical, non-overlapping taxonomy.\n\n` +
  `Instructions: merge duplicates that appear across lenses; group every category under a top-level asset/market FAMILY; keep every genuinely distinct tradable category (do not collapse real distinctions); fold the separately-found "missing" items into the right family; populate the dedicated arbitrage_taxonomy from arbitrage/relative-value items. Be exhaustive and precise — the goal is that nothing real is dropped.\n\n` +
  `RAW DATA (JSON):\n${JSON.stringify({ lenses: enums, missing })}`,
  { label: 'consolidate', phase: 'Consolidate', schema: CONSOLIDATE_SCHEMA },
)

return { taxonomy, raw_missing: missing, lens_count: enums.length, raw_category_count: totalCats }
