---
id: atlas-autonomous-engineering-operating-system
type: engineering_knowledge
title: Atlas Autonomous Engineering Operating System
status: active
category: atlas-ai
priority: 100
summary: Patamar operacional que conduz trabalho de engenharia ponta a ponta com meta, decomposicao, world model, RAG gate obrigatorio, plano, execucao segura, repair loop, compounding, control plane e certificacao.
tags:
  - atlas-ai
  - autonomous-engineering
  - engineering-os
  - rag-gate
  - control-plane
capabilities:
  - autonomous_work_loop
  - goal_decomposition
  - codebase_world_model
  - mandatory_rag_spine_gate
  - execution_planner
  - test_repair_loop
  - engineering_control_plane
  - rivals_shadow_mode
decisions:
  - Autonomia de engenharia no Atlas e loop governado, nao sessao aberta de coding.
  - Nenhum goal pode completar sem evidence refs, outcome receipt e certification.
  - Toda tarefa de engenharia nao trivial passa por world model, RAG gate e execution plan.
  - Execucao real pode ser substituida por simulacao segura quando side effects nao sao permitidos.
  - Rivals Shadow Mode mede oportunidade de comparacao sem declarar vitoria falsa contra Claude Code/Codex.
maintenance:
  - Atualize este doc antes de mudar contratos, persistencia, gates ou criterios de certificacao do Autonomous Engineering OS.
  - Nao relaxe RAG gate, evidence refs, receipts ou claim policy para passar testes.
related_paths:
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-engineering-operating-system
graph_title: Atlas Autonomous Engineering Operating System
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-compounding-engineering-intelligence
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
allowed_changes:
  - Refinar contratos, readiness, world model e repair loop conforme o runtime amadurecer.
forbidden_changes:
  - Declarar autonomia completa sem evidence, receipts, tests e certification.
  - Permitir completed sem outcome receipt e certification.
  - Chamar shadow mode de prova de superioridade contra rivais.
depends_on:
  - atlas-compounding-engineering-intelligence
  - atlas-hyperflow-operation
  - atlas-ai-router-runtime-enterprise-upgrade
  - atlas-dual-core-engineering-system
  - atlas-forge-operating-system
flows_to:
  - atlas_dev
  - atlas_debug
  - atlas_review
  - atlas_research
  - atlas_forge
  - atlas_compounding_engineering_intelligence
unlocks:
  - atlas_autonomous_engineering_runtime
  - atlas_engineering_control_plane
governs:
  - atlas_ai.autonomous_engineering_os
evidence:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
required_tests:
  - "php artisan test tests/Unit/Ai/AutonomousEngineering"
  - "php artisan test tests/Feature/Ai/AtlasAutonomousEngineeringOperatingSystemTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Resumo, Contratos, Fluxo, Regras para IA, Escopo, Riscos e Definition of Done antes de implementar.
quality_gates:
  - goal-has-evidence
  - world-model-built
  - rag-gate-sufficient
  - execution-plan-ready
  - repair-loop-recorded-when-needed
  - compounding-outcome-recorded
  - rivals-shadow-no-false-claim
  - certification-passed
failure_modes:
  - Goal marcado como completo sem evidencia.
  - RAG gate vira formalidade e permite execucao sem contexto.
  - World model mapeia arquivos sem relacao com docs/testes.
  - Repair loop repete falha sem novo contexto.
  - Forge promotion nao dispara para trabalho grande.
observability_signals:
  - goal_id
  - cycle_id
  - step_id
  - rag_gate_status
  - context_sufficiency
  - execution_plan_hash
  - repair_loop_status
  - compounding_outcome_hash
  - rivals_shadow_claim_allowed
next_actions:
  - Manter backend certificado antes de UX pesada.
line_limit: 520
---
# Atlas Autonomous Engineering Operating System

## Resumo

Atlas Autonomous Engineering Operating System e o runtime que transforma uma
meta de engenharia em ciclos verificaveis: entender, decompor, recuperar
contexto, planejar, executar ou simular com seguranca, testar, reparar,
aprender, replanejar e certificar.

Ele fica acima de Hyperflow e Compounding. Hyperflow organiza execucao;
Compounding aprende com outcomes; Autonomous Engineering OS conduz o trabalho
inteiro com estado, gates, receipts e proxima acao.

## Papel no Atlas

Este OS coordena trabalho de engenharia autonomo dentro do Atlas sem substituir
os donos de roteamento, memoria, Forge ou certificacao. Ele organiza ciclos,
evidencias e proxima acao para que execucoes longas possam ser auditadas.

## Onde Se Encaixa

```text
Goal
-> Autonomous Work Loop
-> Router / Intent
-> Codebase World Model
-> Mandatory RAG Gate
-> Execution Plan
-> Dev / Debug / Review / Research / Forge
-> Test / Repair Loop
-> Compounding Outcome
-> Control Plane
-> Certification / Next Action
```

## Papel no Atlas

O Autonomous Engineering OS e o plano de controle que transforma Atlas AI em
operador de engenharia: ele nao substitui Dev, Debug, Review, Research ou Forge;
ele decide quando cada um entra, quais evidencias sao obrigatorias e quando o
trabalho pode ser certificado.

## Contratos

- `atlas.ai.autonomous_engineering.goal.v1`
- `atlas.ai.autonomous_engineering.work_cycle.v1`
- `atlas.ai.autonomous_engineering.work_step.v1`
- `atlas.ai.autonomous_engineering.codebase_world_model.v1`
- `atlas.ai.autonomous_engineering.rag_gate.v1`
- `atlas.ai.autonomous_engineering.execution_plan.v1`
- `atlas.ai.autonomous_engineering.repair_loop.v1`
- `atlas.ai.autonomous_engineering.control_plane_event.v1`
- `atlas.ai.autonomous_engineering.rivals_shadow_run.v1`
- `atlas.ai.autonomous_engineering.certification.v1`

## Fluxo

1. Receber meta e criar goal record.
2. Decompor em ciclo e steps.
3. Construir world model com arquivos, services, comandos, docs, tests e migrations.
4. Gerar retrieval plan e RAG gate obrigatorio.
5. Bloquear execucao se contexto for insuficiente.
6. Criar execution plan com arquivos, testes, riscos e rollback.
7. Executar ou simular com seguranca.
8. Registrar evidence refs e step receipt.
9. Abrir repair loop quando falha ou blocker exigir.
10. Registrar outcome no Compounding.
11. Criar rivals shadow run sem claim falsa.
12. Certificar e publicar control plane.

## Regras Para IA

- Nao marcar completed sem evidence refs, outcome receipt e certification.
- Nao executar engenharia nao trivial sem RAG gate suficiente.
- Nao promover memoria sem evidence.
- Nao declarar vitoria contra Claude Code/Codex por shadow mode.
- Promover para Forge quando escopo for grande, multi-ciclo, enterprise ou longo.
- Usar simulacao segura quando execucao real puder causar side effect indevido.

## Escopo De Implementacao

Backend obrigatorio:

- Autonomous Work Loop.
- Goal Decomposer.
- Codebase World Model.
- Mandatory RAG Spine Gate.
- Execution Planner.
- Test/Repair Loop.
- Engineering Control Plane.
- Rivals Shadow Mode.
- Readiness e Certification command.

## Dependencias

- Router Runtime para intent e flow inicial.
- Agentic RAG / Programming Retrieval para contexto obrigatorio.
- Atlas Dev, Debug, Review, Research e Forge para execucao especializada.
- Compounding Engineering Intelligence para outcome, memoria, RAG feedback e benchmark.
- Evidence Ledger ou receipts locais para hash e audit trail.

## Evidencias

Cada ciclo deve produzir pelo menos goal receipt, world model hash, RAG receipt,
execution plan hash, step evidence refs, compounding outcome hash, rivals shadow
receipt e certification hash quando passar.

## Riscos

- Autonomia sem gate pode executar com contexto fraco.
- Shadow mode pode ser confundido com prova contra Claude Code/Codex.
- Forge promotion fraca pode deixar obra grande no fluxo leve.
- Repair loop sem nova retrieval pode repetir falha.
- UX polida sem backend certificado pode esconder buraco operacional.

## Exemplos

Meta pequena:

```text
implemente um smoke autonomo de engenharia com evidencia
```

Resultado esperado: um cycle seguro com world model, RAG gate suficiente,
execution plan, step simulado, compounding outcome, shadow run e certification.

Meta grande:

```text
refatore todo o subsistema de billing com migracoes, testes e release
```

Resultado esperado: promotion target `atlas_forge`, plano multi-ciclo,
handoff auditavel e shadow run sem claim falsa.

## Definition Of Done

- Doc canonica valida e abaixo do limite.
- Persistencia e modelos existem.
- Command roda `readiness`, `run`, `control-plane` e `certify`.
- Work loop cria goal, cycle, step, world model, RAG gate, plan, repair loop,
  compounding outcome, rivals shadow e certification.
- Completed e bloqueado sem evidence refs, outcome receipt e certification.
- Tests unitarios e feature passam.
- Docs-health, Pint e diff-check passam.

## Proximas Acoes

1. Manter backend certificado antes de UX pesada.
2. Ligar control plane a uma tela leve quando os contratos estiverem estaveis.
3. Expandir benchmark real contra rivais sem permitir claim falsa.
