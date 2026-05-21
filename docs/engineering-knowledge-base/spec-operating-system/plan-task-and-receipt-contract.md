---
id: atlas-ai-sdd-plan-task-receipt-contract
type: engineering_knowledge
title: Atlas SDD Plan Task And Receipt Contract
status: active
category: contracts
priority: 99
summary: Plan compiler, task compiler and Decision Receipt contract for SDD execution.
tags:
  - atlas-ai
  - sdd
  - plan
  - decision-receipt
capabilities:
  - plan_compiler
  - task_compiler
  - sdd_decision_receipt
decisions:
  - Plans and tasks are executable objects, not just markdown prose.
  - Decision Receipt is the execution boundary.
maintenance:
  - Update when task contract, receipt schema or execution harness changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/kernel/contracts.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-sdd-plan-task-receipt-contract

graph_title: Atlas SDD Plan Task And Receipt Contract

graph_world: atlas

graph_layer: gear

graph_kind: contract

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas SDD Plan Task And Receipt Contract
canonical_name: Atlas SDD Plan Task And Receipt Contract
technical_name: atlas-ai-sdd-plan-task-receipt-contract
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md

owner: spec-operating-system

repo_paths:
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md

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
  - spec-operating-system

evidence:
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - gear
  - contract
  - spec-operating-system

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
# Atlas SDD Plan Task And Receipt Contract

## Plan Compiler

Plan output must include:

- target files;
- forbidden files;
- hot-file ownership;
- technical approach;
- backend/frontend/database/security/design impact;
- test plan;
- rollback plan.

## Task Compiler

Tasks must be small and ordered:

```yaml
tasks:
  - id: T1
    type: analysis
    title: Inspect current form submit handler
    allowed_files: ["resources/js/components/forms/ProfileForm.tsx"]
  - id: T2
    type: code_change
    depends_on: ["T1"]
    title: Add save button using design-system Button
```

## Task Lifecycle Audit

Task orchestration surfaces must preserve a local lifecycle trail before they
claim progress. Open Brain MCP task tools write `AtlasTaskEvent` records with
schema `atlas.task_orchestration.event.v1` for `started`, `milestone` and
`completed` events. If `atlas_tasks` or `atlas_task_events` is unavailable, the
tool fails closed with no task write.

Each event payload carries `event_sequence`, `previous_event_id`,
`previous_event_hash` and `event_hash` so task progress is locally hash-chained
and can be replayed as an audit trail. The hash chain is an orchestration
lineage receipt only; it is not a provider dispatch receipt.

Lifecycle events must state when they did not execute providers or runtimes.
Provider dispatch, external runtime execution, merge, approval and policy change
remain outside the task event contract and require a Decision Receipt.
Task and task-event API/CLI resources expose safety summaries as
`atlas.task_orchestration.task_safety.v1` and
`atlas.task_orchestration.event_safety.v1`. These are read-model contracts:
provider dispatch, runtime execution, policy mutation and automatic completion
stay closed unless a separate Decision Receipt authorizes them.
Task resources also expose `atlas.task_orchestration.intent_contract.v1`, which
declares allowed local actions and blocked external actions. Agent Control Plane
dispatch is explicitly blocked here; tasks can plan, schedule, defer, record
evidence or complete by operator action, but external provider/runtime/agent
handoff still requires operator review and a separate authorization path.

Planning, schedule, defer, calendar and completion paths that use
`TaskPlanningService::recordEvent()` also persist the same local event schema.
The service appends `atlas.task_orchestration.local_event_receipt.v1` to each
payload, assigns a per-task `event_sequence`, links to the previous event id and
hash, and stores a SHA-256 `event_hash`. This makes normal task planning
replayable by Open Brain without implying provider dispatch, runtime execution,
Agent Control Plane dispatch, policy mutation or automatic completion authority.
`php artisan atlas:ai:task-orchestration-report --json` is the compact
read-only promotion check for this boundary. It reports task counts, lifecycle
event coverage, receipt/hash-chain coverage, unsafe receipt flags and external
execution review requirements without creating tasks, completing work or
dispatching providers/runtimes/agents. The report also publishes
`atlas.task_orchestration.handoff_contract.v1`, a hash-only fail-closed handoff
contract: provider dispatch, runtime execution, Agent Control Plane dispatch,
policy mutation and auto-completion remain false by report authority, and any
external handoff requires task event receipt, event hash, event sequence,
Decision Receipt, operator review, runtime invocation contract when applicable
and Evidence Ledger ref.
Legacy local task events can be repaired with
`php artisan atlas:ai:task-orchestration-backfill-receipts --json` first as a
dry-run, then with `--write` after review. The backfill only repairs local
sequence, receipt and hash-chain payload fields; it does not execute providers,
runtimes, agents, policy changes or task completion.
Task event resources project receipt safety explicitly: local receipt presence,
schema, completeness, unsafe flag, provider/runtime/Agent Control Plane/policy/
auto-completion booleans and operator-review requirement. These fields are
audit signals only and never authorize dispatch.

## Decision Receipt Fields

SDD receipt must preserve:

- operation id;
- spec id;
- plan id;
- task ids;
- risk level;
- autonomy level;
- selected runtime/agents;
- allowed actions/tools/files;
- forbidden actions/tools/files;
- required gates;
- rollback strategy;
- evidence required.

## Execution Rule

Runtime may edit only what the receipt allows. Any need outside receipt creates
a new proposal or clarification, not silent scope expansion.

## Resumo

Plan compiler, task compiler and Decision Receipt contract for SDD execution.

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
