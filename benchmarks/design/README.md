# 🎨 Design & UX

Provado por teste-de-fogo (clone + install eval-only + smoke) no seu Mac arm64, 2026-07-19. **✅ roda limpo · 🟡 roda com ajuste · ⚪ sem código público.** Comandos abaixo são os que **funcionaram de verdade**.

## ✅ Rodam limpo (provado, zero mexida)

### InfiAgent-DABench
- **repo:** https://github.com/InfiAgent/InfiAgent
- **instalar:** `cd examples/DA-Agent && uv venv .venv --python 3.14   # eval path = ZERO pip deps (scorer is pure Python stdlib: json/re/os/argparse + utils.read_jsonl which only imports json). No torch/vllm/cuda/pandas/numpy. Nothing t…`
- **smoke:** `cd examples/DA-Agent && .venv/bin/python eval_closed_form.py --help   # runner boots; AND real scoring: built gold-derived responses from data/da-dev-labels.jsonl (@name[value]) -> .venv/bin/python eval_closed_form.py --…`
- **ressalva:** Nenhum no avaliador. Roda 100% nativo arm64 (Python 3.14.4 arm64), sem Docker/GPU/gambiarra. Caveat honesto (nao bloqueia): reformat.py — que normaliza a saida crua do agente para o formato @name[value] antes do score — usa uma API LLM externa (url.txt/api_key.txt). Isso e o lado do modelo, nao o sc…

## 🟡 Rodam com ajuste

### Design2Code
- **repo:** https://github.com/NoviScl/Design2Code
- **instalar:** `python3.11 -m venv .venv && ./.venv/bin/pip install torch torchvision openai-clip opencv-python numpy scipy scikit-learn pillow beautifulsoup4 colormath playwright matplotlib tqdm joblib ftfy regex   # NOT `pip install -…`
- **smoke:** `PYTHONPATH=$PWD ./.venv/bin/python -c "from Design2Code.metrics.visual_score import visual_eval_v3_multi; from Design2Code.metrics.ocr_free_utils import get_blocks_ocr_free; import clip,torch; clip.load('ViT-B/32',device…`
- **ressalva:** Stack arm64 do scorer roda 100% nativo (torch 2.13.0 arm64+MPS, CUDA false; CLIP ViT-B/32 CPU; opencv 5.0; sklearn/scipy/colormath/bs4/playwright), zero Docker/x86/GPU, smoke passou. Ressalvas reais (não são limite de hardware arm64): (1) install documentado `pip install -e .` NÃO instala em arm64 —…

### ScreenSpot-Pro
- **repo:** https://github.com/likaixin2000/ScreenSpot-Pro-GUI-Grounding
- **instalar:** `cd tools/rivals/benchmarks/_prova/screenspot_pro && uv venv .venv --python 3.14 && source .venv/bin/activate && uv pip install pillow tqdm   # eval/scorer only — SEM torch/transformers/vllm/cuda`
- **smoke:** `python smoke_arm64.py   # exec do eval_screenspot_pro.py REAL (torch stubado p/ manual_seed no-op) + score de amostra gold dummy -> "SMOKE OK arm64: darwin / arm64"; assert: point dentro do bbox=correct, fora=wrong, None…`
- **ressalva:** Scorer é stdlib puro (eval_screenspot_pro.py L112-331: bbox math), roda NATIVO arm64 — smoke verde, zero GPU/Docker/x86; pillow+tqdm instalam como wheels arm64 nativos. Ressalvas (=parcial): (1) runner serial top-importa torch (L4) só p/ torch.manual_seed — CPU wheel arm64 existe mas força install p…

### CANVAS
- **repo:** https://github.com/kixlab/CANVAS
- **instalar:** `uv venv --python 3.12 .venv && source .venv/bin/activate && uv pip install numpy scipy scikit-image pillow pandas pyyaml opencv-python-headless lpips   # lpips auto-pulls torch+torchvision CPU/arm64; NO tensorflow, NO CU…`
- **smoke:** `cd canvas && source .venv/bin/activate && python -m evaluation.eval_pipeline --help && python -c "from evaluation.metrics import get_metrics; print(sorted(get_metrics()))"   # 10 metrics register; component_similarity=0.…`
- **ressalva:** Eval RUNNER + core scorers (component_similarity, tool_usage) run native arm64 CPU/MPS and computed valid scores. BUT full metric suite not fully arm64: (1) repo requirements.txt is Linux/x86/GPU (tensorflow==1.14.0 has ZERO arm64 wheel, nvidia-cuda-*/mkl x86-only) — won't install on arm64; (2) visu…

### UICrit
- **repo:** https://github.com/google-research-datasets/uicrit
- **instalar:** `uv venv .venv && uv pip install --python .venv pandas   # eval-only: numpy+pandas arm64 wheels, NO torch/vllm/cuda`
- **smoke:** `.venv/bin/python smoke_eval.py   # -> OK arm64=darwin pandas=3.0.3 rows=2981 gold_dqr=6.0 mae=1.0 critiques_in_row0=7`
- **ressalva:** Repo oficial e SO dataset (README + 1 CSV, 2981 linhas) — NAO ha scorer/runner publico. Eval nativo arm64 OK (pandas puro, zero GPU/torch/Docker; numpy .so = Mach-O arm64; smoke carrega gold, parseia criticas, calcula MAE do rating vs gold). Ressalvas reais: (1) tive que escrever o smoke_eval.py emu…

### AesEval-Bench
- **repo:** https://github.com/arctanxarc/AesEval-Bench
- **instalar:** `uv venv .venv && VIRTUAL_ENV=.venv uv pip install tqdm pillow numpy openai httpx   # eval-only; NO torch/transformers/accelerate/qwen-vl-utils`
- **smoke:** `.venv/bin/python main.py --help  (exit 0; full import chain sem torch) + scorer dummy: python -c 'from tasks.layout_task import LayoutTask; t=LayoutTask({"dimension":"layout","task_name":"balance"}); print(t.evaluate({"p…`
- **ressalva:** Eval/scorer roda 100% nativo arm64, GPU-free, sem Docker (smoke --help exit 0 + scorer computa yes/no+choice+bbox-IoU em amostra dummy; torch confirmado AUSENTE). Ressalva real: benchmark_data/ vem VAZIO no repo — corrida real exige baixar dataset grande do Google Drive (drive.google.com/file/d/1W5o…

### DesignBench
- **repo:** https://github.com/WebPAI/DesignBench
- **instalar:** `cd tools/rivals/benchmarks/_prova/designbench && uv venv .venv --python 3.10 && uv pip install --python .venv/bin/python numpy scipy scikit-image opencv-python-headless pillow selenium retry tqdm && (cd code/evaluator &&…`
- **smoke:** `.venv/bin/python smoke.py  # imports config/compile/metric_utils/metric_ast (4/5 eval modules, no torch) + runs 2 real scorers: compile.is_pure_white_image(white)->True,(red)->False AND babel AST engine parses dummy JSX …`
- **ressalva:** Roda nativo no arm64 (sem Docker, sem GPU): deps de eval instalam como wheels arm64 nativas (cv2/numpy .so = Mach-O arm64, zero torch/cuda/nvidia), 4/5 modulos importam, 2 scorers reais executam. RESSALVAS reais que impedem "roda" pleno: (1) 5o modulo metric.py/main.py e' gated por torch+clip e faz …

## ⚪ Sem código público (não roda)

### WebAccessBench
- **repo:** NENHUM repo publico/clonavel. Autor: Casey Kreer (github.com/KreerC, sem repo WebAccessBench). Fonte oficial = Zenodo DO…
- **motivo (sem repo):** Harness/avaliador do WebAccessBench NAO e open-source. Zenodo 10.5281/zenodo.18750348 tem so webaccessbench_whitepaper.pdf (178kB); conesible.de/wab publica so o PDF + public-benchmark.json (dados, nao codigo). GitHub repo-search 'webaccessbench'=0, 'conesible'=0; code-search 'WebAccessBench'=0; Kre…

