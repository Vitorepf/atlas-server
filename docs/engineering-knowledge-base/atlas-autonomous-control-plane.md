---
id: atlas-autonomous-control-plane
type: engineering_knowledge
title: Atlas Autonomous Control Plane
status: active
category: atlas-ai
priority: 100
summary: Estado vivo e auditavel do Autonomous Intelligence OS, mostrando missoes, dominios, ferramentas, custos, safety gates, evidencias, blockers, outcomes, next actions e certificacoes. Meta 9 backend (aggregate read-model + REST + CLI) implementada 2026-05-18.
implementation_status: active_backend_read_model
implementation_boundary: cli_api_services_routes_tests_ready_frontend_surface_future
tags:
  - atlas-ai
  - control-plane
  - observability
  - autonomous-runtime
capabilities:
  - mission_state
  - runtime_observability
  - blocker_tracking
  - cost_tracking
  - next_action
decisions:
  - Autonomia enterprise precisa de estado vivo, nao apenas resposta final.
  - Toda mission relevante deve aparecer no control plane com status e evidencia.
maintenance:
  - Atualize este doc antes de mudar read models ou estados globais.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - app/Services/Ai/ControlPlane/AtlasControlPlaneStatus.php
  - app/Services/Ai/ControlPlane/AtlasControlPlaneSnapshotService.php
  - app/Services/Ai/ControlPlane/AtlasControlPlaneMissionService.php
  - app/Services/Ai/ControlPlane/AtlasControlPlaneDomainService.php
  - app/Services/Ai/ControlPlane/AtlasControlPlanePolicyService.php
  - app/Services/Ai/ControlPlane/AtlasControlPlaneEvidenceService.php
  - app/Services/Ai/ControlPlane/AtlasControlPlaneToolService.php
  - app/Services/Ai/ControlPlane/AtlasControlPlaneRouterService.php
  - app/Services/Ai/ControlPlane/AtlasControlPlaneBlockerService.php
  - app/Services/Ai/ControlPlane/AtlasControlPlaneReadinessService.php
  - app/Services/Ai/ControlPlane/AtlasControlPlaneNextActionService.php
  - app/Console/Commands/AtlasAiControlPlaneCommand.php
  - app/Http/Controllers/AtlasAiControlPlaneController.php
  - routes/api.php
  - tests/Feature/Ai/ControlPlane/AtlasControlPlaneReadinessTest.php
  - tests/Feature/Ai/ControlPlane/AtlasControlPlaneSnapshotTest.php
  - tests/Feature/Ai/ControlPlane/AtlasControlPlaneBlockerTest.php
  - tests/Feature/Ai/ControlPlane/AtlasControlPlaneNextActionTest.php
  - tests/Feature/Ai/ControlPlane/AtlasControlPlaneMissionTest.php
  - tests/Feature/Ai/ControlPlane/AtlasControlPlaneApiTest.php
  - tests/Feature/Ai/ControlPlane/AtlasControlPlaneToleranceTest.php
  - tests/Feature/Ai/ControlPlane/AtlasControlPlaneSmokeTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-control-plane
graph_title: Atlas Autonomous Control Plane
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Autonomous Control Plane
canonical_name: Atlas Autonomous Control Plane
technical_name: atlas-autonomous-control-plane
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-autonomous-control-plane.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-control-plane.md
allowed_changes:
  - Adicionar read models, metrics e views.
forbidden_changes:
  - Ocultar blockers ou custos.
depends_on:
  - atlas-evidence-truth-layer
flows_to:
  - atlas-mission-mode
unlocks:
  - observable-autonomous-intelligence
governs:
  - atlas_ai.autonomous_control_plane
evidence:
  - docs/engineering-knowledge-base/atlas-autonomous-control-plane.md
required_tests:
  - "/opt/homebrew/bin/php artisan test --filter=AtlasControlPlane"
  - "/opt/homebrew/bin/php artisan atlas:ai:control-plane --action=readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:control-plane --action=snapshot --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:control-plane --action=smoke --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Estados e Read Models antes de implementar.
quality_gates:
  - mission-visible
  - blocker-visible
  - evidence-visible
  - next-action-visible
failure_modes:
  - Estado divergente da execucao.
  - Blocker some da UI.
  - Custo nao aparece.
observability_signals:
  - open_missions
  - blocked_missions
  - cost_total
  - certifications
next_actions:
  - Manter parity entre CLI/API e services quando novos runtimes entrarem.
  - Criar tela visual depois do backend sem duplicar regras do read model.
line_limit: 520
---
# Atlas Autonomous Control Plane

## Estado Atual

Meta 9 backend entregue 2026-05-18 como read-model agregador tolerante.

- 10 services em `app/Services/Ai/ControlPlane/`: `AtlasControlPlaneStatus`
  (canon), `AtlasControlPlaneSnapshotService` (agregador top-level),
  `AtlasControlPlaneMissionService`, `AtlasControlPlaneDomainService`,
  `AtlasControlPlanePolicyService`, `AtlasControlPlaneEvidenceService`,
  `AtlasControlPlaneToolService`, `AtlasControlPlaneRouterService`,
  `AtlasControlPlaneBlockerService`, `AtlasControlPlaneReadinessService`,
  `AtlasControlPlaneNextActionService`.
- Comando `atlas:ai:control-plane` com actions
  `readiness | snapshot | mission | blockers | next-actions | smoke`.
- Controller `AtlasAiControlPlaneController` em routes `routes/api.php`
  (apiPrefix vazio): `GET /atlas/ai/control-plane`,
  `GET /atlas/ai/control-plane/readiness`,
  `GET /atlas/ai/control-plane/blockers`,
  `GET /atlas/ai/control-plane/next-actions`,
  `GET /atlas/ai/control-plane/missions/{uuid}`.
- 8 feature tests em `tests/Feature/Ai/ControlPlane/` (25 testes / 108
  asserções verdes) cobrindo readiness/snapshot/blockers/next-actions/mission/
  api/tolerance/smoke.

Status de autoridade vs implementacao:

- `status: active` significa que este doc governa o backend Control Plane
  ja implementado: services, CLI, API, rotas e testes.
- `implementation_status: active_backend_read_model` significa que o
  read model backend esta operacional; tela visual/produto pode evoluir,
  mas nao pode criar regra paralela fora dos services canônicos.
- A lista de estados abaixo e vocabulario runtime canonico de mission/control
  plane, nao roadmap documental nem permissao para IA tratar `planned` como
  doc antiga.

Tolerancia: cada per-runtime service usa `Schema::hasTable()` + verificacao de
classe + try/catch. Runtime ausente vira `component.status = missing|degraded`,
nao excecao. `AtlasControlPlaneStatus::reduce()` reduz multiplos statuses para
o pior (`blocked > degraded > missing > ready`).

Schemas canonicos:
- `atlas.ai.control_plane.snapshot.v1`
- `atlas.ai.control_plane.readiness.v1`
- `atlas.ai.control_plane.mission_state.v1`
- `atlas.ai.control_plane.blocker.v1`
- `atlas.ai.control_plane.next_action.v1`
- `atlas.ai.control_plane.tool.v1`
- `atlas.ai.control_plane.router.v1`

Comandos canonicos:

```bash
php artisan atlas:ai:control-plane --action=readiness --json
php artisan atlas:ai:control-plane --action=snapshot --json
php artisan atlas:ai:control-plane --action=mission --mission=<uuid> --json
php artisan atlas:ai:control-plane --action=blockers --json
php artisan atlas:ai:control-plane --action=next-actions --json
php artisan atlas:ai:control-plane --action=smoke --json
```

## Resumo

Autonomous Control Plane e o estado vivo do Atlas: missoes abertas, dominios,
ferramentas, custos, safety gates, evidencias, blockers, outcomes,
certificacoes e proximas acoes.

## Papel no Atlas

Sem control plane, autonomia vira caixa preta. Com control plane, qualquer IA
ou humano pode assumir, debugar, continuar ou auditar uma missao.

## Onde Se Encaixa

```text
All runtimes -> Events/Receipts -> Control Plane Read Model -> UI/API/CLI
```

## Contratos

- `atlas.ai.control_plane.snapshot.v1`
- `atlas.ai.control_plane.mission_state.v1`
- `atlas.ai.control_plane.blocker.v1`
- `atlas.ai.control_plane.cost.v1`
- `atlas.ai.control_plane.next_action.v1`

## Fluxo

1. Receber events e receipts.
2. Atualizar read models.
3. Agregar estado por mission.
4. Expor blockers, evidencias, custos e next action.
5. Certificar consistencia.
6. Permitir resume/debug/handoff.

## Estados

- `planned`
- `running`
- `waiting_approval`
- `blocked`
- `repairing`
- `certifying`
- `completed`
- `failed`

## Regras para IA

- Consultar control plane antes de continuar missao existente.
- Nao criar missao duplicada se uma equivalente esta running.
- Nao ocultar blocker.
- Nao marcar completed sem certification.
- Sempre registrar next action.

## Escopo de Implementacao

Event store/read model, CLI/API `control-plane --json`, mission detail, blocker
view, cost view, evidence view, next action resolver e resume protocol.

## Dependencias

- Evidence & Truth Layer.
- Mission Mode.
- Domain Runtimes.
- Safety Layer.

## Evidencias

Snapshots, event ids, receipt hashes, blocker ids, certification ids, cost
records e next actions.

## Riscos

- Read model atrasado pode induzir decisao errada.
- UI bonita sem dados confiaveis mascara falha operacional.

## Exemplos

Uma missao de ecommerce deve mostrar: objetivo, departamentos ativos, loja,
campanhas, custos, analytics, blockers, evidencias e proxima acao.

## Proximas Acoes

1. Manter CLI/API/services em parity quando novos runtimes entrarem no
   snapshot.
2. Adicionar tela visual ou surface Desktop consumindo o backend existente,
   sem duplicar regras de readiness, blockers ou next-actions.
3. Expandir action `runtime` e `snapshot` conforme novos receipts reais
   chegarem ao Evidence Ledger.

## Definition of Done

Backend Control Plane esta pronto quando qualquer IA consegue ler CLI/API e
saber o que esta rodando, o que bloqueou, quais evidencias existem e qual a
proxima acao. A surface visual e melhoria de produto sobre o backend, nao
condicao para chamar o read model backend de ativo.
