---
id: atlas-engineering-blueprint
type: engineering_knowledge
title: Atlas Engineering Blueprint System
status: active
category: architecture
priority: 98
summary: Contrato canonico para transformar intencao de produto em blueprint, fases, task contracts, QA evidence, review gates, Postgres gate e memory delta no Atlas.
tags:
  - atlas
  - engineering
  - blueprint
  - qa
  - review
capabilities:
  - engineering_blueprint
  - project_blueprint_pipeline
  - blueprint_task_contracts_core
  - scenario_inventory
  - blueprint_qa_evidence_core
  - review_gates
  - postgres_gate
decisions:
  - Atlas absorve a disciplina de engenharia do SWE-ATLAS sem depender de Claude Code.
  - Blueprint define o que construir e como provar; Harness Runner executa, mede e registra.
  - Harness Runner e motor executor do fluxo pesado, nao o nome do fluxo inteiro.
  - Docs versionados descrevem o contrato; blueprints, tasks, evidencias e runs vivem no Postgres operacional.
  - Uma task tecnica nao deve ser considerada pronta sem contrato, blueprint atual, evidencias e gates resolvidos.
maintenance:
  - Atualizar quando EngineeringBlueprintService, EngineeringTaskContractService, QA evidence, review gates ou Postgres gate mudarem.
  - Rodar atlas engineering knowledge sync --prune depois de alterar estes docs.
  - Rodar atlas engineering knowledge index-code --prune depois de alterar docs ou codigo core.
related_paths:
  - app/Services/Engineering/EngineeringBlueprintService.php
  - app/Services/Engineering/EngineeringTaskContractService.php
  - app/Services/Engineering/EngineeringBlueprintSnapshotService.php
  - app/Services/Engineering/EngineeringContextPackService.php
  - app/Services/Engineering/EngineeringHarnessRunnerService.php
  - app/Services/Engineering/EngineeringControlRegistryService.php
  - app/Services/Engineering/EngineeringRunScoringService.php
  - app/Services/Engineering/EngineeringReviewFindingService.php
  - app/Http/Controllers/AtlasTaskController.php
  - app/Http/Controllers/EngineeringRunController.php
  - database/migrations/2026_05_01_010000_create_atlas_engineering_evidence_table.php
  - database/migrations/2026_05_01_011000_create_atlas_engineering_blueprints_table.php
  - docs/engineering-knowledge-base/engineering-blueprint-contracts.md
  - docs/engineering-knowledge-base/engineering-blueprint-quality-gates.md
  - docs/engineering-knowledge-base/engineering-blueprint-runbook.md
  - docs/engineering-knowledge-base/engineering-blueprint-maturity-dod.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/archive/source-material/atlas-engineering-blueprint-7-itens-2026-05-01.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-engineering-blueprint

graph_title: Atlas Engineering Blueprint System

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas Engineering Blueprint System
canonical_name: Atlas Engineering Blueprint System
technical_name: atlas-engineering-blueprint
cartography_type: module
canonical_source: docs/engineering-knowledge-base/engineering-blueprint.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/engineering-blueprint.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - architecture

evidence:
  - docs/engineering-knowledge-base/engineering-blueprint.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas Engineering Blueprint System

O Atlas Engineering Blueprint System e a camada que transforma uma intencao de
produto em engenharia executavel, auditavel e verificavel antes de qualquer IA
ou agente escrever codigo.

Ele existe porque o Atlas nao deve depender de conversa, memoria informal ou
prompt improvisado para fazer manutencao tecnica. Toda mudanca relevante deve
ter objetivo, escopo, contrato, evidencias e gates rastreaveis.

## Decisao Executiva

O valor principal observado no SWE-ATLAS nao esta em copiar comandos de
Claude Code. O valor esta na disciplina:

- entender o produto antes de codar;
- quebrar trabalho em fases testaveis;
- dar contrato forte para cada task;
- exigir QA manual quando a experiencia visual importa;
- exigir review profundo quando risco tecnico existe;
- tratar Postgres como gate proprio, nao como detalhe de implementacao;
- registrar evidencias para que outra IA consiga continuar depois.

No Atlas, essa disciplina deve ser implementada como produto nativo:

```text
Product Intent
-> Engineering Blueprint Draft
-> Inventory / Scenarios / Data Model / Data Flow
-> Phase Plan
-> Task Contracts
-> Frozen Blueprint Snapshot
-> Context Pack
-> Engineering Harness Run
-> QA Evidence
-> Review Gates
-> Postgres Gate
-> Atlas-Bench / Outcome
-> Memory Delta
```

## O Produto Final

A versao final do Engineering Blueprint System deve permitir que o operador
abra um projeto, descreva um objetivo tecnico ou de produto e receba:

1. um blueprint tecnico versionado;
2. inventario de telas, fluxos, dados, servicos e riscos;
3. cenarios de uso, estados, excecoes e criterios de aceite;
4. fases de implementacao com tarefas geradas;
5. contrato forte por task;
6. execucao pelo Harness Runner com contexto correto;
7. evidencias de teste, QA, review e Postgres;
8. decisao objetiva de pronto, parcial, bloqueado ou inseguro;
9. aprendizado reutilizavel voltando para a Knowledge Base ou memoria.

O operador deve conseguir fazer isso pelo app e pelo CLI. A API deve expor o
mesmo estado para automacoes e futuras interfaces.

## Fronteiras Entre Camadas

| Camada | Papel | Nao deve fazer |
|---|---|---|
| Atlas AI | Orquestra provider, memoria, contexto e ferramentas | Decidir escopo tecnico sem contrato ou evidencias |
| Engineering Blueprint | Define o que construir, por que, em que ordem e como provar | Executar patch, testes ou benchmark diretamente |
| Task Contract | Transforma uma fase em unidade executavel por agente | Substituir blueprint de projeto ou QA final |
| Engineering Harness Runner | Executa tentativas, aplica patch, roda testes, registra artifacts e calcula score | Inventar produto fora do contrato |
| Super Tool Runtime | Registra ferramentas, politicas, runs, artifacts, findings e evidence gates | Ser usado como planejador de produto |
| Code Intelligence | Liga docs ao codigo real, simbolos, rotas, comandos, migrations e testes | Substituir leitura critica do codigo |
| Atlas-Bench | Mede estrategias, providers, regressao e maturidade do Harness | Ser criterio unico de pronto para uma task real |
| Memory Core | Preserva decisoes, feedback, recall e contexto multi-provider | Guardar segredo ou log bruto sem politica |

Para a hierarquia completa de programacao pesada, leia
`atlas-programming-forge-flow.md`. Engineering Blueprint e Engineering Harness
Runner sao camadas de contrato tecnico e execucao; eles nao substituem
Programming Domain, `programming.forge`, Forge OS, Forge Workspace, Agentic RAG,
Tool Runtime, Repair Loop, Evidence ou Cartography.

## Estado Atual No Codigo

O Atlas ja possui uma base profissional forte no nivel de task e runner:

| Capacidade | Estado atual | Implementacao principal | Lacuna para produto final |
|---|---|---|---|
| Task contract | Quase implementado | `EngineeringTaskContractService`, `atlas dev --task-id` | Enforcement global de contrato minimo antes de resolver task |
| Blueprint por task | Implementado base | `EngineeringBlueprintService` | Blueprint project-level com PRD, inventory, data flow e task generation |
| Snapshot congelado | Implementado | `EngineeringBlueprintSnapshotService`, `atlas_engineering_blueprints` | Staleness mais visivel no app e bloqueio quando snapshot fica obsoleto |
| Evidencia de engenharia | Implementado base | `AtlasTaskController::engineeringEvidence`, `atlas_engineering_evidence` | Workflow `atlas qa` e UI rica para screenshots, console, network e passos |
| Harness Runner | Implementado forte | `EngineeringHarnessRunnerService` | UX unificada no app para task run, artifacts, review e evidence |
| Review findings | Implementado parcial | `EngineeringReviewFindingService`, `EngineeringRunScoringService` | `confidence`, `category`, `atlas review --deep` e threshold formal |
| Database review | Parcial/fraco | Gate heuristico `database_review`, migration pretend control | `PostgresEngineeringReviewService`, `atlas db review`, `atlas db explain` |
| App operacional | Parcial | `/projects` e `/engineering` | Fluxo unico Blueprint -> Run -> Review -> Evidence -> Promote |
| Atlas-Bench | Implementado | Benchmark services, suites, results, trends | Promocao mais direta de runs reais para cases e outcomes |
| Knowledge/Code refs | Implementado | Knowledge Base e Code Intelligence | Garantir refs do Blueprint em todo context pack relevante |

Conclusao: o backend ja e forte como task-level harness runner. O que falta para
a versao final e transformar os 7 itens em pipeline formal de projeto e em
comandos/telas explicitos de QA, review profundo e Postgres review.

## Principios De Implementacao

1. **Contrato antes de codigo**: nenhuma execucao autonoma deve comecar sem
   objetivo, escopo, criterios de aceite, arquivos provaveis e DoD.
2. **Freeze antes de escala**: blueprint usado por agente deve ser snapshot
   congelado, com hash, versao e status.
3. **Evidencia antes de conclusao**: teste passado, screenshot, review ou
   database check precisam virar registro persistido, nao frase no chat.
4. **Gates antes de resolved**: P0/P1, QA obrigatorio e Postgres gate bloqueiam
   conclusao.
5. **Contexto compacto**: context pack carrega refs canonicas e code refs, nao
   todos os documentos inteiros.
6. **Provider substituivel**: Claude, Codex, GPT ou outro motor devem consumir
   o mesmo contrato.
7. **Operacao local-first**: usar ferramentas gratuitas e locais quando possivel;
   servicos pagos entram apenas como provider opcional.
8. **Memoria auditavel**: feedback ratificado vira knowledge/memory delta; log
   bruto e segredo nao viram conhecimento.

## Comandos E API

### Existente

```bash
atlas dev --task-id=<task-id>
atlas engineering run --task-id=<task-id> --workspace=<repo> --sandbox=worktree --auto-test
atlas engineering replay --run-id=<run-id>
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering quality-scan --workspace=<repo>
atlas engineering visual-smoke --url=<url>
```

Rotas existentes relevantes:

```text
GET  /tasks/{task}/engineering
POST /tasks/{task}/engineering/blueprint/freeze
POST /tasks/{task}/engineering/evidence
GET  /tasks/{task}/engineering/runs
POST /tasks/{task}/engineering/runs
GET  /engineering/runs/{run}
GET  /engineering/runs/{run}/review-findings
POST /engineering/runs/{run}/review-findings
```

### Alvo Do Produto Final

```bash
atlas project blueprint prepare --project-id=<project-id>
atlas project blueprint create --project-id=<project-id>
atlas project blueprint freeze --project-id=<project-id> --blueprint-version=<version>
atlas project tasks generate --project-id=<project-id> --from-blueprint=<version>
atlas task blueprint show --task-id=<task-id>
atlas task blueprint freeze --task-id=<task-id>
atlas qa --task-id=<task-id>
atlas review --deep --task-id=<task-id>
atlas db review --task-id=<task-id>
atlas db explain --task-id=<task-id>
```

Esses comandos alvo devem ser wrappers profissionais sobre services e rotas, nao
scripts soltos. Cada comando precisa ter JSON estavel, exit codes e testes.

## O Que Cada IA Deve Fazer Ao Trabalhar Nesta Area

Ao receber tarefa sobre planejamento, task contract, QA, review ou Postgres gate:

1. ler este documento;
2. ler `engineering-blueprint-contracts.md`;
3. ler `engineering-blueprint-quality-gates.md`;
4. ler `engineering-blueprint-runbook.md`;
5. consultar Code Intelligence para services, rotas, comandos e testes atuais;
6. editar codigo apenas depois de mapear a lacuna contra o estado atual;
7. atualizar docs e rodar sync/index quando a decisao mudar.

## Anti-Padroes

- Copiar `.claude/commands` como fonte operacional.
- Considerar markdown solto como estado de execucao.
- Rodar provider sem task contract.
- Marcar task como resolvida com base em frase do agente.
- Tratar ausencia de teste como sucesso.
- Fazer QA visual sem screenshot, console/network ou justificativa.
- Tocar migration sem Postgres gate quando a mudanca altera schema, indice,
  constraint, backfill ou query relevante.
- Jogar docs inteiros no prompt quando refs compactas bastam.
- Misturar run artifacts, logs longos ou segredos dentro dos docs canonicos.

## Definition Of Done Global

O Engineering Blueprint System so esta completo quando um projeto tecnico puder
sair de uma intencao vaga e chegar em tarefas executadas pelo Harness com:

- blueprint project-level congelado;
- task contracts derivados do blueprint;
- context pack com docs, codigo, memoria e tool evidence refs;
- execucao reproducivel pelo CLI/API/app;
- QA evidence persistida;
- review profundo com severity, confidence e category;
- Postgres gate deterministico quando aplicavel;
- score e decisao de run explicaveis;
- Atlas-Bench capaz de promover casos relevantes;
- memory delta registrado sem vazar segredo;
- app mostrando o mesmo estado que API e CLI.

## Resumo

Contrato canonico para transformar intencao de produto em blueprint, fases, task contracts, QA evidence, review gates, Postgres gate e memory delta no Atlas.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
