# 🧭 Produto & Estratégia

Provado por teste-de-fogo (clone + install eval-only + smoke) no seu Mac arm64, 2026-07-19. **✅ roda limpo · 🟡 roda com ajuste · ⚪ sem código público.** Comandos abaixo são os que **funcionaram de verdade**.

## ✅ Rodam limpo (provado, zero mexida)

### DeepResearch Bench
- **repo:** https://github.com/Ayanami0730/deep_research_bench
- **instalar:** `python3 -m venv .venv && ./.venv/bin/pip install -r requirements.txt  (deps eval-only: tqdm/pandas/numpy/requests — wheels arm64 nativos, zero torch/vllm/cuda)`
- **smoke:** `./.venv/bin/python deepresearch_bench_race.py --help  (scorer RACE + módulos FACT importam) + run funcional de utils.score_calculator.calculate_weighted_scores sobre data/criteria_data/criteria.jsonl (gold real) → scores…`
- **ressalva:** Nenhum bloqueio arm64. Ressalvas by-design (não rebaixam): score final real precisa de API de LLM-judge externo (utils/api.py) e fase FACT faz scraping web ao vivo — modelo/juiz vêm por API externa como previsto. utils/generate_criteria.py tem import relativo quebrado (from ..prompt), mas é bug de r…

### BrowseComp
- **repo:** https://github.com/openai/simple-evals
- **instalar:** `cd /Users/vitorepf/develop/Atlas/atlas-server/tools/rivals/benchmarks/_prova/browsecomp && uv venv .venv && uv pip install --python .venv/bin/python pandas jinja2 numpy requests tqdm`
- **smoke:** `cd /Users/vitorepf/develop/Atlas/atlas-server/tools/rivals/benchmarks/_prova && browsecomp/.venv/bin/python -c "import base64; from browsecomp import browsecomp_eval as bc; p=b'Berlin'; ct=base64.b64encode(bytes(a^b for …`
- **ressalva:** Nenhum blocker de arm64. Eval/scorer (browsecomp_eval.py) e 100% Python puro; deps eval-only (pandas/jinja2/numpy/requests/tqdm) instalam como wheels arm64 prebuilt, zero torch/vllm/cuda, zero compilacao. Smoke verde: importa o modulo de eval + roda o decrypt do scorer no arm64 (exit 0). Ressalvas i…

### GAIA
- **repo:** https://huggingface.co/spaces/gaia-benchmark/leaderboard
- **instalar:** `python3 -m venv .venv && ./.venv/bin/pip install numpy`
- **smoke:** `./.venv/bin/python -c "from scorer import question_scorer; assert question_scorer('$1,234.5','1234.5') and question_scorer('Paris','paris') and not question_scorer('London','Paris')"`

### DABStep
- **repo:** https://huggingface.co/spaces/adyen/DABstep
- **instalar:** `GIT_LFS_SKIP_SMUDGE=1 git clone --depth 1 https://huggingface.co/spaces/adyen/DABstep dabstep && cd dabstep && uv venv .venv && uv pip install --python .venv/bin/python pytest`
- **smoke:** `.venv/bin/python -m pytest dabstep_benchmark/tests/test_scorer.py -q  # 48 passed. Zero-dep: .venv/bin/python -c "from dabstep_benchmark.evaluation.scorer import question_scorer as q; print(q('\$42.00','42'))" -> True`
- **ressalva:** Nenhum no arm64. Ressalva honesta (nao bloqueia eval): o corretor/scorer (scorer.py) e Python PURO stdlib (re/typing/math/difflib) e roda com ZERO deps de ML. Instalei so pytest p/ rodar a suite oficial do scorer = 48/48 passaram no arm64 (uv CPython 3.12.13), sem Docker/GPU/torch/vllm. O requiremen…

### BIRD
- **repo:** https://github.com/AlibabaResearch/DAMO-ConvAI (subdir: bird)
- **instalar:** `python3 -m venv .venv && .venv/bin/pip install func_timeout numpy`
- **smoke:** `.venv/bin/python bird/llm/src/evaluation.py --db_root_path <dbs>/ --predicted_sql_path <pred>/ --ground_truth_path <gt>/ --data_mode dev --diff_json_path <diff>.json --num_cpus 1 --meta_time_out 30  # 3-sample gold fixtu…`
- **ressalva:** Nenhum blocker arm64: install limpo (numpy cp314 arm64 wheel, func_timeout pure-Python), smoke RC 0. Caveat platform-agnostico: run completo do leaderboard exige baixar o dataset BIRD de 33.4GB (nao e limitacao arm64).

### tau2-bench
- **repo:** https://github.com/sierra-research/tau2-bench
- **instalar:** `cd tau2_bench && uv sync   # uv auto-fetches Python 3.12 per .python-version; core-only, NO torch/vllm/cuda (model via litellm API)`
- **smoke:** `env -u OPENAI_API_KEY -u ANTHROPIC_API_KEY uv run tau2 evaluate-trajs one_sim.json   # re-scored 1 gold sim offline. Also: uv run python -c "import tau2.evaluator.evaluator" ; uv run tau2 evaluate-trajs --help`
- **ressalva:** Nenhum bloqueio. arm64 nativo (python 3.12.13). Ressalva honesta: Python do sistema e 3.14 (repo pina >=3.12,<3.14) mas uv baixa 3.12 sozinho; e o caminho de LLM-judge/NL-assertion (tau2 review) precisa de API key — o scoring deterministico core (ENV/ACTION/COMMUNICATE/DB-match) roda 100% offline, p…

### ForecastBench
- **repo:** https://github.com/forecastingresearch/forecastbench
- **instalar:** `uv venv --python 3.12 .venv && source .venv/bin/activate && uv pip install numpy "pandas>=2.2.2,<3.0" pandera python-dateutil pytz yfinance backoff beautifulsoup4 requests certifi scipy pytest pytest-mock "git+https://gi…`
- **smoke:** `PYTHONPATH=src python -m pytest src/tests/test_resolve_all.py src/tests/test_impute.py src/tests/test_prepare.py -q  # => 31 passed in 0.23s (resolution/scoring corretor on arm64)`
- **ressalva:** Nenhum blocker de arm64: clone OK, venv Mach-O arm64 (py3.12.13), deps do avaliador (pandas/scipy/pandera/statsmodels/pyfixest) instalam so por wheel arm64, zero compilacao, 31 testes do corretor de resolucao verdes; stat stack do leaderboard (pyfixest 0.60.0 + statsmodels 0.14.6) importa limpo. Res…

## ⚪ Sem código público (não roda)

### FermiEval
- **repo:** Sem repo oficial. Autores (Stanford: Epstein/Winnicki/Sornwanee/Dwaraknath) publicaram só o paper arxiv 2510.26995 — nen…
- **motivo (sem repo):** O avaliador/scorer do FermiEval (Winkler interval score + conformal prediction, descritos no paper) NAO tem repo publico. Evidencias: GitHub repo-search 'FermiEval'=0; code-search=2 repos nao-relacionados; PapersWithCode/OpenReview sem link de codigo; varredura dos 5 autores (rajatvd, eepstein, elli…

