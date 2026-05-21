---
id: atlas-ai-sdd-autonomy-clarification-policy
type: engineering_knowledge
title: Atlas SDD Autonomy And Clarification Policy
status: active
category: policy
priority: 99
summary: Autonomy levels and ask/act/block policy for Atlas Spec Operating System.
tags:
  - atlas-ai
  - sdd
  - autonomy
  - clarification
capabilities:
  - sdd_autonomy_policy
  - clarification_gate
decisions:
  - Atlas should avoid unnecessary questions when context is sufficient.
  - Atlas must ask or block when ambiguity can cause wrong or unsafe implementation.
maintenance:
  - Update when autonomy policy or model routing changes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-spec-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-sdd-autonomy-clarification-policy

graph_title: Atlas SDD Autonomy And Clarification Policy

graph_world: atlas

graph_layer: gear

graph_kind: policy

graph_parent: atlas-ai-self-construction-os

graph_status: active

graph_source: repo
human_name: Atlas SDD Autonomy And Clarification Policy
canonical_name: Atlas SDD Autonomy And Clarification Policy
technical_name: atlas-ai-sdd-autonomy-clarification-policy
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/spec-operating-system/autonomy-and-clarification-policy.md

owner: spec-operating-system

repo_paths:
  - docs/engineering-knowledge-base/spec-operating-system/autonomy-and-clarification-policy.md

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
  - docs/engineering-knowledge-base/spec-operating-system/autonomy-and-clarification-policy.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - gear
  - policy
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
# Atlas SDD Autonomy And Clarification Policy

## Autonomy Levels

| Level | Meaning | Use |
|---|---|---|
| L0 manual | Spec/plan only | critical systems, unclear risk |
| L1 assisted | Human approves implementation | medium/high risk |
| L2 auto_patch | Atlas creates scoped patch | low-risk localized change |
| L3 auto_pr | Atlas opens PR with evidence | low/medium risk with gates |
| L4 restricted merge | Future only, very low-risk | docs/copy/tests after metrics |
| L5 proposal-only learning | Improve templates/policy by proposal | self-improvement |

## Ask / Act / Block

Act when:

- target context is known;
- business object is clear;
- design/API rules are known;
- risk is low/medium;
- gates can run.

Ask when:

- target screen/file is unknown;
- business object is ambiguous;
- security/permission rule is unclear;
- design system conflicts with user wording;
- multiple valid interpretations exist.

Block when:

- request bypasses validation/security;
- asks to modify critical policy/runtime without AP;
- demands hardcoded behavior against architecture;
- requires destructive action without explicit approval.

## Scheduled Work Error Hygiene

Long-running scheduled work may persist local status, output paths and delivery
state for operator review. Error metadata must be provider-safe: exception
messages are redacted before being written to `ai_scheduled_tasks.metadata`, and
rendered output files are redacted before storage.

Scheduler failure metadata is evidence, not authority. It must not expose API
keys, bearer tokens, cookies, private keys or raw provider credentials, and it
does not authorize retries, provider changes, notification delivery or task
mutation beyond the guarded scheduled-run contract.

Each scheduled execution persists `metadata.last_run_receipt` with schema
`atlas.scheduled_task_run_receipt.v1` plus `last_run_receipt_hash`. The receipt
is hash/pointer-only: it stores prompt, title, schedule, workspace, output and
error hashes, not raw prompt/output/workspace paths. It also states the autonomy
closures for background work: anti-recursion guarded, tool permissions read-only,
provider changes closed, retry authorization closed and schedule mutation closed.
`atlas:ai:long-running-work-report --json` projects each recent run's autonomy
contract as schema, hash and boolean safety flags only, including unsafe status,
single-run guard, review requirement, schedule mutation, recursion and follow-up.
It also returns `atlas.long_running_work.baseline_contract.v1` with the minimum
structure-mother schedule families and fail-closed execution authority. An empty
schedule table is reported as `warning/no_long_running_work_schedule_declared`
so operators do not mistake a quiet scheduler for working autonomy.
`--emit-baseline-inbox` turns that warning into a proposal Inbox item with only
discussion/review authority; it must not create schedules or dispatch jobs.
`atlas:ai:long-running-work-declare-baseline --apply --json` is the only
allowed baseline write path in this phase. It creates missing Structure Mother
schedule declarations disabled by default, with `next_run_at=null`, no dispatch,
no workspace path exposure, no runtime/provider authority and operator
enablement required before execution.

## Resumo

Autonomy levels and ask/act/block policy for Atlas Spec Operating System.

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
