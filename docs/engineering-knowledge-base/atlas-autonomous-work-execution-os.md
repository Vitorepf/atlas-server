---
id: atlas-autonomous-work-execution-os
type: engineering_knowledge
title: Atlas Autonomous Work Execution OS
status: active
category: autonomous-intelligence
priority: 100
summary: Define o AWEOS, Atlas Autonomous Work Execution OS / Mission Control, como a torre residente que compila intencao, roteia por risco e evidencia, registra outcomes e coordena runtimes user-space sem chamar provider, certificar verdade final ou fazer merge.
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
  - AWEOS plugs into Atlas Autonomous Engineering Government as Mission Control, not as final self-construction authority and not as Engineering Kernel.
  - AWEOS may execute project work only inside an admitted stewardship lane with Task Fabric, Spec Court, Verification Court and Governor policy.
  - O nome canonico/produto e Atlas Autonomous Work Execution OS.
  - O acronimo tecnico obrigatorio e AWEOS.
  - O nome interno de experiencia/superficie e Atlas Mission Control.
  - O runtime tecnico canonico e AtlasAutonomousWorkExecutionService.
  - AWEOS orquestra APCR, AREG, AAWR, AEMOR, Dev, Forge, Autonomos e Control Plane como runtimes/capacidades user-space.
  - AWEOS nao chama provider diretamente, nao executa ferramenta externa e nao roda benchmark.
  - AWEOS nao pode marcar `verified=true`, promover memoria canonica ou fazer main/release entry; isso pertence a Verification Court, Learning-Application Controller e Governor.
  - Completion operacional exige certified outcome, mas certified outcome de codigo/release precisa de receipts de Court/Governor.
  - Memoria confiavel exige AEMOR/Judgment Guard e evidencia.
  - Roteamento AWEOS deve usar risco, escopo, ambiguidade e evidencia AEMOR; roteamento por substring isolada e bug.
maintenance:
  - Atualizar quando APCR, AREG, AAWR, AEMOR, Dev, Forge ou Hyperflow mudarem contrato.
  - Nao criar outro executor autonomo paralelo sem compatibilidade AWEOS.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md
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
human_name: Atlas Autonomous Work Execution OS
canonical_name: Atlas Autonomous Work Execution OS
technical_name: AtlasAutonomousWorkExecutionService
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-autonomous-work-execution-os.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-work-execution-os.md
allowed_changes:
  - Criar runtime, persistencia, comandos, certificacao e read models AWEOS.
  - Integrar AWEOS em Hyperflow e Control Plane sem bypass dos runtimes existentes.
forbidden_changes:
  - Chamar provider diretamente dentro do AWEOS.
  - Executar efeitos externos diretamente dentro do AWEOS.
  - Fazer AWEOS substituir Engineering Kernel, Spec Court, Verification Court, Governor ou Policy Plane.
  - Declarar `verified=true` ou merge/release final a partir de self-report do runtime.
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
evidence_refs:
  - symbol: AtlasAutonomousWorkExecutionService
  - command: atlas:aweos
  - test: AtlasAutonomousWorkExecutionServiceTest
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

> **Parent architecture:** AWEOS is a mission/work execution organ inside
> `Atlas Autonomous Engineering Government`. It coordinates execution, context,
> workcells and outcomes; it does not replace Autonomos / Self-Construction,
> Engineering Kernel, Policy Plane, Spec Court, Verification Court or Governor.

## Resumo

AWEOS transforma uma intencao em uma missao governada:

```text
intent -> APCR -> AREG -> AAWR -> route policy -> Dev/Forge/Autonomos/Flow -> AEMOR -> outcome record -> Mission Control -> continuation
```

Ele e o runtime central que evita o problema de sessoes zeradas, prompts gigantes,
execucoes sem prova e aprendizado falso.

## Papel no Atlas

AWEOS e Mission Control: a camada operacional acima dos runtimes
especializados. Ele nao substitui Hyperflow, Atlas Dev, Atlas Forge, Autonomos,
APCR, AREG, AAWR, AEMOR, Engineering Kernel, Courts ou Governor. Ele coordena
todos em uma unidade auditavel de trabalho e registra o outcome comum que
alimenta aprendizado e roteamento futuro.

## Onde Se Encaixa

```text
Atlas Autonomous Engineering Government
  -> Constitution
  -> Mission Control / AWEOS
      -> APCR: contexto persistente e must-know ledger
      -> AREG: budget cognitivo, ferramentas, risco, camadas
      -> AAWR: workcell, papeis, task graph, verificacao planejada
      -> route policy: Dev, Forge, Autonomos, external bootstrap ou blocked
      -> AEMOR: outcome, aprendizado, replay, anti-false-learning
      -> outcome registrar
  -> Policy Plane / Engineering Kernel / Courts / Governor
      -> chamados quando a missao toca codigo, release, memoria ou autonomia
```

AWEOS nao e o lugar onde provider, shell, git, merge ou `verified=true` vivem.
Ele pede essas capacidades ao Engineering Kernel e aceita apenas receipts de
Spec Court, Verification Court, Governor e Learning-Application Controller como
verdade final.

## Contratos

Schemas canonicos:

- `atlas.aweos.execution.v1`
- `atlas.aweos.event.v1`
- `atlas.aweos.certified_outcome.v1`
- `atlas.aweos.control_plane.v1`
- `atlas.aweos.certification.v1`
- `atlas.aweos.route_decision.v1`
- `atlas.aweos.outcome_record.v1`

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
- `route_decision`
- `outcome_record`

## Fluxo

1. Recebe objetivo.
2. APCR monta contexto minimo e hash.
3. AREG decide budget, risco, ferramentas e camadas.
4. AAWR desenha workcell e verifica contexto por papel.
5. AWEOS gera execution plan com runtime target e policy refs.
6. AWEOS abre episodio AEMOR.
7. Runtime alvo executa ou apresenta plano por contrato.
8. Se tocar codigo/release/memoria/autonomia, Court/Governor/Kernel emitem
   receipts.
9. Outcome e registrado via `certifyOutcome`; quando houver codigo/release, ele
   referencia os receipts independentes, nao self-report.
10. AEMOR/AREG/AAWR recebem feedback.
11. Mission Control mostra estado, blockers e proxima acao.

## Regras para IA

- Nunca chame provider dentro do AWEOS.
- Nunca use AWEOS para contornar Task Fabric, Autonomos policy, Engineering
  Kernel, Policy Plane, Spec Court, Verification Court ou Governor.
- Nunca trate AWEOS como executor final, juiz final ou committer.
- Nunca use roteamento por substring como unica decisao; use risco, escopo,
  ambiguidade, AEMOR e Policy Plane.
- Para projeto externo, exija lane de stewardship com workspace, gates,
  receipts e politica de merge/release proprios.
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

Na arquitetura v3, o estado alvo e mais preciso: AWEOS continua sendo Mission
Control. Execucao perigosa vai ao Engineering Kernel, qualidade de entrada vai
ao Spec Court, qualidade de saida vai ao Verification Court, landing vai ao
Governor e aprendizado aplicado vai ao Learning-Application Controller.

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
