---
id: atlas-adaptive-hierarchical-control-plane
type: engineering_knowledge
title: Atlas Adaptive Hierarchical Control Plane
status: active
category: programming-governance
priority: 100
summary: Control plane adaptativo que estende AHCL para live session control, Forge multi-agent control, predictive replay learning e optimization control twin.
tags:
  - atlas
  - programming
  - control-plane
  - ahcl
  - forge
  - replay
capabilities:
  - adaptive_hierarchical_control_plane
  - live_session_control
  - forge_multi_agent_control
  - predictive_replay_learning
  - optimization_control_twin
decisions:
  - AHCL v1 continua sendo o gate de completion.
  - Atlas Adaptive Hierarchical Control Plane e a camada superior que transforma AHCL em controle vivo da sessao.
  - v2 controla a sessao a cada estado/evento.
  - v3 traduz task contracts em plano Forge multiagente sem executar providers.
  - v4 produz replay deterministico, opcoes preditivas e learning candidates sem autoaplicar.
  - v5 calibra recomendacoes com historico local de work items, mas continua read-only e review-first.
  - AAHCP v5 nao encontrou novo salto de control-plane mais poderoso dentro do AHCL; o salto acima vive em AAEOS/Sovereign Engineering, nao em AHCL v6.
  - Atlas Code consome AAHCP por endpoint HTTP read-only e pelo snapshot consolidado da Obra.
  - Forge promotion consulta AAHCP como guard antes de mutar workspace.
  - Event stream pode ser persistido como Programming Evidence receipt.
  - Learning candidates v4/v5 entram na Programming Learning review queue; nunca autoaplicam.
  - AAHCP nunca substitui Programming Governance, Forge OS ou Evidence Ledger; ele coordena esses sistemas.
maintenance:
  - Atualize este doc quando `ProgrammingAdaptiveHierarchicalControlPlaneService`, comandos, surfaces HTTP/Obra ou contratos AAHCP v2-v5 mudarem.
related_paths:
  - app/Services/Ai/Programming/Governance/ProgrammingAdaptiveHierarchicalControlPlaneService.php
  - app/Console/Commands/AtlasProgrammingAdaptiveControlPlaneCommand.php
  - app/Http/Controllers/AtlasProgrammingGovernanceController.php
  - app/Http/Controllers/AtlasCodeWorkController.php
  - app/Services/Ai/Programming/AtlasForgeGovernedPromotionService.php
  - tests/Feature/ProgrammingGovernance/AdaptiveHierarchicalControlPlaneTest.php
  - tests/Feature/ProgrammingGovernance/ApiSurfaceTest.php
  - tests/Feature/AtlasCodeContractTest.php
  - docs/engineering-knowledge-base/atlas-hierarchical-control-loop.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-adaptive-hierarchical-control-plane

graph_title: Atlas Adaptive Hierarchical Control Plane

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-hierarchical-control-loop

graph_status: active

graph_source: repo
human_name: Atlas Adaptive Hierarchical Control Plane
canonical_name: Atlas Adaptive Hierarchical Control Plane
technical_name: atlas-adaptive-hierarchical-control-plane
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-adaptive-hierarchical-control-plane.md

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-adaptive-hierarchical-control-plane.md
  - app/Services/Ai/Programming/Governance/ProgrammingAdaptiveHierarchicalControlPlaneService.php
  - app/Console/Commands/AtlasProgrammingAdaptiveControlPlaneCommand.php

allowed_changes:
  - Evoluir v2-v5 quando novas evidencias, scheduler Forge, replay, optimization twin, learning gates ou guards de release forem adicionados.
  - Adicionar v6 somente quando houver salto material acima de control plane read-only, nao apenas novo nome.

forbidden_changes:
  - Autoaplicar learning critical behavior.
  - Executar provider dentro do control plane.
  - Declarar Forge release sem AHCL submit.
  - Criar runtime paralelo ao Programming Governance.

depends_on:
  - atlas-hierarchical-control-loop
  - atlas-programming-governance-system
  - atlas-forge-operating-system
  - code-intelligence
  - atlas-engineering-evidence-ledger

flows_to:
  - atlas-code
  - atlas-forge-operating-system
  - atlas-cartographic-knowledge-os

unlocks:
  - live-session-control
  - forge-multi-agent-control
  - predictive-replay-learning
  - optimization-control-twin

governs:
  - programming.adaptive-control-plane
  - programming.live-session
  - programming.forge-submit

evidence:
  - app/Services/Ai/Programming/Governance/ProgrammingAdaptiveHierarchicalControlPlaneService.php
  - tests/Feature/ProgrammingGovernance/AdaptiveHierarchicalControlPlaneTest.php
  - tests/Feature/AtlasCodeContractTest.php

evidence_refs:
  - command: php artisan test tests/Feature/ProgrammingGovernance/AdaptiveHierarchicalControlPlaneTest.php
  - command: php artisan test tests/Feature/AtlasCodeContractTest.php --filter=test_atlas_code_exposes_programming_governance_as_forge_task_queue
  - command: php artisan atlas:programming:adaptive-control-plane <work_item> --json

required_tests:
  - "php artisan test tests/Feature/ProgrammingGovernance/AdaptiveHierarchicalControlPlaneTest.php"
  - "php artisan test tests/Feature/AtlasCodeContractTest.php --filter=test_atlas_code_exposes_programming_governance_as_forge_task_queue"
  - "php artisan test tests/Feature/ProgrammingGovernance"

requires_evidence: true

risk_level: high

visual_tags:
  - programming
  - control-plane
  - forge

ai_entrypoints:
  - Leia este doc antes de alterar Atlas Code long session control, Forge submit, replay ou learning de programacao.

ai_usage_notes:
  - Use `atlas:programming:adaptive-control-plane` para obter o estado completo v2/v3/v4/v5.
  - Use `/atlas-code/programming/work-items/{code}/adaptive-control-plane` para Atlas Code/SCOR consumir o payload.
  - Use `/atlas-code/works/{project}/state` quando a UI precisar do AAHCP junto com a Obra, SDD, receipt, gates, evidence e Forge queue.

quality_gates:
  - hierarchical-control
  - adaptive-control-plane
  - forge-schedule
  - replay-learning

failure_modes:
  - v2 mostrar proximo passo sem evidence binding.
  - v3 ignorar colisao de arquivos.
  - v4 gerar learning autoaplicavel.
  - v5 ser criado sem salto material.
  - Forge promotion mutar workspace sem consultar `atlas.forge_governed_promotion.aahcp_guard.v1`.

observability_signals:
  - atlas.programming.adaptive_hierarchical_control_plane.v1
  - atlas.programming.ahcl.live_session_control.v2
  - atlas.programming.ahcl.forge_multi_agent_control.v3
  - atlas.programming.ahcl.predictive_replay_learning.v4
  - atlas.programming.ahcl.optimization_control_twin.v5

next_actions:
  - Projetar visualmente payload v2-v5 no Atlas Code Desktop.
  - Fazer release gate duro exigir v3 release_ready quando AWIS/Forge E2E estiverem verdes.
---
# Atlas Adaptive Hierarchical Control Plane

## Resumo

Atlas Adaptive Hierarchical Control Plane (AAHCP) e a evolucao operacional do
AHCL. O AHCL v1 responde se um work item pode fechar; o AAHCP responde como a
sessao inteira deve se mover agora, qual plano Forge deve existir e qual caminho
tem melhor chance de sucesso com menor risco.

## Papel no Atlas

AAHCP transforma programacao assistida por IA em controle adaptativo:

- v1: completion control;
- v2: live session control;
- v3: Forge multi-agent control;
- v4: predictive control + replay + learning;
- v5: optimization control twin.

Ele nao executa codigo. Ele governa a proxima decisao.

## Onde Se Encaixa

```text
Programming Governance
-> AHCL v1
-> AAHCP v2/v3/v4/v5
-> Atlas Code / Forge / Evidence / Learning
```

Atlas Code usa o payload para mostrar estado vivo. Forge usa para decidir
dispatch, repair packet, replan, pause ou release. Learning usa apenas
candidatos revisaveis.

## Contratos

### v2 Live Session Control

Schema: `atlas.programming.ahcl.live_session_control.v2`.

Entrega:

- `session_phase`;
- `next_tick.action`;
- `next_tick.reason`;
- `next_tick.next_step`;
- `next_tick.next_command`;
- checkpoint/resume;
- context budget;
- repair/replan loop;
- event stream.

### v3 Forge Multi-Agent Control

Schema: `atlas.programming.ahcl.forge_multi_agent_control.v3`.

Entrega:

- work packets derivados de task contracts;
- Forge schedule via `ForgeMultiAgentSchedulerService`;
- collision/ownership guard;
- provider strategy;
- integration guard;
- control decision: dev fast path, forge dispatch, repair packet, replan,
  pause operator ou release ready.

### v4 Predictive Replay Learning

Schema: `atlas.programming.ahcl.predictive_replay_learning.v4`.

Entrega:

- replay timeline;
- replay hash;
- prediction options;
- recommended path;
- learning candidates;
- auto_apply_allowed=false.

### v5 Optimization Control Twin

Schema: `atlas.programming.ahcl.optimization_control_twin.v5`.

Entrega:

- historical control signals;
- closure/evidence/repair calibration;
- control weights;
- policy recommendations;
- `auto_apply_allowed=false`;
- next evolution scan.

### HTTP Surface

Schema: `atlas.programming.adaptive_control_plane_response.v1`.

Rota:

```text
GET /atlas-code/programming/work-items/{code}/adaptive-control-plane
GET /atlas-code/programming/work-items/{code}/adaptive-control-plane?level=v3
GET /atlas-code/works/{project}/state
```

Entrega `adaptive_control_plane` completo ou nivelado (`v2`, `v3`, `v4`, `v5`)
para o cockpit Atlas Code. A rota e read-only: nao persiste event stream, nao
emite learning e nao chama provider. O estado consolidado da Obra projeta o
payload em `programming_governance.adaptive_control_plane` para telas que ja
consomem `/works/{project}/state`.

### Persistence And Learning Emission

Comando governado:

```bash
php artisan atlas:programming:adaptive-control-plane <work_item> --persist-event --emit-learning --json
```

`--persist-event` grava `atlas.programming.ahcl.control_event_receipt.v1` no
Programming Evidence Ledger e anexa o receipt ao WorkItem. `--emit-learning`
projeta candidatos v4/v5 para `atlas.programming.learning_candidate.v1` e usa
`ProgrammingLearningCandidateStore` para dedupe/review queue. `auto_apply_allowed`
permanece `false`.

### Forge Promotion Guard

Schema: `atlas.forge_governed_promotion.aahcp_guard.v1`.

`AtlasForgeGovernedPromotionService` consulta o AAHCP antes de mutar workspace.
O guard bloqueia quando v3 esta `blocked` (por exemplo colisao de ownership ou
scheduler repair), anexa `plane_hash`/`replay_hash` ao resultado e nunca executa
provider. O release gate duro `v3 release_ready` ainda e fase posterior porque o
Forge E2E atual pode promover patch humano mesmo antes de completion final.

## Fluxo

```text
WorkItem
-> AHCL v1 state
-> v2 live tick
-> v3 Forge schedule/control
-> v4 replay/prediction/learning
-> v5 optimization twin
-> next evolution scan
```

## Escopo de Implementacao

Implementado em:

```text
ProgrammingAdaptiveHierarchicalControlPlaneService
AtlasProgrammingAdaptiveControlPlaneCommand
AtlasProgrammingGovernanceController::adaptiveControlPlane
AtlasCodeWorkController::programmingGovernanceForWork
AtlasForgeGovernedPromotionService::adaptiveControlPlaneGuard
AdaptiveHierarchicalControlPlaneTest
ApiSurfaceTest
AtlasCodeContractTest
```

Comando:

```bash
php artisan atlas:programming:adaptive-control-plane <work_item> --json
php artisan atlas:programming:adaptive-control-plane <work_item> --level=v2 --json
php artisan atlas:programming:adaptive-control-plane <work_item> --level=v3 --json
php artisan atlas:programming:adaptive-control-plane <work_item> --level=v4 --json
php artisan atlas:programming:adaptive-control-plane <work_item> --level=v5 --json
php artisan atlas:programming:adaptive-control-plane <work_item> --persist-event --emit-learning --json
```

## Dependencias

- `ProgrammingHierarchicalControlLoopService`;
- `ForgeMultiAgentSchedulerService`;
- `ProgrammingEvidenceLedger`;
- `ProgrammingLearningCandidateProjector`;
- `ProgrammingLearningCandidateStore`;
- `AtlasProgrammingWorkItem`;
- `AtlasProgrammingGateRun`;
- `AtlasProgrammingReview`;
- Evidence refs do work item.

## Evidencias

Validado por:

```text
php artisan test tests/Feature/ProgrammingGovernance/AdaptiveHierarchicalControlPlaneTest.php
```

O teste prova:

- v2 replaneja structural sem spec;
- v2/v3/v4/v5 convergem apos review approved;
- v3 bloqueia packets com colisao sem consentimento de serializacao;
- v4 gera replay + learning sem autoaplicar quando review pede mudanca.
- v5 gera calibration, control weights e policy recommendations sem autoaplicar.
- event stream pode ser persistido em Evidence Ledger.
- learning candidates entram na review queue com dedupe.
- Forge promotion consulta AAHCP guard antes de mutar workspace.

## Regras para IA

- Antes de continuar uma sessao longa, consulte v2.
- Antes de promover trabalho pesado para Forge, consulte v3.
- Antes de aprender com falha/review, consulte v4.
- Antes de ajustar politica de controle, consulte v5.
- Nao trate prediction como prova; prediction orienta proximo passo.
- Nao execute schedule bloqueado por ownership/collision.
- Nao autoaplique learning candidate.
- Nao feche completion sem AHCL v1 action=`submit`.

## Riscos

- O operador confundir prediction com verdade.
- Learning virar mutacao automatica.
- Forge executar schedule bloqueado.
- Atlas Code esconder `next_command`.
- Work packets sem allowed files gerarem falsa paralelizacao.

## Exemplos

Structural sem spec:

```text
v2.next_tick.action = replan
v2.session_phase = planning_control
```

Packets com mesmo arquivo:

```text
v3.control_decision.status = blocked
v3.schedule.status = blocked_by_ownership_conflict
```

Review approved e gates verdes:

```text
v2.status = submit_ready
v3.status = release_ready
v4.recommended_path.path = submit_now
```

Twin em calibracao:

```text
v5.status = calibrating
v5.optimization_decision.auto_apply_allowed = false
```

## Proximas Acoes

1. Renderizar cards visuais AAHCP v2-v5 no Atlas Code Desktop a partir de `programming_governance.adaptive_control_plane`.
2. Fazer Forge promotion/release exigir v3 release_ready quando AWIS/Forge E2E estiverem verdes.
3. Crescer amostra historica para calibracao v5.
4. Desenhar o patamar acima fora do AHCL: AAEOS Sovereign Engineering Control Plane.
