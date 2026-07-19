# 📊 Dados & Experimentação

Provado por teste-de-fogo (clone + install eval-only + smoke) no seu Mac arm64, 2026-07-19. **✅ roda limpo · 🟡 roda com ajuste · ⚪ sem código público.** Comandos abaixo são os que **funcionaram de verdade**.

## ✅ Rodam limpo (provado, zero mexida)

### StatQA
- **repo:** https://github.com/HKUSTDial/StatQA
- **instalar:** `cd /Users/vitorepf/develop/Atlas/atlas-server/tools/rivals/benchmarks/_prova/statqa && uv venv .venv && uv pip install --python .venv/bin/python pandas numpy scipy scikit-learn seaborn matplotlib   # eval/scorer ONLY — N…`
- **smoke:** `MPLBACKEND=Agg .venv/bin/python -c "import analyze_model_answer as s, pandas as pd, json; df=pd.read_csv('.smoke/gpt-4o_zero-shot.csv'); rows=[json.loads(s.compare_and_count_answer_row('methods',r['extracted_answer'],r['…`
- **ressalva:** Sem blocker no caminho de eval: scorer (analyze_model_answer.py) importou e pontuou 1157 linhas gold reais no arm64 (CPython 3.12.13), so scientific stack. Ressalvas honestas: requirements.txt fixa vllm==0.4.2 (CUDA-only, sem wheel arm64) e openai — caminho de GERACAO local, excluido (modelo via API…

### Corr2Cause
- **repo:** https://github.com/causalNLP/corr2cause
- **instalar:** `uv venv .venv --python 3.14 && uv pip install --python .venv/bin/python scikit-learn pandas efficiency   # eval-only: NO torch/transformers/vllm/cuda (all lazy-imported)`
- **smoke:** `.venv/bin/python smoke_eval.py   # imports run_model, boots Constants(), runs real scorer Model.perf_df2report_dict (sklearn classification_report) on dummy gold/pred -> F1 66.67 Acc 75.0`

### GIFT-Eval
- **repo:** https://github.com/SalesforceAIResearch/gift-eval
- **instalar:** `git clone --depth 1 https://github.com/SalesforceAIResearch/gift-eval <dir> && cd <dir> && uv venv --python 3.11 .venv && uv pip install --python .venv -e .   # eval-only: torch/vllm/cuda ficam so no extra [baseline], qu…`
- **smoke:** `PYTHONPATH=src .venv/bin/python -c 'import gift_eval.data; from gluonts.model import evaluate_model; from gluonts.ev.metrics import MSE,MAE,MASE,MAPE,SMAPE,MSIS,RMSE,NRMSE,ND,MeanWeightedSumQuantileLoss; from gluonts.dat…`
- **ressalva:** Sem blocker de arch. Ressalva honesta (nao impede o scorer): rodar o benchmark COMPLETO exige baixar os datasets GIFT-Eval do HuggingFace (varios GB) — download de dados, nao incompatibilidade arm64. E: usar python3.11, nao o python3.14 do sistema (pins velhos sem wheel cp314).

### nvBench 2.0
- **repo:** https://github.com/HKUSTDial/nvBench-2.0
- **instalar:** `cd tools/rivals/benchmarks/_prova/nvbench_2_0/code/evaluation && uv venv .venv && uv pip install --python .venv/bin/python numpy pandas   # eval-only: no torch/vllm/cuda`
- **smoke:** `.venv/bin/python evaluation.py --help  (argparse OK) + import evaluate_metrics from evaluation & run on 1 real gold_answer from ../../data/nvbench2.0/test.json: perfect pred -> Hit@1=1.0 Precision@5=1.0 Recall@5=1.0 F1@5…`
- **ressalva:** Nao e blocker de arm64 (roda nativo, wheels arm64, sem Docker/GPU). Ressalva de repo-hygiene: evaluation.py __main__ hardcoda raw_data/nvbench_metadata.json + raw_data/test.json com chaves csv_filename/model_predict/ground_truth, mas o repo entrega dados em data/nvbench2.0/*.json com chaves csv_file…

### OpenRCA
- **repo:** https://github.com/microsoft/OpenRCA
- **instalar:** `cd .../benchmarks/_prova/openrca && uv venv --python 3.12 .venv && VIRTUAL_ENV=.venv uv pip install pandas   # eval-only: pandas é a ÚNICA dep que o scorer main/evaluate.py usa (import lazy). requirements.txt restante (a…`
- **smoke:** `VIRTUAL_ENV=.venv .venv/bin/python -m main.evaluate --help   # (smoke 1: runner CLI sobe no arm64). Smoke 2 (dados reais, sem mock/sem download): alimentar predição arquivada real de rca/archive/agent-Bank.csv (row 1, ta…`
- **ressalva:** Nenhum bloqueio no avaliador: scorer roda 100% nativo arm64 (numpy .so = Mach-O arm64), smoke reproduziu score publicado com dado real do repo. Ressalvas honestas (não bloqueiam o eval): (1) RUN ponta-a-ponta do agente precisa do dataset de telemetria via Google Drive (grande) + chave de API — o sco…

### AIRepr
- **repo:** https://github.com/qunhualilab/LLM-DS-Reproducibility
- **instalar:** `uv venv --python 3.11 .venv && uv pip install --python .venv/bin/python pandas==2.2.3 numpy==1.26.4 scikit-learn==1.5.2 matplotlib==3.9.3 statsmodels==0.14.4 scipy==1.11.4 seaborn==0.13.2 econml==0.15.1 pingouin==0.5.5 b…`
- **smoke:** `.venv/bin/python -m eval.run_reproducibility --help   # + import eval.reproducibility, eval.run_reproducibility, utils.code_execution`
- **ressalva:** Nenhum bloqueio arm64: zero torch/vllm/cuda no requirements, todas as wheels nativas macosx_arm64 (sklearn .so = Mach-O arm64), ambos os smokes exit 0. Ressalva (nao-arm64): venv exige Python 3.11 (pins numpy1.26.4/scipy1.11.4 nao tem wheel pra py3.14 do sistema); e um score REAL end-to-end ainda pr…

## 🟡 Rodam com ajuste

### DS-1000
- **repo:** https://github.com/xlang-ai/DS-1000
- **instalar:** `uv venv --python 3.10 .venv && VIRTUAL_ENV=.venv uv pip install "numpy==1.26.4" "pandas==1.5.3" tqdm   # eval-only, native arm64 wheels; NO torch/tf/cuda`
- **smoke:** `.venv/bin/python smoke_arm64.py   # real scorer (execution.check_correctness) scores gold reference_code for Numpy+Pandas = PASS; requires multiprocessing.set_start_method("fork") at top`
- **ressalva:** Roda nativo arm64 (wheels arm64 reais, sem Docker/GPU/x86, scorer pontua gold=PASS). Ressalva real: execution.py assume fork do Linux; no macOS o default spawn quebra com "Can't pickle local object 'check_correctness.<locals>.unsafe_execute'" — precisa de multiprocessing.set_start_method("fork", for…

