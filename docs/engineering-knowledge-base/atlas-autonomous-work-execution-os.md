---
id: atlas-autonomous-work-execution-os
type: engineering_knowledge
title: Atlas Autonomous Work Execution OS
status: active
category: autonomous-intelligence
priority: 100
summary: Define o AWEOS, Atlas Autonomous Work Execution OS, a camada que transforma pedido humano em ciclo operacional completo com contexto persistente, budget cognitivo, workcell, execucao governada, evidencia, certificacao, aprendizado e continuacao.
implementation_state: runtime_implemented_l1_to_l10
macro_layer: true
product_name: Atlas Autonomous Work Execution OS
runtime_acronym: AWEOS
internal_product_name: Atlas Mission Control
technical_runtime: AtlasAutonomousWorkExecutionService
tags:
  - atlas-ai
  - aweos
  - autonomous-work
  - mission-control
  - certified-outcome
  - long-horizon
capabilities:
  - mission_to_execution_loop
  - perfect_context_spine
  - certified_outcome_layer
  - long_horizon_dev_forge_os
  - mission_control
  - execution_memory_learning_guard
decisions:
  - O nome canonico/produto e Atlas Autonomous Work Execution OS.
  - O acronimo tecnico obrigatorio e AWEOS.
  - O nome interno de experiencia/superficie e Atlas Mission Control.
  - O runtime tecnico canonico e AtlasAutonomousWorkExecutionService.
  - AWEOS orquestra APCR, AREG, AAWR, AEMOR, Dev, Forge e Control Plane.
  - AWEOS nao chama provider diretamente, nao executa ferramenta externa e nao roda benchmark.
  - Completion operacional exige certified outcome.
  - Memoria confiavel exige AEMOR/Judgment Guard e evidencia.
maintenance:
  - Atualizar quando APCR, AREG, AAWR, AEMOR, Dev, Forge ou Hyperflow mudarem contrato.
  - Nao criar outro executor autonomo paralelo sem compatibilidade AWEOS.
related_paths:
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md
  - docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-work-execution-os
graph_title: Atlas Autonomous Work Execution OS
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-ai
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-work-execution-os.md
allowed_changes:
  - Criar runtime, persistencia, comandos, certificacao e read models AWEOS.
  - Integrar AWEOS em Hyperflow e Control Plane sem bypass dos runtimes existentes.
forbidden_changes:
  - Chamar provider diretamente dentro do AWEOS.
  - Executar efeitos externos diretamente dentro do AWEOS.
  - Declarar superioridade externa ou benchmark sem gate proprio.
  - Promover memoria sem AEMOR/Judgment Guard.
depends_on:
  - atlas-persistent-context-runtime
  - atlas-runtime-efficiency-governor
  - atlas-agentic-workcell-runtime
  - atlas-execution-memory-outcome-runtime
  - atlas-hyperflow-operation
flows_to:
  - atlas_ai
  - atlas_dev
  - atlas_forge
  - atlas_research
  - atlas_strategy
unlocks:
  - atlas-mission-control
  - certified-autonomous-work
governs:
  - autonomous_work_execution
  - mission_control
  - certified_outcomes
evidence:
  - docs/engineering-knowledge-base/atlas-autonomous-work-execution-os.md
required_tests:
  - php artisan test tests/Feature/Ai/AutonomousWorkExecution
  - php artisan atlas:aweos:certify --json --strict
next_actions:
  - Alimentar AWEOS com outcomes reais Dev/Forge e expor Mission Control no Desktop/Mobile.
requires_evidence: true
risk_level: high
line_limit: 520
---

# Atlas Autonomous Work Execution OS

## Resumo

AWEOS transforma um pedido humano em uma operacao completa:

```text
prompt -> APCR -> AREG -> AAWR -> Dev/Forge/Flow -> AEMOR -> certified outcome -> Mission Control -> continuation
```

Ele e o runtime central que evita o problema de sessoes zeradas, prompts gigantes,
execucoes sem prova e aprendizado falso.

## Papel no Atlas

AWEOS e a camada operacional acima dos runtimes especializados. Ele nao substitui
Hyperflow, Atlas Dev, Atlas Forge, APCR, AREG, AAWR ou AEMOR. Ele coordena todos
em uma unidade auditavel de trabalho.

## Onde Se Encaixa

```text
Atlas AI Surface
  -> Hyperflow
  -> AWEOS
     -> APCR: contexto persistente e must-know ledger
     -> AREG: budget cognitivo, ferramentas, risco, camadas
     -> AAWR: workcell, papeis, task graph, verificacao
     -> Dev/Forge/Research/etc: runtime alvo
     -> AEMOR: outcome, aprendizado, replay, anti-false-learning
     -> Control Plane: estado e decisao humana
```

## Contratos

Schemas canonicos:

- `atlas.aweos.execution.v1`
- `atlas.aweos.event.v1`
- `atlas.aweos.certified_outcome.v1`
- `atlas.aweos.control_plane.v1`
- `atlas.aweos.certification.v1`

Campos obrigatorios de uma execucao:

- `persistent_context`
- `runtime_efficiency`
- `agentic_workcell`
- `aemor_episode`
- `execution_plan`
- `tool_orchestration`
- `repair_recovery_loop`
- `operator_decision_economy`
- `continuation_engine`
- `strategic_next_action`
- `mission_control`
- `claim_policy`

## Fluxo

1. Recebe objetivo.
2. APCR monta contexto minimo e hash.
3. AREG decide budget, risco, ferramentas e camadas.
4. AAWR desenha workcell e verifica contexto por papel.
5. AWEOS gera execution plan com runtime target.
6. AWEOS abre episodio AEMOR.
7. Runtime alvo executa ou apresenta plano.
8. Outcome e certificado via `certifyOutcome`.
9. AEMOR/AREG/AAWR recebem feedback.
10. Control Plane mostra estado, blockers e proxima acao.

## Regras para IA

- Nunca chame provider dentro do AWEOS.
- Nunca execute ferramenta externa diretamente pelo AWEOS.
- Nunca declare completed sem certified outcome.
- Nunca promova memoria sem AEMOR/Judgment Guard.
- Nunca use contexto bruto quando APCR puder fornecer context pack.
- Nunca pule AREG para tarefas nao triviais.
- Nunca crie subagentes reais fora do contrato AAWR.
- Nunca rode benchmark externo dentro da certificacao AWEOS.

## Escopo de Implementacao

AWEOS-L1 a AWEOS-L10:

1. L1 Mission Intake.
2. L2 Perfect Context Spine.
3. L3 Cognitive Budget Governance.
4. L4 Workcell Organization.
5. L5 Execution Planner.
6. L6 Tool/Capability Orchestrator.
7. L7 Repair/Recovery Loop.
8. L8 Certified Outcome Layer.
9. L9 Continuation/Mission Control.
10. L10 Autonomous Work Operating System.

Estado atual: implementado como runtime local de orquestracao, persistencia,
certificacao e control plane. Execucao real continua em Dev/Forge/flows.

## Dependencias

- APCR para contexto persistente.
- AREG para budget e gates.
- AAWR para organizacao de workcells.
- AEMOR para outcome e aprendizado.
- Hyperflow para roteamento canonico.
- Control Plane para estado operacional.

## Evidencias

Comandos:

```bash
php artisan atlas:aweos run --objective="..." --json
php artisan atlas:aweos certify-outcome --execution-id="..." --evidence=test:ok --json
php artisan atlas:aweos control-plane --json
php artisan atlas:aweos:certify --json --strict
```

Testes:

```bash
php artisan test tests/Feature/Ai/AutonomousWorkExecution
```

## Riscos

| Risco | Mitigacao |
| --- | --- |
| Criar executor paralelo | AWEOS so orquestra runtimes existentes |
| Aprendizado falso | AEMOR/Judgment Guard obrigatorio |
| Completion falsa | certified outcome obrigatorio |
| Overengineering | AREG governa admissao de camadas |
| Contexto demais | APCR e context budget |
| Autonomia perigosa | provider/external execution proibidos no AWEOS |

## Exemplos

### Programacao

Pedido: "corrija esse bug no Atlas Dev".

AWEOS cria contexto APCR, AREG escolhe path, AAWR organiza workcell, runtime
target vira `atlas_dev`, certificacao exige testes/diff/evidence.

### Obra Forge

Pedido: "implemente uma grande obra".

AWEOS promove runtime target para `atlas_forge`, exige work packets,
milestones, continuation e operator review.

## Proximas Acoes

1. Conectar Mission Control visual ao read model AWEOS.
2. Alimentar AWEOS com outcomes reais de Dev/Forge.
3. Criar endpoint REST read-only.
4. Ampliar certified outcome para patch/test replay real.
5. Rodar benchmark apenas depois de readiness especifica.
