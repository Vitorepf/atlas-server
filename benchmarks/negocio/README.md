# 💼 Negócio & Operações

Provado por teste-de-fogo (clone + install eval-only + smoke) no seu Mac arm64, 2026-07-19. **✅ roda limpo · 🟡 roda com ajuste · ⚪ sem código público.** Comandos abaixo são os que **funcionaram de verdade**.

## ✅ Rodam limpo (provado, zero mexida)

### CUAD
- **repo:** https://github.com/TheAtticusProject/cuad
- **instalar:** `uv venv .venv && VIRTUAL_ENV=.venv uv pip install pandas numpy scikit-learn  (eval-only; evaluate.py precisa só de pandas+numpy+sklearn — torch/transformers ficam em train.py e não são usados para pontuar)`
- **smoke:** `.venv/bin/python -c "import evaluate; print('cats', len(evaluate.qtype_dict)); print(evaluate.compute_precision_recall({'D__Parties':['Acme Corp']},{'D__Parties':['Acme Corp']}))"  → import lê category_descriptions.csv v…`

### LegalBench
- **repo:** https://github.com/HazyResearch/legalbench
- **instalar:** `python3 -m venv .venv && .venv/bin/pip install pandas scikit-learn nltk numpy   # eval-only; ZERO torch/vllm/cuda (repo has no requirements.txt/setup.py — the scorer evaluation.py imports only these 4)`
- **smoke:** `.venv/bin/python -c "import csv,evaluation; a=[r['answer'] for r in list(csv.DictReader(open('tasks/abercrombie/train.tsv'),delimiter='\t'))[:5]]; print('perfect',evaluation.evaluate('abercrombie',a,a)); w=a[:]; w[0]='x'…`

### CEO-Bench
- **repo:** https://github.com/zlab-princeton/ceobench-src
- **instalar:** `cd tools/rivals/benchmarks/_prova/ceo_bench && uv venv --python 3.13 .venv && uv pip install --python .venv/bin/python "numpy>=2.4.1" "pandas>=2.3.3" "scikit-learn>=1.8.0" "pytest>=9.0.2"  # eval/scorer subset only; NO t…`
- **smoke:** `PYTHONPATH=src ./.venv/bin/python -c "from saas_bench.config import BenchmarkConfig; from saas_bench.database import init_database; from saas_bench.simulation import Simulator; from saas_bench.tools import AgentTools; pr…`
- **ressalva:** Nenhum no caminho de avaliacao. Scorer roda 100% nativo arm64: numpy/pandas/scikit-learn instalados como wheels arm64 prebuilt (zero compilacao), DB via sqlite3 stdlib. Ressalva de escopo (nao bloqueio): instalei so o subset do scorer; o `uv sync` completo puxa weasyprint (pango/cairo nativo), modal…

## 🟡 Rodam com ajuste

### FinanceBench
- **repo:** https://github.com/patronus-ai/financebench
- **instalar:** `cd /Users/vitorepf/develop/Atlas/atlas-server/tools/rivals/benchmarks/_prova/financebench && uv venv .venv --python 3.12 && source .venv/bin/activate && uv pip install pandas langchain langchain-community openai anthropi…`
- **smoke:** `source .venv/bin/activate && python smoke_eval.py   # loads gold+meta, joins (150q), scores 150 model completions vs gold labels (128 Correct/22 Incorrect), imports langchain harness -> SMOKE_OK on arm64`
- **ressalva:** Eval substrate RODA native arm64 (no Docker/GPU/torch): pandas gold+completions load/join/score 150 rows, langchain 1.3.14 harness imports clean. Ressalvas reais: (1) FinanceBench NAO tem scorer automatico — score final e revisao humana manual; usei a coluna `label` ja anotada, nao computada. (2) O …

### Finance Agent Benchmark
- **repo:** https://github.com/vals-ai/finance-agent
- **instalar:** `cd /Users/vitorepf/develop/Atlas/atlas-server/tools/rivals/benchmarks/_prova/finance_agent_benchmark && uv venv --python 3.12 && uv sync --no-dev   # eval/harness-only, NO torch/vllm/cuda; all wheels arm64/universal2 nat…`
- **smoke:** `.venv/bin/python -c "import platform; from finance_agent import run_agent, get_agent, tools; from finance_agent.tools import VALID_TOOLS; print(platform.machine())" && .venv/bin/finance-agent --help   # both OK on arm64;…`
- **ressalva:** Harness boots 100% native on arm64 (no Docker/GPU/x86, no ML deps). RESSALVA: o repo aberto e SO o agentic harness (geracao de resposta); o corretor/scorer rubric LLM-as-judge NAO esta no repo — roda na plataforma Vals gated (VALS_API_KEY, requer aprovacao), entao o grading fim-a-fim nao roda local/…

### MAUD
- **repo:** https://github.com/TheAtticusProject/maud
- **instalar:** `git clone --depth 1 https://github.com/TheAtticusProject/maud maud && cd maud && uv venv .venv --python 3.14 && source .venv/bin/activate && uv pip install numpy scipy scikit-learn matplotlib pandas tqdm  # eval/scorer d…`
- **smoke:** `PYTHONPATH=src python -c "import numpy as np; from maud import pr_curves; c=pr_curves.MAUDPrecRecallCurve.from_results(np.array([0,1,2,0,1,2]), np.array([[3,.1,.2],[.1,2.5,.3],[.2,.4,2.8],[1.9,.6,.5],[.3,1.4,.9],[.5,.7,2…`
- **ressalva:** Scorer (maud.pr_curves, macro AUPR via sklearn) roda nativo arm64/py3.14 sem torch — smoke passou (AUPR=1.0). Ressalva (parcial): o entrypoint empacotado scripts/evaluate_plots.py NAO sobe eval-only: `ModuleNotFoundError: No module named 'regex'` e a cadeia maud.utils/specs/data importa torch+transf…

### PlanBench
- **repo:** https://github.com/karthikv792/LLMs-Planning
- **instalar:** `cd plan-bench && uv venv .venv --python /Users/vitorepf/.local/bin/python3.11 && uv pip install --python .venv/bin/python numpy "PyYAML==6.0" "tarski==0.7.0" "pddl==0.2.0"  # eval scorer core: 8 pkgs, ZERO torch/vllm/cud…`
- **smoke:** `cd plan-bench && PYTHONPATH="$(pwd)" .venv/bin/python -c "import platform; from tarski.io import PDDLReader; from Executor import Executor; from model_parser.parser_new import parse_model; print('arch', platform.machine(…`
- **ressalva:** Scorer core roda NATIVO arm64 (tarski 0.7.0 parseou instance gold blocksworld real, Executor+model_parser importaram, py3.11 arm64, sem torch). MAS 2 ressalvas reais: (1) o pacote utils/ que hospeda os helpers do avaliador (validate_plan/text_to_plan) é acoplado à geração: utils/__init__ exige OPENA…

