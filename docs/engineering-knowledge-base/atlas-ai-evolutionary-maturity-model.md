---
id: atlas-ai-evolutionary-maturity-model
type: engineering_knowledge
title: Atlas AI Evolutionary Maturity Model
status: active
category: roadmap
priority: 99
summary: Modelo canonico da linha evolutiva do Atlas, definindo o que o Atlas e hoje, quais patamares deve buscar, como classificar cada nivel e quais metas devem ser batidas para nao confundir wrapper, assistente, sistema operacional, empresa autonoma e engine de acao no mundo.
tags:
  - atlas-ai
  - maturity-model
  - evolutionary-roadmap
  - autonomous-intelligence
  - strategic-north-star
capabilities:
  - atlas_maturity_classification
  - evolutionary_targeting
  - roadmap_alignment
  - architecture_prioritization
  - implementation_guardrails
decisions:
  - Atlas nao e AGI nem ASI; Atlas e um sistema operacional de inteligencia que orquestra modelos, memoria, ferramentas, agentes, contexto, evidencia, execucao e governanca.
  - A evolucao do Atlas deve ser medida por mudanca de natureza operacional, nao por quantidade de features.
  - O estado atual aproximado e Nivel 4.5 a 5; fortes sinais de Nivel 6 existem via Atlas Intelligence Factory OS, mas ainda precisam uso continuo e UI/governanca completos.
  - O proximo grande patamar de produto e Atlas Swarm Company Runtime.
  - A visao final governada e Atlas Autonomous Intelligence Operating System, podendo evoluir para Atlas Civilization Intelligence Engine se houver capacidade real de coordenar ecossistemas.
  - Toda IA que entrar no repo deve usar esta doc para classificar metas grandes antes de propor novo nome, nova camada ou novo runtime.
maintenance:
  - Atualizar quando um nivel for atingido com evidencia verificavel.
  - Nao promover nivel por existencia de doc ou scaffold; exigir runtime, testes, control plane e uso no fluxo padrao.
  - Manter esta doc como bussola de priorizacao antes de criar novas metas grandes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-qualitative-levels-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-conversation-operations-layer.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-evolutionary-maturity-model
graph_title: Atlas AI Evolutionary Maturity Model
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas AI Evolutionary Maturity Model
canonical_name: Atlas AI Evolutionary Maturity Model
technical_name: atlas-ai-evolutionary-maturity-model
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-evolutionary-maturity-model.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolutionary-maturity-model.md
allowed_changes:
  - Atualizar niveis, evidencia, metas e classificacao quando runtime real mudar.
  - Adicionar referencias a docs canonicas novas quando virarem parte da evolucao.
forbidden_changes:
  - Declarar Atlas AGI, ASI ou sistema completo sem evidencia operacional.
  - Tratar LLM wrapper, assistente, OS, empresa autonoma e World Action Engine como equivalentes.
  - Usar este modelo para autorizar execucao externa, auto-trust ou benchmark sem gates proprios.
depends_on:
  - atlas-ai-qualitative-levels-roadmap
  - atlas-autonomous-intelligence-operating-system
flows_to:
  - atlas-ai-canonical-architecture-index
  - atlas-governed-backlog
  - atlas-intelligence-factory-os
unlocks:
  - roadmap_alignment
  - maturity_gate_reviews
  - next_patamar_planning
governs:
  - atlas.maturity_classification
  - atlas.long_term_product_direction
  - atlas.next_major_goal_selection
evidence:
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
evidence_refs:
  - symbol: AtlasAiEvolutionaryMaturityModelService
  - command: atlas:aaeos:ai-evolutionary-maturity-model
  - test: AtlasAiEvolutionaryMaturityModelTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Usar este modelo antes de definir metas macro de 2 meses, 6 meses, 1 ano e 5 anos.
  - Criar evidence pack de maturidade quando algum nivel for promovido.
  - Priorizar Swarm Company Runtime como proximo patamar depois de consolidar ASEIF/APCR/AEMOR no uso real.
ai_entrypoints:
  - Leia Resumo, Contratos, Fluxo, Escopo de Implementacao, Evidencias e Proximas Acoes antes de propor qualquer meta macro.
  - Se a tarefa menciona proximo patamar, linha evolutiva, AGI, ASI, autonomous company ou substituir Claude/Codex, comece por esta doc.
ai_usage_notes:
  - Use a classificacao atual como baseline conservador; nao promova nivel sem evidence pack.
  - Quando implementar uma camada nova, declare qual nivel ela avanca e quais niveis permanecem incompletos.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
---
## Resumo

Este documento define a linha evolutiva canonica do Atlas. Ele existe para
impedir tres erros: achar que Atlas e apenas um wrapper, declarar que Atlas ja
esta pronto por ter muitos modulos, ou confundir Atlas com AGI/ASI.

Atlas e um **AI Operating System**: uma camada operacional que transforma
modelos frontier, modelos locais, ferramentas, memoria, agentes, contexto,
evidencia, execucao e governanca em uma inteligencia persistente e utilizavel.

## Papel no Atlas

Esta doc e a bussola de maturidade. Toda IA que trabalhar no Atlas deve
conseguir responder:

- o que o Atlas e hoje;
- qual patamar esta sendo perseguido;
- o que falta para chegar no proximo nivel;
- quais claims sao proibidas sem evidencia;
- quais modulos sao meios e quais patamares sao fins.

## Onde Se Encaixa

Este modelo fica acima dos roadmaps de dominio. Ele nao substitui Hyperflow,
APCR, AEMOR, ASEIF, Dev, Forge, Finance, Research, Marketing ou Control Plane.
Ele classifica esses sistemas dentro de uma evolucao maior.

Camada conceitual:

```text
Modelos LLM / Tools / Providers
-> Atlas Runtime Layers
-> Atlas Operating System
-> Atlas Autonomous Company
-> Atlas World Action Engine
```

## Contratos

Contratos de linguagem:

- **Wrapper**: UI fina que manda prompt para modelo e recebe resposta.
- **Copilot**: assistente que ajuda, mas depende do contexto humano.
- **Operating System**: sistema que roteia, recupera contexto, governa,
  executa, audita e aprende.
- **Autonomous Company**: sistema que planeja e executa metas longas com
  divisao de trabalho, revisao, memoria e governanca.
- **World Action Engine**: sistema que age em ambientes digitais/fisicos com
  seguranca, observabilidade e responsabilidade.

Contrato de claim:

```text
Atlas nao e AGI.
Atlas nao e ASI.
Atlas pode ser o sistema operacional que orquestra motores cognitivos cada vez
mais fortes, incluindo futuros modelos proximos de AGI/ASI.
```

## Fluxo

A linha evolutiva canonica:

```text
Nivel 0  - LLM Wrapper
Nivel 1  - Atlas Copilot
Nivel 2  - Atlas Router OS
Nivel 3  - Atlas Specialist Runtime
Nivel 4  - Atlas Persistent Intelligence OS
Nivel 5  - Atlas Outcome-Learning OS
Nivel 6  - Atlas Intelligence Factory OS
Nivel 7  - Atlas Swarm Company Runtime
Nivel 8  - Atlas Autonomous Company OS
Nivel 9  - Atlas World Action Engine
Nivel 10 - Atlas Civilization Intelligence Engine
```

Processo evolutivo resumido:

```text
Resposta avulsa
-> contexto correto
-> roteamento correto
-> execucao especializada
-> memoria persistente
-> aprendizado por outcome
-> criacao de capacidades
-> enxame de agentes
-> empresa autonoma
-> acao governada no mundo
-> coordenacao de ecossistema
```

O Atlas evolui quando muda a unidade operacional. Uma feature isolada nao
promove nivel. Exemplos:

- adicionar botao novo nao muda nivel;
- adicionar flow novo sem evidence tambem nao muda nivel;
- conectar flow ao Hyperflow, APCR, AEMOR, Control Plane e testes pode mudar
  maturidade dentro do nivel;
- fazer subagentes trabalharem com handoff, ownership e evidence muda natureza
  operacional e aponta para Nivel 7.

## Regras para IA

1. Nao diga que Atlas e AGI ou ASI.
2. Nao diga que um nivel foi atingido sem runtime, testes, docs, Control Plane e
   evidence refs.
3. Nao trate doc north-star como implementacao.
4. Nao crie novos nomes de patamar se os niveis existentes explicarem o caso.
5. Ao propor uma meta macro, declare qual nivel ela move.
6. Ao implementar uma camada, conecte no fluxo padrao ou declare que esta
   isolada.
7. Benchmark contra rivais so entra quando houver benchmark gate autorizado.

## Escopo de Implementacao

### Classificacao oficial atual

Classificacao conservadora em maio de 2026:

```text
Atlas e um AI Operating System em transicao de Nivel 4/5 para Nivel 6.
Ele nao e ainda uma empresa autonoma completa.
Ele nao e AGI.
Ele nao e ASI.
```

Leitura operacional:

- Como produto geral, Atlas ja passou de wrapper e copilot simples.
- Como sistema de engenharia, Atlas tem Dev, Forge, Hyperflow, APCR, AEMOR,
  ACIE, ACOL, TEOS e ASEIF em diferentes graus de implementacao.
- Como sistema autonomo multi-dominio, Atlas ainda precisa fechar Swarm
  Company Runtime, Company OS, external execution governado e UX operacional.
- Como inteligencia superior ao uso cru de Claude/Codex, Atlas deve vencer por
  memoria, contexto, evidence, handoff, outcome learning, capability factory e
  governanca; nao por fingir que o modelo base ficou mais inteligente.

### Nivel 0 - LLM Wrapper

Estado: nao e objetivo final.

Definicao: interface fina sobre provider. Sem memoria real, sem runtime,
sem auditoria.

Anti-meta: nunca voltar para este modelo como arquitetura principal.

### Nivel 1 - Atlas Copilot

Definicao: assistente inteligente que conversa, organiza, explica e apoia.

Evidencia: conversas, historico, UX basica e capacidade de ajudar sem operar
com autonomia profunda.

### Nivel 2 - Atlas Router OS

Definicao: Atlas entende intencao e escolhe dominio/flow.

Componentes: Hyperflow, Intent Kernel, Domain Router, Flow Router, Decision
Receipt.

Meta: prompt ambiguo deve virar decisao auditavel, nao chute de provider.

### Nivel 3 - Atlas Specialist Runtime

Definicao: cada dominio tem comportamento proprio.

Componentes: Atlas Dev, Forge, Debug, Review, Research, Finance, Marketing,
Conversation, Explain, Automation, Cyber e outros dominios.

Meta: cada flow ter contrato, evidence, receipts, testes e readiness.

### Nivel 4 - Atlas Persistent Intelligence OS

Definicao: Atlas nao nasce zerado.

Componentes: APCR, ACIE, ACOL, TEOS, memory, context packs, continuation,
freshness, handoff e compaction.

Meta: o usuario nao precisa repetir todo contexto em cada sessao.

### Nivel 5 - Atlas Outcome-Learning OS

Definicao: Atlas aprende com execucao real.

Componentes: AEMOR, judgment guard, negative knowledge, outcome attribution,
memory feedback, patch fingerprint, policy proposals.

Meta: erro repetido vira bloqueio ou aprendizado; acerto repetido vira memoria
candidate; nada vira verdade sem evidencia.

### Nivel 6 - Atlas Intelligence Factory OS

Definicao: Atlas cria e certifica capacidades proprias.

Componentes: ASEIF, capability registry, gap detector, build/buy/borrow
decision, sandbox simulation, capability certification, marketplace.

Meta: quando faltar uma ferramenta, workflow ou agente, Atlas detecta,
simula, registra, certifica e melhora a propria operacao.

### Nivel 7 - Atlas Swarm Company Runtime

Definicao: Atlas opera com subagentes e meta-agentes coordenados.

Componentes esperados: agent scheduler, specialist handoff, conversation
operators, context janitors, critic/adjudicator, reviewer agents, memory
curators e task leases.

Meta: dividir trabalho sem sujar contexto central, com recibos e ownership.

Este e o proximo patamar macro. Ele transforma Atlas de sistema com flows
especializados em uma organizacao operacional de agentes.

O que implementar:

- Agent Scheduler: escolhe agentes, momento e contexto.
- Meta-Agent Layer: manutencao da conversa, limpeza, critica e memory curation.
- Handoff Contract: objetivo, ownership, context pack, evidence e retorno.
- Context Isolation: subagente recebe so o necessario e nao polui o centro.
- Work Lease: prazo, escopo e criterio de aceite por agente.
- Adjudicator/Critic: julga perda de contexto, qualidade e contradicoes.
- Control Plane: agentes ativos, bloqueios, handoffs, receipts, custos e acao.

### Nivel 8 - Atlas Autonomous Company OS

Definicao: Atlas conduz metas longas como uma empresa autonoma assistida.

Componentes esperados: mission mode maduro, strategy loop, operations loop,
milestones, work packets, financial/marketing/research/engineering domains e
human approval gates.

Meta: executar metas de semanas/meses com planejamento, revisao, reparo,
aprendizado e entrega.

O que implementar:

- Mission-to-Company Planner: meta vira departamentos, workstreams e packets.
- Company Memory: memoria por projeto, dominio, empresa, cliente e risco.
- Executive Review Gate: revisao executiva antes de decisoes irreversiveis.
- Strategy/Finance/Marketing/Research/Engineering loops integrados.
- Long-horizon Control Plane: estado de meses, nao apenas conversas.
- Outcome Ledger: cada entrega retroalimenta AEMOR e ASEIF.

### Nivel 9 - Atlas World Action Engine

Definicao: Atlas age no mundo digital e, futuramente, fisico.

Componentes esperados: browser/API/terminal/runtime execution governados,
external action mandates, credentials vault, compliance, robotics/IoT opcional.

Meta: fazer coisas reais, nao apenas recomendar, sem romper seguranca.

O que implementar:

- External Action Mandate: mandato, escopo, risco, rollback e approval.
- Credential Boundary: acesso via vault, policy e auditoria.
- Browser/API/Terminal Executor: execucao real com sandbox, receipts e replay.
- Financial/Legal/Safety Gates: dominios sensiveis exigem governanca propria.
- Kill Switch: operador pode interromper execucao externa rapidamente.

### Nivel 10 - Atlas Civilization Intelligence Engine

Definicao: Atlas coordena ecossistemas complexos de longo prazo.

Escopo: empresas, capital, tecnologia, conhecimento, pessoas, operacoes,
pesquisa, infraestrutura e decisao estrategica.

Meta: ainda north-star. Nao implementar como claim unico; implementar por
capabilities verificaveis.

Este nivel nao deve virar meta de sprint. Ele existe para orientar arquitetura
de longo prazo: interoperabilidade, governanca, memoria institucional,
simulacao, capital, operacoes e conhecimento. Cada pedaco precisa nascer como
capability verificavel dentro dos niveis anteriores.

## Dependencias

Dependencias por nivel:

| Nivel | Depende de |
| --- | --- |
| 2 | Hyperflow, Router Runtime, receipts |
| 3 | Specialist flows, Dev/Forge, domain runtimes |
| 4 | APCR, ACIE, ACOL, TEOS, memory |
| 5 | AEMOR, evidence, outcome tracking |
| 6 | ASEIF, simulation, capability registry |
| 7 | agent scheduler, handoff, meta-agents |
| 8 | mission/company runtime, milestones, work packets |
| 9 | governed external execution, policy, approvals |
| 10 | all previous levels plus ecosystem governance |

## Roadmap de Implementacao

Ordem recomendada para nao perder foco:

1. **Fechar Nivel 4 no uso real**: APCR, ACIE, ACOL e TEOS precisam estar no
   fluxo padrao de Dev, Forge, Research e demais flows, nao apenas como docs.
2. **Fechar Nivel 5**: AEMOR precisa registrar outcomes reais e influenciar
   memoria, politica, risco, provider choice e patch strategy.
3. **Fechar Nivel 6**: ASEIF precisa detectar gaps reais, criar/avaliar
   capacidades, certificar e promover capabilities com revisao.
4. **Construir Nivel 7**: subagentes/metagentes como runtime padrao para
   tarefas complexas, com handoff e context isolation.
5. **Construir Nivel 8**: Company OS multi-dominio, com metas longas,
   departamentos logicos, milestones e review executivo.
6. **Construir Nivel 9**: execucao externa governada por mandato, approval,
   sandbox, receipts e rollback.

Regra de priorizacao:

```text
Se uma meta nao melhora contexto, execucao, memoria, aprendizado, capacidade,
handoff, governanca ou UX operacional, ela nao e meta macro.
```

## Evidencias

Classificacao atual:

```text
Atlas atual: Nivel 4.5 a 5, com inicio forte de Nivel 6.
```

Evidencia:

- Hyperflow/Router implementam Nivel 2.
- Dev/Forge/Specialist Flows implementam partes do Nivel 3.
- APCR/ACIE/ACOL/TEOS implementam partes do Nivel 4.
- AEMOR implementa partes do Nivel 5.
- Atlas Intelligence Factory OS/ASEIF implementa runtime local do Nivel 6.

Evidencia minima para promover nivel:

- doc canonica;
- runtime conectado ao fluxo padrao;
- persistencia ou contrato versionado quando houver estado;
- CLI/API/control plane;
- testes focados e regressao impactada;
- readiness/certification;
- evidence refs verificaveis;
- uso real em pelo menos um flow principal.

Nao atingido plenamente:

- Nivel 7 ainda precisa Swarm Company Runtime real.
- Nivel 8 ainda precisa Company OS completo multi-dominio.
- Nivel 9 ainda precisa external execution governado em escala.
- Nivel 10 e visao de longo prazo, nao runtime atual.

## Riscos

| Risco | Mitigacao |
| --- | --- |
| Confundir Atlas com AGI/ASI | Manter contrato: Atlas e OS de inteligencia, nao modelo cognitivo geral |
| Declarar nivel sem evidencia | Exigir tests, docs, Control Plane e receipts |
| Criar nomes demais | Reusar os 10 niveis como vocabulario canonico |
| Focar so engenharia | Expandir dominios sem romper governanca |
| Autoexecucao perigosa | Policy, approval, sandbox, simulation e evidence obrigatorios |
| Benchmark prematuro | Benchmark so com readiness gate proprio |

## Exemplos

Exemplo de classificacao:

```text
Pergunta simples respondida por chat: Nivel 1
Prompt ambiguo roteado para Research: Nivel 2-3
Pedido de codigo com contexto automatico: Nivel 3-4
Patch que aprende com falha anterior: Nivel 5
Atlas cria workflow para YouTube ingestion: Nivel 6
Cinco agentes trabalham em obra longa com handoff: Nivel 7
Atlas conduz projeto de negocio por meses: Nivel 8
Atlas opera APIs, vendas e automacoes reais: Nivel 9
Atlas coordena ecossistema empresarial completo: Nivel 10
```

## Proximas Acoes

Horizonte recomendado:

| Horizonte | Meta |
| --- | --- |
| 2 meses | Consolidar Nivel 4-6 no uso real: APCR, AEMOR, ASEIF, Hyperflow e UX |
| 6 meses | Entregar Nivel 7: Swarm Company Runtime com subagentes e meta-agentes |
| 1 ano | Entregar Nivel 8: Autonomous Company OS multi-dominio |
| 5 anos | Buscar Nivel 9-10 com World Action Engine e ecosystem governance |

Meta operacional agora:

```text
Transformar Atlas Intelligence Factory OS + AEMOR + APCR em base viva do
Atlas Swarm Company Runtime, consolidando Desktop UX, subagentes/metagentes,
execucao externa governada, Company Runtime e capability evolution como partes
do fluxo padrao, sem benchmark/rivals nem claims externos.
```

Implementacoes que devem sair dessa meta:

- Desktop Control Plane mostrando Hyperflow, APCR, AEMOR, ASEIF, Mission,
  handoffs, blockers, capabilities e external action state.
- Swarm Company Runtime com scheduler, meta-agents, handoff e leases.
- External Execution Governance com mandates, approvals, rollback e receipts.
- Capability Usage Loop conectando AEMOR outcomes a ASEIF improvement.
- Readiness/certification unico para provar prontidao do patamar.

Definition of Done para promover qualquer nivel: runtime conectado ao fluxo
padrao, persistencia/contratos quando houver estado, control plane, readiness,
certification, testes focados, regressao impactada, docs-health verde,
evidencias de uso real, riscos e limites declarados.
