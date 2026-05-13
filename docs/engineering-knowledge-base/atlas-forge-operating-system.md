---
id: atlas-forge-operating-system
type: engineering_knowledge
title: Atlas Forge Operating System
status: future
category: programming-forge
priority: 100
summary: Contrato canonico do patamar acima do Programming Governance System: a fabrica operacional que transforma specs em trabalho multiagente, evidence, integracao e evolucao de software.
tags:
  - atlas
  - forge
  - programming
  - multi-agent
  - software-factory
capabilities:
  - forge_operating_system
  - programming_factory
  - multi_agent_execution
  - work_packet_orchestration
  - integration_queue
  - forge_evidence_loop
decisions:
  - Atlas Forge Operating System e o patamar acima do Programming Governance System.
  - Programming Governance System define as regras; Forge OS opera a fabrica que executa essas regras em escala.
  - Forge OS deve transformar uma spec-mae em packets seguros, agentes coordenados, evidence normalizada, integracao e learning.
  - Nenhum provider recebe autoridade direta sobre o Atlas; providers recebem packets assinados, contexto limitado e evidence obligations.
  - Forge OS usa Obras Shared Workspace como escritorio persistente e Forge Workspace como sua especializacao de programacao.
maintenance:
  - Atualize este documento quando Forge Workspace, Self-Construction OS, Agent Control Plane, multi-provider orchestration, Programming Governance ou Evidence Ledger mudarem.
  - Leia junto de Atlas Programming Governance System, Obras Shared Workspace, Self-Construction OS e Programming Domain antes de implementar Forge.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
  - docs/engineering-knowledge-base/self-construction/work-splitter-contract.md
  - docs/engineering-knowledge-base/self-construction/scope-validator-contract.md
  - docs/engineering-knowledge-base/self-construction/assignment-and-claim-contract.md
  - docs/engineering-knowledge-base/self-construction/packet-evidence-report-contract.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/engineering-blueprint.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-epistemic-operating-system.md
  - docs/engineering-knowledge-base/atlas-sovereign-operating-system.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-forge-operating-system

graph_title: Atlas Forge Operating System

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-programming-governance-system

graph_status: future

graph_source: repo

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-programming-governance-system
  - obras-shared-workspace-and-forge
  - atlas-ai-self-construction-os
  - atlas-ai-spec-operating-system

flows_to:
  - atlas-ai-self-construction-os
  - atlas-cartographic-knowledge-os
  - atlas-code

unlocks:
  - ai-software-factory
  - multi-agent-programming
  - autonomous-implementation-at-scale

governs:
  - programming.forge
  - forge-workspace
  - multi-provider-programming
  - integration-queue

evidence:
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:engineering:knowledge sync --prune --json"
  - "php artisan atlas:engineering:knowledge index-code --prune --json --summary-only"

requires_evidence: true

risk_level: high

visual_tags:
  - forge
  - programming
  - multi-agent
  - software-factory

ai_entrypoints:
  - Leia Resumo, Contratos, Fluxo, Regras para IA e Escopo de Implementacao antes de planejar Forge.

ai_usage_notes:
  - Forge OS deve ser tratado como fabrica operacional, nao como prompt grande.
  - Se o trabalho nao exige multiagente, longa duracao, escopo pesado ou integracao complexa, use Programming Governance sem acionar Forge completo.

quality_gates:
  - mother-spec-approved
  - work-packets-scoped
  - reservations-valid
  - evidence-normalized
  - integration-queue-clean
  - release-gate-green

failure_modes:
  - Agentes paralelos editam o mesmo arquivo sem reservation.
  - Provider recebe contexto solto em vez de packet governado.
  - Integration queue aceita artefato sem evidence.
  - Forge vira uma colecao de prompts sem estado persistente.
  - Human/IA nao consegue ver no mapa o que cada agente fez.

observability_signals:
  - work packets criados
  - claims ativos
  - scope collisions detectados
  - evidence reports normalizados
  - integration queue auditavel
  - release gate com status

next_actions:
  - Implementar Forge OS como camada operacional sobre Programming Governance, Obras Shared Workspace e Self-Construction OS.
---
# Atlas Forge Operating System

## Resumo

Atlas Forge Operating System e a fabrica operacional de programacao do Atlas.
Ele e o patamar acima do Programming Governance System porque nao apenas define
regras: ele pega uma spec-mae, divide em packets, distribui para agentes,
controla escopo, coleta evidence, integra artefatos, roda gates, publica
cartografia e devolve aprendizado.

Se Programming Governance System e a constituicao da programacao segura, Forge
OS e a fabrica que aplica essa constituicao em escala.

## Papel no Atlas

Forge OS existe para permitir que o Atlas seja desenvolvido por IA de forma
pesada, longa, paralela e auditavel. Ele deve suportar:

- uma IA trabalhando sozinha com contrato forte;
- varias IAs trabalhando em paralelo;
- providers diferentes com especialidades diferentes;
- agentes locais validando, indexando ou testando;
- integracao controlada;
- evidence unificada;
- learning pos-execucao;
- cartografia visual do trabalho.

Forge OS nao e "mais um chat". Ele e um sistema operacional de producao de
software.

## Onde Se Encaixa

```text
Sovereign OS
-> Epistemic OS
-> Programming Governance System
-> Forge Operating System
-> Forge Workspace / Obras Shared Workspace
-> Work Packets / Agents / Tools
-> Integration Queue
-> Evidence / Learning / Cartography
```

Forge OS depende de Programming Governance System. Sem governanca, Forge vira
paralelismo perigoso. Com governanca, Forge vira uma fabrica de software com
estado, contratos e prova.

## Contratos

### Contrato 1: Mother Spec

Todo trabalho Forge comeca com uma spec-mae. Ela define:

- objetivo maior;
- motivacao;
- escopo;
- fora de escopo;
- arquitetura afetada;
- docs canonicos;
- Code Intelligence context;
- riscos;
- gates;
- estrategia de divisao;
- criterio de conclusao global.

### Contrato 2: Work Splitter

Forge OS deve dividir a spec-mae em packets menores. Cada packet precisa ter:

- id;
- titulo;
- objetivo;
- owner/agente sugerido;
- arquivos permitidos;
- arquivos proibidos;
- dependencias;
- entradas;
- saidas;
- comandos de validacao;
- evidence requerido;
- risco;
- rollback;
- criterio de aceite.

### Contrato 3: Agent Assignment

Agentes nao recebem poder generico. Eles recebem assignments:

- Codex pode implementar e integrar quando autorizado;
- Claude pode planejar, revisar, criticar ou propor;
- Gemini pode pesquisar, explorar, validar contexto ou comparar;
- agentes locais podem testar, indexar, lintar, observar ou checar;
- futuros providers entram por adapter, nao por excecao.

O assignment e limitado pelo packet, nao pela capacidade maxima do provider.

### Contrato 4: Workspace State

Forge OS precisa de estado persistente:

- spec-mae;
- packets;
- claims;
- reservations;
- artifact bus;
- scope/collision map;
- decisions;
- evidence reports;
- integration queue;
- release gate;
- learning proposals;
- cartography updates.

Esse estado vive no Forge Workspace, que e especializacao de programacao do
Obras Shared Workspace.

### Contrato 5: Scope And Collision Control

Antes de executar packets paralelos, Forge OS deve saber:

- quais arquivos cada agente pode tocar;
- quais simbolos sao compartilhados;
- quais migrations, rotas ou comandos podem colidir;
- quais docs precisam ser editados por apenas um owner;
- quais packets dependem de outros.

Se houver colisao, Forge deve serializar, redividir ou exigir integrador.

### Contrato 6: Evidence Normalization

Cada agente pode produzir evidence diferente, mas Forge OS deve normalizar:

- comandos;
- saidas relevantes;
- testes;
- diffs;
- logs;
- screenshots quando necessario;
- receipts;
- riscos residuais;
- rollback;
- arquivos alterados.

Integration queue nao deve aceitar artefato sem evidence minima.

### Contrato 7: Integration Queue

Forge OS integra por fila, nao por mistura caotica. Cada entrada da fila deve
ter:

- packet id;
- artefatos;
- diff esperado;
- evidence;
- conflitos conhecidos;
- status de review;
- status de testes;
- decisao de merge/aplicacao;
- rollback.

### Contrato 8: Release Gate

Nada sai de Forge como concluido sem release gate:

- todos os packets fechados ou explicitamente adiados;
- evidence normalizada;
- tests/gates proporcionais ao risco;
- docs atualizadas;
- Code Intelligence atualizado quando aplicavel;
- cartografia atualizada quando aplicavel;
- learning registrado;
- risco residual claro.

## Fluxo

Fluxo operacional completo:

```text
1. Intake
2. Sovereign/Epistemic preflight quando necessario
3. Feature Placement
4. Mother Spec
5. Code Intelligence Context
6. Work Splitter
7. Packet Contracts
8. Scope/Collision Map
9. Agent Assignment
10. Execution
11. Evidence Collection
12. Review
13. Integration Queue
14. Quality Gates
15. Release Gate
16. Learning Extraction
17. Docs + Code Intelligence + Cartography Update
```

Forge pode compactar o fluxo para tarefas simples, mas nao pode inverter a
ordem: spec e contrato vem antes da execucao; evidence vem antes da conclusao.

## Regras para IA

- Nao trate Forge OS como prompt longo.
- Nao distribua trabalho sem spec-mae.
- Nao crie packet sem arquivos permitidos/proibidos.
- Nao permita agente paralelo sem reservation ou scope map.
- Nao aceite resultado sem evidence.
- Nao integre diff sem review proporcional ao risco.
- Nao declare release sem atualizar docs, Code Intelligence e cartografia
  quando eles forem afetados.
- Nao deixe provider decidir escopo proprio.
- Nao use Forge para tarefa pequena se Programming Governance simples resolve.
- Nao use Forge para mudar proposito, autonomia ou prioridade sem Sovereign OS.

## Escopo de Implementacao

Para considerar Forge OS concluido, o Atlas precisa ter estes modulos:

| Modulo | Responsabilidade |
|---|---|
| Forge Intake | Receber tarefa e classificar se precisa de Forge |
| Mother Spec Compiler | Criar spec-mae governada por SDD |
| Code Intelligence Loader | Montar contexto real de codigo/docs/testes |
| Work Splitter | Dividir spec em packets seguros |
| Packet Contract Engine | Gerar allowed/forbidden files, evidence, tests e rollback |
| Agent Assignment Engine | Escolher agente/provider por packet |
| Workspace State Manager | Persistir spec, packets, claims, artifacts e status |
| Scope Collision Engine | Detectar e resolver colisao de arquivos/simbolos |
| Artifact Bus | Receber outputs estruturados de agentes |
| Evidence Normalizer | Padronizar provas entre providers |
| Review Orchestrator | Rodar revisao tecnica, security, QA e arquitetura |
| Integration Queue | Ordenar aplicacao/merge dos packets |
| Quality Gate Runner | Executar gates proporcionais ao risco |
| Release Gate | Fechar trabalho apenas com evidence suficiente |
| Rollback Manager | Definir contencao e reversao segura |
| Learning Extractor | Promover melhorias para docs, tests, prompts e gates |
| Cartography Publisher | Publicar mapa visual do trabalho e das engrenagens |
| Cost/Telemetry Monitor | Registrar custo, duracao, provider e eficiencia |

## Dependencias

Dependencias canonicas:

- Atlas Programming Governance System;
- Programming Domain;
- Spec Operating System;
- Obras Shared Workspace;
- Self-Construction OS;
- Agent Control Plane;
- Multi-Provider Agent Orchestration;
- Code Intelligence;
- Evidence Ledger;
- Cartographic Knowledge OS;
- Sovereign OS;
- Epistemic OS.

## Evidencias

Evidence minimo para uma execucao Forge:

- mother spec;
- lista de packets;
- packet contracts;
- claims/reservations;
- scope/collision map;
- agent assignments;
- artifacts;
- diffs;
- comandos e resultados;
- review;
- integration queue status;
- release gate;
- learning proposals;
- cartography update quando aplicavel.

## Riscos

- Paralelismo sem ownership virar conflito de arquivos.
- Provider externo receber contexto demais ou autoridade demais.
- Forge operar acima da governanca e virar atalho perigoso.
- Integration queue aceitar output incompleto.
- Evidence nao ser normalizada e impedir auditoria.
- Cartografia nao mostrar o estado real dos packets.
- Learning gerar novas tarefas sem passar pelos mesmos gates.
- Custo e tempo crescerem sem telemetry.

## Exemplos

Exemplo de uso correto:

```text
Objetivo: implementar Programming Governance System no runtime.
Forge: cria mother spec, divide em packets para CLI gate, API gate, docs,
Code Intelligence, tests e Cartography, controla escopo, integra por fila e
fecha com release gate.
```

Exemplo de uso incorreto:

```text
Objetivo: mudar nome de uma label.
Forge completo: desnecessario. Use Programming Governance compacto com diff e
evidence simples.
```

Exemplo multi-provider:

```text
Codex implementa packet A.
Claude revisa packet A e planeja packet B.
Gemini explora risco de arquitetura.
Agente local roda tests/index.
Forge OS integra tudo pela fila, nao por conversa solta.
```

## Proximas Acoes

1. Criar Forge Intake para decidir quando uma tarefa precisa de Forge.
2. Implementar Mother Spec Compiler sobre Spec Operating System.
3. Implementar Packet Contract Engine com allowed/forbidden files.
4. Ligar Code Intelligence ao contexto de packets.
5. Persistir Forge Workspace com claims, artifacts, scope map e queue.
6. Implementar Evidence Normalizer multiprovider.
7. Criar Release Gate e Cartography Publisher para Forge.
