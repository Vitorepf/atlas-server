---
id: atlas-agentic-engineering-os-contracts
type: engineering_knowledge
title: Atlas Agentic Engineering OS Contracts
status: active
category: atlas-ai
priority: 102
summary: Contratos detalhados de departamentos, entidades, gates, surfaces, maturidade, benchmarks externos e criterios de conclusao do Atlas Agentic Engineering OS.
tags:
  - atlas-ai
  - agentic-engineering
  - engineering-contracts
  - departments
capabilities:
  - agentic_engineering_departments
  - engineering_gates
  - engineering_data_model
  - engineering_maturity_ladder
decisions:
  - Este doc detalha os contratos operacionais do Atlas Agentic Engineering OS.
  - A doc-mae `atlas-agentic-engineering-os.md` define nome, autoridade e fronteira; este doc define departamentos, gates e DoD operacional.
  - Departamento sem entrada, saida, evidence e gate e apenas prompt solto.
maintenance:
  - Atualize junto com `atlas-agentic-engineering-os.md` quando departamento, gate, entidade ou maturity ladder mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agentic-engineering-os-contracts
graph_title: Atlas Agentic Engineering OS Contracts
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas Agentic Engineering OS Contracts
canonical_name: Atlas Agentic Engineering OS Contracts
technical_name: atlas-agentic-engineering-os-contracts
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-agentic-engineering-os-contracts.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-contracts.md
allowed_changes:
  - Refinar departamentos, gates, entidades, surfaces e maturity ladder.
forbidden_changes:
  - Alterar o nome da area ou autoridade-mae; isso pertence a `atlas-agentic-engineering-os.md`.
depends_on:
  - atlas-agentic-engineering-os
flows_to:
  - atlas-autonomous-software-company-runtime
  - atlas-programming-governance-system
  - atlas-forge-continuum-os
unlocks:
  - engineering-department-contracts
governs:
  - atlas_ai.agentic_engineering_os.contracts
evidence:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-contracts.md
evidence_refs:
  - symbol: AtlasAgenticEngineeringOsContractsService
  - command: atlas:aaeos:agentic-engineering-os-contracts
  - test: AtlasAgenticEngineeringOsContractsTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
quality_gates:
  - departments-have-contracts
  - gates-cover-delivery
  - maturity-ladder-clear
failure_modes:
  - Departamento vira prompt solto.
  - Gate de delivery nao cobre seguranca, QA, release ou docs.
observability_signals:
  - department_contract_count
  - gate_count
next_actions:
  - Criar schemas executaveis quando os departamentos virarem tabelas/services.
line_limit: 520
---
# Atlas Agentic Engineering OS Contracts

## Resumo

Este documento detalha os contratos operacionais do Atlas Agentic Engineering
OS. A doc-mae define o nome e a fronteira; este doc preserva a parte extensa:
departamentos, gates, entidades, maturidade e criterios de pronto.

## Papel no Atlas

Funciona como o manual contratual da organizacao de engenharia agentica.

## Onde Se Encaixa

```text
Atlas Agentic Engineering OS
-> Contracts
   -> Departments
   -> Surfaces
   -> Data Model
   -> Gates
   -> Maturity
   -> Completion
```

## Contratos

Cada departamento precisa declarar entrada, saida, owner doc, allowed actions,
forbidden actions, required evidence, gates, tools permitidas, escalonamento
para humano, escalonamento para Forge e criterio de pronto.

Departamento sem contrato e apenas prompt solto.

## Fluxo

```text
Human ambiguous intent
-> Executive Intake
-> Product clarification
-> Architecture
-> Programming Governance
-> Context / Code Intelligence
-> Dev or Forge execution
-> QA / Review / Security
-> Release / Delivery
-> Evidence / Certification
-> Docs / Cartography
-> Learning
```

## Regras para IA

- Nao crie departamento novo sem contrato.
- Nao declare delivery sem verification, evidence e certification.
- Nao reduza Atlas Agentic Engineering OS a UI, IDE, chat ou provider.
- Nao copie concorrente externo como arquitetura canonica.

## Escopo de Implementacao

### Executive Intake

Recebe pedido humano ambiguo e transforma em objetivo operacional. Deve extrair
intencao, negocio, prazo, risco, restricoes, contexto faltante e criterio de
sucesso. Saida: `engineering_goal`.

### Product And Requirements Office

Converte intencao em requisitos, fluxos, casos extremos, acceptance criteria e
tradeoffs. Saida: spec ou delta spec.

### Architecture Office

Decide boundaries, ownership, data model, integrations, runtime, API, security
model, migration, observability e impacto em dominios existentes. Saida:
design/plan com arquivos provaveis, riscos e rollback.

### Programming Governance

Aplica placement, spec-before-code, Code Intelligence, task contracts, evidence,
learning e cartografia. Doc dono: `atlas-programming-governance-system.md`.

### Context And Code Intelligence

Monta o world model real do repo: docs, services, controllers, routes, commands,
tests, migrations, schemas, owners, simbolos e riscos. Saida: context pack ou
manifest de descoberta com freshness.

### Agent Runtime Kernel

Executa agentes e ferramentas com permissoes, sandbox, logs, tool calls,
checkpoints, resumes, provider adapters e cancelamento seguro.

### Atlas Dev

Fluxo eficiente para trabalho leve e medio: bug fix, refactor pequeno, patch
focado, debug, review pequeno, mini-spec e evidence proporcional.

### Atlas Forge

Fluxo pesado para Obras longas, enterprise, multiagente e multiprovider. Usa
packets, reservations, integration queue, provider topology, review, repair,
release gate, evidence normalization e cartografia.

### QA And Verification Office

Seleciona testes por impacto, cria testes quando faltam, roda suites, valida
UI com Playwright quando aplicavel, valida contratos e registra blockers.

### Security And Compliance Office

Cuida de auth, secrets, permissoes, supply chain, threat model, privacidade,
policies, compliance, logs sensiveis e alteracoes destrutivas. Pode bloquear
release.

### Platform / Infra / SRE Office

Opera infraestrutura, ambientes, deploy, migrations, rollback, observabilidade,
SLOs, alertas, incidentes e capacidade.

### Review And Quality Office

Faz revisao independente de diff, design, teste, regressao, legibilidade,
performance, seguranca, maintainability e aderencia ao canon.

### Release And Delivery Office

Monta delivery pack: resumo, diff, testes, riscos, migrations, rollback,
observability, docs, evidence, release status e next action.

### Evidence And Certification Runtime

Transforma claims em prova. Cada entrega precisa de receipts, comandos,
artefatos, hashes, logs, testes, blockers ou decisoes humanas.

### Documentation And Cartography Office

Atualiza docs canonicos, KB, Code Intelligence e cartografia. Esta camada e
critica porque as proximas IAs dependem dela.

### Learning And Compounding Office

Analisa outcome, custo, falhas, repairs, review findings, provider performance,
lacunas de spec e oportunidades de melhorar prompts, gates, docs, tests e
runtime.

## Dependencias

- `atlas-agentic-engineering-os.md`;
- `atlas-autonomous-software-company-runtime.md`;
- `atlas-autonomous-engineering-operating-system.md`;
- `atlas-programming-governance-system.md`;
- `atlas-forge-continuum-os.md`;
- `atlas-evidence-certification-runtime.md`;
- `atlas-cartographic-knowledge-os.md`.

## Evidencias

Evidence minima por entrega enterprise:

- goal id;
- spec/design/task hashes;
- provider decision receipt;
- context/code intelligence receipt;
- execution receipts;
- test results ou blocker;
- review/security findings;
- release/rollback decision;
- docs/cartography decision;
- certification.

## Riscos

- IA chama o Atlas de ferramenta de programacao assistida e otimiza so UI/chat.
- IA copia uma ferramenta externa e perde a arquitetura propria.
- Atlas implementa codigo mas nao opera qualidade, seguranca, release,
  incidentes e documentacao.
- Gestor humano precisa virar gerente tecnico detalhado.
- Forge gasta mais que provider puro sem compensar com processo.

## Exemplos

Pedido humano:

```text
melhora o billing, esta confuso e dando erro em alguns clientes
```

Fluxo esperado: intake, clarificacao, discovery, spec, architecture, Dev ou
Forge, tests, security, release plan, evidence, docs, learning.

Pedido humano:

```text
crie a nova area de automacao interna
```

Fluxo esperado: product requirements, domain/architecture placement, risk gate,
spec, plan, task contracts, implementation, QA, release, cartography.

## Surfaces

| Surface | Papel |
|---|---|
| Atlas Code | Cockpit de engenharia longa, Obras, SDD, evidence e terminal |
| Atlas AI conversa | Entrada natural, triagem, perguntas e pequenas execucoes |
| Atlas Dev | Fast lane de programacao eficiente |
| Atlas Forge | Operacao pesada e multiagente |
| Cartografia | Leitura visual do sistema e zoom semantico |
| CLI/API | Automacao, gates, sync, testes e execution control |

Surface nao decide autoridade. Surface projeta runtime e evidence.

## Data Model Conceitual

Entidades esperadas: `EngineeringGoal`, `EngineeringIntent`, `ProductSpec`,
`ArchitectureDecision`, `WorkItem`, `TaskContract`, `WorkPacket`, `AgentRun`,
`ProviderDecisionReceipt`, `ToolCall`, `Checkpoint`, `EvidencePack`, `GateRun`,
`ReviewFinding`, `SecurityFinding`, `TestResult`, `ReleasePack`,
`IncidentCapsule`, `LearningProposal`, `CartographyNode`, `Certification`.

## Gates Universais

1. intent classified;
2. owner docs loaded;
3. duplicate check;
4. placement decided;
5. context/code intelligence fresh enough;
6. spec/design proportional to risk;
7. task contract with allowed scope;
8. execution receipts;
9. test or blocker;
10. review or explicit risk acceptance;
11. security check when relevant;
12. release/rollback plan when relevant;
13. evidence pack;
14. docs/cartography impact decision;
15. certification.

## Autonomy Ladder

| Nivel | Autonomia | Humano |
|---|---|---|
| L0 Assist | Sugere e explica | Faz tudo |
| L1 Patch | Altera pequeno escopo | Aprova e valida |
| L2 Flow | Executa fluxo Dev com gates | Decide risco |
| L3 WorkItem | Entrega tarefa completa | Supervisiona |
| L4 Obra | Conduz trabalho longo no Forge | Aprova milestones |
| L5 Department | Opera departamentos de engenharia | Define prioridade |
| L6 Company | Opera area tech completa | Atua como gestor |
| L7 Self-Evolving | Melhora o proprio sistema com safety | Governa soberania |

## Completion Criteria

O OS so pode ser considerado completo quando pedido ambiguo vira goal, spec,
plano, execucao, tests, review, seguranca, release, docs, cartografia,
learning e certification sem depender de memoria de chat.

## Proximas Acoes

- Criar schemas executaveis quando os departamentos virarem tabelas/services.
- Criar scorecard Rivals para medir Atlas Dev/Forge contra provider puro.
- Expor gates principais no Atlas Code e na Cartografia.
