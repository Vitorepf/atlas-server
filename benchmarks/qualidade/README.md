# 🔒 Qualidade & Segurança

Provado por teste-de-fogo (clone + install eval-only + smoke) no seu Mac arm64, 2026-07-19. **✅ roda limpo · 🟡 roda com ajuste · ⚪ sem código público.** Comandos abaixo são os que **funcionaram de verdade**.

## ✅ Rodam limpo (provado, zero mexida)

### IDoFT
- **repo:** https://github.com/TestingResearchIllinois/idoft
- **instalar:** `uv venv .venv --python 3.14 && . .venv/bin/activate && uv pip install -r format_checker/requirements.txt  (só errorhandler==2.0.1 + requests==2.28.1; wheels puro-Python, zero ML/torch/cuda)`
- **smoke:** `python format_checker/main.py  (rodado da raiz do repo; gold limpo -> exit 0 "Success"; linha com Status corrompido -> exit 1 "Invalid Status")`
- **ressalva:** Nenhum no arm64. Ressalva de escopo: IDoFT é dataset de flaky tests + validador CSV (format_checker), NAO um benchmark de modelo — nao ha modelo/geracao pra rodar, o "eval" e o proprio scorer de formato. Python 3.14 instalou os pins antigos sem build error.

## 🟡 Rodam com ajuste

### SWR-Bench
- **repo:** https://github.com/ZZR0/SWRench
- **instalar:** `uv venv .venv --python 3.14 && uv pip install --python .venv/bin/python -r requirements.txt   (requirements.txt = openai/numpy/pandas/tiktoken/PyGithub/gitpython/loguru/tenacity/tqdm/dateutil/requests — ZERO torch/vllm/c…`
- **smoke:** `cd swrbench && OPENAI_API_BASE=http://localhost:9/v1 OPENAI_API_KEY=sk-dummy python evaluation_struct.py --help   (sobe no arm64 e imprime a CLI completa; dataset de 1000 PRs carrega offline)`
- **ressalva:** Como vem no repo o runner crasha no import: swrbench/utils.py:13 usa os.getenv['OPENAI_API_KEY'] -> TypeError: 'function' object is not subscriptable. Isso quebra em QUALQUER SO, nao e arm64. Apos corrigir 1 char (os.getenv(...)) o eval sobe nativo no arm64. Eval real ainda exige endpoint OpenAI-com…

### PrimeVul
- **repo:** https://github.com/DLVulDet/PrimeVul
- **instalar:** `uv venv --python 3.14 .venv && . .venv/bin/activate && uv pip install numpy scikit-learn`
- **smoke:** `python calc_vd_score.py --help  (+ direct call calculate_vul_det_score([0.9,0.8,0.2,0.1,0.7,0.3],[1,1,0,0,1,0]) -> VD-S=0.0 OK). Documented CLI: python calc_vd_score.py --pred_file pred.txt --test_file test.jsonl`
- **ressalva:** Eval roda 100% nativo arm64 (numpy 2.5.1 + scikit-learn 1.9.0, wheels cp314 arm64, zero compilacao, zero CUDA/GPU/Docker). Ressalva: o CLI documentado calcula o score e entao quebra na ultima linha: NameError: name 'target_fpr' is not defined (calc_vd_score.py:76) — bug upstream, o f-string do print…

### CWEval
- **repo:** https://github.com/Co1lin/CWEval
- **instalar:** `cd cweval && uv venv --python 3.11 .venv && VIRTUAL_ENV="$PWD/.venv" uv pip install -r requirements/core.txt  # eval/scorer only; SKIP ai.txt (API providers) e local_ai.txt (torch/vllm/gpu). Todos os wheels arm64, sem to…`
- **smoke:** `PYTHONPATH="$PWD" .venv/bin/python -m cweval.evaluate --help  &&  PYTHONPATH="$PWD" .venv/bin/python -m pytest benchmark/core/py/cwe_020_0_test.py -v --timeout=30   # => 13 passed (functionality + security) nativo arm64`
- **ressalva:** Nao e falha: o avaliador/scorer instala e roda NATIVO arm64 sem Docker/GPU, e o subset Python (25 tasks, 56.7%) pontua fim-a-fim (13/13 incl. teste de seguranca). Ressalva real p/ cobertura 5-linguagens: falta toolchain Go (trivial, `brew install go`, arm64 nativo); C/C++/JS ja presentes nativos (Ap…

