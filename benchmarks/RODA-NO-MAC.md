# Roda no meu Mac — lista PROVADA

Teste-de-fogo real (clone + install eval-only + smoke) nos 52 benchmarks nativos, no seu Mac arm64, 2026-07-19. **Nenhum ❌ falhou de vez.**

- **✅ 26 rodam limpo** (instalaram + smoke passou, zero mexida) — a lista garantida.
- **🟡 22 rodam com ajuste** (patch pequeno de macOS, bug de repo, ou troca de dependência — ver o README da área).
- **⚪ 4 sem código público** (não liberado — não dá pra rodar).

Comandos que funcionaram + ressalva de cada um: no README de cada área. Braços: **sem Atlas** (modelo cru) e **com Atlas** (`atlas:cli:dev` com Hermes dentro).

## ✅ Rodam limpo — por área (a lista garantida)

**🧭 Produto:** DeepResearch Bench · BrowseComp · GAIA · DABStep · BIRD · tau2-bench · ForecastBench
**🎨 Design & UX:** InfiAgent-DABench
**⚙️ Engenharia:** ArchBench · CRUXEval · ClassEval · RepoBench · LocAgent · debug-gym · TestEval
**🔒 Qualidade & Segurança:** IDoFT
**📊 Dados:** StatQA · Corr2Cause · GIFT-Eval · nvBench 2.0 · OpenRCA · AIRepr
**📈 Crescimento:** QRData
**💼 Negócio:** CUAD · LegalBench · CEO-Bench

## 🟡 Rodam com ajuste — por área

**🎨 Design & UX:** Design2Code · ScreenSpot-Pro · CANVAS · UICrit · AesEval-Bench · DesignBench
**⚙️ Engenharia:** R2ABench · EvalPlus · CrossCodeEval · BigCodeBench · DevEval · Long Code Arena · REval
**🔒 Qualidade & Segurança:** SWR-Bench · PrimeVul · CWEval
**📊 Dados:** DS-1000
**📈 Crescimento:** NegotiationArena
**💼 Negócio:** FinanceBench · Finance Agent Benchmark · MAUD · PlanBench

## ⚪ Sem código público

FermiEval · WebAccessBench · Creativity Benchmark · PersuasionBench

## Integrados (já rodando, nativos)
live_code_bench · aider_polyglot · bfcl (engenharia) · inspect_evals · tau2_bench (conhecimento, cru)
