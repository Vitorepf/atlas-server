# Capacidades para o Atlas ser dono autônomo de uma empresa

Mapa das capacidades que um agente precisa dominar para **pesquisar produto, especificar, construir, testar, fazer QA, analisar dados, e gerir/crescer o negócio** — sozinho. Cruzado com o benchmark REAL que mede cada uma (7 agentes especialistas, fonte verificada, 2026-07-19).

## Veredito (responde "não é amador? não falta capacidade?")

**Sim para as duas.** A lista que o Atlas media até agora cobre ~8 de **95** capacidades — só a linha de **execução de engenharia** (o *músculo*: gerar/editar código). Todo o **cérebro** — decidir o que construir, pra quem, se funcionou, e quem contratar — estava **sem régua nenhuma**.

- **55/95 (58%)** têm benchmark sério · **21/95 (22%)** só *proxy* (régua adjacente) · **19/95 (20%)** são **LACUNA pura** (zero medição).
- Contando proxy como "medido pela régua errada": **~42% das capacidades estão cegas** para a tarefa real.
- O buraco não é uma função — é uma **camada**: *julgamento / decisão / gestão*. Tudo que é "executar tarefa bem-definida" tem benchmark; tudo que é "decidir o que fazer, definir sucesso, gerir a função" não tem. É exatamente a camada "rodar a empresa".

**Boa notícia:** ~50 das capacidades com benchmark rodam **nativo no seu Mac** (produto, design, dados, negócio quase não usam Docker) — muito mais mensurável localmente que os benchmarks agênticos de código (que precisam de x86).

## Produto & Estratégia  ·  13 caps · 5 ✅ · 4 ⛔

| capacidade | mede? | onde | benchmark |
|---|---|---|---|
| Pesquisa de mercado/concorrência multi-fonte na web, sintetizada em relatório … | ✅ benchmark | nativo | [DeepResearch Bench](https://github.com/Ayanami0730/deep_research_bench) |
| Due diligence factual: localizar e verificar um fato específico e enterrado (c… | ✅ benchmark | nativo | [BrowseComp](https://openai.com/index/browsecomp/) |
| Executar tarefa longa multi-ferramenta de assistente: encadear web + leitura d… | 🟡 proxy | nativo | [GAIA](https://huggingface.co/spaces/gaia-benchmark/leaderboard) |
| Análise quantitativa de dados de produto (funil, retenção, coorte) via raciocí… | ✅ benchmark | nativo | [DABStep](https://huggingface.co/spaces/adyen/DABstep) |
| Traduzir pergunta de negócio em linguagem natural para query executável sobre … | ✅ benchmark | nativo | [BIRD](https://bird-bench.github.io/) |
| Análise qualitativa de entrevistas de usuário: aplicar codebook / extrair tema… | 🟡 proxy | nativo | [Benchmark de qualitative coding vs. adjudicaçã…](https://arxiv.org/abs/2606.26541) |
| Elicitação/entrevista multi-turno: fazer SÓ as perguntas necessárias para desa… | 🟡 proxy | nativo | [tau2-bench / τ²-Bench](https://github.com/sierra-research/tau2-bench) |
| Estimar tamanho de mercado / TAM-SAM-SOM sob incerteza (raciocínio Fermi com i… | 🟡 proxy | nativo | [FermiEval](https://arxiv.org/abs/2510.26995) |
| Prever resultado de aposta de produto / adoção / tendência (forecasting de eve… | ✅ benchmark | nativo | [ForecastBench](https://www.forecastbench.org/) |
| Escrever PRD/spec com critérios de aceite testáveis (requisitos funcionais e n… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |
| Priorizar backlog/roadmap a partir de sinais mistos sob restrição (RICE/tradeo… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |
| Teardown competitivo estruturado: matriz de features/posicionamento e identifi… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |
| Definir métrica de sucesso / North Star e o plano de instrumentação (eventos, … | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |

> **Veredito:** NÃO cobre. A lista atual do Atlas (EvalPlus, SWE-bench, function calling) mede geração e edição de código — zero das 13 capacidades desta função aparece nela. Mesmo as 9 capacidades com benchmark real ou proxy exigem suites totalmente diferentes que hoje NÃO estão medidas: deep research com citação (DeepResearch Bench), browsing factual difícil (BrowseComp), agente de dados multi-step (DABStep), N…

## Design & UX  ·  13 caps · 6 ✅ · 5 ⛔

| capacidade | mede? | onde | benchmark |
|---|---|---|---|
| Sintetizar pesquisa qualitativa (entrevistas, feedback, tickets, testes de usa… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |
| Priorizar backlog/roadmap a partir de sinais de usuário + metas de negócio (RI… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |
| Escrever PRD/spec com critérios de aceite testáveis, escopo, edge cases e métr… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |
| Traduzir design/screenshot em UI funcional fiel (design-to-code: HTML/CSS + Re… | ✅ benchmark | nativo | [Design2Code](https://arxiv.org/abs/2403.03163) |
| Perceber e localizar elementos de UI em telas reais de alta resolução (groundi… | ✅ benchmark | nativo | [ScreenSpot-Pro](https://arxiv.org/abs/2504.07981) |
| Operar ferramenta de design/protótipo de forma iterativa por linguagem (invoca… | ✅ benchmark | nativo | [CANVAS](https://canvas.kixlab.org/) |
| Avaliar usabilidade e produzir crítica de UX acionável (heurísticas de Nielsen… | ✅ benchmark | nativo | [UICrit](https://github.com/google-research-datasets/uicrit) |
| Julgar estética visual (layout, tipografia, cor, hierarquia) alinhado ao gosto… | ✅ benchmark | nativo | [AesEval-Bench — 'Can VLMs Assess Graphic Desig…](https://arxiv.org/abs/2603.01083) |
| Auditar acessibilidade (WCAG 2.1/2.2) e propor correções verificáveis (ARIA, s… | ✅ benchmark | nativo | [WebAccessBench](https://conesible.de/wab/whitepaper_webaccessbench.pdf) |
| UX writing / microcopy: labels, mensagens de erro, estados vazios, tom de voz … | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério de microcopy |
| Interpretar analytics de produto e resultados de A/B test, ligando dado→decisã… | 🟡 proxy | nativo | [InfiAgent-DABench](https://arxiv.org/abs/2401.05507) |
| Governar consistência de design system em escala (tokens, componentes, variant… | 🟡 proxy | nativo | [DesignBench](https://github.com/WebPAI/DesignBench) |
| Arquitetura de informação e navegação (agrupar conteúdo, rotular, estruturar f… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |

> **Veredito:** NÃO cobre. A lista atual do Atlas (EvalPlus, SWE-bench, function calling) mede geração de código puro e tool-calling texto→texto. Disso, ela toca por acidente apenas UMA das 13 capacidades desta função — design-to-code (Design2Code/DesignBench são primos front-end do SWE-bench) — e roça a mecânica de tool-use que CANVAS exige. Mede ZERO do núcleo perceptual/multimodal e de julgamento que define o …

## Engenharia & Arquitetura  ·  14 caps · 8 ✅ · 2 ⛔

| capacidade | mede? | onde | benchmark |
|---|---|---|---|
| Resolver um issue real reportado em repositório multi-arquivo: localizar o def… | ✅ benchmark | x86 | [SWE-bench Verified](https://www.swebench.com/) |
| Executar tarefas de engenharia de valor econômico real fim-a-fim E tomar a dec… | ✅ benchmark | x86 | [SWE-Lancer](https://openai.com/index/swe-lancer/) |
| Escrever testes que reproduzem um bug a partir da descrição do issue — teste q… | ✅ benchmark | x86 | [SWT-Bench](https://swtbench.com/) |
| Otimizar performance de código em repositório real: reduzir runtime medível de… | ✅ benchmark | x86 | [SWE-Perf](https://swe-perf.github.io/) |
| Gerar Infrastructure-as-Code correta e deployável (Terraform/CloudFormation/CD… | ✅ benchmark | ? | [IaC-Eval / Multi-IaC-Eval](https://arxiv.org/abs/2509.05303) |
| Diagnosticar incidente em produção a partir de telemetria: detectar que há inc… | ✅ benchmark | x86 | [AIOpsLab](https://www.microsoft.com/en-us/research/blog/aiopslab-building-ai-agents-for-autonomous-clouds/) |
| Operar autonomamente num terminal real em tarefas duras end-to-end: compilar, … | ✅ benchmark | x86 | [Terminal-Bench 2.0](https://arxiv.org/abs/2601.11868) |
| Operar como colega autônomo dentro de uma empresa de software: colaborar via c… | ✅ benchmark | x86 | [TheAgentCompany](https://github.com/TheAgentCompany/TheAgentCompany) |
| Detectar e corrigir vulnerabilidade de segurança em aplicação/código real (não… | 🟡 proxy | x86 | [CVE-Bench](https://github.com/uiuc-kang-lab/cve-bench) |
| Revisar um pull request com utilidade real: apontar os defeitos que um revisor… | 🟡 proxy | ? | [SWE-PRBench / CR-Bench](https://arxiv.org/abs/2603.26130) |
| Especificar produto: transformar requisitos ambíguos em PRD com critérios de a… | 🟡 proxy | nativo | [R2ABench](https://arxiv.org/abs/2604.06683) |
| Tomar decisão de arquitetura de sistema com trade-offs explícitos: decompor em… | 🟡 proxy | nativo | [ArchBench / SAKE](https://arxiv.org/abs/2603.17833) |
| Priorizar backlog a partir de sinais reais de usuário/dados de uso (telemetria… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |
| Gerir e crescer o negócio da função: roadmap, staffing/alocação, custo de clou… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério |

> **Veredito:** Não, cobre só o começo. A lista atual (EvalPlus/HumanEval + SWE-bench + function calling) mede apenas a capacidade 1 e o "músculo" de gerar patch — o eixo mais fácil e o único com benchmark maduro. Faltam, com benchmark executável já existente e ignorado: performance real (SWE-Perf), QA/geração de testes (SWT-Bench), valor econômico + decisão gerencial (SWE-Lancer), e todo o eixo ops/infra/SRE que…

## Qualidade & Segurança  ·  14 caps · 10 ✅ · 2 ⛔

| capacidade | mede? | onde | benchmark |
|---|---|---|---|
| Reproduzir um bug como teste executável (fail-to-pass) a partir do relato de i… | ✅ benchmark | x86 | [SWT-Bench](https://arxiv.org/abs/2406.12952) |
| Gerar uma suíte de testes para código sob teste maximizando cobertura E mutati… | ✅ benchmark | x86 | [TestGenEval](https://arxiv.org/abs/2410.00752) |
| Revisar um diff/PR com contexto de projeto completo e apontar defeitos reais (… | ✅ benchmark | nativo | [SWR-Bench](https://arxiv.org/abs/2509.01494) |
| Detectar vulnerabilidade em nível de função e classificar o CWE correto, com b… | ✅ benchmark | nativo | [PrimeVul](https://arxiv.org/abs/2403.18624) |
| Gerar prova-de-conceito/exploit que confirma que uma vulnerabilidade é de fato… | ✅ benchmark | x86 | [SEC-bench](https://arxiv.org/abs/2506.11791) |
| Corrigir a vulnerabilidade produzindo um patch de segurança que fecha a falha … | ✅ benchmark | x86 | [SEC-bench](https://arxiv.org/abs/2506.11791) |
| Gerar código que é funcionalmente correto E seguro por padrão (não introduzir … | ✅ benchmark | nativo | [CWEval](https://arxiv.org/abs/2501.08200) |
| Pentest ofensivo end-to-end estilo CTF (web/pwn/crypto/reversing) num ambiente… | ✅ benchmark | x86 | [Cybench](https://arxiv.org/abs/2408.08926) |
| Gerar harness de fuzzing válido que compila, aumenta cobertura da função-alvo … | ✅ benchmark | x86 | [OSS-Fuzz-Gen](https://github.com/google/oss-fuzz-gen) |
| Fazer QA end-to-end de UI dirigindo o app como um usuário real (executar fluxo… | 🟡 proxy | x86 | [WebArena — 812 tarefas long-horizon em 4 clone…](https://webarena.dev) |
| Triagem de incidente em produção: detectar, localizar, diagnosticar causa-raiz… | ✅ benchmark | x86 | [AIOpsLab](https://www.microsoft.com/en-us/research/wp-content/uploads/2024/10/arxiv_AIOpsLab.pdf) |
| Detectar e classificar testes instáveis (flaky) para manter o sinal do CI conf… | 🟡 proxy | nativo | [IDoFT — International Dataset of Flaky Tests](https://mir.cs.illinois.edu/flakytests/) |
| Mapear a implementação a controles de compliance técnico (SOC2 CC5-CC8, ISO 27… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério. Existe muita … |
| Definir estratégia de teste baseada em risco: priorizar o que testar/revisar a… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério. Não há benchm… |

> **Veredito:** NÃO cobre. A lista atual (EvalPlus, SWE-bench, function calling) mede 'o Atlas escreve código que funciona' — o lado CRIAÇÃO. A função Qualidade & Segurança é o lado JULGAR/VERIFICAR/DEFENDER, e hoje está quase toda sem sinal: (1) QA/verificação — geração de teste com mutation score (TestGenEval), reprodução de bug como teste (SWT-Bench), code review de PR (SWR-Bench) e flaky (IDoFT) não são medid…

## Dados & Experimentação  ·  13 caps · 8 ✅ · 2 ⛔

| capacidade | mede? | onde | benchmark |
|---|---|---|---|
| Traduzir pergunta de negócio em SQL correto sobre schema empresarial multi-tab… | ✅ benchmark | nativo | [BIRD-SQL](https://bird-bench.github.io/) |
| Análise end-to-end a partir de CSV/planilha: carregar, limpar, computar a métr… | ✅ benchmark | nativo | [InfiAgent-DABench / DAEval](https://infiagent.github.io/) |
| Raciocínio multi-passo combinando dados + documentação heterogênea de regras d… | ✅ benchmark | nativo | [DABStep](https://huggingface.co/spaces/adyen/DABstep) |
| Escolher e aplicar o teste estatístico correto (t-test/qui-quadrado/variância)… | ✅ benchmark | nativo | [StatQA](https://statqa.github.io/) |
| Inferência causal: distinguir correlação de causa e especificar estratégia de … | 🟡 proxy | nativo | [Corr2Cause](https://openreview.net/forum?id=vqIH0ObdqL) |
| Forecasting de série temporal de métrica de negócio (prever receita/DAU) e ava… | ✅ benchmark | nativo | [GIFT-Eval](https://github.com/SalesforceAIResearch/gift-eval) |
| ML engineering end-to-end: dado um problema preditivo, preparar dados, treinar… | ✅ benchmark | x86 | [MLE-bench](https://github.com/openai/mle-bench) |
| Construir pipeline de dados ELT end-to-end: extrair de fontes, carregar no war… | ✅ benchmark | x86 | [ELT-Bench](https://arxiv.org/abs/2504.04808) |
| Gerar a visualização correta a partir de linguagem natural (escolher tipo de g… | ✅ benchmark | nativo | [nvBench 2.0](https://nvbench2.github.io/) |
| Diagnosticar causa-raiz de mudança em métrica de PRODUTO (ex.: 'por que o DAU … | 🟡 proxy | nativo | [LACUNA para métrica de produto. OpenRCA](https://github.com/microsoft/OpenRCA) |
| Desenhar e analisar um experimento A/B com rigor: cálculo de poder/tamanho de … | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério. AgentA/B é si… |
| Definir a árvore de métricas/KPIs a partir do objetivo de negócio: North Star,… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério mede tradução … |
| Garantir qualidade/reprodutibilidade da própria análise antes de publicar: rev… | 🟡 proxy | nativo | [AIRepr](https://arxiv.org/abs/2502.16395) |

> **Veredito:** NÃO cobre. A lista atual (EvalPlus, SWE-bench, function calling) mede geração de código — que é só o "músculo" de várias dessas capacidades, nunca o julgamento. Existem benchmarks de dados sérios e majoritariamente native-runnable que o Atlas deveria adotar HOJE (BIRD, InfiAgent-DABench, DABStep, StatQA/QRData, GIFT-Eval/ForecastBench, nvBench, DSBench). Porém as capacidades mais críticas para um …

## Crescimento & GTM  ·  14 caps · 8 ✅ · 3 ⛔

| capacidade | mede? | onde | benchmark |
|---|---|---|---|
| Inteligência de mercado: fazer research multi-fonte na web e sintetizar em rel… | ✅ benchmark | nativo | [DeepResearch Bench](https://github.com/Ayanami0730/deep_research_bench) |
| Priorizar backlog de growth a partir de sinais reais de usuário (tickets, entr… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério; não existe ev… |
| Escrever PRD/spec de feature ou campanha com critérios de aceite testáveis, mé… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério mede a QUALIDA… |
| Produzir copy/conteúdo de marketing com voz de marca consistente e diferenciaç… | 🟡 proxy | nativo | [Creativity Benchmark](https://arxiv.org/abs/2509.09702) |
| SEO & crescimento orgânico ponta-a-ponta: keyword research → briefing → on-pag… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério da disciplina;… |
| Analytics de mídia paga: interpretar performance de campanhas com ferramentas … | ✅ benchmark | ? | [AD-Bench](https://arxiv.org/abs/2602.14257) |
| Análise multi-step de dados de funil respondendo pergunta de negócio: navegar … | ✅ benchmark | nativo | [DABStep](https://huggingface.co/spaces/adyen/DABstep) |
| Desenhar e interpretar experimentos (A/B) e raciocinar sobre causalidade: hipó… | 🟡 proxy | nativo | [QRData](https://arxiv.org/abs/2402.17644) |
| Atendimento/suporte ao cliente multi-turn: resolver o pedido do usuário via fe… | ✅ benchmark | nativo | [τ²-bench](https://github.com/sierra-research/tau2-bench) |
| Operar o funil de vendas/CRM multi-turn: qualificar lead, CPQ (configure-price… | ✅ benchmark | ? | [CRMArena-Pro](https://huggingface.co/datasets/Salesforce/CRMArenaPro) |
| Persuasão: escrever argumento/pitch/página que efetivamente muda a decisão ou … | ✅ benchmark | nativo | [PersuasionBench / PersuasionArena](https://arxiv.org/abs/2410.02653) |
| Negociação bilateral: fechar preço/contrato/parceria com contraparte adversári… | ✅ benchmark | nativo | [NegotiationArena](https://proceedings.mlr.press/v235/bianchi24a.html) |
| Gestão de comunidade e social: analisar dados sociais, entender audiência/pers… | 🟡 proxy | ? | [SoMe](https://arxiv.org/abs/2512.14720) |
| Executar em ferramentas/dashboards reais na web (não só planejar): publicar um… | ✅ benchmark | x86 | [WebArena](https://github.com/web-arena-x/webarena) |

> **Veredito:** NÃO cobre. A lista atual do Atlas só mede codificação (EvalPlus, SWE-bench, function calling) e isso certifica ZERO dos resultados de GTM: pesquisa/síntese, persuasão, negociação, resolução de suporte, vendas/CRM, analytics de mídia paga, qualidade de conteúdo, experimentação/causalidade e comunidade. Function calling é só o trilho horizontal fino que SUBJAZ tau2-bench/CRMArena/WebArena — não subs…

## Negócio & Operações  ·  14 caps · 10 ✅ · 1 ⛔

| capacidade | mede? | onde | benchmark |
|---|---|---|---|
| Responder perguntas financeiras sobre documentos reais (10-K/10-Q/8-K/earnings… | ✅ benchmark | nativo | [FinanceBench](https://github.com/patronus-ai/financebench) |
| Construir e auditar modelos financeiros de ponta a ponta em planilha: 3-statem… | ✅ benchmark | ? | [FrontierFinance — computer-use benchmark de mo…](https://arxiv.org/abs/2604.05912) |
| Pesquisa financeira agêntica multi-etapa: buscar, extrair e calcular sobre fon… | ✅ benchmark | nativo | [Finance Agent Benchmark — tarefas reais de res…](https://arxiv.org/abs/2508.00828) |
| Revisar contratos e extrair cláusulas de risco (indenização, non-compete, chan… | ✅ benchmark | nativo | [CUAD — Contract Understanding Atticus Dataset](https://huggingface.co/datasets/theatticusproject/cuad-qa) |
| Due diligence de M&A: localizar deal points em merger agreements e classificar… | ✅ benchmark | nativo | [MAUD](https://www.atticusprojectai.org/maud/) |
| Raciocínio jurídico/compliance aplicado: issue-spotting, aplicar regra a fato … | ✅ benchmark | nativo | [LegalBench](https://github.com/HazyResearch/legalbench) |
| Executar tarefas corporativas cross-role de longo horizonte num ambiente de em… | ✅ benchmark | x86 | [TheAgentCompany](https://the-agent-company.com) |
| Operar sistemas de negócio via API em diálogo multi-turno cumprindo política e… | ✅ benchmark | nativo | [τ²-bench](https://github.com/sierra-research/tau2-bench) |
| Previsão probabilística calibrada de eventos futuros de negócio sob incerteza … | ✅ benchmark | nativo | [ForecastBench — 1.000 perguntas sobre futuro, …](https://arxiv.org/abs/2409.19839) |
| Planejamento de longo horizonte com pré-condições e restrições: gerar plano vá… | 🟡 proxy | nativo | [PlanBench](https://github.com/karthikv792/LLMs-Planning) |
| Análise de dados de negócio autônoma end-to-end: do CSV bruto à conclusão, esc… | ✅ benchmark | nativo | [InfiAgent-DABench](https://arxiv.org/abs/2401.05507) |
| Decisão estratégica nível-CEO: sintetizar conselhos conflitantes de C-suite e … | 🟡 proxy | nativo | [CEO-Bench — realocação estratégica de recursos…](https://arxiv.org/abs/2606.17459) |
| Negociação multi-turno de acordos maximizando resultado sob informação privada… | 🟡 proxy | nativo | [NegotiationArena](https://arxiv.org/abs/2402.05863) |
| Contratação e gestão de pessoas: triar/avaliar candidatos, decidir contratação… | ⛔ LACUNA | — | LACUNA — nenhum benchmark sério de qualidade d… |

> **Veredito:** NÃO cobre. A lista atual do Atlas mede só síntese/reparo de código (EvalPlus, SWE-bench) e a mecânica de function calling — zero sobreposição com julgamento de NEGÓCIO. O único elo é function calling, que é PRÉ-CONDIÇÃO para os benchmarks agênticos (τ²-bench, CRMArena-Pro), mas mede se a ferramenta certa foi chamada, não se a decisão de negócio estava certa (emitir o reembolso? o MAE foi disparado…

## ⛔ As 19 lacunas — capacidades SEM benchmark (o cérebro não-medido)

**Produto & Estratégia:** Escrever PRD/spec com critérios de aceite testáveis (requisitos funcio… · Priorizar backlog/roadmap a partir de sinais mistos sob restrição (RIC… · Teardown competitivo estruturado: matriz de features/posicionamento e … · Definir métrica de sucesso / North Star e o plano de instrumentação (e…
**Design & UX:** Sintetizar pesquisa qualitativa (entrevistas, feedback, tickets, teste… · Priorizar backlog/roadmap a partir de sinais de usuário + metas de neg… · Escrever PRD/spec com critérios de aceite testáveis, escopo, edge case… · UX writing / microcopy: labels, mensagens de erro, estados vazios, tom… · Arquitetura de informação e navegação (agrupar conteúdo, rotular, estr…
**Engenharia & Arquitetura:** Priorizar backlog a partir de sinais reais de usuário/dados de uso (te… · Gerir e crescer o negócio da função: roadmap, staffing/alocação, custo…
**Qualidade & Segurança:** Mapear a implementação a controles de compliance técnico (SOC2 CC5-CC8… · Definir estratégia de teste baseada em risco: priorizar o que testar/r…
**Dados & Experimentação:** Desenhar e analisar um experimento A/B com rigor: cálculo de poder/tam… · Definir a árvore de métricas/KPIs a partir do objetivo de negócio: Nor…
**Crescimento & GTM:** Priorizar backlog de growth a partir de sinais reais de usuário (ticke… · Escrever PRD/spec de feature ou campanha com critérios de aceite testá… · SEO & crescimento orgânico ponta-a-ponta: keyword research → briefing …
**Negócio & Operações:** Contratação e gestão de pessoas: triar/avaliar candidatos, decidir con…

## As 5 lacunas para destravar primeiro (o loop de decisão)

Formam o loop fechado que *envolve* a execução já medida — é o M× do wrapper Atlas:
**4. As 5 lacunas de medição para destravar primeiro**

Elas formam o **loop de decisão fechado** que *envolve* a execução já medida — o M× de wrapper da tese Atlas é justamente a parte não-medida:

1. **Priorização de backlog/roadmap sob sinais mistos** (RICE/WSJF, go/no-go, kill-vs-scale). LACUNA em 4 funções. É o portão "o que fazer a seguir" — sem ele, todo o resto executa a coisa errada com perfeição.
2. **Autoria de PRD/spec com critérios de aceite testáveis.** LACUNA em produto/design/crescimento, só proxy em eng. É o *contrato de hand-off* que converte decisão em trabalho para o músculo já benchmarkado — maior alavanca porque alimenta a única parte medida, e é a interface linguagem-natural→máquina.
3. **Definição de métrica de sucesso/North Star + plano de instrumentação.** LACUNA em produto e dados. Sem isso o loop não fecha: a empresa autônoma não consegue dizer se algo funcionou — um runaway por definição.
4. **Desenho e leitura de experimento A/B (shipar/matar).** LACUNA em dados, proxy em design/crescimento. É a verificação "a aposta pagou?". Com o #3, fecha o loop de compounding — a antifragilidade exige sinal de feedback real, não alucinado.
5. **Gerir/crescer a própria função** (staffing/alocação, FinOps, roadmap, reporte a stakeholder). LACUNA em engenharia, ecoada pela LACUNA de contratação em negócio. É a camada gerencial que dá nome à ambição inteira — e a menos medida de todas.

Cortados de propósito (importam, mas são mais estreitos que os 5 portões do loop): teardown/posicionamento, microcopy, SEO/GEO honesto, evidência de compliance, estratégia de teste por risco.

