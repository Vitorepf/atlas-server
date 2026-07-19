# 📈 Crescimento & GTM

Provado por teste-de-fogo (clone + install eval-only + smoke) no seu Mac arm64, 2026-07-19. **✅ roda limpo · 🟡 roda com ajuste · ⚪ sem código público.** Comandos abaixo são os que **funcionaram de verdade**.

## ✅ Rodam limpo (provado, zero mexida)

### QRData
- **repo:** https://github.com/xxxiaol/QRData
- **instalar:** `git clone --depth 1 https://github.com/xxxiaol/QRData qrdata && cd qrdata && python3 -m venv .venv   # eval path has ZERO pip deps: benchmark/eval.py imports only stdlib json+re (no torch/vllm/cuda/numpy)`
- **smoke:** `cd qrdata/benchmark && ../.venv/bin/python -c "import json;d=json.load(open('QRData.json'));s=set();p=[{'pred':x['answer'],'answer':x['answer'],'meta_data':x['meta_data']} for x in d if not (x['meta_data']['question_type…`
- **ressalva:** Nenhum blocker. eval.py roda nativo em arm64 (Python 3.14.4), 100% stdlib, smoke gold-vs-gold acc=1.0. Ressalva menor (nao impede): eval.py le 'tmp.json' hardcoded no cwd e nao tem --help/CLI runner; smoke usou o caminho 'avaliar amostras gold'. Rodar o benchmark completo exige unzip do data.zip (21…

## 🟡 Rodam com ajuste

### NegotiationArena
- **repo:** https://github.com/vinid/NegotiationArena
- **instalar:** `git clone --depth 1 https://github.com/vinid/NegotiationArena.git negotiationarena && uv venv --python 3.11 .venv && . .venv/bin/activate && uv pip install openai python-dotenv==1.0.0 anthropic==0.5.0   # eval-only: NO t…`
- **smoke:** `NEGOTIATION_LOG_FOLDER="$PWD/.logs/" PYTHONPATH=. python smoke_eval.py   # -> "arch: arm64 py: 3.11.15 / SMOKE OK: import graph + scorer payoff + parser all green"`
- **ressalva:** Eval path roda NATIVO arm64 (sem Docker/x86/GPU): BuySellGame+SimpleGame importam, scorer (Valuation.value/Trade.execute_trade -> payoff 40->60=+20) e parser (extract_multiple_tags/text_to_dict) verdes. Ressalva REAL (repo bug, NAO arm64): games.trading_game e games.ultimatum quebram no main -> "Imp…

## ⚪ Sem código público (não roda)

### Creativity Benchmark
- **repo:** N/A — nenhum repo público clonável. arxiv 2509.09702 (Springboards.ai / Bhat, Browne, Bingemann) não publica código nem …
- **motivo (sem repo):** Benchmark de avaliação HUMANA fechado: o "scorer" são 678 creatives com 11.012 votos pareados ajustados por Bradley-Terry. Nenhum código de avaliação, gold dataset ou runner foi liberado (paper sem seção de code/data availability). Não há artefato para rodar em arm64 — a matemática Bradley-Terry é t…

### PersuasionBench
- **repo:** Nenhum repo de codigo. Unico artefato publico oficial: HF dataset https://huggingface.co/datasets/behavior-in-the-wild/P…
- **motivo (sem repo):** Nao existe repo github de codigo do PersuasionBench/PersuasionArena oficial. A pagina do projeto (behavior-in-the-wild.github.io/measure-persuasion.html) tem botao 'Code' mas o href e placeholder morto ./index.html. Nem a org behavior-in-the-wild (10 repos) nem o 1o autor someshsingh22 (39 repos) pu…

