---
id: atlas-ai-evolution-lineage-and-target-state
type: engineering_knowledge
title: Atlas AI Evolution Lineage And Target State
status: active
category: roadmap
priority: 100
summary: Documento canonico que define a linha evolutiva completa do Atlas AI, sua classificacao atual, os patamares futuros, a ordem de implementacao, as metas que devem ser batidas e as evidencias necessarias para qualquer IA entender para onde o Atlas deve evoluir.
tags:
  - atlas-ai
  - evolution
  - target-state
  - maturity-model
  - implementation-goal
capabilities:
  - evolutionary_lineage
  - target_state_definition
  - maturity_classification
  - evolution_ai_orientation
decisions:
  - Atlas nao deve ser descrito como AGI ou ASI; Atlas e um sistema operacional de inteligencia que orquestra modelos, memoria, contexto, agentes, ferramentas, evidence, aprendizado e execucao governada.
  - A linha evolutiva oficial deve medir mudanca de natureza operacional, nao quantidade de features ou nomes novos.
  - O estado conservador atual e Atlas Persistent/Outcome-Learning Intelligence OS em consolidacao, com inicio real de Intelligence Factory OS.
- O proximo salto macro deve consolidar contexto persistente, outcome learning, capability factory e control plane antes de escalar Swarm Company Runtime.
- UX nesta linha evolutiva significa superficie operacional auditavel, nao polimento visual. Beleza de interface nao promove patamar.
  - O alvo de medio prazo e Atlas Swarm Company Runtime e Atlas Autonomous Company OS; o alvo de longo prazo e Atlas World Action Engine governado.
  - Nenhum patamar pode ser declarado completo sem runtime conectado ao fluxo padrao, tests, docs-health, control plane, receipts e evidence refs.
maintenance:
  - Atualizar quando um patamar for promovido com evidencia real.
  - Sincronizar com atlas-ai-evolutionary-maturity-model.md e atlas-ai-evolutionary-target-and-implementation-goal.md.
  - Manter este documento como entrada canonica para IAs que precisam entender a direcao macro do Atlas.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolutionary-maturity-model.md
  - docs/engineering-knowledge-base/atlas-ai-evolutionary-target-and-implementation-goal.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-conversation-operations-layer.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-aemor-judgment-learning-guard.md
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
  - docs/engineering-knowledge-base/atlas-strategic-reality-engine.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-product-certification.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-evolution-lineage-and-target-state
graph_title: Atlas AI Evolution Lineage And Target State
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-evolutionary-maturity-model
graph_status: active
graph_source: repo
human_name: Atlas AI Evolution Lineage And Target State
canonical_name: Atlas AI Evolution Lineage And Target State
technical_name: atlas-ai-evolution-lineage-and-target-state
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
allowed_changes:
  - Atualizar classificacao atual, metas por horizonte, evidencias e ordem de implementacao quando o runtime real mudar.
  - Adicionar novas referencias canonicas quando um novo patamar virar parte da linha evolutiva.
forbidden_changes:
  - Declarar Atlas AGI, ASI, completo, autonomo absoluto ou superior a rivais sem evidence pack, gates e autorizacao especifica.
  - Promover scaffold, doc ou comando isolado a produto final.
  - Autorizar benchmark/rivals ou execucao externa fora dos gates proprios.
depends_on:
  - atlas-ai-evolutionary-maturity-model
  - atlas-autonomous-intelligence-operating-system
flows_to:
  - atlas-ai-canonical-architecture-index
  - atlas-ai-governed-backlog
  - atlas-autonomous-software-company-runtime
unlocks:
  - ai_macro_goal_alignment
  - next_patamar_implementation
  - maturity_evidence_review
governs:
  - atlas.evolutionary_lineage
  - atlas.target_state
  - atlas.next_major_goal
evidence:
  - docs/engineering-knowledge-base/atlas-ai-evolutionary-maturity-model.md
  - docs/engineering-knowledge-base/atlas-ai-evolutionary-target-and-implementation-goal.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
ai_entrypoints:
  - Leia Resumo, Fluxo, Escopo de Implementacao, Evidencias e Proximas Acoes antes de propor uma meta macro.
  - Se a tarefa pergunta o que e Atlas, onde estamos, para onde vamos, AGI/ASI, linha evolutiva ou produto final, comece por este documento.
ai_usage_notes:
  - Classifique cada proposta pelo patamar que ela move.
  - Declare explicitamente se algo e implementado, parcial, planejado ou north-star.
  - Nao crie camada nova se APCR, ACIE, ACOL, TEOS, AEMOR, ASEIF, Swarm ou Company OS ja explicam o problema.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Confundir documentacao com runtime.
  - Criar nomes novos para capacidades ja canonicas.
  - Pular contexto persistente/outcome learning e tentar ir direto para autonomia externa.
  - Declarar maturidade por entusiasmo, sem evidence refs.
observability_signals:
  - atlas:ai:product-certify --json
  - atlas:ai:control-plane runtime --json
  - atlas:persistent-context:certify --json
  - atlas:aemor:certify --json
  - atlas:intelligence-factory:certify --json
next_actions:
  - Consolidar APCR, ACIE, ACOL, TEOS, AEMOR e Intelligence Factory OS no fluxo padrao.
  - Implementar Swarm Company Runtime com subagentes, metagentes, handoff, leases e context isolation.
  - Expandir para Autonomous Company OS multi-dominio e depois World Action Engine governado.
---
## Resumo

Este documento define a linha evolutiva completa do Atlas AI. Ele existe para
que qualquer IA nova entenda rapidamente:

- o que o Atlas e;
- o que o Atlas nao e;
- em qual patamar o Atlas esta;
- qual patamar deve buscar;
- o que implementar primeiro;
- qual meta precisa ser batida;
- qual evidencia prova avanco real.

Atlas nao e apenas um app, chat, wrapper ou interface sobre Claude/Codex. Atlas
e um **AI Operating System**: uma camada que organiza modelos, memoria,
contexto, ferramentas, agentes, dominios, evidence, outcomes, governance e
execucao para produzir uma inteligencia operacional persistente.

## Papel no Atlas

O papel deste documento e ser a bussola macro. Ele deve impedir tres erros:

1. tratar o Atlas como wrapper de LLM;
2. declarar maturidade por existir documentacao ou scaffold;
3. perder a linha evolutiva ao criar nomes novos sem integrar no fluxo real.

Esta doc governa metas de 2 meses, 6 meses, 1 ano e 5 anos. Docs especificas
governam implementacao local. Esta doc governa direcao.

## Onde Se Encaixa

Hierarquia:

```text
Atlas AI Canonical Architecture Index
-> Atlas AI Evolutionary Maturity Model
-> Atlas AI Evolution Lineage And Target State
-> Atlas AI Evolutionary Target And Implementation Goal
-> APCR / ACIE / ACOL / TEOS / AEMOR / ASEIF / Swarm / Company OS
-> Control Plane / readiness / certification / UX operacional
```

Esta doc nao substitui:

- `atlas-ai-evolutionary-maturity-model.md`, que define os niveis;
- `atlas-ai-evolutionary-target-and-implementation-goal.md`, que traduz o alvo
  em briefing operacional;
- `atlas-canonical-glossary-and-naming.md`, que governa nomes como Atlas Dev,
  Atlas Forge, Obra, Mission, WorkOrder, Domain, Flow e Runtime;
- docs de runtime, que definem contratos de implementacao.

Ela une essas partes em um mapa unico.

## Contratos

### Identidade

```text
Atlas = AI Operating System.
Atlas nao = AGI.
Atlas nao = ASI.
Atlas pode orquestrar modelos cada vez mais fortes, inclusive futuros modelos
proximos de AGI/ASI, mas o Atlas em si e a camada operacional governada.
```

### Unidade de evolucao

Um patamar so muda quando a unidade operacional muda:

| Mudanca | Conta como patamar? |
| --- | --- |
| Novo botao | Nao |
| Novo prompt | Nao |
| Nova doc | Nao |
| Novo flow sem caller | Nao |
| Runtime usado no fluxo padrao com evidence | Sim |
| Memoria que altera decisoes futuras com receipts | Sim |
| Subagentes coordenados com handoff e leases | Sim |
| Execucao externa com mandato, approval e rollback | Sim |

### Claim policy

Proibido declarar sem evidence pack:

```text
Atlas e AGI.
Atlas e ASI.
Atlas esta completo.
Atlas substitui Claude/Codex em todos os cenarios.
Atlas e superior por benchmark externo.
Atlas pode executar no mundo sem aprovacao.
```

Permitido declarar com evidencia local:

```text
Atlas possui runtime interno para roteamento, contexto, memoria, outcomes,
capabilities, company workflow ou control plane, quando tests e gates provam.
```

## Fluxo

Linha evolutiva canonica:

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

Evolucao funcional:

```text
responder
-> entender intencao
-> rotear dominio/flow
-> executar especialista
-> recuperar contexto automaticamente
-> preservar continuidade
-> registrar outcome
-> aprender com acertos/erros
-> criar capacidades novas
-> coordenar agentes
-> operar como empresa
-> agir no mundo com governanca
-> coordenar ecossistemas complexos
```

## Regras para IA

1. Sempre classifique uma nova meta pelo nivel que ela move.
2. Se algo existe apenas como doc, declare como doc ou planejamento.
3. Se algo existe como codigo mas nao tem caller, declare como parcial.
4. Se algo nao aparece no Control Plane, nao trate como operacionalmente claro.
5. Se algo nao gera evidence, nao trate como claim forte.
6. Nao use benchmark/rivals como atalho antes de readiness.
7. Nao crie nova camada para problema ja coberto por APCR, ACIE, ACOL, TEOS,
   AEMOR, ASEIF, Swarm ou Company OS.
8. Nao aumente autonomia externa sem mandato, policy, sandbox, approval,
   receipts, rollback e kill switch.
9. Subagentes precisam de context isolation, handoff, ownership e retorno com
   evidence.
10. Aprendizado automatico precisa passar por AEMOR/Judgment Guard.

## Escopo de Implementacao

### Estado atual conservador

```text
Atlas atual = Nivel 4/5 em consolidacao, com inicio operacional de Nivel 6.
```

Leitura:

- Nivel 2: existe via Hyperflow, Intent Kernel, Router e Decision Receipts.
- Nivel 3: existe parcialmente via specialist flows, Atlas Dev e Atlas Forge.
- Nivel 4: existe parcialmente via APCR, ACIE, ACOL, TEOS, context packs,
  continuation e compaction.
- Nivel 5: existe parcialmente via AEMOR, outcome memory e judgment guard.
- Nivel 6: existe parcialmente via Atlas Intelligence Factory OS / ASEIF.
- Nivel 7: ainda precisa virar runtime padrao de subagentes/metagentes.
- Nivel 8: ainda precisa Company OS multi-dominio completo.
- Nivel 9: ainda precisa external execution governado em escala.
- Nivel 10: e north-star, nao meta de sprint.

### Meta macro ativa

Nesta fase, a meta nao e criar outro nome. A meta e fechar cinco lacunas do
produto real: Control Plane/Desktop operacional, subagentes/metagentes como
runtime padrao, execucao externa governada, empresa autonoma completa e
capabilities usadas/melhoradas por outcome. Benchmark/rivals ficam fora desta
fase. Nao ha foco em UX bonita; o foco e o operador conseguir ver estado,
blockers, approvals, evidence, outcomes e proximas acoes.

Tabela operacional dos niveis:

| Nivel | Nome | Unidade operacional | Meta |
| --- | --- | --- | --- |
| 0 | LLM Wrapper | prompt para provider | nunca ser arquitetura principal |
| 1 | Atlas Copilot | assistente util | apoiar o usuario sem autonomia profunda |
| 2 | Router OS | intencao -> dominio/flow | prompt ambiguo vira decisao auditavel |
| 3 | Specialist Runtime | flows por dominio | Dev, Forge, Research, Finance e outros com contratos |
| 4 | Persistent Intelligence OS | contexto persistente | eliminar sessoes zeradas via APCR/ACIE/ACOL/TEOS |
| 5 | Outcome-Learning OS | aprendizado por resultado | AEMOR altera decisoes futuras com evidence |
| 6 | Intelligence Factory OS | capacidades criadas/certificadas | ASEIF detecta gaps e melhora a operacao |
| 7 | Swarm Company Runtime | subagentes/metagentes | trabalho dividido com handoff, leases e context isolation |
| 8 | Autonomous Company OS | metas longas multi-dominio | operar como empresa assistida com milestones |
| 9 | World Action Engine | acao externa governada | executar APIs/browser/terminal com mandate e rollback |
| 10 | Civilization Intelligence Engine | ecossistemas | coordenacao de longo prazo; north-star, nao sprint goal |

Detalhe por familia de nivel:

- Niveis 0-1: conversa e assistencia.
- Niveis 2-3: roteamento e especializacao.
- Niveis 4-6: contexto persistente, outcomes e capacidade de evoluir.
- Niveis 7-8: agentes coordenados e company runtime.
- Niveis 9-10: execucao externa e coordenacao de ecossistemas.

## Dependencias

| Nivel | Dependencias principais |
| --- | --- |
| 2 | Hyperflow, Router Runtime, Decision Receipts |
| 3 | Specialist Flows, Domain Runtimes, Atlas Dev/Forge |
| 4 | APCR, ACIE, ACOL, TEOS, memory, context packs |
| 5 | AEMOR, evidence, outcome tracking, judgment guard |
| 6 | ASEIF, capability registry, simulation, certification |
| 7 | agent scheduler, handoff, leases, context isolation |
| 8 | mission/company runtime, milestones, work packets |
| 9 | external execution governance, policy, approval, rollback |
| 10 | all previous levels plus ecosystem governance |

Sequencia obrigatoria:

```text
contexto correto
-> execucao correta
-> outcome correto
-> aprendizado correto
-> capability correta
-> agentes corretos
-> empresa correta
-> acao externa correta
```

Pular etapas gera autonomia fragil.

## Evidencias

Evidencia minima para promover qualquer patamar:

- doc canonica ativa;
- runtime conectado ao fluxo padrao;
- contrato/schema versionado quando houver estado;
- persistencia ou read model quando houver estado duravel;
- CLI/API/surface consumidora;
- Control Plane exibindo estado;
- receipts/hashes/evidence refs;
- tests focados;
- regressao dos caminhos impactados;
- docs-health verde;
- readiness/certification;
- limitacoes declaradas.

Sinais que nao bastam:

```text
doc existe;
classe existe;
migration existe;
comando isolado existe;
teste narrow existe;
demo manual funcionou uma vez;
provider respondeu bem;
```

Promocao so vale quando o fluxo padrao usa a capacidade.

## Riscos

| Risco | Mitigacao |
| --- | --- |
| Confundir Atlas com AGI/ASI | Manter identidade como AI Operating System |
| Criar nomes demais | Reusar niveis e docs canonicas |
| Scaffold virar claim | Exigir caller, tests, Control Plane e evidence |
| Focar so programacao | Tratar engenharia como dominio, nao Atlas inteiro |
| Subagentes poluirem contexto | Context isolation, handoff e leases |
| Aprendizado falso | AEMOR Judgment Guard e human override learning |
| Capability perigosa virar default | ASEIF certification e usage/outcome loop |
| Execucao externa perigosa | Mandate, approval, sandbox, rollback e kill switch |
| Superficie operacional ficar cega | Desktop Control Plane deve mostrar estado, evidence e blockers |
| Benchmark prematuro | Benchmark so depois de readiness e autorizacao |

## Exemplos

Classificacao de exemplos:

```text
Pergunta simples respondida por chat
-> Nivel 1

Prompt ambiguo roteado para Research ou Finance
-> Nivel 2/3

Pedido de codigo com contexto automatico e retrieval correto
-> Nivel 3/4

Patch que considera falhas anteriores e negative knowledge
-> Nivel 5

Atlas cria/certifica capability para YouTube multi-idioma
-> Nivel 6

Agentes paralelos pesquisam, implementam, revisam e consolidam
-> Nivel 7

Atlas conduz projeto de negocio por meses com departamentos logicos
-> Nivel 8

Atlas executa APIs, vendas, campanhas ou automacoes reais com rollback
-> Nivel 9

Atlas coordena portfolio de empresas, conhecimento, capital e operacoes
-> Nivel 10
```

Exemplo central do problema que o Atlas deve eliminar:

```text
Problema:
Toda sessao de Claude/Codex nasce zerada. O usuario esquece contexto,
passa contexto incompleto, cansa, e o agente entende errado.

Resposta Atlas:
APCR recupera contexto persistente.
ACIE seleciona o contexto certo.
ACOL protege a conversa e opera handoffs.
TEOS preserva continuidade temporal.
AEMOR usa outcomes anteriores.
ASEIF melhora capacidades ausentes.
Swarm divide trabalho sem sujar contexto.
Control Plane mostra estado, blockers e evidence.
```

## Proximas Acoes

Ordem de implementacao macro:

1. Fechar Nivel 4 no uso real: APCR, ACIE, ACOL e TEOS precisam aparecer no
   fluxo padrao de Atlas AI, Dev, Forge e demais dominios relevantes.
2. Fechar Nivel 5: AEMOR precisa registrar outcomes reais e influenciar novas
   execucoes, memoria, provider choice, risco e estrategia.
3. Fechar Nivel 6: Intelligence Factory precisa detectar gaps, usar
   capabilities, registrar outcome e propor melhoria.
4. Fechar Desktop Control Plane: cockpit operacional deve mostrar contexto,
   outcomes, capabilities, agents, blockers, approvals e external execution.
5. Construir Nivel 7: Swarm Company Runtime com subagentes, metagentes,
   handoffs, leases, critic/adjudicator e context isolation.
6. Construir Nivel 8: Autonomous Company OS multi-dominio, com metas longas,
   departamentos logicos, milestones e review executivo.
7. Construir Nivel 9: World Action Engine governado por mandato, approval,
   sandbox, receipts, rollback e kill switch.

Horizonte:

| Horizonte | Meta |
| --- | --- |
| Agora | Consolidar APCR, ACIE, ACOL, TEOS, AEMOR e ASEIF no fluxo padrao |
| 2 meses | Nivel 4-6 robusto, auditavel e visivel no Desktop Control Plane |
| 6 meses | Nivel 7 com Swarm Company Runtime operacional |
| 1 ano | Nivel 8 com Autonomous Company OS multi-dominio |
| 5 anos | Nivel 9/10 progressivo com World Action Engine e ecosystem governance |

Meta que deve ser batida:

```text
Atlas deve deixar de depender de contexto manual e sessoes zeradas.
Ele deve entrar em cada tarefa com contexto persistente, memoria de outcomes,
capabilities certificadas, agentes governados, control plane auditavel e
evidencia verificavel de cada decisao relevante.
```

Definition of Done para o proximo patamar:

- Atlas AI/Dev/Forge usam contexto persistente por padrao.
- ACIE monta context pack correto e audita perda de informacao.
- ACOL preserva conversa, handoff, contexto e limpeza operacional.
- TEOS preserva continuidade temporal em tarefas longas.
- AEMOR registra outcomes e altera novas execucoes sem falso aprendizado.
- Intelligence Factory registra uso real de capabilities e cria melhorias.
- Control Plane mostra APCR, AEMOR, ASEIF, agents, blockers e approvals.
- Subagentes/metagentes usam handoff, lease, context pack e receipts.
- Execucao externa permanece governada por mandato, approval e rollback.
- Product certification cobre os claims internos relevantes.
- Tests impactados, docs-health e builds necessarios passam.
- Nenhum benchmark/rivals ou claim externo e executado sem gate separado.
