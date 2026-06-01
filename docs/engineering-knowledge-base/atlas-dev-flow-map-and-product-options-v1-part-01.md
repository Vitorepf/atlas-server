---
id: atlas-dev-flow-map-and-product-options-v1-part-01
type: engineering_knowledge
title: Atlas Dev Flow Map And Product Options v1 · Parte 1
status: active
category: programming
priority: 104
summary: Recorte focado de Atlas Dev Flow Map And Product Options v1: Resumo ate Contexto 2: Programming Governance, SCOR-1 E Spec Como Arma.
tags:
  - atlas-dev
  - product-options
  - split-doc
  - cartography-readable
capabilities:
  - atlas_dev_product_flow_map
  - atlas_documentation_split
decisions:
  - Este recorte preserva uma parte do mapa de fluxo/produto sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md quando o mapa de produto mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-flow-map-and-product-options-v1-part-01
graph_title: Atlas Dev Flow Map And Product Options v1 Parte 1
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-dev-flow-map-and-product-options-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Flow Map And Product Options v1 Parte 1
canonical_name: Atlas Dev Flow Map And Product Options v1 Parte 1
technical_name: atlas-dev-flow-map-and-product-options-v1-part-01
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-01.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-01.md
allowed_changes:
  - Atualizar somente a parte descrita neste recorte.
forbidden_changes:
  - Transformar opção de produto, diário ou hipótese em contrato runtime sem evidência.
depends_on:
  - atlas-dev-flow-map-and-product-options-v1
flows_to:
  - atlas-dev-flow-map-and-product-options-v1
unlocks:
  - atlas_cartography_readable_documentation
governs:
  - atlas_dev.product_options
evidence:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
evidence_refs:
  - symbol: AtlasDevFlowMapProductOptionsV1Part01Service
  - command: atlas:aaeos:atlas-dev-flow-map-product-options-v1-part01
  - test: AtlasDevFlowMapProductOptionsV1Part01Test
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao contrato de documentação.
---
# Atlas Dev Flow Map And Product Options v1 · Parte 1

## Resumo

Este recorte preserva uma parte focada de Atlas Dev Flow Map And Product Options v1: Resumo ate Contexto 2: Programming Governance, SCOR-1 E Spec Como Arma.

## Papel no Atlas

Mantém diário, opções, entrypoints ou matriz fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md` e deve ser lido quando a pessoa precisar do detalhe desta decisão de produto/fluxo.

## Contratos

Segue o documento dono, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → decisão de produto, execução ou revisão correspondente.

## Regras para IA

Não transformar hipótese, diário, opção futura ou comparação em runtime pronto. Não misturar patamar, versão, fonte, risco, regra ou prova.

## Escopo de Implementacao

Este arquivo só guarda o detalhe extraído do documento maior.

## Dependencias

Depende do índice `atlas-dev-flow-map-and-product-options-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o documento principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém confundir opção/produto futuro com contrato implementado.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a parte correspondente mudar e rodar docs-health.

## Conteudo Extraido
## Resumo

Atlas Dev hoje e a camada de programacao diaria do Atlas. Ele nao e um unico
servico: e uma composicao de surfaces, CLI, chat, runtime de payload, decisao
de provider/modelo, Open Brain, Kernel Pipeline, contrato de programacao,
execucao provider/harness, quality gate, repair e promocao para Forge.

A direcao desta campanha mudou: nao vamos comecar mexendo em Rivals. Rivals e a
arena final, nao a oficina. Antes disso, vamos construir uma maquina Atlas Dev
+ Sonnet forte o bastante para entrar nessa arena com chance real de destruir
Opus puro em tarefas praticas de engenharia.

A aposta:

```text
Provider puro
-> rapido e barato, mas pouco contexto/governanca.

Atlas Dev + Sonnet
-> quase tao barato quanto Sonnet puro, mas com contexto melhor, plano curto,
   escopo, patch, testes focados, verificacao, reparo leve e escalada honesta.

Atlas Forge
-> governanca alta: evidence, replay, topology, fallback, auditoria,
   high-risk/enterprise/long-running.

Opus puro
-> modelo muito forte, mas sem maquina local de contexto, execucao,
   verificacao e aprendizado de repo.
```

Este doc coloca todas as pecas na mesa para montar o Atlas Dev como produto de
execucao diaria e manter a sessao viva por varios dias: contexto recebido,
valor extraido, hipoteses, decisoes, backlog, experimentos, criterios de
vitoria e preparacao futura para Rivals.

## Missao Desta Campanha

Construir uma maquina Atlas Dev + Sonnet que vença Opus puro em desenvolvimento
real. Nao por "Sonnet ser mais inteligente", mas porque Atlas entrega ao Sonnet
um sistema melhor:

- contexto certo em vez de contexto bruto;
- intencao normalizada em vez de prompt humano solto;
- escopo provavel de arquivos antes da chamada;
- plano curto e verificavel;
- patch com respeito ao workspace;
- teste focado e barato;
- repair capsule com erro real;
- criterio claro de quando parar;
- criterio claro de quando escalar para Forge;
- memoria operacional do que funcionou e do que falhou.

### Ordem De Batalha

1. Entender todos os fluxos atuais do Atlas Dev.
2. Receber contextos do usuario e registrar o valor de cada um.
3. Extrair principios de produto e arquitetura desses contextos.
4. Transformar principios em hipoteses testaveis.
5. Transformar hipoteses em mudancas pequenas no Atlas Dev.
6. Medir localmente contra tarefas representativas.
7. So depois preparar o Atlas para entrar no Rivals.

### Regra De Ouro

Nao mexer em Rivals agora.

Rivals so deve mudar quando a maquina Atlas Dev + Sonnet tiver:

- contrato de execucao claro;
- resultados locais reproduziveis;
- tarefas de benchmark selecionadas;
- metricas de qualidade/custo/tempo;
- politica de escalada;
- criterio de comparacao contra Opus puro.

## Diario De Contexto Da Sessao

Esta secao deve acumular os contextos que o usuario trouxer durante a campanha.
Cada contexto precisa virar material operacional, nao apenas anotacao.

Formato obrigatorio para cada contexto novo:

```text
Contexto N:
- Fonte:
- Texto/ideia recebida:
- Valor estrategico:
- O que muda na tese Atlas Dev + Sonnet:
- Fluxos Atlas Dev afetados:
- Hipoteses geradas:
- Experimentos derivados:
- Decisoes ou nao-decisoes:
- Riscos:
- Backlog candidato:
```

### Contexto 1: Atlas Dev Como Peca Entre Provider Puro E Forge

Fonte: conclusao anterior da conversa.

Texto/ideia recebida:

```text
Provider puro -> barato, rapido, menos contexto/governanca.
Atlas Dev -> quase tao barato quanto provider puro, mas com contexto, escopo,
testes e verificacao.
Atlas Forge -> caro e robusto, usado para mudancas criticas, longas,
enterprise ou quando Atlas Dev falha.
```

Valor estrategico:

- separa produto diario de produto enterprise;
- impede Forge de virar default caro;
- impede provider puro de ser confundido com sistema;
- cria uma camada onde Sonnet pode vencer modelos maiores por orquestracao;
- define a tese de custo: Atlas Dev + Sonnet precisa ficar perto de Sonnet puro.

O que muda na tese Atlas Dev + Sonnet:

- a vitoria contra Opus nao vem de uma chamada melhor, vem do ciclo completo;
- o Atlas Dev precisa ser excelente em selecionar contexto, limitar escopo,
  executar teste certo e reparar erro pequeno;
- cada etapa precisa ser barata, porque custo proximo de Sonnet puro e parte da
  tese.

Fluxos Atlas Dev afetados:

- intake de `atlas:cli:dev`;
- Open Brain compacto;
- Programming Orchestrator;
- provider/model routing;
- quality gate;
- repair;
- Dev -> Forge promotion.

Hipoteses geradas:

- Sonnet com contexto selecionado + teste focado vence Opus puro em bugfix
  comum.
- Sonnet com plano curto e escopo de arquivos vence Opus puro em tarefas
  messy-real onde o prompt humano e incompleto.
- Opus puro mantem vantagem em arquitetura abstrata, mas perde em repos reais
  quando nao tem execucao/teste/reparo.

Experimentos derivados:

- criar bateria local de tarefas humanas normais antes de Rivals;
- medir Sonnet puro vs Atlas Dev + Sonnet usando os mesmos prompts;
- registrar quantas chamadas foram necessarias;
- registrar se o primeiro teste focado pegou erro real;
- registrar se o repair leve resolveu sem escalar.

Decisoes ou nao-decisoes:

- decisao: Atlas Dev e o modo diario.
- decisao: Forge e escalada, nao default.
- nao-decisao: nao implementar arm de Rivals agora.

Riscos:

- Atlas Dev virar um Forge pequeno e caro;
- Atlas Dev virar provider puro com branding;
- benchmark ser contaminado por fallback/council;
- vitoria ser declarada sem criterio reproduzivel.

Backlog candidato:

- definir `sonnet_killer_mode` como perfil interno de Atlas Dev;
- adicionar budget de chamadas por task;
- fortalecer retrieval barato;
- criar criterio de sucesso local antes de Rivals.

### Contexto 2: Programming Governance, SCOR-1 E Spec Como Arma

Fonte: contexto do usuario sobre a linha anterior de programacao assistida por
IA no Atlas.

Texto/ideia recebida:

```text
Atlas Programming Governance System:
intake, classificacao, spec, plan, task contracts, receipts, verify, evidence,
review e completion.

Atlas Forge Operating System:
work packets, multiagente, reservas, collision matrix, integration queue,
release gate e execucao mais pesada.

Atlas Code SCOR-1:
cockpit visual para sessoes longas: spec/plan/tasks vivos, gates, evidence,
scope guard, checkpoint/resume, repair loop e cartografia.

Spec antes do codigo:
Spec, Plan e Tasks nao sao texto decorativo. Sao objetos versionados, hashados,
auditaveis e ligados a evidence.

Fluxo ideal:
Intent -> Context -> Spec -> Plan -> Task Contracts -> Execution -> Gates
-> Evidence -> Review -> Learning -> Cartography
```

Docs relacionadas no repo:

- `docs/engineering-knowledge-base/atlas-programming-governance-system.md`;
- `docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md`;
- `docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md`;
- `docs/engineering-knowledge-base/atlas-forge-operating-system.md`;
- `docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md`;
- `docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md`;
- `docs/engineering-knowledge-base/spec-operating-system/`.

Valor estrategico:

- mostra que o Atlas ja tem uma gramatica propria de engenharia operacional;
- transforma "programacao com IA" em pipeline auditavel, nao improviso;
- da ao Atlas Dev um arsenal que Opus puro nao tem: spec, task contract,
  receipts, gates, evidence, learning e cartografia;
- permite um modo diario com governanca proporcional, sem carregar o Forge OS
  inteiro;
- torna a vitoria contra Opus uma vitoria de sistema, nao de modelo.

O que muda na tese Atlas Dev + Sonnet:

- Atlas Dev + Sonnet deve usar uma versao leve de Programming Governance;
- a unidade minima nao deve ser "prompt -> resposta", mas
  "intent -> context -> mini-spec -> plan -> scoped execution -> verification";
- Spec/Plan/Task precisam existir em forma compacta mesmo no modo leve;
- evidence simples e obrigatoria separa "parece certo" de "foi verificado";
- learning/cartography devem registrar padroes de falha para melhorar runs
  futuros.

Fluxos Atlas Dev afetados:

- intake precisa classificar risco e decidir se precisa spec leve ou spec forte;
- Open Brain deve alimentar contexto com docs canonicas e contratos relevantes;
- Programming Orchestrator deve produzir mini-spec, plan e task contract;
- command builder deve projetar isso no prompt do Sonnet;
- quality gate deve gerar receipt, nao apenas texto final;
- repair deve consumir evidence real do gate falho;
- completion deve retornar estado honesto: passed, needs_review, failed ou
  escalate_forge.

Hipoteses geradas:

- Sonnet com mini-spec + task contract vence Opus puro em tarefas ambivalentes.
- Sonnet com evidence/receipt evita alucinacao de conclusao melhor que Opus
  puro sem execucao.
- A governanca leve melhora qualidade sem explodir custo se for compacta e
  proporcional ao risco.
- SCOR-1 e Forge indicam o destino visual/operacional, mas Atlas Dev CLI pode
  capturar 60-70% do valor antes da UI completa.

Experimentos derivados:

- comparar prompt humano bruto contra prompt normalizado com mini-spec;
- medir tarefas com e sem task contract de arquivos permitidos/proibidos;
- medir taxa de erro de escopo com scope guard leve;
- medir qualidade final com e sem receipt de verification;
- criar uma task longa e testar checkpoint/resume minimo antes de SCOR completo.

Decisoes ou nao-decisoes:

- decisao: Atlas Dev deve herdar a lei "spec antes do codigo" de forma
  proporcional ao risco.
- decisao: task contract e evidence sao armas contra Opus, nao burocracia.
- decisao: Forge OS continua reservado para execucao pesada.
- nao-decisao: nao puxar multiagente, reservas, collision matrix ou release
  gate completo para o modo diario.

Riscos:

- governanca leve virar burocracia pesada e matar a vantagem de custo;
- mini-spec virar texto decorativo sem contrato executavel;
- evidence virar resumo narrativo sem comando/teste/diff;
- SCOR-1 ser confundido com requisito obrigatorio antes de melhorar CLI;
- copiar ferramentas externas em vez de absorver principios.

Backlog candidato:

- criar `MiniProgrammingSpec` para Atlas Dev;
- criar `LightTaskContract` com allowed files, forbidden files, expected tests,
  risk level e acceptance;
- criar `VerificationReceipt` simples para runs Dev;
- adicionar `scope_guard_light` antes de completion;
- adicionar `session_memory_light` com decisoes, arquivos lidos, falhas e
  proxima acao segura;
- projetar docs canonicas relevantes no Open Brain com budget curto.

