---
id: atlas-dev-flow-map-and-product-options-v1
type: engineering_knowledge
title: Atlas Dev Flow Map And Product Options v1
status: active
category: programming
priority: 104
summary: Mapa completo e caderno de campanha do Atlas Dev (fluxo de desenvolvimento workspace-bound dentro do Atlas AI): fluxos atuais, casos de uso, opcoes de produto, contexto acumulado, hipoteses e plano para construir um Atlas Dev robusto, governado e de qualidade extrema dentro do Atlas AI. Atlas AI e o produto/superficie unica; Atlas Dev e UM fluxo entre varios (Research, Explain, Debug, Review, Conversation, Forge). Benchmark, Rivals e comparacao contra Opus ficam fora da fase atual (equipe Medicao). Para entrypoint canonico do Atlas Dev e ordem de leitura, ver `atlas-dev-index.md`.
tags:
  - atlas-dev
  - atlas-cli
  - programming
  - provider-routing
  - open-brain
  - dev-to-forge
capabilities:
  - atlas_dev_flow_map
  - atlas_dev_light_design
  - daily_programming_runtime
  - programming_repair_loop
  - dev_to_forge_promotion
decisions:
  - Atlas Dev e a camada diaria de programacao antes de Forge, nao um Forge menor com outro nome.
  - O Atlas Dev atual ja passa por CLI, chat, surface adapters, Domain Catalog, Atlas Decide, Open Brain, Kernel Pipeline, provider execution, quality gate e promocao Dev -> Forge.
  - Nao vamos mudar Rivals agora; a fase atual e exclusivamente construcao do Atlas Dev robusto.
  - Benchmark, Opus challenge, battery de prompts e scoring competitivo pertencem a outro Codex/Claude e outro contrato.
  - A meta atual e performance e qualidade extrema do Atlas Dev por contratos, contexto, prompt projection, escopo, verificacao, repair e escalada.
  - Forge continua sendo modo de governanca alta; Atlas Dev deve cobrir o cotidiano com custo e latencia proximos do provider puro.
  - decision_locked initial_surface atlas_ai_desktop_mac_via_surface_id_atlas_desktop_ai 2026-05-16
  - decision_locked governance_mapping atlas_dev_gates_are_compact_projections_of_programming_governance_gates 2026-05-16
maintenance:
  - Atualize este doc antes de alterar `atlas:cli:dev`, `atlas:ai:chat --dev`, AtlasDevRuntimeService, AtlasProgrammingOrchestrator, DevToForgePromotionService ou SurfaceAdapters.
  - Nao alterar Rivals nesta fase. Nao adicionar benchmark competitivo a este fluxo.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-glossary.md
  - docs/engineering-knowledge-base/atlas-dev-policy.md
  - docs/engineering-knowledge-base/atlas-dev-patamares.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-ai-router-flow-routing-contract-v1.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md
  - docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md
  - docs/engineering-knowledge-base/spec-operating-system/
  - app/Console/Commands/AtlasCliDevCommand.php
  - app/Console/Commands/AiChatCommand.php
  - app/Console/Commands/AtlasCliFixCommand.php
  - app/Console/Commands/AtlasCliContinueCommand.php
  - app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
  - app/Services/Ai/Cli/AtlasCliProviderStrategyService.php
  - app/Services/Ai/Programming/AtlasDevRuntimeService.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/Programming/ProgrammingSurfaceContractFactory.php
  - app/Services/Ai/Programming/AtlasProgrammingSurfaceCommandBuilder.php
  - app/Services/Ai/Surface/Adapters/AtlasCliDevSurfaceAdapter.php
  - app/Services/Ai/Surface/Adapters/AtlasDesktopAiSurfaceAdapter.php
  - app/Services/Ai/Surface/Adapters/AtlasAppSurfaceAdapter.php
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/contract.ts
  - ../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/client.ts
  - app/Services/AtlasCode/DevToForgePromotionService.php
  - app/Services/AtlasCode/PromotionSignalDetector.php
  - tests/Feature/AtlasCliDevCommandTest.php
  - tests/Unit/AtlasCliDevWorkflowServiceTest.php
  - tests/Unit/Ai/Programming/AtlasDevRuntimeServiceTest.php
  - tests/Feature/AtlasCode/AtlasCodeDevToForgePromotionTest.php
  - tests/Unit/Ai/Surface/SurfaceAdaptersTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-flow-map-and-product-options-v1
graph_title: Atlas Dev Flow Map And Product Options v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-conversation-surface-and-atlas-dev-v1
graph_status: active
graph_source: repo
owner: programming
next_actions:
  - Normalizar o contrato de execucao diaria do Atlas Dev como fast lane do Programming Governance System.
  - Implementar a primeira surface completa no Atlas AI Desktop Mac (`surface_id=atlas_desktop_ai`).
  - Registrar receipts, telemetry, error ledger e criterio de escalada operacional antes de qualquer promocao para Forge.
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1.md
allowed_changes:
  - Adicionar novos modos, casos de uso e contratos quando o Atlas Dev ganhar driver real ou nova UI.
  - Registrar contextos novos desta sessao, extrair valor deles e transformar em hipoteses/testes/backlog.
  - Refinar a fronteira Atlas Dev vs Atlas Forge com base em evidence operacional interna.
forbidden_changes:
  - Tratar provider como arquitetura.
  - Declarar que Atlas Dev executa com driver dedicado quando o caminho real ainda delega para `atlas:ai:chat` ou Engineering Harness.
  - Misturar promocao Dev -> Forge com auto-criacao irrestrita de Obra.
  - Mexer em Rivals como primeiro passo desta campanha.
  - Criar benchmark competitivo, Opus challenge, messy prompt battery ou score de Rivals nesta fase de construcao.
  - Chamar de vitoria contra Opus qualquer resultado sem tarefa reproduzivel, evidencia, custo, tempo e criterio de qualidade.
depends_on:
  - atlas-ai-conversation-surface-and-atlas-dev-v1
  - open-brain-context-injection
  - atlas-code-programming-obras-operating-system
flows_to:
  - atlas_cli_dev
  - atlas_ai_chat
  - atlas_desktop_ai
  - atlas_app
  - atlas_dev_light
  - atlas_forge
unlocks:
  - atlas_dev_efficient_programming_flow
  - daily_programming_product_matrix
  - dev_to_forge_routing_policy
governs:
  - atlas_dev.daily_programming
  - atlas_dev_light.product_shape
  - atlas_dev_to_forge.escalation
evidence:
  - app/Console/Commands/AtlasCliDevCommand.php
  - app/Console/Commands/AiChatCommand.php
  - app/Services/Ai/Cli/AtlasCliDevWorkflowService.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - tests/Feature/AtlasCliDevCommandTest.php
  - tests/Unit/AtlasCliDevWorkflowServiceTest.php
required_tests:
  - "php artisan test tests/Feature/AtlasCliDevCommandTest.php tests/Unit/AtlasCliDevWorkflowServiceTest.php tests/Unit/Ai/Programming/AtlasDevRuntimeServiceTest.php"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia este doc antes de implementar `atlas_dev_light`, mexer em Atlas Dev ou mudar a politica de escalada para Forge.
quality_gates:
  - atlas_dev_entrypoints_mapped
  - dev_runtime_workspace_required
  - provider_routing_auditable
  - quality_gate_contract_explicit
  - forge_escalation_honest
failure_modes:
  - Atlas Dev virar provider puro com branding.
  - Atlas Dev virar Forge caro por padrao.
  - Perder workspace/contexto ao sair de CLI para Desktop/App.
  - Deixar outra IA puxar medicao competitiva para dentro da construcao do runtime.
observability_signals:
  - plan_id
  - thread_id
  - workspace
  - surface_id
  - programming_profile
  - programming_flow
  - executor_decision.executor
  - selected_provider
  - selected_model
  - open_brain.context_pack_hash
  - kernel_pipeline.pipeline_id
  - quality_gate_policy.required_final_status
  - promotion_target
line_limit: 2100
---
# Atlas Dev Flow Map And Product Options v1

## Escopo Ativo Inviolavel

Esta sessao pertence a equipe de criacao do Atlas Dev.

Foco unico:

- construir o Atlas Dev robusto;
- maximizar performance e qualidade do runtime;
- implementar contratos, contexto, prompt projection, scope guard, verification,
  repair, telemetry, error ledger e escalada limpa;
- preparar uma maquina de programacao diaria brutalmente competente.

Surface inicial locked: **Atlas AI Desktop Mac**, a aba `Atlas AI` do aplicativo desktop. O id tecnico do payload e `atlas_desktop_ai`; os arquivos de entrada sao `../atlas-desktop/apps/desktop/src/surfaces/atlas-ai/AtlasAiSurface.tsx`, `contract.ts` e `client.ts`; no backend, a entrada passa por `AtlasDesktopAiSurfaceAdapter` e `AtlasDevRuntimeService`.

Fora de escopo nesta fase:

- Rivals;
- benchmark competitivo;
- Opus challenge;
- buscar, criar, curar ou sugerir prompts de teste;
- battery de prompts;
- score custo-normalizado;
- claim de vitoria contra Sonnet/Opus;
- arms ou alteracoes em codigo de arena/medicao.

Qualquer trecho historico deste documento que fale de benchmark/Rivals deve ser
lido apenas como contexto antigo. A instrucao ativa e esta: **criar Atlas Dev,
nao testar Atlas Dev contra modelos**.

## Papel no Atlas

Este modulo organiza a camada diaria de programacao do Atlas Dev. Ele define
como CLI, chat, surfaces, Open Brain, Kernel Pipeline, quality gate, repair e
promocao para Forge devem se encaixar para criar o runtime diario robusto.

## Onde Se Encaixa

O Atlas Dev fica entre provider puro e Atlas Forge. Ele deve ser leve o
suficiente para trabalho cotidiano, mas governado o bastante para preservar
contexto, escopo, evidencia, custo e criterio de escalada.

## Contratos

- Atlas Dev nao e provider com branding.
- Forge continua sendo o modo de governanca alta.
- Atlas Dev e fast lane do Programming Governance System; seus gates sao projecoes compactas, nao concorrentes.
- O core do Atlas Dev e surface-agnostic; Desktop-first e estrategia de entrega vertical, nao acoplamento.
- Benchmark/Rivals nao fazem parte da fase ativa.

## Fluxo

1. Entrada por CLI, chat ou surface.
2. Normalizacao de intencao e contexto.
3. Selecao de escopo e provider/modelo.
4. Execucao controlada com teste focado.
5. Reparo leve ou escalada para Forge.
6. Registro de evidencia para aprendizado operacional.

Entrega vertical locked:

```text
Marco 1: foundation backend, sem UI
Marco 2: plan-only visivel no Atlas AI Desktop
Marco 3: one-call visivel no Atlas AI Desktop
Marco 4: repair visivel no Atlas AI Desktop
Marco 5: paridade CLI/App/API
```

O orchestrator central recebe `OperationEnvelope` e retorna `PlanOnlyResult|PatchResult`. Somente adapters em `AtlasDev/Surface/` conhecem Desktop, CLI, App ou API.

## Regras para IA

- Leia este documento antes de alterar Atlas Dev, Dev Light, provider routing ou
  promocao Dev -> Forge.
- Nao proponha benchmark, prompts de teste, Opus challenge ou Rivals neste
  fluxo.
- Nao altere Rivals como substituto de construir o driver real do Atlas Dev.

## Escopo de Implementacao

Inclui mapeamento de produto, contratos operacionais, surfaces, comandos,
runtime de programacao, quality gates e politicas de escalada. Exclui
benchmarks, prompts de teste e mudancas diretas em Rivals.

## Dependencias

- Atlas Programming Governance System.
- Atlas CLI Dev.
- Atlas Programming Orchestrator.
- Surface adapters.
- Open Brain context injection.
- Dev to Forge promotion policy.

## Evidencias

As evidencias esperadas sao os caminhos em `related_paths`, os testes em
`required_tests`, hashes de contexto, resultados de quality gate, receipts,
work product e registros operacionais.

## Riscos

- Atlas Dev virar wrapper de provider puro.
- Atlas Dev virar Forge caro por padrao.
- A equipe desviar para benchmark/Rivals em vez de construir runtime.
- Promocao Dev -> Forge criar Obra sem governanca suficiente.

## Exemplos

- `atlas:cli:dev` para tarefa cotidiana com escopo pequeno.
- `atlas:ai:chat --dev` para superficie conversacional de programacao.
- Escalada para Forge quando risco, duracao ou evidencia exigirem governanca
  maior.

## Proximas Acoes

- Fechar contrato de execucao diaria do Atlas Dev.
- Implementar `ProviderPromptProjectionContract`.
- Implementar telemetry e error ledger.
- Revisar o design de `atlas_dev_light` somente depois do runtime real.

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

### Contexto 3: Pacote De Leitura Enterprise Para Nao Perder Pecas

Fonte: segundo contexto do usuario sobre docs que outro Codex deveria ler para
estruturar o fluxo completo.

Texto/ideia recebida:

```text
Nao e so a lista anterior. A lista anterior e o mapa principal.
Para estruturar o fluxo sem perder nada, outro Codex precisa ler pelo menos:
Nucleo Obrigatorio + Specs/SDD + Atlas Code/Interface.

Forge, Code Intelligence e Obras sao a camada de profundidade para nao
transformar o fluxo em uma UI bonita sem engenharia real.
```

Frase operacional recebida:

```text
Estruture o fluxo de programacao assistida por IA do Atlas usando Spec OS como
cerebro de especificacao, Programming Governance como trilho de execucao
governada, Atlas Code SCOR-1 como cockpit visual, Forge OS como patamar
multiagente, Code Intelligence como mapa real do codigo, Evidence Ledger como
prova e Obras/Forge Workspace como unidade de producao persistente.
```

Valor estrategico:

- define o pacote minimo de contexto para qualquer IA entender o fluxo sem
  achatar o Atlas em "chat + UI";
- separa espinha dorsal de profundidade enterprise;
- mostra quais documentos devem alimentar Open Brain/context packs quando Atlas
  Dev estiver trabalhando em programacao;
- previne implementacoes bonitas, mas sem spec, evidence, workspace persistente
  ou code intelligence;
- cria uma ontologia clara para a maquina Sonnet: Spec OS pensa, Governance
  governa, Code Intelligence localiza, Evidence prova, Obras persiste, Forge
  escala.

#### Pacote A: Nucleo Obrigatorio

Estes docs sao leitura obrigatoria para qualquer mudanca estrutural no Atlas
Dev ou para qualquer IA que va redesenhar o fluxo:

- `docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md`;
- `docs/engineering-knowledge-base/atlas-programming-governance-system.md`;
- `docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md`;
- `docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md`;
- `docs/engineering-knowledge-base/atlas-forge-operating-system.md`;
- `docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md`;
- `docs/engineering-knowledge-base/atlas-forge-operating-system-runbook.md`.

Papel para Atlas Dev + Sonnet:

- extrair leis e invariantes;
- decidir quando spec leve basta e quando Forge e necessario;
- impedir que Sonnet implemente fora da governanca canonica.

#### Pacote B: Specs/SDD Detalhado

Estes docs definem o cerebro de especificacao:

- `docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md`;
- `docs/engineering-knowledge-base/spec-operating-system/spec-compiler-and-critic.md`;
- `docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md`;
- `docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md`;
- `docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md`;
- `docs/engineering-knowledge-base/spec-operating-system/data-model-and-services.md`;
- `docs/engineering-knowledge-base/spec-operating-system/context-packages-and-projections.md`;
- `docs/engineering-knowledge-base/spec-operating-system/drift-detector-and-learning.md`.

Papel para Atlas Dev + Sonnet:

- transformar prompt humano em mini-spec;
- gerar plano e task contract compacto;
- manter rastreabilidade entre intencao, arquivos, testes e evidence;
- detectar drift quando codigo, doc e spec se afastam.

#### Pacote C: Atlas Code / Interface

Estes docs definem o cockpit e a projecao visual do trabalho:

- `docs/engineering-knowledge-base/atlas-desktop-code-surface.md`;
- `docs/engineering-knowledge-base/atlas-desktop-backend-contract.md`;
- `docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md`;
- `docs/engineering-knowledge-base/atlas-code-scor-1-implementation-contract.md`;
- `docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md`;
- `docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md`;
- `docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md`.

Lacuna verificada:

- `docs/engineering-knowledge-base/atlas-code-work-intake-spec-governance-v1.md`
  foi citado no contexto, mas nao existe neste repo neste caminho.
- O arquivo existente relacionado e
  `docs/engineering-knowledge-base/atlas-code-forge-work-intake-spec-governance-v1.md`.

Papel para Atlas Dev + Sonnet:

- garantir que o modo CLI/runtime tenha artefatos que depois possam aparecer no
  cockpit;
- preservar estados honestos em vez de mock;
- modelar checkpoint/resume, gates, evidence e scope guard como dados reais.

#### Pacote D: Forge / Execucao Avancada

Estes docs sao profundidade para escalada, nao default diario:

- `docs/engineering-knowledge-base/atlas-programming-forge-flow.md`;
- `docs/engineering-knowledge-base/atlas-programming-self-construction-forge-map-v1.md`;
- `docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md`;
- `docs/engineering-knowledge-base/atlas-code-forge-operator-cockpit-v1.md`;
- `docs/engineering-knowledge-base/atlas-code-forge-review-completion-gate-v1.md`;
- `docs/engineering-knowledge-base/atlas-forge-runtime-certification-one-shot.md`;
- `docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md`.

Papel para Atlas Dev + Sonnet:

- definir criterio de escalada;
- evitar recriar multiagente/topology no modo leve;
- reaproveitar padroes de completion/review quando risco justificar.

#### Pacote E: Code Intelligence / Ferramentas

Estes docs impedem que Atlas Dev trabalhe cego:

- `docs/engineering-knowledge-base/code-intelligence.md`;
- `docs/engineering-knowledge-base/code-intelligence/README.md`;
- `docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md`;
- `docs/engineering-knowledge-base/programming-power-tools-catalog.md`;
- `docs/engineering-knowledge-base/tool-runtime/programming-tool-families.md`;
- `docs/engineering-knowledge-base/tool-runtime/evidence-gates.md`.

Papel para Atlas Dev + Sonnet:

- achar arquivos/simbolos certos antes da chamada;
- escolher testes e comandos provaveis;
- gerar evidence verificavel;
- reduzir a vantagem de contexto bruto do Opus.

#### Pacote F: Obras / Workspace Compartilhado

Estes docs definem unidade persistente de producao:

- `docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md`;
- `docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md`.

Papel para Atlas Dev + Sonnet:

- garantir que sessao longa nao dependa de memoria de chat;
- preparar handoff para SCOR/Forge quando a tarefa crescer;
- registrar contexto, decisoes, artifacts e evidence em unidade persistente.

O que muda na tese Atlas Dev + Sonnet:

- a maquina precisa de "context package" governado por camadas, nao RAG solto;
- cada run deve saber qual pacote de docs e necessario pelo risco da tarefa;
- para tarefas simples, carregar apenas Nucleo minimo + Code Intelligence
  compacto;
- para tarefas estruturais, incluir Spec/SDD detalhado;
- para tarefas longas/UI/Obra, incluir Atlas Code/Interface;
- para risco alto, preparar escalada para Forge.

Hipoteses geradas:

- Um Sonnet alimentado com pacote de leitura correto vence Opus puro alimentado
  apenas por prompt humano em tarefas de repo.
- A selecao de pacote por risco melhora qualidade sem explodir contexto.
- Code Intelligence + Spec OS e o combo mais importante para reduzir erro de
  arquivo e erro de escopo.
- Obras/Workspace melhora continuidade em sessoes longas mais do que aumentar
  tamanho de contexto.

Experimentos derivados:

- criar `doc_context_tiers` para Atlas Dev: core, sdd, interface, forge,
  code_intelligence, obras;
- medir run com prompt humano bruto vs run com pacote core+sdd compacto;
- medir se Code Intelligence reduz arquivos tocados fora de escopo;
- medir se context tiers menores preservam custo perto de Sonnet puro;
- testar tarefa longa com resumo persistente de decisoes/arquivos/evidence.

Decisoes ou nao-decisoes:

- decisao: este pacote vira referencia de leitura enterprise da campanha.
- decisao: Atlas Dev nao deve carregar tudo sempre; deve selecionar por risco.
- decisao: `atlas-code-work-intake-spec-governance-v1.md` e lacuna ou nome
  antigo, nao deve ser tratado como arquivo existente.
- nao-decisao: nao transformar essa lista em dependencia obrigatoria para toda
  tarefa simples.

Riscos:

- carregar docs demais e perder a tese de custo;
- carregar docs de menos e construir UI rasa sem engenharia real;
- usar Forge docs como desculpa para deixar Atlas Dev pesado;
- deixar lacunas de arquivo virarem referencias quebradas em prompts;
- confundir unidade persistente de producao com conversa solta.

Backlog candidato:

- implementar `DocContextTierSelector` para Atlas Dev;
- adicionar manifest `atlas_dev_enterprise_reading_pack`;
- criar projection compacta desses docs para Open Brain;
- adicionar checker de paths de docs antes de montar prompt;
- registrar no receipt quais docs foram usados e por qual motivo;
- criar fallback quando doc citado nao existe: related existing path + gap.

### Contexto 4: Opiniao Externa Sobre Atlas Dev Efficient Programming Flow

Fonte: opiniao de outro Codex trazida pelo usuario.

Texto/ideia recebida:

```text
A tese nao e "Sonnet virar Opus por magica".
A tese e Atlas Dev + Sonnet vencer Sonnet puro porque Atlas fornece contexto,
spec, triagem, gates, memoria operacional e feedback.

Atlas Dev + Sonnet pode bater de frente com Opus em tarefas reais onde o
gargalo nao e so inteligencia bruta, mas processo.

Forge perde por custo quando o benchmark ja vem limpo, porque entra com
blindagem demais. Atlas Dev deve ser fast path eficiente; Forge deve ser heavy
path robusto; Rivals deve medir quando cada um vence.

Proximo documento/fluxo:
Atlas Dev Efficient Programming Flow
com fast path, compact SDD, context budget, adaptive gates, escalation to Forge,
human messy benchmark e cost-normalized rivals scoring.
```

Avaliacao desta sessao:

- concordo com a direcao;
- a frase "nao e Sonnet virar Opus por magica" e fundamental para manter rigor;
- o diagnostico sobre Forge e correto: Forge pode parecer pior em benchmark
  limpo porque paga custo de governanca que a tarefa nao exige;
- a peca nova e transformar Atlas Dev em fast path com governanca adaptativa;
- ainda falta transformar a tese em contrato: estados, budgets, gates,
  criterios de escalada e score local antes de Rivals.

Valor estrategico:

- protege a campanha contra hype de modelo;
- posiciona Atlas Dev como maquina de processo, nao como modelo alternativo;
- explica por que Forge nao deve ser julgado como default diario;
- cria uma arquitetura em tres velocidades:
  provider puro, Atlas Dev eficiente, Forge robusto;
- introduz o nome operacional `Atlas Dev Efficient Programming Flow`.

O que muda na tese Atlas Dev + Sonnet:

- a meta primaria imediata e vencer Sonnet puro de forma consistente;
- bater Opus puro e uma consequencia esperada em tarefas reais onde processo
  pesa mais que raciocinio bruto;
- tarefas limpas e bem especificadas nao sao o melhor campo de batalha;
- a suite de avaliacao precisa conter prompts humanos baguncados, nao apenas
  tickets perfeitos;
- custo normalizado importa tanto quanto qualidade.

Fluxos Atlas Dev afetados:

- intake deve distinguir `clean_task` de `messy_human_task`;
- planner deve gerar `compact_sdd`, nao spec longa por default;
- context builder deve respeitar `context_budget`;
- quality gate deve ser adaptativo por risco;
- repair deve ser barato e limitado;
- escalation deve ir para Forge quando o fast path deixa de ser seguro.

Hipoteses geradas:

- Em tarefas humanas baguncadas, Atlas Dev + Sonnet vence Sonnet puro por margem
  clara.
- Em tarefas humanas baguncadas, Atlas Dev + Sonnet empata ou vence Opus puro em
  qualidade/custo quando verificacao e repair contam no score.
- Em tarefas limpas, Opus puro pode vencer ou empatar, e isso nao invalida a
  tese.
- Forge deve vencer em tarefas longas/criticas, mas perder em custo no fast
  path; isso e esperado.

Experimentos derivados:

- criar bateria `human_messy_local` com prompts naturais, incompletos e
  multiinterpretaveis;
- criar bateria `clean_ticket_local` para medir onde Opus direto continua
  forte;
- medir score normalizado por custo e tempo;
- comparar quatro modos locais antes de Rivals: Sonnet puro, Opus puro, Atlas
  Dev + Sonnet, Forge + Sonnet;
- registrar quando Atlas Dev escalaria para Forge e se essa escalada foi
  correta.

Decisoes ou nao-decisoes:

- decisao: a proxima arquitetura a desenhar e
  `Atlas Dev Efficient Programming Flow`.
- decisao: fast path e heavy path devem ter contratos diferentes.
- decisao: benchmark deve incluir tarefa humana baguncada.
- nao-decisao: nao prometer vitoria universal contra Opus.
- nao-decisao: nao mudar Rivals ainda.

Riscos:

- otimizar Atlas Dev para ticket limpo e perder o diferencial real;
- comparar Forge contra provider puro em tarefa pequena e concluir errado;
- criar gates adaptativos tao flexiveis que virem ausencia de governanca;
- normalizar custo de forma injusta e mascarar baixa qualidade;
- declarar vitoria contra Opus sem separar clean vs messy.

Backlog candidato:

- criar doc/section `Atlas Dev Efficient Programming Flow`;
- definir `fast_path_contract`;
- definir `compact_sdd_schema`;
- definir `context_budget_policy`;
- definir `adaptive_gate_policy`;
- definir `messy_human_benchmark`;
- definir `cost_normalized_score`;
- definir `forge_escalation_thresholds`.

## Sintese Dos 5 Agentes Especializados

Documento filho consolidado:

- `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md`.

Os cinco agentes convergiram em uma arquitetura unica:

```text
Surface / CLI / App
-> OperationEnvelope
-> Intake normalizado
-> Workspace + permission preflight
-> Classificacao question|patch|repair|review|frontend|risky
-> Risk level R0-R5
-> DocContextTierSelector
-> CodeDiscoveryManifest
-> Open Brain Programming Projection
-> CompactSDD
-> MiniProgrammingSpec
-> LightTaskContract
-> RoutingDecision
-> ProviderDecision
-> ScopedExecution
-> ScopeGuardReceipt
-> FocusedVerification
-> VerificationReceipt
-> CheapRepair se permitido
-> CompletionState ou Forge promotion preview
```

Decisoes consolidadas:

- Atlas Dev + Sonnet e fast path diario; Forge e heavy path robusto.
- Todo write precisa de mini-spec, task contract, scope guard e receipt.
- Contexto e selecionado por tiers, nao despejado inteiro no prompt.
- Code Intelligence e autoridade operacional para achar arquivos/simbolos.
- Repair e barato, limitado, mesma provider/model, baseado em erro real.
- `passed` so existe com evidence; `needs_review` nao conta como sucesso cheio.
- Risco R4/R5 gera plan-only + promotion preview, nao patch Dev.
- Rivals continua congelado ate prova local.

Artefatos alvo:

- `CompactSDD`;
- `MiniProgrammingSpec`;
- `LightTaskContract`;
- `CodeDiscoveryManifest`;
- `OpenBrainProgrammingProjection`;
- `ScopeGuardReceipt`;
- `VerificationReceipt`;
- `FailureCapsule`;
- `EscalationDecision`;
- `LocalBenchmarkScorecard`.

Decisao atual apos corte de escopo:

- foco exclusivo em criar o Atlas Dev robusto;
- sem Rivals;
- sem Opus challenge;
- sem benchmark competitivo;
- sem battery de prompts;
- sem score custo-normalizado;
- sem oracle privado de avaliacao;
- sem claim de vitoria.

O que permanece no fluxo de construcao:

- `ProviderPromptProjectionContract`;
- `FastPathTelemetrySchema`;
- `FastPathErrorLedger`;
- persistencia local de receipts;
- quality build gates;
- no-rivals-leakage tests;
- prompt/provider projection gerado de contratos fortes, nao de prompt solto.

## Tese Sonnet Contra Opus

### Formula De Vitoria

```text
Atlas Dev + Sonnet > Opus puro
quando:
  ganho_de_contexto
+ ganho_de_escopo
+ ganho_de_execucao
+ ganho_de_teste
+ ganho_de_reparo
+ ganho_de_memoria_local
>
  vantagem_bruta_de_modelo_do_Opus
```

### Onde Sonnet Pode Vencer

- bugfix com teste falhando;
- tarefa com prompt humano incompleto;
- mudanca pequena em repo grande;
- refactor localizado;
- frontend onde screenshot/verificacao visual guia a correcao;
- tarefa que exige achar os arquivos certos;
- tarefa que exige preservar mudancas do usuario;
- tarefa que exige rodar o teste certo, nao todos os testes;
- tarefa que exige corrigir um erro pequeno apos primeira tentativa.

### Onde Opus Pode Continuar Forte

- raciocinio arquitetural sem repo;
- design de sistema abstrato;
- refactor muito amplo sem limite de escopo;
- tarefas com requisitos ambiguidade alta e pouca verificacao;
- escrita de RFC conceitual.

### Como Atlas Deve Compensar

| vantagem do Opus puro | resposta Atlas Dev + Sonnet |
| --- | --- |
| raciocinio bruto melhor | decompor em plano curto e verificavel |
| entende prompt ambivalente | normalizar intencao e perguntar so quando necessario |
| aguenta contexto grande | escolher contexto certo e evitar ruido |
| melhor julgamento em abstrato | usar repo, testes e diffs como grounding |
| menos necessidade de repair | usar repair barato com erro real |
| solucao mais completa | limitar escopo e validar comportamento esperado |

## Criterios De Vitoria Antes De Rivals

Atlas Dev + Sonnet so esta pronto para entrar no Rivals quando passar por uma
bateria local com estes sinais:

- melhora clara sobre Sonnet puro em tarefas praticas;
- empate ou vitoria contra Opus puro em parte relevante das tarefas comuns;
- custo por tarefa perto de Sonnet puro;
- tempo aceitavel para uso diario;
- no maximo 1-2 repairs na maioria dos casos;
- baixa taxa de erro de escopo;
- alta taxa de teste focado correto;
- falhas declaradas honestamente;
- escalada para Forge quando risco e alto;
- logs suficientes para reproduzir comparacao.

### Definicao De "Destruir Opus"

Nesta campanha, "destruir Opus" nao significa vencer todos os tipos de tarefa.
Significa vencer onde importa para o produto diario:

```text
Em repos reais, com prompts humanos normais,
Atlas Dev + Sonnet entrega mais tarefas corretas,
com custo menor ou parecido,
com menos erro de escopo,
com teste/verificacao melhores,
do que Opus puro sem orquestracao.
```

## Estado Atual

### O que ja existe

Atlas Dev ja tem estas capacidades implementadas:

- comando `atlas:cli:dev` para one-shot, plan-only e cockpit interativo;
- delegacao para `atlas:ai:chat --dev --cockpit`;
- `atlas:cli:fix` como alias fino para `atlas:cli:dev --repair`;
- `atlas:cli:continue` para retomar plano anterior;
- workspace auto-detectado pelo git root;
- provider/model manual ou Atlas Decide;
- override de modelo por alias/id;
- modo Fair Claude com provider/model lock e sem fallback/council;
- Open Brain auto/required/off, refresh e budget;
- imagens por arquivo, clipboard e auto-image;
- skill bundles, incluindo `dev-quality-gate`;
- Kernel Pipeline scaffold e guard;
- Programming Orchestrator com agentic RAG, sandbox plan, test impact,
  patch verifier, stage receipts, repair contract e frontend design harness;
- quality gate pos-execucao no chat dev;
- escalada para Engineering Harness quando perfil e `forge` ou quando
  intencao/risco pedem harness;
- promocao Dev -> Forge por preview/candidate/Obra;
- surface adapters para CLI Dev, Desktop AI e App.

### O que ainda nao existe como driver proprio

O `atlas_dev_light` do Rivals esta declarado como arm, mas real-run ainda
bloqueia honestamente com `atlas_dev_light_driver_pending`. Hoje, o caminho
real do Atlas Dev passa por `atlas:cli:dev` -> `atlas:ai:chat` -> provider ou
Engineering Harness. O driver dedicado ainda precisa ser criado.

## Entrypoints

### 1. `atlas:cli:dev`

Arquivo: `app/Console/Commands/AtlasCliDevCommand.php`.

Formas principais:

```bash
php artisan atlas:cli:dev
php artisan atlas:cli:dev "corrija este bug"
php artisan atlas:cli:dev "implemente X" --plan-only --json
php artisan atlas:cli:dev "implemente X" --provider=codex_cli --model=5.5
php artisan atlas:cli:dev "implemente X" --claude-only --plan-only --json
php artisan atlas:cli:dev "implemente X" --forge
php artisan atlas:cli:dev "corrija X" --repair --auto-test
```

Responsabilidades:

- resolver workspace;
- normalizar provider e modelo;
- aplicar Fair Claude quando solicitado;
- montar preflight;
- montar `dev_execution_plan`;
- anexar `kernel_pipeline`;
- gerar preview Open Brain;
- montar comando `atlas:ai:chat`;
- se `--plan-only`, parar antes do provider;
- se `--forge`, executar Engineering Harness diretamente;
- senao, delegar para `atlas:ai:chat`.

### 2. `atlas:ai:chat --dev`

Arquivo: `app/Console/Commands/AiChatCommand.php`.

E o runtime conversacional real usado por Atlas Dev. Ele:

- abre thread nova ou continua thread existente;
- aceita REPL interativo;
- aceita `/fix`;
- aceita `/mode`, `/model`, `/handoff`, `/quality`, `/paste-image`;
- monta payload com workspace, permission, provider/model, images, skills;
- cria ou recebe `dev_execution_plan`;
- cria `programming_message_plan`;
- decide provider/harness;
- executa provider via gateway ou Engineering Harness;
- roda quality gate em modo dev quando aplicavel.

### 3. `atlas:cli:fix`

Arquivo: `app/Console/Commands/AtlasCliFixCommand.php`.

Alias canonico para repair:

```text
atlas:cli:fix ... -> atlas:cli:dev ... --repair --surface-origin=atlas_cli_fix
```

Ele preserva contrato `atlas.cli_fix.contract.v1`, flow `programming.repair`,
runtime `dev_repair_executor` e flags de auto-test/allow-write/plan-only.

### 4. `atlas:cli:continue`

Arquivo: `app/Console/Commands/AtlasCliContinueCommand.php`.

Retoma plano anterior via `AtlasCliSessionService`, reconstrui comando
`atlas:cli:dev` com `--resume=<plan_id>`, preserva provider/model/profile,
Open Brain e flags relevantes. Em `--dry-run`, mostra o comando e o contrato
sem executar.

### 5. API / Desktop / App

Atlas Dev tambem aparece fora do terminal:

- `AtlasDevRuntimeService` aplica runtime em payloads de `/ai/interactions`;
- `AtlasCliDevSurfaceAdapter` declara capacidades do CLI Dev;
- `AtlasDesktopAiSurfaceAdapter` mapeia Desktop AI para flows de programacao;
- `AtlasAppSurfaceAdapter` mapeia App/mobile para `programming.dev`,
  `programming.review` e `programming.repair`;
- `AtlasCodeDevToForgePromotionController` expoe promocao para Forge.

## Fluxo End-to-End Atual

### One-shot normal

```text
Operador
-> atlas:cli:dev "tarefa"
-> workspace()
-> provider/model selection
-> AtlasCliDevWorkflowService::preflight()
-> AtlasProgrammingOrchestrator::sessionPlan(profile=dev)
-> KernelPipelineDevPlanBuilder::attachProgrammingPlan()
-> Open Brain preview
-> atlas:ai:chat --dev --dev-plan=<json>
-> activeDevExecutionPlan()
-> programmingMessagePlan()
-> programmingDispatchContract()
-> provider gateway OU Engineering Harness
-> trace/thread metadata
-> quality gate
```

### Plan-only

```text
atlas:cli:dev "tarefa" --plan-only --json
-> nao chama provider
-> retorna workflow, dev_execution_plan, activated_skills,
   open_brain_preview e chat_command
```

Uso: auditar roteamento, modelo, Open Brain, skills, permission e pipeline
antes de gastar tokens.

### Interativo

```text
atlas:cli:dev
-> atlas:ai:chat --dev --new-thread --cockpit --dev-plan=<json>
-> REPL
-> mensagens sucessivas preservam thread/workspace/status
```

Comandos relevantes dentro do REPL:

- `/fix [texto]`: converte input em repair;
- `/quality`: roda/mostra quality gate;
- `/model`: troca ou mostra modelo;
- `/handoff codex|claude`: troca provider;
- `/paste-image`: anexa imagem;
- `/status`: mostra contexto da sessao.

### Prompted cockpit

Mesmo com tarefa one-shot, o comando usa a mesma rota do cockpit quando nao
esta em `--plan-only` e nao esta em `--forge`:

```text
atlas:cli:dev "tarefa"
-> interactiveChatCommand(... task=tarefa ...)
-> atlas:ai:chat "tarefa" --dev --cockpit
```

Isso evita que o one-shot tenha contrato mais fraco que o interativo.

### Repair

```text
atlas:cli:fix "corrija teste X"
-> atlas:cli:dev "Corrija: ..." --repair
-> programming.repair
-> dev_repair_executor
-> repair_execution_contract
-> repair prompt/capsule quando gate falha
```

O chat tambem detecta repair automaticamente por sinais como `corrija`, `fix`,
`bug`, `erro`, `teste falhando`, `quality gate`.

### Forge profile dentro do comando Dev

```text
atlas:cli:dev "tarefa critica" --forge
-> programming_profile=forge
-> flow=programming.forge
-> executor=engineering_harness
-> Open Brain required
-> auto_test true
-> evidence_required
```

Esse caminho e Forge, nao Dev Light. Deve ser medido separadamente no Rivals.

## Runtime De Payload: AtlasDevRuntimeService

Arquivo: `app/Services/Ai/Programming/AtlasDevRuntimeService.php`.

Aplica em payloads de surfaces Atlas AI quando:

- surface e `atlas_app`, `atlas_desktop_ai`, `atlas_api_interaction` ou
  `atlas_cli_dev`;
- modo normalizado e `programming`;
- workspace existe.

Nao aplica quando:

- surface e `atlas_code` (Atlas Code/Forge tem propria fronteira);
- modo nao e programming;
- surface desconhecida.

Mapeamento de task:

| task | flow |
| --- | --- |
| `dev` | `programming.dev` |
| `plan` | `programming.dev` |
| `direct` | `programming.dev` |
| `review` | `programming.review` |
| `debug` | `programming.repair` |
| `repair` | `programming.repair` |

Artefatos esperados:

```text
plan
diff_or_reason
tests_or_reason
risks
```

Slice emitido:

```text
atlas_dev_runtime.schema_version = atlas.dev_runtime.v1
enabled = true
flow_id = programming.dev|review|repair
mode = programming
workspace = ...
decision_mode = atlas_decide|manual_override
provider = null|manual provider
requires_obra = false
open_brain_policy = auto
```

## Provider E Modelo

### Provedores aceitos

Atlas Dev normaliza aliases para:

| input | provider |
| --- | --- |
| `claude`, `claude-cli` | `claude_cli` |
| `codex`, `codex-cli` | `codex_cli` |
| `gemini`, `gemini-cli` | `gemini_cli` |
| `conselho`, `council`, `ambos`, `claude-codex` | `claude_codex` |

No modo `dev`, `gemini_cli` e bloqueado para execucao write: Gemini e restrito
a analise read-only nesse caminho.

### Decisao automatica

`AtlasCliProviderStrategyService` recomenda provider por modo:

- `dev` / `debug`: default, Codex, Claude;
- `review` / `plan` / `research`: default, Claude, Gemini, Codex;
- `critical`: se Claude e Codex online, recomenda `claude_codex`;
- respeita `allow_auto`, `allow_manual`, health snapshots e budget block;
- inclui performance empirica via `ProviderPerformanceProjection`.

### Override manual

Quando o operador passa `--provider` ou `--model`, Atlas Dev:

- marca `decision_mode=manual_override`;
- cria `model_selection_contract`;
- cria `ai_policy_override`;
- limita fallback/council no provider selecionado;
- valida se modelo combina com provider;
- respeita bloqueio `allow_manual=false`.

### Fair Claude

Flags:

```text
--claude-only
--single-provider
--no-decide
--fallback-disabled
```

Efeito:

- provider lock `claude_cli`;
- model lock `opus`/premium model configurado;
- Atlas Decide desabilitado;
- fallback/council proibidos;
- Codex/Gemini proibidos;
- quality gate precisa `passed`;
- unverified nao conta como pass;
- repair capsule preserva mesmo provider/modelo.

Uso: benchmark justo contra Claude Code puro, nao modo de produto diario.

## Open Brain E Contexto

Atlas Dev injeta ou previewa Open Brain por padrao:

- modo normal: `auto`;
- `--forge`: `required`;
- `--no-open-brain`: desliga;
- `--require-open-brain`: falha fechado se nao houver contexto;
- `--open-brain-refresh`: forca refresh;
- `--open-brain-budget=<chars>`: limita budget.

No plan-only, `open_brain_preview` mostra:

- status;
- surface (`cli_dev` ou `cli_continue`);
- context readiness;
- provider_execution_allowed;
- context_pack_hash;
- audit_id;
- summary compacta;
- warnings e next actions.

Para Atlas Dev Light, esse e um dos maiores diferenciais contra provider puro:
contexto selecionado e auditavel antes da chamada.

## Kernel Pipeline

`KernelPipelineDevPlanBuilder` anexa um scaffold de pipeline a todo plano Dev:

```text
schema_version = atlas.kernel.pipeline.scaffold.v1
surface_id = atlas_cli_dev | atlas_ai_chat | atlas_cli_forge
flow = programming.dev | programming.repair | programming.forge
runtime = dev_repair_executor | engineering_harness | ...
input_mode = one_shot | interactive | chat_dev_auto_plan | declared_dev_plan
provider_execution_allowed = false no scaffold inicial
kernel_pipeline_contract.required = true
```

O guard valida planos declarados por `--dev-plan`. Planos aceitos/rejeitados
sao auditados pelo Kernel Pipeline Audit Service.

## Programming Orchestrator

Arquivo: `app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php`.

O `sessionPlan()` monta o contrato de programacao:

- `plan_id`;
- `programming_profile`: `dev` ou `forge`;
- `programming_flow`;
- `executor_decision`;
- `execution_profile`;
- `policy_profile`;
- `policy_contracts`;
- `agent_behavior_contract`;
- `operational_decision`;
- `agentic_rag_plan`;
- `stage_receipt_plan`;
- `resume_state`;
- `test_impact_plan`;
- `sandbox_plan`;
- `patch_verifier_gate`;
- `learning_candidate_policy`;
- `programming_orchestration_contract`;
- `frontend_design_harness_contract` quando aplicavel;
- `repair_execution_contract`.

Executores:

| executor | quando |
| --- | --- |
| `simple_provider_execution` | fluxo leve sem repair/harness |
| `dev_repair_executor` | Dev completo com repair/quality loop |
| `engineering_harness` | Forge ou intencao/risco que exige harness |

## Quality Gate E Repair

### Quality gate

O `dev-quality-gate` e anexado quando:

- complete mode esta ativo;
- ou max iterations > 1.

Policy:

```text
procedure = plan_validate_execute
required_final_status = passed em complete/fair mode
required_final_status = not_failed em modo leve/single-shot
```

No chat dev, `maybeRunDevQualityGate()` roda depois da interacao quando:

- modo e `dev`;
- nao esta `--json`;
- nao esta `--no-run`;
- nao esta `--no-quality-gate`;
- sem imagem, ou verbose quando ha imagem.

### Repair

Repair e ativado por:

- `--repair`;
- `atlas:cli:fix`;
- `/fix` no REPL;
- sinais no texto;
- gate failed/needs_review.

Contrato:

- max iterations normalizado;
- stop quando passed;
- stop se qualidade piora;
- repair pesado exige evidencia;
- fallback nao e permitido dentro do repair capsule;
- Kernel repair decision e obrigatoria antes de enfileirar repair estrutural.

## Permissoes E Sandbox

Flags principais:

| flag | efeito |
| --- | --- |
| `--permission=read|write|danger` | modo de permissao |
| `--allow-write` | confirma writes no workspace |
| `--operator` | promove para danger local operator |
| `--allow-unsandboxed` | permite write/danger com provider sem sandbox direto |
| `--dangerously-allow-all` | confirma danger-full-access |
| `--sandbox` | override para Forge/Harness |
| `--provider-runtime` | host/docker/auto em Forge/Harness |

Default atual do comando tende a `write` quando permitido pela config. Isso e
conveniente para CLI, mas para Atlas Dev Light em Rivals precisamos separar:

- Dev Light plan-only/read;
- Dev Light patch/write;
- Dev Light harness-escalated.

## Imagens E UI

Atlas Dev aceita:

- `--image=<path>`;
- `--clipboard-image`;
- auto-attach quando prompt menciona screenshot/imagem;
- `--no-auto-image`.

Surface CLI Dev declara `IMAGE_PASTE`. Desktop/App declaram image uploads ou
attachments. Para UI/frontend, o Orchestrator pode anexar
`frontend_design_harness_contract`, exigindo evidencias como screenshots,
visual smoke multi-viewport, no text overlap, a11y/perf or reason.

## Skills

Flags:

```text
--skill=dev-quality-gate
--skill=code-reviewer
--skill=engineering-blueprint
```

Regras atuais:

- `dev-quality-gate` entra automaticamente em complete/multi-iteration;
- `engineering-blueprint` entra quando existe engineering contract/task;
- workspace skills podem ser confiadas/ignoradas no chat;
- skill trace e esperado pelos flows de programacao.

Para Atlas Dev Light, as skills default provavelmente devem ser:

- `dev-quality-gate` sempre que houver patch;
- `code-reviewer` apenas em risco medio ou diff amplo;
- `engineering-blueprint` apenas quando houver task contract.

## Task ID E Engineering Contract

`--task-id` carrega `AtlasTask`, gera:

- `atlas_task`;
- `engineering_contract`;
- `engineering_blueprint`;
- `engineering_blueprint_snapshot`;
- prompt enriquecido com escopo, acceptance, likely files, tests e refs.

Isso permite Atlas Dev operar como camada diaria sobre tasks existentes sem
virar Obra/Forge automaticamente.

## Dev -> Forge Promotion

Arquivos:

- `DevToForgePromotionService`;
- `PromotionSignalDetector`;
- `AtlasCodeDevToForgePromotionController`.

Endpoints:

```text
GET  /atlas-code/dev-to-forge/threads/{thread}/promotion-preview
POST /atlas-code/dev-to-forge/threads/{thread}/promote
GET  /atlas-code/dev-to-forge/candidates
GET  /atlas-code/dev-to-forge/candidates/{candidate}
POST /atlas-code/dev-to-forge/candidates/{candidate}/dismiss
```

Targets:

| target | uso |
| --- | --- |
| `none` | continue em Atlas Dev |
| `quick_intervention` | pequeno, claro, reversivel |
| `obra_candidate` | precisa descoberta/decisao humana |
| `forge_obra` | trabalho pesado/governado |

Sinais usados:

- densidade de mensagens;
- tamanho do contexto;
- quantidade de arquivos/subsistemas;
- arquitetura/spec/refactor;
- risco/producao/migration/security;
- falha recorrente;
- pedido explicito do operador;
- veto `thin_small_bug` para chat curto com <=1 arquivo e sem risco.

Regra: o servico nunca auto-cria Obra sem chamada de promocao. Preview e
read-only; humanos decidem via Attention/operador.

## Casos De Uso

### Pergunta tecnica com workspace

Exemplo: "onde fica a validacao de checkout?"

Fluxo ideal:

```text
programming.dev
read/context only
Open Brain auto
sem patch
resposta com refs
sem quality gate obrigatorio
```

### Bug pequeno

Exemplo: "corrija typo/variavel/condicao simples".

Fluxo ideal:

```text
programming.dev
Atlas Decide ou manual provider
patch pequeno
teste focado ou motivo
quality gate simples
nao promover para Obra
```

### Debug / teste falhando

Exemplo: "o teste X esta falhando".

Fluxo:

```text
programming.repair
failure signal
repair capsule
patch minimo
teste que falhava
max_iterations 3
```

### Review

Exemplo: "revise este diff".

Fluxo:

```text
programming.review
read-only preferencial
sem patch por padrao
findings primeiro
promover se risco/arquitetura crescer
```

### Frontend/UI

Exemplo: "ajuste esse componente pela screenshot".

Fluxo:

```text
programming.dev ou programming.frontend
imagem anexada
frontend_design_harness_contract se detectado
patch
visual smoke / screenshot or reason
no text overlap
```

### Refactor medio

Exemplo: "extraia service e atualize dois callers".

Fluxo:

```text
programming.dev
agentic RAG
plano curto
patch escopado
test impact
quality gate
se multi-subsistema/risk -> obra_candidate ou Forge
```

### Mudanca de banco/migration/security

Fluxo recomendado:

```text
detectar harness signals
se simples: plan-only + pedir confirmacao
se risco medio/alto: Forge/Obra
rollback obrigatorio
```

### Tarefa longa/ambigua

Fluxo:

```text
Atlas Dev para discovery
promotion-preview
obra_candidate
humano refina objective/criteria
Forge quando aprovado
```

### Benchmark Fair Claude

Fluxo:

```text
--claude-only --model=opus --single-provider --no-decide --fallback-disabled
sem Codex/Gemini/council/fallback
quality gate deterministico
unverified != passed
```

Uso: comparar harness+Claude contra Claude Code. Nao e UX diaria.

## Matriz De Escalada

| sinal | Atlas Dev | Dev Light | Forge |
| --- | --- | --- | --- |
| pergunta/codigo read-only | sim | sim | nao |
| bug pequeno 1-2 arquivos | sim | sim | nao |
| teste falhando localizado | sim | sim | talvez se recorrente |
| frontend com screenshot | sim | sim | se visual amplo |
| refactor 3-5 arquivos | sim com cuidado | sim com gates | se arquitetura |
| migration/producao/security | plan/review | escalar cedo | sim |
| multi-provider/council | opcional | nao default | sim |
| evidence/replay/auditoria | parcial | simples | forte |
| tarefa longa/enterprise | nao ideal | nao ideal | sim |

## Produto Futuro: Atlas Dev Sonnet

Antes de existir como arm confiavel no Rivals, Atlas Dev + Sonnet precisa virar
um runner real com contrato proprio. O nome de produto pode ser
`atlas_dev_light`, `atlas_dev_sonnet`, `sonnet_killer_mode` ou outro; o nome
importa menos que o comportamento.

```text
1. Intake
   - normalizar tarefa
   - classificar kind: question | patch | repair | review | frontend | risky
   - detectar risco e criterios de escalada

2. Context
   - workspace summary
   - git status
   - arquivos provaveis
   - Open Brain compacto
   - semantic code graph quando barato

3. Short Plan
   - 3-6 passos max
   - file scope provavel
   - teste focado provavel
   - escalation check

4. Provider Call
   - Sonnet ou Codex
   - prompt curto, provider-safe
   - sem council por padrao
   - low call budget

5. Patch
   - aplicar diff
   - preservar mudancas do usuario
   - bloquear out-of-scope obvio

6. Verification
   - teste focado
   - lint/typecheck quando barato
   - no-test reason aceito para docs/read-only

7. Repair
   - no max 1-2 tentativas por default
   - repair capsule com erro real
   - sem trocar provider sem politica

8. Finish
   - summary
   - files changed
   - tests run
   - risks
   - escalation recommendation
```

### Nao Objetivos

- nao deve ter replay/evidence pesado como Forge;
- nao deve executar topology multi-provider por padrao;
- nao deve virar Obra automaticamente;
- nao deve esconder falha de teste;
- nao deve declarar sucesso sem patch/teste/motivo.

### Escalada automatica para Forge

Escalar ou recomendar Forge quando:

- risco alto (`production`, `security`, `migration`, `billing`, auth);
- camada >= 3 (db + API + UI, por exemplo);
- mais de 5-6 arquivos esperados;
- qualidade falha apos repair;
- contexto necessario excede budget;
- operador pede "forge", "obra", "arquitetura", "RFC";
- precisa evidence/replay/auditoria;
- precisa multi-provider/council.

## Preparacao Futura Para Rivals

Rivals e a prova final. Nao e o primeiro lugar onde vamos desenvolver a
maquina. Esta secao existe para nao perder de vista como a competicao sera
medida depois que Atlas Dev + Sonnet estiver pronto.

### Regra De Entrada No Rivals

Nao criar ou alterar arm de Rivals ate existir:

- driver real do Atlas Dev + Sonnet;
- bateria local reproduzivel;
- resultados locais salvos;
- contrato de output estavel;
- custo e numero de chamadas registrados;
- politica de escalada congelada;
- criterio explicito de comparacao com Opus puro.

### Bracos Que Vamos Querer Comparar Depois

Bracos sugeridos:

```text
claude_sonnet_puro
claude_opus_puro
codex_puro
atlas_dev_light_sonnet
atlas_dev_light_codex
atlas_forge_sonnet
atlas_forge_opus
```

Categorias:

- human-normal;
- messy-real;
- frontend/UI;
- backend/logica;
- bugfix;
- refactor;
- review;
- repair;
- architecture/risk.

Metricas:

- qualidade;
- conclusao sem humano;
- custo;
- tempo;
- numero de chamadas;
- patch size;
- files changed vs expected;
- teste certo rodado;
- erro de escopo;
- repair success;
- escalation accuracy;
- false escalation para Forge;
- missed escalation para Forge.

Tese:

```text
Atlas Dev + Sonnet
≈ custo Sonnet puro
> qualidade Sonnet puro
≈ ou > Opus puro em tarefas praticas
< custo Forge
```

## Decisoes A Tomar

1. Runner real do Atlas Dev + Sonnet: chamar CLI existente ou criar service
   dedicado?
2. Default provider: Sonnet, Codex ou Atlas Decide?
3. Quantas chamadas max por task leve: 1, 2 ou 3?
4. Test policy: sempre teste focado quando patch, ou somente quando detectado?
5. Open Brain budget default: 6k, 12k, 20k chars?
6. Quando acionar `code-reviewer`?
7. Quando bloquear write e pedir Forge?
8. Como registrar custo por chamada no Rivals?
9. Como diferenciar "no patch needed" de falha?
10. Como evitar que Dev Light use Forge indiretamente e contamine benchmark?
11. Qual e a bateria local minima para declarar "pronto para Rivals"?
12. Qual score minimo contra Opus puro justifica ativar arm real?
13. Quais tarefas representam uso diario, e quais sao apenas show-off?
14. Como salvar aprendizado de falhas para melhorar o proximo run?

## Implementacao Recomendada Em Fatias

### Fatia 0: caderno de campanha

- manter este doc atualizado por toda a sessao;
- registrar todo contexto recebido do usuario;
- transformar contexto em hipoteses/backlog;
- nao alterar Rivals.

### Fatia 1: contrato local e dry-run

- definir contrato local do Atlas Dev + Sonnet;
- montar plano sem provider;
- evidenciar context/plan/test policy;
- definir score local antes de benchmark externo.

### Fatia 2: provider real de uma chamada

- Sonnet apenas;
- contexto compacto;
- patch + teste focado;
- sem repair automatico;
- status `passed|needs_review|failed|escalate_forge`.

### Fatia 3: repair leve

- uma tentativa de repair com erro real;
- manter mesmo provider/model;
- registrar failure capsule;
- escalar se falhar.

### Fatia 4: Codex variant

- variante Atlas Dev + Codex;
- mesma pipeline, outro provider;
- comparar por categoria.

### Fatia 5: Decide integration

- escolher Sonnet/Codex por categoria/ranking;
- registrar motivo;
- preservar modo benchmark deterministico.

### Fatia 6: promotion loop

- quando `escalate_forge`, gerar promotion preview;
- nao criar Obra sem confirmacao;
- permitir Attention mostrar candidato.

### Fatia 7: entrada no Rivals

- so depois dos resultados locais;
- criar/ativar arm real;
- congelar contrato;
- rodar contra Sonnet puro e Opus puro;
- registrar custo, tempo, qualidade e falhas.

## Comandos De Auditoria

Plan-only:

```bash
php artisan atlas:cli:dev "implemente getter" --workspace=/path/repo --plan-only --json
```

Repair plan:

```bash
php artisan atlas:cli:fix "teste X falhando" --workspace=/path/repo --plan-only --json
```

Forge plan:

```bash
php artisan atlas:cli:dev "mudanca critica" --forge --plan-only --json
```

Fair Claude:

```bash
php artisan atlas:cli:dev "tarefa" --claude-only --plan-only --json
```

Dev -> Forge preview:

```bash
GET /atlas-code/dev-to-forge/threads/{thread}/promotion-preview?workspace=atlas
```

Rivals arms:

```bash
php artisan atlas:forge:rivals arms --json
```

## Regra Final

Atlas Dev e o modo diario eficiente. Forge e o modo de governanca maxima.
Rivals deve medir os dois como produtos diferentes.

O criterio de sucesso do Atlas Dev Light nao e "fazer tudo que Forge faz mais
barato". E:

```text
resolver muito bem o trabalho comum,
detectar cedo quando nao deve continuar,
e escalar para Forge antes de virar risco.
```
