# Lista completa de benchmarks — o que roda no seu Mac (arm64)

Verificado na fonte, 2026-07-19. **Critério:** venv/toolchain nativo (executa o código no host ou é métrica pura) — sem Docker de imagem x86, sem GPU, sem serviço de nuvem. ✅ = roda liso · ⚠️ = roda com ressalva real. Os que exigem x86/GPU estão **estacionados** no fim.

> **Regra de ouro:** cada benchmark roda em 2 braços — **sem Atlas** (modelo cru) e **com Atlas** (cérebro `atlas:cli:dev` com Hermes por dentro). O relatório de cada um é colhido e juntado num só.

## Codificação — geração & raciocínio

| benchmark | roda | o que testa | fonte |
|---|---|---|---|
| **EvalPlus** | ✅ | Correção de código função-a-função em HumanEval e MBPP, mas com suíte de testes expandida ~80x (HumanEval+) e ~35x (MBPP+) para pegar soluções que passam… | [repo](https://github.com/evalplus/evalplus) |
| **CRUXEval** | ✅ | Raciocínio de execução em funções Python auto-contidas: CRUXEval-O (simular a execução e prever o output) e CRUXEval-I (prever um input que produz dado… | [repo](https://github.com/facebookresearch/cruxeval) |
| **ClassEval** | ✅ | Geração a nível de CLASSE: escrever classes Python inteiras (~45 linhas) com múltiplos métodos interdependentes a partir de esqueleto+docstrings. Três… | [repo](https://github.com/FudanSELab/ClassEval) |
| **BigCodeBench** | ⚠️ | Geração de código a nível de função a partir de instruções/docstrings complexas que exigem invocar múltiplas function calls de 139 bibliotecas reais em 7… _(ressalva: Backend padrao e a Gradio/E2B remote sandbox (cloud); rodar nativo exige…)_ | [repo](https://github.com/bigcode-project/bigcodebench) |
| **live_code_bench** | ✅ integrado | Programação competitiva contamination-free (geração + raciocínio). | [repo](https://github.com/LiveCodeBench/LiveCodeBench) |

## Codificação — repositório & multi-arquivo

| benchmark | roda | o que testa | fonte |
|---|---|---|---|
| **RepoBench** | ✅ | Auto-completacao repo-level em 3 sub-tarefas encadeadas: RepoBench-R (retrieval do snippet cross-file mais relevante), RepoBench-C (predicao da proxima… | [repo](https://github.com/Leolty/repobench) |
| **CrossCodeEval** | ✅ | Completacao de codigo cross-file: exemplos construidos por analise estatica para SO exigirem contexto de OUTROS arquivos do repo (imports, defs, tipos). 4… | [repo](https://github.com/amazon-science/cceval) |
| **DevEval** | ⚠️ | Geracao de codigo repo-level alinhada a repos reais: cada tarefa exige implementar uma funcao usando dependencias e contexto do repo real (requisitos,… _(ressalva: O `environment.txt` fornecido esta pinado em `# platform: linux-64` (conda…)_ | [repo](https://github.com/seketeam/DevEval) |
| **Long Code Arena** | ⚠️ | Suite de 6 tarefas de contexto LONGO (ate o repo inteiro): project-level code completion, library-based code generation, bug localization, CI builds… _(ressalva: A tarefa CI-builds-repair 'pushes the repo to GitHub and requests the result of…)_ | [repo](https://github.com/JetBrains-Research/lca-baselines) |
| **aider_polyglot** | ✅ integrado | Edição de código multi-linguagem — o agente edita os arquivos e roda os testes. | [repo](https://github.com/Aider-AI/aider) |

## Depuração & localização

| benchmark | roda | o que testa | fonte |
|---|---|---|---|
| **REval** | ✅ | Raciocínio sobre execução em nível de ESTADO intermediário, não só saída final: prediz cobertura de código, estado do programa em cada passo, caminho de… | [repo](https://github.com/r-eval/REval) |
| **LocAgent** | ✅ |  | [repo](https://github.com/gersteinlab/LocAgent) |
| **debug-gym** | ⚠️ | Depuração interativa real: o agente usa ferramentas de debugger (pdb, breakpoints, view, listdir, rewrite, eval) num loop de busca ativa de informação… _(ressalva: A ferramenta pdb (o proposito central do benchmark) 'only works on Linux'.…)_ | [repo](https://github.com/microsoft/debug-gym) |

## Testes

| benchmark | roda | o que testa | fonte |
|---|---|---|---|
| **TestEval** | ✅ | Geração de teste com alvo de cobertura preciso: (1) cobertura geral, (2) cobertura de linha/branch alvo, (3) cobertura de caminho alvo. Programas Python… | [repo](https://github.com/LLM4SoftwareTesting/TestEval) |

## Uso de ferramentas / function calling

| benchmark | roda | o que testa | fonte |
|---|---|---|---|
| **bfcl** | ✅ integrado | Function calling / uso de ferramentas em múltiplas categorias. | [repo](https://github.com/ShishirPatil/gorilla) |
| **AppWorld** | ✅ | Agente de codigo interativo que opera 9 apps do dia a dia via 457 APIs e 100+ tabelas, gerando codigo com fluxo de controle rico e iterativo (nao… | [repo](https://github.com/StonyBrookNLP/appworld) |
| **ComplexFuncBench** | ✅ | Function calling COMPLEXO: multi-step dentro de um turno, com restricoes do usuario, inferencia de parametros a partir de info implicita, valores de… | [repo](https://github.com/zai-org/ComplexFuncBench) |
| **NESTFUL** | ✅ | Sequencias ANINHADAS de chamadas de API — a saida de uma chamada vira entrada da proxima (composicao funcional). Todas as funcoes sao executaveis;… | [repo](https://github.com/IBM/NESTFUL) |
| **ToolSandbox** | ✅ | Uso de ferramentas STATEFUL, conversacional e interativo: execucao com estado, dependencias implicitas de estado entre tools, simulador de usuario… | [repo](https://github.com/apple/ToolSandbox) |

## Pesquisa / agente geral

| benchmark | roda | o que testa | fonte |
|---|---|---|---|
| **GAIA** | ✅ | Assistente geral: 466 perguntas do mundo real que exigem raciocinio, web browsing, multimodalidade e uso de ferramentas em cadeia. 3 niveis (Nivel 3 =… | [repo](https://arxiv.org/abs/2311.12983) |

## ML & Dados

| benchmark | roda | o que testa | fonte |
|---|---|---|---|
| **DS-1000** | ✅ | 1000 problemas de geracao de codigo de data science em 7 bibliotecas Python (NumPy, Pandas, etc.), coletados do StackOverflow. Avaliacao automatica dupla:… | [repo](https://github.com/xlang-ai/DS-1000) |
| **MLAgentBench** | ✅ | 13 tarefas de experimentacao de ML fim-a-fim (de melhorar CIFAR-10 ate BabyLM): dado dataset + descricao da tarefa, o agente le/escreve arquivos, executa… | [repo](https://github.com/snap-stanford/MLAgentBench) |

## Conhecimento & raciocínio (só modelo cru)

| benchmark | roda | o que testa | fonte |
|---|---|---|---|
| **inspect_evals** | ✅ integrado | Q&A de conhecimento, raciocínio e recusa (MMLU, MUSR, coconot, ifeval). | [repo](https://github.com/UKGovernmentBEIS/inspect_evals) |
| **tau2_bench** | ✅ integrado | Diálogo agêntico com ferramentas (atendimento). | [repo](https://github.com/sierra-research/tau2-bench) |

## ⛔ Estacionados — precisam de x86/GPU (não rodam confiável aqui)

Rodam por emulação x86 (que corrompe o resultado — provado: até o gold do SWE-bench falha aqui), ou exigem GPU / online-judge / nuvem. Prontos para quando houver x86.

| benchmark | por que |
|---|---|
| AIOpsLab | docker_generic |
| BountyBench | docker_generic |
| CVE-Bench | docker_generic |
| CodeElo | online_judge |
| Commit0 | docker_x86 |
| Cybench | docker_generic |
| CyberGym | docker_x86 |
| DA-Code | docker_generic |
| DebugBench | online_judge |
| EnvBench | docker_generic |
| ITBench | docker_generic |
| InterCode | docker_generic |
| MCP-Universe | cloud_service |
| MLE-bench | docker_x86 |
| MLGym | docker_generic |
| McEval | docker_x86 |
| Multi-SWE-bench | docker_x86 |
| RE-Bench | gpu_required |
| Repo2Run | docker_generic |
| SWE-Lancer | docker_x86 |
| SWE-bench Multimodal | docker_generic |
| SWE-bench Pro | docker_x86 |
| SWE-bench Verified | docker_x86 |
| SWE-rebench | docker_generic |
| SWT-Bench | docker_generic |
| SeCodePLT | docker_generic |
| SetupBench | docker_generic |
| TDD-Bench Verified | docker_generic |
| TestGenEval | docker_x86 |
| TheAgentCompany | docker_x86 |
| UTBoost | docker_x86 |
| hal_harness (atual) | docker |
| senior_swe_bench (atual) | docker_x86 |
| swe_bench_live (atual) | docker_x86 |
| swe_marathon (atual) | docker_x86 |
| terminal_bench (atual) | docker |
