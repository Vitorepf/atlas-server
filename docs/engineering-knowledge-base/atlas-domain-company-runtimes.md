---
id: atlas-domain-company-runtimes
type: engineering_knowledge
title: Atlas Domain Company Runtimes
status: active
category: atlas-ai
priority: 100
summary: Contrato para criar empresas digitais autonomas por dominio dentro do Atlas AI / Autonomous Intelligence OS, mantendo Kernel, WorkOrder, Policy, Tool Runtime, Evidence, Control Plane e Certification compartilhados.
tags:
  - atlas-ai
  - domain-runtimes
  - company-runtime
  - orchestration
capabilities:
  - domain_routing
  - company_runtime_contracts
  - department_model
  - company_cross_domain_handoff
  - domain_maturity_model
  - enterprise_domain_operating_model
decisions:
  - Cada dominio complexo deve operar como company runtime com departamentos, contratos, gates, artifacts, metrics e delivery.
  - Software Company e apenas o primeiro dominio; Cyber, Finance, Marketing, Strategy, Research, Learning, Operations, Automation e Tool Factory seguem o mesmo padrao.
  - Novo dominio deve ser manifest + runtime adapter + flow profiles + gates especificos; nao pode criar Kernel, Policy Engine, Tool Runtime, Evidence Ledger ou UI shell paralelos.
maintenance:
  - Atualize este doc antes de adicionar runtime de dominio.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-multi-domain-implementation-sequence.md
  - docs/engineering-knowledge-base/domains/domain-routing-governance.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-domain-company-runtimes
graph_title: Atlas Domain Company Runtimes
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Domain Company Runtimes
canonical_name: Atlas Domain Company Runtimes
technical_name: atlas-domain-company-runtimes
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
allowed_changes:
  - Adicionar dominios com contratos completos.
forbidden_changes:
  - Criar dominio sem DoD, evidence, safety e certification.
depends_on:
  - atlas-objective-intelligence
flows_to:
  - atlas-tool-economy
  - atlas-autonomous-control-plane
unlocks:
  - multi-domain-autonomous-execution
governs:
  - atlas_ai.domain_company_runtimes
evidence:
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Dominios Oficiais e Como Criar Novo Runtime antes de implementar.
quality_gates:
  - domain-selected
  - departments-defined
  - gates-defined
  - delivery-defined
failure_modes:
  - Dominio vira prompt solto.
  - Handoff entre dominios perde contexto.
observability_signals:
  - domain_runtime_id
  - department_count
  - handoff_count
next_actions:
  - Implementar registry de dominios.
line_limit: 520
---
# Atlas Domain Company Runtimes

## Resumo

Domain Company Runtimes define como o Atlas cria "empresas autonomas" por
dominio. Cada dominio tem departamentos, contratos, gates, artifacts, metrics,
delivery, memoria e certificacao.

Este e o modo correto de escalar o Atlas AI para alem de programacao. Atlas AI
nao deve virar colecao de agentes soltos; deve virar sistema operacional com
empresas digitais plugaveis.

## Papel no Atlas

O Autonomous Intelligence OS usa esses runtimes para resolver metas fora de
software puro, como pesquisa, marketing, vendas, automacao e dados.

Para decidir **qual** dominio entra, quando criar flow/profile e quando criar
dominio novo, use `domains/domain-routing-governance.md`. Este documento define
a forma da empresa digital; o guia de routing define a escolha operacional.

## Onde Se Encaixa

```text
Atlas AI Surface
-> Mission Mode
-> Objective / WorkOrder
-> Atlas Kernel
-> Domain Router
-> Domain Company Runtime
-> Delivery / Evidence / Certification
```

## Contrato de Runtime

O design pack canonico da interface, manifest, registry, capability catalog,
handoff e maturity assessment vive em
`atlas-domain-runtime-contract.md` (Meta 2). Este doc descreve a visao macro
de Domain Company Runtimes; o contrato executavel detalhado fica la.

Todo dominio deve implementar a interface conceitual:

```text
DomainRuntime
- canHandle(objective)
- plan(mission)
- execute(step)
- validate(output)
- certify(delivery)
- handoff(toDomain)
- describeCapabilities()
```

O dominio nao escolhe provider, permissao, custo, credencial ou ferramenta por
conta propria. Ele solicita capabilities ao Kernel/Tool Runtime e opera sob
Policy/Profile.

## Contratos

- `atlas.ai.domain_runtime.v1`
- `atlas.ai.domain_department.v1`
- `atlas.ai.domain_handoff.v1`
- `atlas.ai.domain_delivery.v1`
- `atlas.ai.domain_certification.v1`
- `atlas.ai.domain_manifest.v1`
- `atlas.ai.domain_capability.v1`
- `atlas.ai.domain_maturity_assessment.v1`

Campos minimos: `domain_id`, `mission_id`, `objective_id`, `departments`,
`entry_conditions`, `exit_conditions`, `evidence_refs`, `blockers`,
`next_action`, `receipt_hash`.

## Domain Manifest

Todo dominio deve declarar:

- `domain_id`;
- `charter`;
- `ontology`;
- `capabilities`;
- `departments`;
- `flow_profiles`;
- `tools_allowed`;
- `policy_profile`;
- `memory_scope`;
- `evidence_schema`;
- `quality_gates`;
- `handoff_rules`;
- `delivery_types`;
- `metrics`;
- `forbidden_actions`;
- `maturity_stage`.

## Fluxo

1. Receber objective.
2. Selecionar dominio principal.
3. Selecionar dominios secundarios.
4. Criar runtime record.
5. Instanciar departamentos.
6. Executar departamentos por plano.
7. Fazer handoff cross-domain quando necessario.
8. Validar qualidade.
9. Gerar delivery e certification.

## Dominios Oficiais

- Software Company Runtime.
- Cyber Security Company Runtime.
- Finance / Investment Company Runtime.
- Marketing / Growth Company Runtime.
- Corporate Strategy / Venture Studio Runtime.
- Research Company Runtime.
- Sales Company Runtime.
- Automation Company Runtime.
- Data Intelligence Company Runtime.
- Tool Factory Runtime.
- Personal Development / Learning Runtime.
- Operations Company Runtime.

## Estrutura Padrao Por Dominio

Cada dominio deve ter:

- **Charter:** missao, fronteira, usuarios, outcomes e proibicoes.
- **Ontology:** objetos centrais do dominio, como `Finding`, `Portfolio`,
  `Campaign`, `Opportunity`, `Experiment` ou `WorkOrder`.
- **Departments:** mesas, funcoes ou equipes internas do dominio.
- **Workflows:** caminhos executaveis com entrada, saida, gates e artifacts.
- **Agents:** papeis especializados, sempre subordinados ao runtime.
- **Tools:** capabilities permitidas via Tool Runtime.
- **Memory:** o que aprende, por quanto tempo, com qual escopo e provenance.
- **Policies:** autonomia, risco, custo, legal, privacidade e aprovacao.
- **Artifacts:** entregaveis versionados, nao apenas mensagens.
- **Metrics:** qualidade, impacto, custo, tempo, risco, falha e aprendizado.
- **Certification:** readiness, blockers e evidence pack final.

## Modelo De Maturidade

Cada dominio evolui em cinco estagios:

1. **Assistant:** responde, organiza e ajuda sob supervisao.
2. **Specialist:** executa workflows especificos com artifacts e evidence.
3. **Department:** coordena agentes/funcoes internas com handoffs e gates.
4. **Operating Unit:** opera ciclos recorrentes, dashboards, SLAs e memoria.
5. **Autonomous Enterprise Unit:** atua como empresa digital governada, com
   outcomes, risco, compliance, auditoria e melhoria continua.

Nenhum dominio pode declarar Stage 4/5 sem evidence, metrics e certification.

## Dominios Criticos

### Software Company Runtime

Empresa de engenharia de software: Dev, Debug, Review, QA, Security, Database,
Frontend, Forge, delivery, repair e release packs. E o primeiro dominio completo
porque ja existe base forte no Atlas Dev/Forge.

### Corporate Strategy / Venture Studio

Estrategista corporativo e venture builder: identifica oportunidades, modela
empresas/produtos, TAM/SAM/SOM, unit economics, GTM, hiring plan, operacao,
experimentos e decisoes. Toda estrategia deve virar modelo, hipotese,
experimento, decisao e execucao.

### Finance / Investment

Empresa de investimento governada: Research Desk, Valuation, Portfolio, Risk,
Trading/Execution, Compliance e Reporting. Sem mandato formal, opera somente em
research/simulacao. Live trade exige limites, broker policy, approvals,
reconciliacao, audit trail e kill switch.

### Marketing / Growth

Agencia/growth lab: posicionamento, ICP, campanha, criativo, copy, funil,
SEO/SEM, analytics, experimento, budget e performance review. Nunca publica ou
gasta midia sem approval/policy.

### Cyber Security

Empresa de cyber autorizada: governance, engagement, scope, RoE, AppSec, GRC,
remediation, defensive security, pentest e bug bounty autorizado. Acoes ofensivas
exigem autorizacao escrita, escopo, legal/privacy gate e evidence chain.

### Research / Market Intelligence

Empresa de pesquisa: fonte primaria, freshness, source quality, contradiction
check, claims, citations, synthesis, opportunity radar e relatorios.

### Personal Development / Learning

Professor e treinador cognitivo nao clinico: metas, habitos, estudo, pratica
deliberada, revisao, rotina e feedback. Nao diagnostica nem prescreve.

### Automation / Tool Factory

Empresa de automacao e ferramentas: browser, APIs, terminal, GitHub, repo
evaluation, tool selection, tool builder, tool evolution e operation runbooks.
Ferramenta pronta, repo clonado ou ferramenta propria devem passar por safety,
licenca, custo, security e validation.

## Como Criar Novo Runtime

Novo dominio precisa declarar: proposito, triggers, departamentos, ferramentas,
gates, evidencias, safety, delivery, blockers e testes.

Regra anti-reescrita:

```text
1 manifest
1 runtime adapter
N flow profiles
N gates especificos
N evidence schemas especificos
0 forks do Kernel
0 policy engines paralelos
0 tool runtimes paralelos
0 UIs obrigatorias novas
```

## Regras para IA

- Nao resolver tudo com um unico agente generico.
- Nao criar dominio quando o problema cabe como flow/profile de dominio ja
  existente.
- Nao criar executor, ledger, approval ou policy local quando o Kernel ja possui
  contrato equivalente.
- Nao chamar Sales quando o objetivo ainda e validacao de mercado.
- Nao chamar Software quando ferramenta pronta resolve melhor.
- Nao fazer handoff sem contexto, objetivo e evidence refs.
- Nao declarar dominio "enterprise" sem maturity assessment e certification.

## Escopo de Implementacao

Criar domain registry, department registry, handoff protocol, domain control
plane, maturity assessment, capability catalog e testes de selecao de dominio.

## Dependencias

- Objective Intelligence.
- Tool Economy.
- Evidence & Truth Layer.
- Evidence/Certification Runtime (`atlas-evidence-certification-runtime.md`) fornece EvidencePack, Receipt, Certification, Blocker e AuditEvent universais; Domain Manifest declara `evidence_schema_extension` e `certification_requirements` que estendem o minimo, nunca reduzem.
- Control Plane.

## Evidencias

Domain decision receipt, department receipts, handoff receipts, delivery pack,
certification hash e blockers.

## Riscos

- Overengineering para tarefas simples.
- Runtime errado desperdiça tempo/custo.
- Handoffs ruins criam retrabalho.

## Exemplos

"crie ecommerce e venda" aciona Research, Growth, Software/No-code, Sales,
Automation, Data e Control Plane.

"avalie minha carteira de investimentos" aciona Finance/Investment em modo
research/risk/reporting, com mandato e proibicao de ordem real sem approval.

"encontre bugs num programa de bug bounty" aciona Cyber somente se houver
programa autorizado, escopo parseado, RoE, legal/privacy gates e evidence chain.

## Proximas Acoes

A ordem em que cada dominio deve ser promovido para Domain Company Runtime
(Software primeiro, depois Research/Strategy, Finance, Marketing, Cyber,
Personal Development e Automation/Tool Factory) vive em
`atlas-ai-multi-domain-implementation-sequence.md`. Esta doc define a forma;
a sequencia define a ordem.

1. Criar registry canonico.
2. Definir departamentos por dominio.
3. Implementar maturity assessment.
4. Criar testes de roteamento, handoff e certification.

## Definition of Done

Esta pronto quando cada dominio tem contrato, entrada, saida, gates, evidencias
e handoff auditavel.
