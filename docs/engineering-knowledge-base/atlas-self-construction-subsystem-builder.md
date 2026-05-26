---
id: atlas-self-construction-subsystem-builder
type: engineering_knowledge
title: Atlas Self-Construction Subsystem Builder
status: planned
implementation_state: runtime_candidate_not_scorecard_promoted
blocker: Runtime candidate service and commands exist, but ASCB is not promoted into ACOS scorecard until architecture/gate integration is explicitly approved.
category: self-construction
priority: 86
summary: Proposta canonica para um builder proposal-only que detecta gaps reais do scorecard Self-Construction, gera envelopes deterministas de proposta e exige aprovacao humana antes de qualquer merge.
tags: [atlas-ai, self-construction, proposal-builder, governance, human-approval]
capabilities: [gap_detection, subsystem_proposal_envelope, proposal_approval_receipt, dry_run_scaffolding]
decisions:
  - Self-Construction may propose subsystem scaffolds, but must not merge or enable runtime without operator approval.
  - Gap detection must come from scorecard/runtime evidence, not invented by provider output.
  - This doc cannot mark ASCB ACOS-ready until owner decision and architecture/readiness integration exist.
maintenance:
  - Promote only after owner decision, architecture/readiness integration and scorecard update.
  - Keep proposal generation provider-safe, deterministic and human-approved.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-canonical-cleanup-inventory.md
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract-part-02.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderService.php
  - app/Console/Commands/AtlasSelfConstructionDetectGapsCommand.php
  - app/Console/Commands/AtlasSelfConstructionProposeSubsystemCommand.php
  - app/Console/Commands/AtlasSelfConstructionApproveProposalCommand.php
  - app/Console/Commands/AtlasSelfConstructionListProposalsCommand.php
  - tests/Unit/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderServiceTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionCommandsTest.php
doc_schema: atlas_canonical_module_doc.v1
owner: self-construction
graph_id: atlas-self-construction-subsystem-builder
graph_title: Atlas Self-Construction Subsystem Builder
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-ai-self-construction-os
graph_status: planned
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-construction-subsystem-builder.md
depends_on: [atlas-ai-self-construction-os, atlas-canonical-cleanup-inventory]
flows_to: [atlas-ai-self-construction-os]
unlocks: [self_construction_subsystem_proposals, governed_scaffold_generation]
governs: [subsystem_proposal_envelopes, subsystem_approval_receipts]
evidence:
  - docs/engineering-knowledge-base/atlas-self-construction-subsystem-builder.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderService.php
  - tests/Unit/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderServiceTest.php
  - tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionCommandsTest.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Unit/Ai/SelfConstruction/AtlasSelfConstructionSubsystemBuilderServiceTest.php tests/Feature/Ai/SelfConstruction/AtlasSelfConstructionCommandsTest.php"
next_actions:
  - Decidir se ASCB deve entrar no ACOS scorecard.
  - Integrar o runtime candidate ao readiness/architecture gates se aprovado.
  - Manter Doctor 3-Tier, append-only receipts e human approval.
allowed_changes:
  - Refine proposal contracts, schemas and safety gates before implementation.
forbidden_changes:
  - Claim runtime readiness or ACOS scorecard integration without code and tests.
  - Let automation merge production code or migrations without operator receipt.
requires_evidence: true
risk_level: high
line_limit: 520
---

# Atlas Self-Construction — Subsystem Builder

> **Status**: runtime candidate present; not ACOS-promoted
> **Authority**: ACOS · Patamar 3 (Sovereign Cognitive Substrate)
> **Schema**: `atlas.self_construction.subsystem_proposal.v1`
> **Runtime candidate**: `App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService`
> **Owner**: this doc is canonical context. Scorecard promotion still requires owner decision and architecture integration.

## Resumo

Esta doc transforma uma proposta solta de Self-Construction Subsystem Builder em
contexto canonico planejado. O objetivo e gerar propostas auditaveis para novos
subsystems a partir de gaps reais, sem permitir merge automatico de codigo.

## Papel no Atlas

O papel do ASCB e ser uma fabrica de propostas, nao uma fabrica de runtime
auto-promovido. Ele pode sugerir service/doc/test skeletons e receipts, mas a decisao
de implementar continua com operador e fluxo normal de engenharia.

## Onde Se Encaixa

```text
Self-Construction scorecard/gaps
  -> ASCB proposal envelope
  -> human approval receipt
  -> normal implementation workflow
```

## Contratos

- `atlas.self_construction.subsystem_proposal.v1`
- `atlas.self_construction.subsystem_approval.v1`

## Fluxo

1. Detectar gaps vindos de scorecard ou pedido do operador.
2. Gerar envelope deterministico de proposta.
3. Registrar proposta em JSONL append-only.
4. Exigir aprovacao humana antes de qualquer implementacao.
5. Consumir scaffold apenas por workflow normal de git/testes/gates.

## Regras para IA

- Nao declarar ASCB promovido no ACOS scorecard sem owner decision.
- Nao declarar Self-Construction pronto por existir scaffold.
- Nao inventar gaps sem evidence de scorecard, doc ou operador.
- Nao gerar merge automatico de codigo de producao.

## Escopo de Implementacao

Escopo planejado: service proposal-only, comandos detect/propose/approve/list,
receipt logs append-only, hash deterministico, Doctor 3-Tier e testes de
provider-safety, idempotencia e aprovacao humana.

## Dependencias

Depende de Atlas Self-Construction OS, scorecard ACOS, cleanup inventory,
Evidence Ledger/receipts e politica de aprovacao humana.

## Evidencias

Evidencia atual: service proposal-only, comandos detect/propose/approve/list,
testes unitarios/feature e docs-health limpo. Evidencia ainda exigida antes de
promover no scorecard: owner decision e integracao architecture/readiness.

## Riscos

- Confundir scaffold com implementacao.
- Permitir self-programming sem operador.
- Criar subsystem paralelo por gap inventado.
- Declarar scorecard ready sem runtime.

## Exemplos

Um gap `missing_service_class` pode gerar proposta com service skeleton, doc
skeleton e test skeleton. O operador aprova ou rejeita; nada e mergeado sozinho.

## Proximas Acoes

1. Decidir owner promotion para ACOS scorecard.
2. Integrar architecture/readiness gates se aprovado.
3. Manter Doctor 3-Tier e append-only receipts.
4. Nao habilitar merge automatico.

## 1. Why this exists

Self-Construction OS exists as scaffold but has never built a new ACOS
subsystem. This is the **primeira prova** that Atlas constructs Atlas: detect
gap → propose subsystem → generate scaffolding artifacts → emit canonical
proposal envelope → require human approval before any merge.

The service is intentionally **proposal-only**. It never writes production
code or migrations on its own. It produces a verifiable proposal envelope
that the operator (or a higher-trust automation) consumes.

## 2. Hard invariants

- **No code merge from automation alone** — every proposal carries
  `requires_human_approval=true` until an explicit operator receipt unblocks.
- **Doctor 3-Tier** — CLI is plan/dry-run/apply with `--check` + `--confirm`.
- **Gap detection sourced from scorecard** — never invents gaps.
- **Provider-safe** — proposal never contains operator secrets or sensitive
  domain data.
- **Audit-first** — every proposal appended to JSONL receipt log.
- **Idempotent** — re-running on the same gap produces the same proposal
  hash (deterministic).

## 3. Gap detection

A "gap" is one of:

| Gap kind | Trigger condition |
|---|---|
| `missing_service_class` | Scorecard subsystem row has `code_status=blocked` |
| `partial_canon` | Scorecard `doc_status=partial` |
| `pipeline_not_proven` | Scorecard `pipeline_status` not `ready` |
| `coverage_drift` | `must_keep_coverage` invariant trips |
| `operator_request` | Operator-supplied free-text gap (manual) |

## 4. Proposal envelope schema

`atlas.self_construction.subsystem_proposal.v1`:

```json
{
  "schema_version": "atlas.self_construction.subsystem_proposal.v1",
  "proposal_id": "uuid",
  "generated_at": "ISO-8601",
  "gap": {
    "kind": "missing_service_class | partial_canon | pipeline_not_proven | coverage_drift | operator_request",
    "subsystem_acronym": "AGRN | ... | NEW",
    "subsystem_name": "...",
    "rationale": "free-text"
  },
  "proposed_subsystem": {
    "acronym": "NEWX",
    "name": "...",
    "group": "cognitive_immune|memory_core|aucri|self_improvement|atlas_decide|reality|self_construction|cross_domain|teos",
    "service_class": "App\\Services\\Ai\\...",
    "doc_path": "docs/engineering-knowledge-base/atlas-newx-subsystem.md",
    "schemas": ["atlas.newx.v1"]
  },
  "scaffold": {
    "service_skeleton": "PHP code text — class outline only, no real logic",
    "doc_skeleton": "Markdown text — section headings only",
    "test_skeleton": "PHPUnit class outline"
  },
  "checks": {
    "claim_policy_compliant": true,
    "provider_safe": true,
    "cognitive_immune_law_enforced": true,
    "external_rivals_certification_touched": false
  },
  "requires_human_approval": true,
  "proposal_hash": "sha256:..."
}
```

## 5. Approval receipt schema

`atlas.self_construction.subsystem_approval.v1`:

```json
{
  "schema_version": "atlas.self_construction.subsystem_approval.v1",
  "proposal_id": "...",
  "proposal_hash": "...",
  "action": "approve | reject",
  "actor": "operator",
  "at": "ISO-8601",
  "rationale": "free-text"
}
```

Approval does NOT auto-merge code. It records that the operator endorsed the
proposal. Actual code generation is a separate operator action via standard
git workflow consuming the scaffold strings.

## 6. Persistence

- Proposals: `storage/atlas/self_construction/proposals.jsonl`
- Approvals: `storage/atlas/self_construction/approvals.jsonl`

Both append-only, locally signed by `proposal_hash`.

## 7. Operator workflow

```bash
# Detect gaps from the live scorecard
php artisan atlas:self-construction:detect-gaps --json

# Propose a subsystem for a gap (plan/dry-run/apply Doctor 3-Tier)
php artisan atlas:self-construction:propose-subsystem \
  --gap-kind=missing_service_class --subsystem-acronym=NEWX \
  --mode=apply --check=self-construction-propose --confirm

# Approve a proposal
php artisan atlas:self-construction:approve-proposal \
  --proposal-id=<uuid> --mode=apply --check=self-construction-approve --confirm

# List proposals
php artisan atlas:self-construction:list-proposals --json
```

## 8. Test coverage requirements

- Unit: gap detection, proposal envelope, deterministic hash, claim_policy enforcement.
- Feature: artisan list, propose plan/apply, approve cycle.
- Real-fixture: tests use real `AtlasCognitionScoreCardService` output; no mocked gaps.

## 9. ACOS scorecard integration

When implemented, it may be registered as:

```
['ASCB', 'Self-Construction Subsystem Builder', 'self_construction',
 AtlasSelfConstructionSubsystemBuilderService::class, 'ready', 'ready']
```

Do not update the scorecard until code and tests prove the runtime.

## 10. Future evolution

- **Auto-PR generation** — once the operator trusts the proposal pattern, scaffold strings can drive a branch+PR via a separate human-supervised pipeline.
- **Proposal critique loop** — second pass that critiques its own proposals using the Cognitive Immune G6 replay machinery.
- **Cross-proposal coherence** — when multiple proposals overlap, surface conflicts before approval.
