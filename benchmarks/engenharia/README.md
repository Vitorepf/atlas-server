# ⚙️ Engenharia

Provado por teste-de-fogo (clone + install eval-only + smoke) no seu Mac arm64, 2026-07-19. **✅ roda limpo · 🟡 roda com ajuste · ⚪ sem código público.** Comandos abaixo são os que **funcionaram de verdade**.

## ✅ Rodam limpo (provado, zero mexida)

### ArchBench
- **repo:** https://github.com/sa4s-serc/archbench-cli
- **instalar:** `cd tools/rivals/benchmarks/_prova/archbench && uv venv .venv --python 3.11 && VIRTUAL_ENV=$PWD/.venv uv pip install -e ".[eval]"   # eval-only: rouge/nltk/evaluate, SEM torch/vllm/cuda`
- **smoke:** `source .venv/bin/activate; archbench --help; archbench evaluate --help; python -c "from archbench.harness.grading import compute_metrics; print(compute_metrics('traceability',[{'doc_sentence':'S1','code_artifact':'A.java…`
- **ressalva:** Sem blocker de arm64 — roda limpo. Ressalvas honestas p/ README: (1) metrica-carro-chefe do ADR e BERTScore F1, que exige extra [bertscore]=torch CPU (wheel arm64 existe, ainda nativo, so nao instalei; sem ele ADR ainda pontua ROUGE/BLEU/METEOR e campos bertscore viram None de forma graciosa; ha fla…

### CRUXEval
- **repo:** https://github.com/facebookresearch/cruxeval
- **instalar:** `python3 -m venv .venv && ./.venv/bin/pip install numpy tabulate   # eval-only; NAO usar requirements.txt (puxa torch/transformers/vllm p/ geracao)`
- **smoke:** `cd evaluation && ../.venv/bin/python evaluate_generations.py --generations_path <gold_output.json>   # 800 samples gold -> pass@1: 100.0 pass@5: 100.0 em ~20s, arm64, sem Docker/GPU`
- **ressalva:** Sem blocker (roda). Ressalva honesta: macOS usa multiprocessing 'spawn' (Linux='fork'); o runner shipado funciona pois chama o executor sob if __name__=='__main__', mas qualquer caller custom de check_correctness precisa do mesmo guard senao quebra (RuntimeError/EOFError). Dataset (cruxeval.jsonl 18…

### ClassEval
- **repo:** https://github.com/FudanSELab/ClassEval
- **instalar:** `cd tools/rivals/benchmarks/_prova/classeval && uv venv --python 3.12 .venv && VIRTUAL_ENV=.venv uv pip install func_timeout scipy   # eval-only: sem torch/transformers/openai. scipy .so = Mach-O arm64 (wheel nativo, sem …`
- **smoke:** `cd classeval_evaluation && ../.venv/bin/python evaluation.py --source_file_name gold_smoke_greedy --eval_data ClassEval_data --greedy 1   # gold_smoke_greedy.json = 1 amostra gold (ClassEval_1). Resultado: class_success=…`
- **ressalva:** Nenhum blocker. O corretor (test_pipeline.py) só usa stdlib + func_timeout + scipy.special.comb. Ressalva honesta (nao chega a parcial): rodar a suite COMPLETA das 100 classes precisa de libs extras que as classes-sob-teste importam (PyPDF2, python-docx, beautifulsoup4, nltk, openpyxl, Levenshtein, …

### RepoBench
- **repo:** https://github.com/Leolty/repobench
- **instalar:** `python3 -m venv .venv && .venv/bin/pip install fuzzywuzzy python-Levenshtein codebleu fire && .venv/bin/pip install --upgrade "tree-sitter>=0.23,<0.24" "tree-sitter-python==0.23.6" "tree-sitter-java==0.23.5"  # eval-only…`
- **smoke:** `.venv/bin/python eval.py --help  &&  .venv/bin/python eval.py --path=results/_smoke --language=python  # gold jsonl dummy -> EM/ES/CodeBLEU weighted avgs; import evaluation.metrics + calc_codebleu('python') OK em arm64`
- **ressalva:** Roda 100% nativo arm64 (sem Docker/GPU/Rosetta) — todas as wheels arm64 (rapidfuzz/levenshtein cp314, tree-sitter compilado do source, grammars abi3). Ressalva real (nao-arm64-especifica): codebleu 0.7.0 pina tree-sitter<0.23, mas os unicos grammars no PyPI sao 0.23+ (capsule API) -> `pip install co…

### LocAgent
- **repo:** https://github.com/gersteinlab/LocAgent
- **instalar:** `cd .../tools/rivals/benchmarks/_prova/locagent && git clone --depth 1 https://github.com/gersteinlab/LocAgent . && uv venv --python 3.12 .venv && uv pip install --python .venv/bin/python torch pandas datasets   # eval-on…`
- **smoke:** `.venv/bin/python smoke_eval.py`
- **ressalva:** RODA nativo em arm64 (cpu, sem Docker/GPU/gambiarra). Ressalvas honestas: (1) exige Python 3.12+ — eval_metric.py:193 usa f-string aninhada PEP 701, em 3.11 dá SyntaxError; uv baixou CPython 3.12 arm64 nativo. (2) requirements.txt pina torch==2.5.1 + triton + nvidia-cuda-* (só Linux x86), mas o cami…

### debug-gym
- **repo:** https://github.com/microsoft/debug-gym
- **instalar:** `uv venv --python 3.12 .venv && VIRTUAL_ENV=.venv uv pip install -e .   # requirements.txt = openai/anthropic/datasets/transformers(tokenizer)/swebench — SEM torch/vllm/cuda`
- **smoke:** `.venv/bin/python scripts/run.py --help  +  driver: LocalEnv(path="data/mini_nightmare/counter", entrypoint="python -m pytest -sq test.py", terminal=LocalTerminal(session_commands=["export PATH=.venv/bin:$PATH"])); env.ad…`
- **ressalva:** Nenhum no caminho de avaliacao: clone+install+smoke verdes em arm64, scorer (EvalTool/LocalTerminal) roda pytest nativo e pontua correto (pass/fail) nos golds mini_nightmare, sem Docker/GPU/LLM. Caveat honesto: benchmarks SWE-bench/SWE-smith/R2E-Gym usam terminal type=docker por padrao + baixam data…

### TestEval
- **repo:** https://github.com/LLM4SoftwareTesting/TestEval
- **instalar:** `git clone --depth 1 https://github.com/LLM4SoftwareTesting/TestEval testeval && cd testeval && python3 -m venv .venv && ./.venv/bin/pip install pytest pytest-cov tqdm sortedcontainers   # eval-only; NÃO instalei requirem…`
- **smoke:** `./.venv/bin/python -c "import data_utils,eval_overall,eval_linecov,eval_branchcov,eval_pathcov,eval_base,eval_pathcov_base; print('eval modules OK')" && ./.venv/bin/python -c "from data_utils import read_jsonl; print(len…`
- **ressalva:** Nenhum blocker de arm64. Eval 100% nativo. Ressalvas honestas (não impedem): (1) requirements.txt mistura deps de geração pesadas — instalei só o scorer (pytest-cov+tqdm+sortedcontainers), coverage veio como wheel nativo cp314-macosx_11_0_arm64; (2) o scorer executa test cases gerados via subprocess…

## 🟡 Rodam com ajuste

### R2ABench
- **repo:** https://github.com/homehappily/R2ABENCH-_view_evaluation
- **instalar:** `git clone --depth 1 https://github.com/homehappily/R2ABENCH-_view_evaluation r2abench && cd r2abench && python3 -m venv .venv   # ZERO pip deps: static viewer + stdlib http.server; nada de torch/vllm/cuda pra instalar`
- **smoke:** `.venv/bin/python -c 'import json;d=json.load(open("samples.json"));assert d["dimensions"] and len(d["samples"])==160' && node --check app.js && (.venv/bin/python -m http.server 8931 --directory . & sleep 1.5; curl -s -o …`
- **ressalva:** O unico repo git publico clonavel e SO o VIEWER de human-review (HTML/JS estatico + samples.json com 160 candidatos ja pontuados em 5 dimensoes: completeness/faithfulness/architectural_rationality/traceability/readability + SVG/PNG). O scorer automatizado do paper (GED, Node/Edge F1, plantuml.jar, L…

### EvalPlus
- **repo:** https://github.com/evalplus/evalplus
- **instalar:** `cd /Users/vitorepf/develop/Atlas/atlas-server/tools/rivals/benchmarks/_prova/evalplus && git clone --depth 1 https://github.com/evalplus/evalplus . ; uv venv --python 3.11 .venv && uv pip install --python .venv/bin/pytho…`
- **smoke:** `.venv/bin/python -m evalplus.evaluate --help   # CLI do avaliador sobe (fire). E prova do sandbox de correção rodando nativo arm64: EVALPLUS_MAX_MEMORY_BYTES=-1 .venv/bin/python -c "from evalplus.eval import untrusted_ch…`
- **ressalva:** RODA nativo arm64 (CPU-only, sem Docker/GPU) MAS o sandbox de correção quebra no default do macOS: evalplus/eval/utils.py reliability_guard() chama resource.setrlimit(RLIMIT_AS/RLIMIT_DATA, 4GB) e o Darwin rejeita com "ValueError: current limit exceeds maximum limit" (o código já pula RLIMIT_STACK n…

### CrossCodeEval
- **repo:** https://github.com/amazon-science/cceval
- **instalar:** `cd tools/rivals/benchmarks/_prova/crosscodeeval && git clone --depth 1 https://github.com/amazon-science/cceval . ; uv venv --python 3.11 .venv && source .venv/bin/activate && uv pip install "tree-sitter==0.21.3" fuzzywu…`
- **smoke:** `cd scripts && python smoke_metric.py  # runs UNMODIFIED eval_metric.compute_metric_stmt(args) on 2 gold+dummy python samples; args=output_dir(prediction.jsonl)+prompt_file(gold.jsonl)+ts_lib(build/python-lang-parser.so)+…`
- **ressalva:** README's `pip install -r requirements.txt` FAILS on arm64 (pins vllm>=0.3.3 + deepspeed + bitsandbytes = CUDA/Linux-only); must hand-pick eval subset. tree-sitter drift: repo uses removed Language.build_library + 2-arg Language(path,name) -> needs tree-sitter==0.21.3, and latest grammar throws "Inco…

### BigCodeBench
- **repo:** https://github.com/bigcode-project/bigcodebench
- **instalar:** `uv venv --python 3.12 .venv && source .venv/bin/activate && uv pip install gradio_client e2b httpx numpy termcolor tqdm rich pqdm tempdir wget appdirs datasets tree-sitter tree-sitter-python multipledispatch fire transfo…`
- **smoke:** `PYTHONPATH=. python -c "import bigcodebench.evaluate"  &&  PYTHONPATH=. python -m bigcodebench.evaluate --help   # both exit 0 on arm64, sem torch`
- **ressalva:** Runner/scorer importa e CLI (--help) rodam NATIVO arm64 sem torch/vllm/docker; execução REMOTA (--execution gradio/e2b, o default) é arm64-limpa (só HTTP). Mas --execution local (grading on-host) QUEBRA no arm64 macOS: reliability_guard chama resource.setrlimit(RLIMIT_AS/RLIMIT_DATA) e o macOS rejei…

### DevEval
- **repo:** https://github.com/seketeam/DevEval
- **instalar:** `cd .../benchmarks/_prova/deveval && git clone --depth 1 https://github.com/seketeam/DevEval deveval && python3.11 -m venv .venv && .venv/bin/pip install psutil numpy tqdm func-timeout   # eval-only; NAO instalar requirem…`
- **smoke:** `tar -xzf data.tar.gz; .venv/bin/python pass_k.py --help  (runner sobe, exit=0); e scorer real end-to-end: .venv/bin/python pass_k.py --output_file _smoke/output.jsonl --log_file _smoke/log.jsonl --data_file data.jsonl --…`
- **ressalva:** Scorer Pass@k roda 100% nativo arm64 (so psutil/numpy/tqdm/func-timeout, wheels nativos, zero compile, zero GPU/Docker). Ressalva (=parcial): eval de correcao funcional REAL (rodar o codigo gerado nos testes, nao log pre-preenchido) exige baixar Source_Code.tar.gz da HuggingFace (115 repos reais, da…

### Long Code Arena
- **repo:** https://github.com/JetBrains-Research/lca-baselines
- **instalar:** `cd .../long_code_arena/library_based_code_generation && uv venv --python 3.11 .venv && VIRTUAL_ENV="$PWD/.venv" uv pip install 'sacrebleu==2.4.2' 'tree-sitter==0.22.3' colorama lxml   # eval-only scorers; NO torch/datase…`
- **smoke:** `PYTHONPATH="$PWD" .venv/bin/python -c "from src.metrics.chrf import ChrF; print(ChrF().score(gen, ref, []))"  # -> ChrF=0.6802 on arm64 (ChrF scorer runs native). API_recall scorer (src.metrics.overlap, tree-sitter) does…`
- **ressalva:** API_recall scorer blocked native-arm64: locked pin tree-sitter-python==0.21.0 has NO arm64 macOS wheel and NO sdist on PyPI (only x86_64/linux/win). Bumping to an arm64-wheel grammar (0.23.6/0.25.0) breaks unmodified parser.py under core 0.22.3: "PY_LANGUAGE = Language(tspython.language()) -> TypeEr…

### REval
- **repo:** https://github.com/r-eval/REval
- **instalar:** `git clone --depth 1 https://github.com/r-eval/REval reval && cd reval && uv venv --python 3.11 .venv && source .venv/bin/activate && uv pip install backoff bullet openai pandas python-dotenv pytz tqdm numpy   # eval-only…`
- **smoke:** `python evaluation.py --help   # + gold-sample run: python -c "from dynamics import FunctionFactory,Sandbox; fn=FunctionFactory.create('add','def add(a,b):\n    s=a+b\n    return s\n'); r,st=Sandbox(fn).run(2,3); print(r,…`
- **ressalva:** Documented eval-only file requirements-nogpu.txt does NOT install as-written on arm64: gensim==4.2.0 has no arm64/cp311 wheel and its source build fails (`AttributeError: 'dict' object has no attribute '__NUMPY_SETUP__'`). gensim is NOT part of the scorer — it only appears embedded inside 3 benchmar…

