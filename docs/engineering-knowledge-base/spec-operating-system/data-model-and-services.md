---
id: atlas-ai-spec-operating-system-data-model-and-services
type: engineering_knowledge
title: Atlas Spec Operating System Data Model And Services
status: active
category: architecture
priority: 99
summary: Canonical data model and Laravel service contract for making Atlas SDD executable and auditable.
tags:
  - atlas-ai
  - sdd
  - data-model
  - laravel
capabilities:
  - spec_operating_system
  - sdd_core
  - evidence_ledger
decisions:
  - SDD artifacts must be stored as structured records, not only Markdown.
  - Specs, requirements, acceptance criteria, tasks, receipts and evidence must be traceable by operation.
  - Laravel services may orchestrate SDD only through Kernel, receipts and gates.
maintenance:
  - Update before adding SDD migrations, repositories, services or runtime execution.
  - Keep schemas compatible with Evidence Ledger and Spec Graph docs.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/spec-operating-system/spec-graph-and-traceability.md
  - docs/engineering-knowledge-base/spec-operating-system/plan-task-and-receipt-contract.md
  - docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md
owner: atlas-ai
layer: 0.7-and-programming
line_limit: 240
---

# Atlas Spec Operating System Data Model And Services

Atlas SDD becomes enterprise only when every artifact is queryable, versioned and
traceable. Markdown is useful for humans, but runtime SDD must store structured
records.

## Core Tables

Minimum internal model:

```text
atlas_operations
- id
- tenant_id
- user_id
- project_id
- raw_input
- interpreted_intent
- status
- risk_level
- created_at

atlas_specs
- id
- operation_id
- project_id
- title
- type
- status
- version
- risk_level
- content_json
- content_markdown
- created_at
- approved_at

atlas_requirements
- id
- spec_id
- code
- text
- priority
- status

atlas_acceptance_criteria
- id
- requirement_id
- code
- given
- when
- then

atlas_assumptions
- id
- spec_id
- text
- confidence
- blocking
- evidence_json

atlas_plans
- id
- spec_id
- content_json
- status

atlas_tasks
- id
- plan_id
- code
- title
- type
- status
- depends_on_json
- allowed_files_json

atlas_decision_receipts
- id
- operation_id
- spec_id
- autonomy_level
- allowed_actions_json
- forbidden_actions_json
- required_gates_json
- signed_at

atlas_evidence_events
- id
- operation_id
- type
- payload_json
- hash
- created_at

atlas_spec_traceability
- id
- spec_id
- requirement_id
- acceptance_criteria_id
- task_id
- file_path
- test_path
- evidence_event_id
```

## Required Questions

The model must answer:

- which request produced this change;
- which requirement justified this file edit;
- which acceptance criterion this test proves;
- which Decision Receipt authorized the action;
- which evidence event proves the gate result;
- which assumptions were active at execution time;
- whether code, tests and spec drifted after implementation.

## Laravel Service Contract

The canonical orchestration shape:

```php
final class AtlasSddPipeline
{
    public function run(OperationEnvelope $envelope): AtlasOutput
    {
        $intent = $this->intentRouter->route($envelope);

        $context = $this->contextBuilder->build(
            envelope: $envelope,
            intent: $intent,
        );

        $spec = $this->specCompiler->compile(
            envelope: $envelope,
            context: $context,
        );

        $critique = $this->specCritic->review($spec, $context);

        if ($critique->hasBlockingQuestions()) {
            return AtlasOutput::needsClarification($critique->questions());
        }

        $plan = $this->planCompiler->compile($spec, $context);
        $tasks = $this->taskCompiler->compile($plan);

        $receipt = $this->decisionEngine->createReceipt(
            envelope: $envelope,
            spec: $spec,
            plan: $plan,
            tasks: $tasks,
            context: $context,
        );

        $execution = $this->runtimeExecutor->execute($receipt);
        $gates = $this->qualityGateRunner->run($receipt, $execution);

        if (! $gates->passed()) {
            return $this->repairLoop->repair($receipt, $execution, $gates);
        }

        $this->evidenceLedger->append($receipt, $execution, $gates);
        $this->learningSignals->proposeIfUseful($receipt, $execution, $gates);

        return $this->outputRenderer->render($receipt, $execution, $gates);
    }
}
```

## Service Boundaries

| Service | Responsibility |
|---|---|
| Intent Router | Classify request, domain, risk and required harness. |
| Context Builder | Build context pack from docs, repo, specs, memory and code intelligence. |
| Spec Compiler | Produce operational spec, assumptions and acceptance criteria. |
| Spec Critic | Find ambiguity, missing rules, design conflicts and security risk. |
| Plan Compiler | Convert spec into technical approach. |
| Task Compiler | Produce ordered, scoped tasks with dependencies and allowed files. |
| Decision Engine | Create receipt with actions, tools, files, gates and rollback. |
| Runtime Executor | Execute only inside receipt boundaries. |
| Quality Gate Runner | Run required tests, linters, type checks and audits. |
| Repair Loop | Repair only failures inside receipt scope. |
| Evidence Ledger | Append diff, tests, gates and traceability proof. |
| Learning Signals | Propose template/policy updates without auto-changing critical behavior. |

## Prohibitions

- No controller or worker may bypass Decision Receipt for SDD execution.
- No direct write to code outside receipt scope.
- No SDD runtime may treat generated Markdown as authoritative without matching structured record.
- No failed gate may be hidden by changing the spec after execution.
- No learning proposal may mutate core policy without review.
