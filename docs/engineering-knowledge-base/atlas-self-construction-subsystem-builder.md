---
id: atlas-self-construction-subsystem-builder
type: engineering_knowledge
title: Atlas Self-Construction Subsystem Builder
status: active
implementation_state: runtime_active_scorecard_registered_promotion_pipeline_guarded
blocker: ASCB service, commands and tests exist and are registered in ACOS; production self-programming remains blocked because proposals require approval and staging/promotion are separate governed steps.
category: self-construction
priority: 86
summary: Runtime canonico proposal-only que detecta gaps reais do scorecard Self-Construction, gera envelopes deterministas de proposta e exige aprovacao humana antes de qualquer staging ou merge.
tags: [atlas-ai, self-construction, proposal-builder, governance, human-approval]
capabilities: [gap_detection, subsystem_proposal_envelope, proposal_approval_receipt, dry_run_outline_generation]
decisions:
  - Self-Construction may propose subsystem outlines, but must not merge or enable runtime without operator approval.
  - Gap detection must come from scorecard/runtime evidence, not invented by provider output.
  - ASCB is scorecard-registered as code/doc ready with promotion pipeline guarded; this is not permission for autonomous source-tree writes.
maintenance:
  - Update when ASCB scorecard row, staging executor, approvals, schemas or command behavior change.
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
graph_status: active
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-construction-subsystem-builder.md
depends_on: [atlas-ai-self-construction-os, atlas-canonical-cleanup-inventory]
flows_to: [atlas-ai-self-construction-os]
unlocks: [self_construction_subsystem_proposals, governed_outline_generation]
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
  - Provar pipeline ready no ACOS somente quando proposal -> approval -> staging -> human promotion flow tiver evidence completa.
  - Integrar ASCB ao readiness/architecture gates sem permitir source-tree write automatico.
  - Manter Doctor 3-Tier, append-only receipts e human approval.
allowed_changes:
  - Refine proposal contracts, schemas and safety gates while preserving proposal-only behavior.
forbidden_changes:
  - Claim autonomous source-tree write readiness from proposal/staging evidence alone.
  - Let automation merge production code or migrations without operator receipt.
requires_evidence: true
risk_level: high
line_limit: 520
---

# Atlas Self-Construction — Subsystem Builder

> **Status**: runtime active; scorecard registered; promotion pipeline guarded
> **Authority**: ACOS · Patamar 3 (Sovereign Cognitive Substrate)
> **Schema**: `atlas.self_construction.subsystem_proposal.v1`
> **Runtime**: `App\Services\Ai\SelfConstruction\AtlasSelfConstructionSubsystemBuilderService`
> **Owner**: this doc is canonical context. Source-tree mutation still requires separate staging/promotion governance.

## Resumo

Esta doc descreve o runtime canonico proposal-only do Self-Construction
Subsystem Builder. O objetivo e gerar propostas auditaveis para novos subsystems
a partir de gaps reais, sem permitir merge automatico de codigo.

## Papel no Atlas

O papel do ASCB e ser uma fabrica de propostas, nao uma fabrica de runtime
auto-promovido. Ele pode sugerir outlines de service/doc/test e receipts, mas a decisao
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
5. Consumir outline artifacts apenas por workflow normal de git/testes/gates.

## Regras para IA

- Nao declarar ASCB promovido no ACOS scorecard sem owner decision.
- Nao declarar Self-Construction pronto por existir outline/staging artifact.
- Nao inventar gaps sem evidence de scorecard, doc ou operador.
- Nao gerar merge automatico de codigo de producao.

## Escopo de Implementacao

Escopo implementado: service proposal-only, comandos detect/propose/approve/list,
receipt logs append-only, hash deterministico, Doctor 3-Tier e testes de
provider-safety, idempotencia e aprovacao humana. Escopo ainda guardado:
prova pipeline-ready completa do fluxo proposal -> approval -> staging ->
promocao humana.

## Dependencias

Depende de Atlas Self-Construction OS, scorecard ACOS, cleanup inventory,
Evidence Ledger/receipts e politica de aprovacao humana.

## Evidencias

Evidencia atual: service proposal-only, comandos detect/propose/approve/list,
testes unitarios/feature, reachability high e registro no scorecard ACOS.
Evidencia ainda exigida: pipeline-ready completo e integracao architecture/readiness
sem source-tree write automatico.

## Riscos

- Confundir outline/staging artifact com implementacao.
- Permitir self-programming sem operador.
- Criar subsystem paralelo por gap inventado.
- Declarar scorecard ready sem runtime.

## Exemplos

Um gap `missing_service_class` pode gerar proposta com outlines de service, doc
e test. O operador aprova ou rejeita; nada e mergeado sozinho.

## Proximas Acoes

1. Fechar pipeline-ready completo com staging/promotion governance.
2. Integrar architecture/readiness gates.
3. Manter Doctor 3-Tier e append-only receipts.
4. Nao habilitar merge automatico.

## 1. Why this exists

Self-Construction OS had proposal contracts but had not produced a new ACOS
subsystem. This is the **primeira prova** that Atlas constructs Atlas: detect
gap → propose subsystem → generate outline artifacts → emit canonical
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
  "outline_artifacts": {
    "service_outline": "PHP code text — class outline only, no real logic",
    "doc_outline": "Markdown text — section headings only",
    "test_outline": "PHPUnit class outline"
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
git workflow consuming the outline strings.

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

Current scorecard row:

```
['ASCB', 'Self-Construction Subsystem Builder', 'self_construction',
 AtlasSelfConstructionSubsystemBuilderService::class, 'ready', 'ready', 'building']
```

Scorecard pipeline remains `building`; do not claim autonomous self-programming
readiness until staging, promotion and architecture gates are fully proved.

## 10. Later governed evolution

- **Auto-PR generation** — once the operator trusts the proposal pattern, outline strings can drive a branch+PR via a separate human-supervised pipeline.
- **Proposal critique loop** — second pass that critiques its own proposals using the Cognitive Immune G6 replay machinery.
- **Cross-proposal coherence** — when multiple proposals overlap, surface conflicts before approval.
