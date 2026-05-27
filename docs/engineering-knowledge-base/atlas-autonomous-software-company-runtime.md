---
id: atlas-autonomous-software-company-runtime
type: engineering_knowledge
title: Atlas Autonomous Software Company Runtime
status: active
category: atlas-ai
priority: 100
summary: Camada executiva que transforma Atlas AI em uma empresa de software autonoma em runtime, coordenando Dev, Debug, Plan, Review, Research, Forge, Security, QA, Delivery, memoria e certificacao como departamentos especializados.
tags:
  - atlas-ai
  - autonomous-company
  - software-engineering
  - orchestration
  - enterprise-runtime
capabilities:
  - executive_intent_control
  - department_orchestration
  - autonomous_engineering_flow
  - specialist_flow_governance
  - forge_promotion
  - quality_control
  - delivery_certification
  - organizational_memory
decisions:
  - Atlas Agentic Engineering OS e a camada-mae; este runtime e a coordenacao executiva de departamentos dentro dele.
  - O Company Runtime e a camada superior de engenharia do Atlas AI, nao um flow isolado.
  - Dev, Debug, Plan, Review, Research, Forge e futuros especialistas entram como departamentos oficiais.
  - Um prompt ambiguo deve virar plano, execucao, teste, review, delivery e aprendizado quando o escopo for engenharia de software.
  - Nenhum departamento declara pronto sozinho; delivery final passa por evidencia e certificacao.
  - Tarefas grandes viram Obra no Forge; tarefas leves ficam no Dev/Debug/Review conforme risco.
  - O runtime interno pode declarar autonomous software company somente quando os 9 departamentos tiverem agent task packets, review, QA, release, evidence e certification passed.
  - A certificacao interna nao autoriza claim de superioridade externa, benchmark real ou comparacao com rivais.
  - Atlas Autonomous Software Company Night Shift e contrato operacional filho deste runtime; v1 roda somente no proprio Atlas e v2 em empresas externas exige promotion receipt aprovado.
maintenance:
  - Atualize este doc antes de adicionar novo flow especializado de programacao.
  - Nao criar especialista solto fora do Company Runtime sem contrato de entrada, saida, evidencias e gates.
  - Nao vender o runtime como melhor que Claude Code/Codex sem benchmark externo real e evidence pack.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-real-engineering-execution-kernel.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-programming-domain-adapter-integration-plan.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-software-company-runtime
graph_title: Atlas Autonomous Software Company Runtime
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-ai
graph_status: active
graph_source: repo
human_name: Atlas Autonomous Software Company Runtime
canonical_name: Atlas Autonomous Software Company Runtime
technical_name: atlas-autonomous-software-company-runtime
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
allowed_changes:
  - Adicionar departamentos especializados com contratos, receipts, gates e testes.
  - Refinar politicas de roteamento, promocao para Forge, quality control e delivery.
forbidden_changes:
  - Misturar departamentos sem fronteiras e contratos.
  - Permitir delivery sem evidence refs, teste/review aplicavel e certification.
  - Deixar novo flow de programacao fora do Company Runtime.
  - Declarar substituicao de Claude Code/Codex sem benchmark externo real.
depends_on:
  - atlas-ai-router-runtime-enterprise-upgrade
  - atlas-hyperflow-operation
  - atlas-autonomous-engineering-operating-system
  - atlas-real-engineering-execution-kernel
  - atlas-forge-operating-system
  - atlas-compounding-engineering-intelligence
flows_to:
  - atlas_dev
  - atlas_debug
  - atlas_plan
  - atlas_review
  - atlas_research
  - atlas_explain
  - atlas_forge
  - atlas_security
  - atlas_qa
  - atlas_delivery
unlocks:
  - atlas_engineering_company_runtime
  - atlas_ai_primary_engineering_interface
governs:
  - atlas_ai.software_company_runtime
  - atlas_ai.specialist_flows
  - atlas_ai.engineering_delivery
evidence:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - app/Services/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeService.php
  - tests/Feature/Ai/AtlasRealEngineeringCompanyRuntimeTest.php
  - tests/Unit/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeServiceTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Ai/AtlasRealEngineeringCompanyRuntimeTest.php tests/Unit/Ai/EngineeringCompany/AtlasRealEngineeringCompanyRuntimeServiceTest.php --stop-on-failure"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Resumo, Papel no Atlas, Departamentos, Fluxo, Contratos, Como Implementar Novo Departamento, Debug e Definition of Done antes de alterar codigo.
quality_gates:
  - intent-classified
  - department-selected
  - context-gathered
  - execution-plan-ready
  - implementation-or-handoff-complete
  - tests-or-blocker-recorded
  - review-or-risk-accepted
  - delivery-pack-ready
  - memory-updated
  - certification-complete
failure_modes:
  - Novo flow especialista criado sem contrato.
  - Router escolhe Dev para Obra enterprise que deveria ir ao Forge.
  - Debug altera codigo sem repair receipt.
  - Review vira comentario sem gate bloqueante.
  - Security encontra risco e delivery ignora.
  - Memoria aprende sem evidencia.
observability_signals:
  - company_goal_id
  - selected_departments
  - department_receipts
  - forge_promotion_status
  - delivery_pack_hash
  - certification_status
  - blockers
  - next_action
next_actions:
  - Implementar service e comandos quando o Kernel real estiver estabilizado.
  - Criar matriz de departamentos e contratos v1.
  - Conectar Router/Hyperflow ao Company Runtime como entrada principal de engenharia.
  - Implementar Night Shift v1 primeiro no Atlas antes de qualquer operacao em empresa externa.
line_limit: 520
---
# Atlas Autonomous Software Company Runtime

## Resumo

O Atlas Autonomous Software Company Runtime e a camada que faz o Atlas AI
operar como uma empresa de software autonoma. Ele recebe uma meta de
engenharia, entende a intencao, escolhe departamentos especializados, coordena
execucao, testa, revisa, entrega, registra evidencia e aprende.

Ele nao substitui Atlas Dev, Debug, Review, Research ou Forge. Ele organiza
todos como departamentos oficiais dentro de um fluxo unico de engenharia.

O contrato filho `atlas-autonomous-software-company-night-shift.md` define a
rotina noturna desse runtime: Atlas varre repos, cria findings/specs, abre
branches isoladas, tenta fixes de baixo risco e entrega Morning Inbox. A regra
canonica e que a primeira versao roda somente no proprio Atlas; empresas
externas como BlackInk so entram na segunda versao, apos promotion evidence e
aprovacao do operador.

Status operacional atual: o runtime interno pode ser certificado como
`ready_to_claim_autonomous_software_company=true` somente no escopo interno do
Atlas. Isso significa que o fluxo de software company com 9 departamentos,
subagentes via Agent Control Plane task packets, review, QA, release e
certificacao passou. Nao significa claim externo contra Claude/Codex, nem
benchmark real, nem superioridade publica.

## Objetivo

Transformar Atlas AI na interface principal de engenharia do Atlas:

- resolver tarefas leves com Atlas Dev;
- investigar falhas com Debug;
- planejar trabalho ambiguo com Plan;
- revisar qualidade com Review;
- pesquisar contexto tecnico com Research;
- promover Obras longas para Forge;
- adicionar futuros especialistas, como Cyber Security, sem criar fluxos soltos;
- entregar somente com evidencia, receipts e certificacao.

## Papel no Atlas

```text
Atlas AI
-> Atlas Autonomous Software Company Runtime
   -> Product / Intent Owner
   -> Architect / Planner
   -> Research
   -> Dev
   -> Debug / Repair
   -> Review
   -> QA / Test
   -> Security
   -> Forge
   -> Delivery / Certification
   -> Memory / Compounding
```

Atlas AI continua sendo a interface central. O Company Runtime e o cerebro
executivo de engenharia dentro dessa interface.

## Onde Se Encaixa

O Company Runtime fica acima dos specialist flows e abaixo da interface Atlas
AI. Ele consome Router/Hyperflow, Autonomous Engineering OS e Real Execution
Kernel para coordenar departamentos com receipts e certificacao.

## Diferenca Para Outros Modulos

| Modulo | Papel |
| --- | --- |
| Atlas AI | Interface, conversa, intencao e roteamento geral |
| Hyperflow | Runtime de roteamento e specialist flows |
| Autonomous Engineering OS | Loop de meta, contexto, plano, execucao, repair e certificacao |
| Real Execution Kernel | Motor que executa patch/test/delivery real com isolamento |
| Atlas Dev | Departamento leve/medio de programacao |
| Atlas Forge | Departamento pesado para Obras longas e enterprise |
| Company Runtime | Camada executiva que coordena todos os departamentos |

## Departamentos Oficiais

### Product / Intent Owner

Converte prompt ambiguo em objetivo operacional. Define intencao, risco,
prioridade, escopo e criterio de pronto.

### Architect / Planner

Cria plano tecnico, decompoe milestones, decide boundaries, riscos, rollback e
se o trabalho e leve, medio ou Obra.

### Research

Busca contexto tecnico, docs, APIs, dependencias, padroes do repo e evidencias
externas quando necessario.

### Dev

Implementa tarefas leves e medias com patch, teste e evidence refs.

### Debug / Repair

Reproduz falhas, classifica causa, cria repair plan, altera codigo quando
necessario e reroda gates focados.

### Review

Faz revisao independente: regressao, risco, arquitetura, padrao do repo,
testes, seguranca e legibilidade.

### QA / Test

Seleciona testes por impacto, cria casos quando faltarem, roda gates e registra
falhas reais ou blockers.

### Security

Departamento futuro para auth, secrets, permissao, vulnerabilidade, hardening,
compliance, supply chain e threat modeling. Security pode bloquear delivery.

### Forge

Assume Obras grandes, longas, multi-ciclo ou enterprise. Recebe handoff com
objetivo, plano, contexto, riscos, evidence refs e next action.

### Delivery / Certification

Monta delivery pack, resumo, diff, testes, riscos restantes, receipts,
certification hash e proxima acao.

### Memory / Compounding

Registra outcome, erro, aprendizado, benchmark candidate, RAG feedback e
heuristicas para melhorar o proximo ciclo.

## Contratos

- `atlas.ai.company.goal.v1`
- `atlas.ai.company.department_decision.v1`
- `atlas.ai.company.department_receipt.v1`
- `atlas.ai.company.execution_plan.v1`
- `atlas.ai.company.quality_gate.v1`
- `atlas.ai.company.forge_handoff.v1`
- `atlas.ai.company.delivery_pack.v1`
- `atlas.ai.company.certification.v1`

Cada contrato deve conter `schema_version`, `goal_id`, `department_id`,
evidence refs, hash e status bloqueante quando aplicavel.

Contrato de certificacao atual:

```text
atlas.ai.engineering_company.certification.v1
```

Checks obrigatorios:

- canonical_doc;
- persistence_tables;
- engagement_exists;
- all_roles_recorded;
- all_roles_have_agent_control_plane_task_packets;
- real_execution_completed;
- independent_review_passed;
- qa_passed;
- benchmark_recorded como shadow/internal ledger, sem execucao externa.

Claim policy obrigatoria:

```text
ready_to_claim_engineering_company_runtime=true quando passed
ready_to_claim_autonomous_software_company=true quando passed
ready_to_claim_external_superiority=false
external_benchmark_executed=false
rivals_provider_called=false
requires_multi_cycle_human_review_for_broad_enterprise_claim=true
```
`status`, `input_summary`, `output_summary`, `evidence_refs`, `blockers`,
`next_action` e `receipt_hash`.

## Fluxo

1. Receber prompt do usuario.
2. Classificar intencao, risco, urgencia e escopo.
3. Se for engenharia de software, abrir Company Runtime goal.
4. Criar plano inicial e selecionar departamentos.
5. Montar contexto obrigatorio via world model/RAG quando nao trivial.
6. Decidir Dev, Debug, Research, Review, Security ou Forge.
7. Executar ou promover com contrato claro.
8. Registrar receipts por departamento.
9. Rodar teste, review e security gate quando aplicavel.
10. Reparar falhas ou registrar blocker real.
11. Montar delivery pack.
12. Atualizar memoria/compounding.
13. Certificar ou declarar blockers.
14. Gerar proxima acao.

## Como Implementar Novo Departamento

Todo novo flow especializado de programacao deve entrar como departamento.
Exemplo: `Atlas Cyber Security`.

Checklist obrigatorio:

1. Declarar `department_id`, escopo e triggers.
2. Definir inputs aceitos.
3. Definir outputs e receipts.
4. Definir gates bloqueantes.
5. Definir quando chama Dev, Debug, Review ou Forge.
6. Definir persistencia/telemetry quando necessario.
7. Criar testes unitarios e feature.
8. Atualizar este doc e docs relacionados.
9. Rodar docs-health.

Cyber Security, por exemplo, deve ser chamado quando houver sinais de auth,
secrets, roles, permissions, data exposure, dependency risk, injection, SSRF,
XSS, supply chain, compliance ou hardening.

## Regras Para IA

- Nao criar flow especialista fora do Company Runtime.
- Nao misturar departamentos; cada um precisa fronteira, contrato e receipt.
- Nao promover para Forge sem handoff auditavel.
- Nao deixar Debug corrigir sem failure class e repair evidence.
- Nao deixar Review ser apenas opiniao; review pode bloquear delivery.
- Nao deixar Security ser advisory quando encontrar risco critico.
- Nao certificar sem delivery pack e evidence refs.
- Nao declarar superioridade contra Claude Code/Codex sem evidence pack externo.

## Escopo de Implementacao

Implementacao completa deve criar tabelas ou modelos equivalentes para:

- company goals;
- department decisions;
- department receipts;
- execution plans;
- quality gates;
- Forge handoffs;
- delivery packs;
- certifications;
- memory outcomes;
- blockers e next actions.

## Dependencias

- Atlas AI Router Runtime para entrada e intencao.
- Hyperflow para specialist flows.
- Autonomous Engineering OS para goal loop, world model, RAG gate e plano.
- Real Execution Kernel para patch/test/repair/delivery real.
- Atlas Forge para Obras pesadas.
- Compounding Engineering Intelligence para memoria e aprendizado.

## Evidencias

Entrega valida precisa de goal receipt, department decisions, department
receipts, plano, teste/review/security aplicavel, delivery pack,
certification hash e next action. Handoff para Forge precisa carregar contexto,
risco, evidence refs e motivo da promocao.

## Riscos

- Especialistas virarem atalhos sem contrato.
- Runtime escolher departamento errado para prompt ambiguo.
- Delivery ser liberado sem review/test/security quando necessario.
- Forge receber handoff incompleto.
- Memoria aprender padrao ruim sem evidencia.
- Claim contra Claude Code/Codex ser feita sem benchmark externo real.

## Debug Do Runtime

Quando falhar, investigar nesta ordem:

1. O prompt foi classificado corretamente?
2. O departamento correto foi escolhido?
3. Havia contexto suficiente?
4. O plano tinha arquivos, testes, riscos e rollback?
5. O executor alterou o escopo correto?
6. O teste realmente rodou?
7. O repair registrou causa e nova evidencia?
8. Review/Security bloquearam corretamente?
9. Delivery pack contem evidence refs?
10. Certification explica blockers?

## Observabilidade

Control plane deve expor:

- goals abertos;
- departamentos acionados;
- tempo por departamento;
- blockers;
- handoffs para Forge;
- repairs;
- reviews;
- security gates;
- delivery packs;
- certifications;
- next actions.

## Produto Final

Na versao final, o usuario usa Atlas AI como entrada unica de engenharia.
Ele pode escrever:

```text
melhore o checkout
corrija esse bug estranho
crie o modulo de billing
audite a seguranca da autenticacao
refatore esse sistema para escala enterprise
```

O Company Runtime decide se isso e Dev, Debug, Research, Review, Security,
Forge ou uma composicao deles. O usuario recebe entrega com evidencia, nao
apenas uma resposta textual.

## Exemplos

Prompt leve:

```text
corrija o bug no filtro da lista de pedidos
```

Fluxo esperado: Intent Owner -> Dev -> Test -> Review -> Delivery.

Prompt enterprise:

```text
refatore o billing para multi-tenant e compliance
```

Fluxo esperado: Intent Owner -> Architect -> Research -> Security -> Forge ->
QA -> Delivery -> Certification.

## Proximas Acoes

1. Criar service do Company Runtime.
2. Criar matriz de departamentos.
3. Conectar Router/Hyperflow.
4. Integrar Dev, Debug, Plan, Review, Research e Forge.
5. Criar command/readiness/certification.
6. Criar testes de fluxo leve, debug, review, security blocker e Forge handoff.

## Definition of Done

O Company Runtime esta pronto quando:

- Atlas AI roteia trabalho de software para ele.
- Ele cria goal e department decisions.
- Dev, Debug, Plan, Review, Research e Forge estao integrados.
- Novo departamento pode ser adicionado por contrato.
- Trabalhos grandes promovem para Forge com handoff.
- Delivery pack e certification sao obrigatorios.
- Blockers sao explicitos.
- Memory/Compounding recebe outcomes.
- Docs-health passa.
- Testes provam fluxo feliz, debug, review, Forge promotion e blocker real.
