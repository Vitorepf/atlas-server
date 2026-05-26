---
id: atlas-aaeos-cross-department-choreography
type: engineering_knowledge
title: Atlas AAEOS Cross-Department Choreography
status: active
category: atlas-ai
priority: 101
summary: State machine canonica que define como os 11 departamentos do AAEOS se coordenam: handoffs, escalations, vetos, repair loops e parallel execution. Garante que Security bloqueando pause Dev/Forge corretamente, que Review reprovando volte para Architect e que Memory capture learning ao final de toda Obra.
tags:
  - atlas-ai
  - cross-department
  - choreography
  - state-machine
  - escalation
  - handoff
  - repair-loop
capabilities:
  - cross_department_state_machine
  - escalation_routing
  - veto_propagation
  - repair_loop_governance
  - parallel_department_safety
decisions:
  - Departamentos coordenam via state machine canonica; comunicacao ad-hoc proibida.
  - Veto propaga downstream automaticamente.
  - Repair loop tem max 3 iteracoes antes de escalar para operator.
maintenance:
  - Atualize antes de adicionar transicao, mudar policy de escalation, alterar repair loop limit.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-department-contract.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-aaeos-cross-department-choreography
graph_title: Atlas AAEOS Cross-Department Choreography
graph_world: atlas
graph_layer: system
graph_kind: flow
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Atlas AAEOS Cross-Department Choreography
canonical_name: Atlas AAEOS Cross-Department Choreography
technical_name: atlas-aaeos-cross-department-choreography
cartography_type: state_machine
canonical_source: docs/engineering-knowledge-base/atlas-aaeos-cross-department-choreography.md
owner: atlas-ai
product_name: Atlas AAEOS Cross-Department Choreography
internal_product_name: AAEOS Cross-Department Choreography
runtime_acronym: AAEOS-CDC
technical_runtime: atlas.aaeos.cross_department
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-cross-department-choreography.md
allowed_changes:
  - Refinar transicoes, escalation policy, repair loop limits.
forbidden_changes:
  - Permitir comunicacao ad-hoc entre departamentos.
  - Repair loop infinito.
depends_on:
  - atlas-agentic-engineering-os-department-contract
flows_to:
  - atlas-aaeos-obra-replay-spec
unlocks:
  - cross-department-coordination-runtime
governs:
  - atlas_ai.aaeos.cross_department
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-cross-department-choreography.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - state-machine
  - choreography
quality_gates:
  - all-handoffs-have-schema
  - veto-propagation-defined
  - repair-loop-bounded
failure_modes:
  - Departamento ignora veto upstream.
  - Repair loop infinito.
  - Handoff sem schema.
observability_signals:
  - cross_dept_handoff_count
  - cross_dept_veto_count
  - cross_dept_repair_loop_iterations_p95
next_actions:
  - Implementar `AtlasCrossDepartmentChoreographyService`.
---
# Atlas AAEOS Cross-Department Choreography

## Resumo

State machine canonica entre 11 departamentos.

## Papel no Atlas

Garante coordenacao governada e bloqueia comunicacao ad-hoc.

## Onde Se Encaixa

```text
atlas-agentic-engineering-os-department-contract (define deptos)
  +-- atlas-aaeos-cross-department-choreography  (define coordenacao)
```

## Contratos

### Schema handoff (`atlas.aaeos.cross_dept.handoff.v1`)

```text
{
  "schema": "atlas.aaeos.cross_dept.handoff.v1",
  "handoff_id": "<uuid>",
  "from_department": "<id>",
  "to_department": "<id>",
  "intent_id": "<id>",
  "kind": "delegation|escalation|veto|repair|review_request",
  "payload_hash": "sha256:...",
  "evidence_hashes": ["sha256:..."],
  "deadline": "<iso8601>",
  "rationale": "<string>"
}
```

### Transicoes canonicas

```mermaid
stateDiagram-v2
  Product --> Architect: spec_request
  Architect --> Security: security_review_needed
  Architect --> Forge: spec_ready_R4_plus
  Architect --> Dev: spec_ready_R3_or_below
  Dev --> Review: patch_ready
  Forge --> Review: obra_milestone
  Review --> Architect: changes_requested
  Review --> Delivery: approved
  Security --> Operator: deny_escalation
  Security --> Architect: mitigation_required
  Delivery --> Operator: human_review_required
  Operator --> Memory: learning_capture
  QA --> Dev: coverage_drop
  QA --> Forge: regression_detected
  Debug --> Dev: root_cause_found
```

### Regras de veto

- Security veto -> propaga para Dev/Forge/Delivery (todos pausam).
- Architect veto em spec -> volta a Product para clarification.
- Review veto em delivery -> volta a Dev/Forge para repair.
- Operator veto -> override final (sempre passa).

### Repair loop

- Max 3 iteracoes em qualquer ciclo (Dev<->Review, Forge<->Review).
- 4a iteracao escala automaticamente para Architect + Operator.

## Fluxo

Veja diagrama acima.

## Regras para IA

- Toda comunicacao entre depts via `atlas.aaeos.cross_dept.handoff.v1`.
- Veto upstream pausa downstream em <=10s.
- Repair loop registra cada iteracao.
- Operator override gera Operator Decision Receipt.

## Escopo de Implementacao

`AtlasCrossDepartmentChoreographyService`, `AtlasVetoPropagationWatchdog`, `AtlasRepairLoopGuard`.

## Dependencias

Department Contract (T1.2), Mission Control Cockpit (T1.5).

## Evidencias

Comando: `atlas:aaeos:choreography-status --intent=<id> --json`.

## Riscos

Veto perdido, repair loop infinito, handoff sem schema.

## O que este doc NAO e

Nao e contrato de departamento (T1.2); e coordenacao entre eles.

## Exemplos

Security detecta secret no patch -> emite `kind=veto` para Dev e Delivery -> ambos pausam -> Architect recebe escalation -> Architect ajusta spec -> Security re-review.

## Proximas Acoes

1. Implementar servicos.
2. Telemetria.
3. Integrar com cockpit.
