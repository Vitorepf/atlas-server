# Catálogo de expansão — Engenharia de Software agêntica

8 benchmarks é pouco. Um agente de programação de verdade tem que ser medido no
**fluxo inteiro**: da especificação ao código, do issue em repo real ao ambiente,
teste, depuração, ferramentas, longo horizonte, ML e segurança. Este catálogo
reúne os benchmarks **reais e reconhecidos** (com fonte verificada em pesquisa
web) para cada etapa, para o Atlas expandir muito além das 8 atuais.

> **A régua de escolha:** priorizar os **agênticos** (exigem um agente multi-step
> num repo/ambiente real, não só completar uma função), com **harness rodável** e
> **corretor determinístico** (testes que passam/falham — não juiz-LLM). É onde o
> braço "com Atlas" (cérebro `atlas:cli:dev` com Hermes por dentro) prova valor.

**Já integrados (10):** terminal_bench, bfcl, senior_swe_bench, swe_bench_live,
live_code_bench, hal_harness, aider_polyglot, swe_marathon (engenharia) +
inspect_evals, tau2_bench (outros intuitos).

**Honestidade das fontes:** as URLs vêm de pesquisa web verificada pelos agentes;
a validação final de cada uma é o **clone + smoke** no pipeline (URL fantasma não
clona → é pega na hora). Integrar cada benchmark é trabalho próprio (adapter +
harness + corretor + dois braços); esta lista é o mapa e a ordem, não um
compromisso de integrar tudo de uma vez.

## Prioridade recomendada (por onde começar)

**Tier 1 — canônico + agêntico + factível (começar aqui):**
- **SWE-bench Verified** — o padrão-ouro do mercado. Sem ele, o Rivals não tem a régua que todo mundo usa. (issue em repo real)
- **SWT-Bench** — geração de teste a partir de issue; fecha a etapa "testes".
- **debug-gym** (Microsoft) — depuração interativa com ferramentas de debug reais.
- **AppWorld** — uso de ferramentas/APIs agêntico e realista (corretor determinístico).
- **SetupBench** — montar o ambiente/build do zero (a etapa DevOps que ninguém mede).
- **Commit0** — construir uma biblioteca do zero até os testes oficiais passarem.
- **MLE-bench** (OpenAI) — engenharia de ML agêntica (Kaggle real).

**Tier 2 — realismo e horizonte longo:**
- **SWE-bench Pro** — long-horizon, anti-contaminação (top models caem para ~23%).
- **SWE-bench Multimodal** — UI/JS com verificação visual (eixo que nenhuma atual cobre).
- **SWE-Lancer** (OpenAI) — ancora capacidade em **valor econômico real** (US$ do Upwork).
- **TheAgentCompany** — trabalho de empresa ponta a ponta (o mais perto de "substituir uma função").
- **Multi-SWE-bench** — issue em repo real **poliglota** (Go, Rust, TS, Java, C/C++).

**Tier 3 — base barata e diagnóstico (piso de sanidade):**
- **EvalPlus** (HumanEval+/MBPP+) — piso; se não fecha isto, o gerador está quebrado.
- **CRUXEval** — raciocínio de execução (entende o que o código faz, não só passa teste).
- **BigCodeBench** — compor chamadas a bibliotecas reais.
- **CodeElo** — raciocínio algorítmico (Elo comparável a humano, anti-contaminação).
- **Cybench** — segurança (CTF), o eixo defensivo/ofensivo.

## Por etapa do fluxo de programação

### 1. Geração de código (especificação → código)

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [BigCodeBench](https://github.com/bigcode-project/bigcodebench) | unit | média | Geração de código a nível de função a partir de instruções/docstrings complexas que exigem invocar múltiplas function calls de 139 bibliotecas reais… |
| [EvalPlus (HumanEval+ / MBPP+)](https://github.com/evalplus/evalplus) | unit | baixa | Correção de código função-a-função em HumanEval e MBPP, mas com suíte de testes expandida ~80x (HumanEval+) e ~35x (MBPP+) para pegar soluções que pa… |
| [ClassEval](https://github.com/FudanSELab/ClassEval) | unit | média | Geração a nível de CLASSE: escrever classes Python inteiras (~45 linhas) com múltiplos métodos interdependentes a partir de esqueleto+docstrings. Trê… |
| [CodeElo](https://github.com/QwenLM/CodeElo) | unit | alta | Geração de código nível competição a partir do enunciado do problema (Codeforces, últimos ~6 meses), com rating Elo comparável a humanos, dividido po… |
| [CRUXEval](https://github.com/facebookresearch/cruxeval) | unit | baixa | Raciocínio/execução de código: dado uma função Python, prever a saída (CRUXEval-O) ou achar uma entrada que produza a saída dada (CRUXEval-I). Testa… |

### 2. Resolver issue/bug/feature em repositório real

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [SWE-bench Verified](https://github.com/SWE-bench/SWE-bench) | 🤖 agêntico | baixa | 500 issues reais validados por engenheiros (12 repos Python populares). Agente recebe a issue + codebase inteiro e precisa produzir um patch (diff) q… |
| [SWE-bench Pro](https://github.com/scaleapi/SWE-bench_Pro-os) | 🤖 agêntico | média | 1.865 problemas long-horizon de 41 repos ativos (business apps, B2B, dev tools). Tarefas que levam horas-dias para um humano, patches em multiplos ar… |
| [Multi-SWE-bench](https://github.com/multi-swe-bench/multi-swe-bench) | 🤖 agêntico | alta | 1.632 instancias de issue-resolving em 7 linguagens alem de Python: Java, TypeScript, JavaScript, Go, Rust, C, C++. Mesmo protocolo do SWE-bench (iss… |
| [SWE-Lancer](https://github.com/openai/SWELancer-Benchmark) | 🤖 agêntico | alta | 1.400+ tarefas freelance reais do Upwork (US$1M em payouts reais), de bug de US$50 a feature de US$32k. Duas classes: IC (implementar, corrigido por… |
| [SWE-bench Multimodal (SWE-bench M)](https://arxiv.org/abs/2410.03859) | 🤖 agêntico | média | 617 tarefas de 17 libs JavaScript visuais/user-facing (UI web, diagramas, dataviz, syntax highlight, mapas). Cada instancia inclui pelo menos uma ima… |
| [SWE-rebench](https://github.com/SWE-rebench) | 🤖 agêntico | média | Pipeline automatizado que minera tarefas SWE reais do GitHub continuamente (21.000+ tarefas Python interativas), com scaffolding fixo padronizado e r… |

### 3. Edição multi-arquivo / contexto de repo inteiro

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [CrossCodeEval](https://github.com/amazon-science/cceval) | unit | baixa | Completacao de codigo cross-file: exemplos construidos por analise estatica para SO exigirem contexto de OUTROS arquivos do repo (imports, defs, tipo… |
| [RepoBench](https://github.com/Leolty/repobench) | unit | baixa | Auto-completacao repo-level em 3 sub-tarefas encadeadas: RepoBench-R (retrieval do snippet cross-file mais relevante), RepoBench-C (predicao da proxi… |
| [Commit0](https://github.com/commit-0/commit0) | 🤖 agêntico | alta | Gerar uma BIBLIOTECA Python inteira do zero a partir de uma spec de API + suite de testes interativa. O agente escreve multiplos arquivos, recebe fee… |
| [Multi-SWE-bench](https://github.com/multi-swe-bench/multi-swe-bench) _(repetido)_ | 🤖 agêntico | alta | Resolucao de issues reais editando o repo em multiplos arquivos, em 7 linguagens (Java, TypeScript, JavaScript, Go, Rust, C, C++). Agente navega o re… |
| [DevEval](https://github.com/seketeam/DevEval) | unit | alta | Geracao de codigo repo-level alinhada a repos reais: cada tarefa exige implementar uma funcao usando dependencias e contexto do repo real (requisitos… |
| [Long Code Arena](https://github.com/JetBrains-Research/lca-baselines) | unit | média | Suite de 6 tarefas de contexto LONGO (ate o repo inteiro): project-level code completion, library-based code generation, bug localization, CI builds… |

### 4. Terminal, ambiente, build, DevOps/CI

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [SetupBench](https://github.com/microsoft/SetupBench) | 🤖 agêntico | média | Bootstrap de ambiente de dev a partir de um sandbox Linux nu: instalar toolchains de sistema/linguagem, resolver conflitos de dependência, inicializa… |
| [EnvBench](https://github.com/JetBrains-Research/EnvBench) | 🤖 agêntico | média | Configuração automatizada de ambiente em escala: dado um repo, o agente deve deixá-lo instalável/compilável. Exclui repos que um script determinístic… |
| [Repo2Run](https://github.com/bytedance/Repo2Run) | 🤖 agêntico | média | Automatizar a configuração de ambiente gerando um Dockerfile executável e livre de erros para um repositório Python arbitrário, com síntese atômica d… |
| [AIOpsLab](https://github.com/microsoft/AIOpsLab) | 🤖 agêntico | alta | Operação autônoma de nuvem no ciclo de incidente completo: detecção, localização, root-cause analysis e mitigação sobre microsserviços reais com inje… |
| [ITBench](https://github.com/itbench-hub/ITBench) | 🤖 agêntico | alta | Tarefas reais de automação de TI em três trilhas: SRE (diagnóstico/remediação de incidentes em k8s), CISO (segurança/compliance) e FinOps (custo). Wo… |
| [InterCode (IC-Bash)](https://github.com/princeton-nlp/intercode) | 🤖 agêntico | baixa | Coding interativo como ambiente RL padrão: dado um pedido em linguagem natural, o agente interage com o sistema via comandos (Bash/terminal) usando e… |

### 5. Testes (gerar, consertar, cobertura)

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [SWT-Bench](https://github.com/logic-star-ai/SWT-Bench) | 🤖 agêntico | média | Dado o codebase + a issue do GitHub (sobre o dataset do SWE-bench), gerar um teste reprodutor que FALHA antes do golden patch e PASSA depois (fail-to… |
| [TDD-Bench Verified](https://github.com/IBM/TDD-Bench-Verified) | 🤖 agêntico | média | Test-driven development real: dado issue + codebase ANTES da resolução, gerar teste fail-to-pass com boa adequação (cobre o código que a issue muda).… |
| [TestGenEval](https://github.com/facebookresearch/testgeneval) | unit | média | Três tarefas: test authoring (escrever suite do zero), test completion (completar suite existente) e melhoria de COBERTURA de código. Métricas: pass… |
| [TestEval](https://github.com/LLM4SoftwareTesting/TestEval) | unit | baixa | Geração de teste com alvo de cobertura preciso: (1) cobertura geral, (2) cobertura de linha/branch alvo, (3) cobertura de caminho alvo. Programas Pyt… |
| [UTBoost](https://github.com/uiuc-kang-lab/UTBoost) | unit | média | Augmentação/adequação de testes: usa um gerador (UTGenerator) que analisa codebase + dependências para criar testes que expõem patches que passavam s… |

### 6. Depuração e raciocínio sobre execução

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [debug-gym (Microsoft)](https://github.com/microsoft/debug-gym) | 🤖 agêntico | média | Depuração interativa real: o agente usa ferramentas de debugger (pdb, breakpoints, view, listdir, rewrite, eval) num loop de busca ativa de informaçã… |
| [SWT-Bench](https://github.com/logic-star-ai/swt-bench) _(repetido)_ | 🤖 agêntico | média | Reprodução de falha via geração de teste: dado um issue do GitHub, o agente escreve um teste que FALHA antes do golden patch e PASSA depois. Corretor… |
| [LocAgent / Loc-Bench](https://github.com/gersteinlab/LocAgent) | 🤖 agêntico | média | Localização de código/falha agêntica guiada por grafo: o agente navega um grafo heterogêneo do repo (multi-hop) para apontar arquivo → função → linha… |
| [REval](https://github.com/r-eval/REval) | unit | baixa | Raciocínio sobre execução em nível de ESTADO intermediário, não só saída final: prediz cobertura de código, estado do programa em cada passo, caminho… |
| [CRUXEval](https://github.com/facebookresearch/cruxeval) _(repetido)_ | unit | baixa | Raciocínio de execução em funções Python auto-contidas: CRUXEval-O (simular a execução e prever o output) e CRUXEval-I (prever um input que produz da… |
| [DebugBench](https://github.com/thunlp/DebugBench) | unit | média | Capacidade de depuração single-turn: código com bug implantado (GPT-4 sobre soluções LeetCode) em 4 categorias maiores (Syntax, Reference, Logic, Mul… |

### 7. Ferramentas / APIs / function calling

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [AppWorld](https://github.com/StonyBrookNLP/appworld) | 🤖 agêntico | média | Agente escreve codigo interativo que orquestra 457 APIs reais de 9 apps do dia-a-dia (Amazon/Spotify/Venmo-like) sobre um mundo simulado de ~100 usua… |
| [MCP-Universe](https://github.com/SalesforceAIResearch/MCP-Universe) | 🤖 agêntico | alta | Agente resolve tarefas reais interagindo com SERVIDORES MCP de verdade em 6 dominios (Location Navigation, Repository Management, Financial Analysis,… |
| [GAIA](https://arxiv.org/abs/2311.12983) | 🤖 agêntico | média | Perguntas reais multi-step para assistentes gerais que exigem raciocinio, uso de ferramentas, navegacao web e leitura de arquivos/multimodalidade. 3… |
| [ComplexFuncBench](https://github.com/zai-org/ComplexFuncBench) | 🤖 agêntico | média | Function calling COMPLEXO: multi-step dentro de um turno, com restricoes do usuario, inferencia de parametros a partir de info implicita, valores de… |
| [ToolSandbox](https://github.com/apple/ToolSandbox) | 🤖 agêntico | média | Uso de ferramentas STATEFUL, conversacional e interativo: execucao com estado, dependencias implicitas de estado entre tools, simulador de usuario em… |
| [NESTFUL](https://github.com/IBM/NESTFUL) | unit | baixa | Sequencias ANINHADAS de chamadas de API — a saida de uma chamada vira entrada da proxima (composicao funcional). Todas as funcoes sao executaveis; do… |

### 8. Agente de longo horizonte / trabalho de empresa

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [SWE-Bench Pro](https://github.com/scaleapi/SWE-bench_Pro-os) _(repetido)_ | 🤖 agêntico | média | Resolucao de tarefas SWE de nivel enterprise/long-horizon: patches que cruzam multiplos arquivos e exigem horas-a-dias de um engenheiro humano, em 41… |
| [SWE-Lancer](https://github.com/openai/SWELancer-Benchmark) _(repetido)_ | 🤖 agêntico | alta | Tarefas freelance reais do Upwork (US$50 bug fix ate US$32k feature), avaliadas por testes end-to-end triplo-verificados; inclui tarefas gerenciais o… |
| [TheAgentCompany (TAC)](https://github.com/TheAgentCompany/TheAgentCompany) | 🤖 agêntico | alta | Empresa de software simulada e auto-hospedada (GitLab, Plane, RocketChat, ownCloud) onde o agente navega na web, escreve codigo, roda programas e con… |
| [MLE-bench](https://github.com/openai/mle-bench) | 🤖 agêntico | média | Engenharia de ML fim-a-fim em 75 competicoes reais do Kaggle: preparar dados, treinar modelos, rodar experimentos e submeter. Grader deterministico c… |
| [AppWorld](https://github.com/StonyBrookNLP/appworld) _(repetido)_ | 🤖 agêntico | média | Agente de codigo interativo que opera 9 apps do dia a dia via 457 APIs e 100+ tabelas, gerando codigo com fluxo de controle rico e iterativo (nao seq… |
| [GAIA](https://arxiv.org/abs/2311.12983) _(repetido)_ | 🤖 agêntico | baixa | Assistente geral: 466 perguntas do mundo real que exigem raciocinio, web browsing, multimodalidade e uso de ferramentas em cadeia. 3 niveis (Nivel 3… |

### 9. Engenharia de ML e ciência de dados

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [MLE-bench](https://github.com/openai/mle-bench) _(repetido)_ | 🤖 agêntico | alta | Engenharia de ML fim-a-fim em 75 competicoes reais do Kaggle: preparar dataset, treinar/otimizar modelo, gerar submissao. Baselines humanos calibrado… |
| [RE-Bench (METR)](https://github.com/METR/RE-Bench) | 🤖 agêntico | alta | 7 ambientes abertos de research engineering de ML com pontuacao continua (ex.: ajustar scaling law, otimizar kernel de GPU, pipeline AlphaZero). Comp… |
| [MLGym / MLGym-Bench (Meta)](https://github.com/facebookresearch/MLGym) | 🤖 agêntico | alta | 13 tarefas abertas de pesquisa em IA (visao, NLP, RL, teoria dos jogos): gerar hipotese, processar dados, implementar metodo, treinar, rodar experime… |
| [MLAgentBench](https://github.com/snap-stanford/MLAgentBench) | 🤖 agêntico | média | 13 tarefas de experimentacao de ML fim-a-fim (de melhorar CIFAR-10 ate BabyLM): dado dataset + descricao da tarefa, o agente le/escreve arquivos, exe… |
| [DA-Code](https://github.com/yiyihum/da-code) | 🤖 agêntico | média | 500 tarefas agenticas de data science em 3 categorias — Data Wrangling, Machine Learning e EDA — sobre dados reais e diversos, exigindo Python e SQL.… |
| [DS-1000](https://github.com/xlang-ai/DS-1000) | unit | baixa | 1000 problemas de geracao de codigo de data science em 7 bibliotecas Python (NumPy, Pandas, etc.), coletados do StackOverflow. Avaliacao automatica d… |

### 10. Segurança de código e multi-linguagem

| benchmark | tipo | integrar | o que testa |
|---|---|---|---|
| [CyberGym](https://github.com/sunblaze-ucb/cybergym) | 🤖 agêntico | média | Reprodução/descoberta de vulnerabilidades reais: o agente recebe um projeto OSS e precisa escrever um PoC que faz o código não-corrigido crashar. Der… |
| [CVE-Bench](https://github.com/uiuc-kang-lab/cve-bench) | 🤖 agêntico | alta | Exploração agêntica de CVEs críticos reais de aplicações web dentro de sandbox containerizada que imita condições de produção; sucesso verificado por… |
| [Cybench](https://github.com/andyzorigin/cybench) | 🤖 agêntico | média | 40 tarefas de Capture The Flag de nível profissional (crypto, web, pwn, reversing, forense) — o agente executa comandos num ambiente e precisa recupe… |
| [BountyBench](https://github.com/bountybench/bountybench) | 🤖 agêntico | alta | Ciclo completo ofensa+DEFESA em sistemas reais: três tipos de tarefa — Detect (achar zero-day), Exploit (explorar vuln dada) e Patch (corrigir vuln d… |
| [SeCodePLT](https://github.com/ucsb-mlsec/SeCodePLT) | unit | média | Geração de código seguro sob demanda: para cada categoria de CWE traz semente vulnerável+corrigida, testes dinâmicos e PoC ground-truth; avalia insec… |
| [McEval](https://github.com/MCEVAL/McEval) | unit | alta | Correção funcional de código em cobertura massivamente multilíngue: completação, geração e compreensão de código, com corpora curados nativos por lin… |

## Lacunas abertas (crítica de cobertura — etapas ainda sem benchmark forte)

Etapas do fluxo que a varredura por lane não cobriu bem e valem uma frente própria:

- **Otimização de performance** ("deixe o repo mais rápido sem quebrar") — etapa
  inteira ausente. Candidatos: [SWE-Perf](https://github.com/SWE-Perf/SWE-Perf),
  [GSO](https://github.com/gso-bench/gso),
  [EffiBench](https://github.com/huangd1999/EffiBench),
  [KernelBench](https://github.com/ScalingIntelligence/KernelBench) (GPU/kernel).
- **Refatoração multi-arquivo com estado** — [RefactorBench](https://arxiv.org/abs/2503.07832).
- **Front-end / UI com verificação visual** (gerar UI e checar pixel) —
  [Design2Code](https://github.com/NoviScl/Design2Code),
  [FullStack Bench](https://github.com/bytedance/FullStackBench).
- **SQL / banco de dados** — [Spider 2.0](https://github.com/xlang-ai/Spider2), BIRD.
- **Detecção de vulnerabilidade** (achar o bug de segurança, distinto de exploração) —
  [PrimeVul](https://github.com/DLVulDet/PrimeVul).
- **Requisitos ambíguos / pergunta de esclarecimento** — o agente pede a
  desambiguação certa. **Casa direto com a tese do Atlas** (só as perguntas
  necessárias). Campo imaturo; candidatos: ClarifyGPT, CodeClarQA. Lacuna aberta.
- **Code review / revisão de PR** (agente como revisor, não autor) — emergindo;
  candidato c-CRAB. Lacuna aberta.

## Como integrar um benchmark novo (o processo)

1. Registrar em `config/atlas_rivals.php` → `benchmarks.repos.<id>`: URL, adapter,
   `native_agent_default`, `install`, `smoke`, timeout.
2. Clonar no root de trabalho (`ATLAS_RIVALS_BENCHMARKS_ROOT`) e passar o smoke
   (sem provider).
3. Escrever o adapter em `app/Services/Ai/Rivals/Adapters/External/<Nome>Adapter.php`
   com os **dois braços** (bare = agente nativo/`hermes -z`; with_atlas =
   `atlas:cli:dev` via bridge) e um corretor determinístico.
4. Adicionar ao perfil em `config/atlas_arena.php` (suites + weights + capability_map)
   quando quiser que conte no composto.
5. Documentar aqui: criar `benchmarks/engenharia-de-software/<id>/README.md`.

Ver `docs/engineering-knowledge-base/atlas-rivals-external-suites-v1.md` (catálogo
canônico) e `docs/rivals-warroom.md` (coordenação).
