---
id: atlas-ai-self-construction-runtime-implementation-roadmap
type: engineering_knowledge
title: Atlas Self-Construction Runtime Implementation Roadmap
status: active
category: architecture
priority: 98
summary: Phased roadmap for implementing Self-Construction OS runtime safely.
tags:
  - atlas-ai
  - self-construction
  - roadmap
capabilities:
  - self_construction_os
  - roadmap
decisions:
  - Runtime begins read-only and advisory before autonomous patching.
  - Each phase promotes one maturity slice with tests and evidence.
maintenance:
  - Update when a phase is implemented or a new runtime slice is approved.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/ap/AP-691-atlas-self-construction-os-contract.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 220
---

# Atlas Self-Construction Runtime Implementation Roadmap

Self-Construction runtime must be phased.

## Phase 1 - Documentation And Registry

Deliver:

- AP-691 docs;
- Knowledge DB sync;
- Code Intelligence index;
- canonical index links;
- maturity labels in docs.

Goal:

```text
Any AI can understand the law of Atlas self-construction.
```

## Phase 2 - Read-Only Gap Report

Deliver:

- command/API that reports target capability, maturity, missing docs, missing
  tests, missing evidence and likely next safe step;
- no code writes;
- focused tests.

Initial command:

```bash
php artisan atlas:ai:self-construction --json
```

Current implementation is CLI read-only/advisory. API exposure, deeper evidence
inspection and traceability scoring remain future slices.

Goal:

```text
Atlas can identify construction gaps without changing itself.
```

## Phase 3 - Meta-SDD Artifact Generator

Deliver:

- service that generates Meta-SDD packet from gap report;
- assumptions ledger;
- priority packet;
- build graph packet;
- human-readable summary.

Initial command:

```bash
php artisan atlas:ai:self-construction --meta-sdd --json
```

Current implementation emits a read-only candidate packet. It does not create a
Decision Receipt, write files or execute tasks.

Goal:

```text
Atlas can turn self-construction gaps into structured specs.
```

## Phase 4 - Receipt-Scoped Task Planner

Deliver:

- plan/tasks for small slices;
- allowed/forbidden files;
- gates and rollback;
- Decision Receipt preview.

Initial command:

```bash
php artisan atlas:ai:self-construction --receipt-preview --json
```

Current implementation emits a preview-only receipt envelope. It does not sign
execution, apply patches, run migrations or enable self-programming writes.

Goal:

```text
Atlas can prepare safe work for agent execution.
```

## Phase 4.5 - Traceability Guardrail

Deliver:

- read-only audit that proves required Self-Construction docs exist;
- root-document reachability for canonical construction artifacts;
- self-construction tag and layer checks;
- focused tests before any maturity promotion.

Initial command:

```bash
php artisan atlas:ai:self-construction --traceability --json
```

Current implementation emits a read-only traceability report. It does not
execute work, sign receipts, mutate docs or promote runtime autonomy.

Goal:

```text
Atlas can prove the construction law is discoverable before executing it.
```

## Phase 4.8 - Promotion Gate

Deliver:

- read-only gate that consolidates readiness, Meta-SDD, receipt preview and
  traceability;
- explicit blocking failures;
- candidate next phase;
- allowed and forbidden first-execution scopes;
- required human review and signed Decision Receipt boundary.

Initial command:

```bash
php artisan atlas:ai:self-construction --promotion-gate --json
```

Current implementation can recommend Phase 5 as a candidate when all read-only
checks pass. It does not sign execution, write files, mutate policy or enable
self-programming.

Goal:

```text
Atlas can decide whether the next construction phase is safe to plan.
```

## Phase 5 - Low-Risk Agent Execution

Deliver:

- docs-only or test-only scoped execution;
- evidence capture;
- drift check;
- no high-risk runtime mutation.

Current read-only Phase 5 surfaces:

| Command | Current implementation |
|---|---|
| `--execution-candidate --json` | Candidate hash, docs/test/report scope, forbidden hot files and evidence boundary. |
| `--approval-packet --json` | Human review checklist, reviewer roles, invariants and decision fields. |
| `--receipt-draft --json` | Unsigned Decision Receipt draft with receipt hash and preview signature. |
| `--execution-preflight --json` | Expected blocked preflight while signature and execution flag are absent. |
| `--signature-request --json` | Signable payload, request hash, signer roles and confirmations. |
| `--execution-runbook --json` | Post-signature ordered steps, stop conditions, evidence, gates and rollback. |
| `--evidence-packet --json` | Required proof template, claim checks and future-run failure policy. |
| `--completion-readiness --json` | Blocks false completion until signed execution evidence exists. |
| `--residual-risk --json` | Classifies remaining blockers before promotion or completion claims. |
| `--handoff-packet --json` | Gives next operator hashes, blockers, commands and forbidden hot scope. |
| `--next-action --json` | Selects the next safe action while execution remains blocked. |
| `--surface-matrix --json` | Lists every command surface, schema and read-only invariant. |
| `--external-blockers --json` | Reports hot-file blockers outside Self-Construction ownership. |
| `--cold-lane-certification --json` | Certifies cold lane status with external blockers separated. |
| `--operator-checklist --json` | Orders the next human/operator review steps without signing or execution. |
| `--promotion-blockers --json` | Consolidates promotion and completion blockers without execution. |
| `--readiness-digest --json` | Emits a compact hashable handoff digest for operators and other AIs. |
| `--governance-scorecard --json` | Scores governed readiness while execution, promotion and completion stay blocked. |
| `--integrity-manifest --json` | Bundles governed packet hashes for audit and handoff integrity checks. |
| `--continuation-token --json` | Emits a compact audited resume token with must-run and must-not-touch constraints. |
| `--ownership-boundary --json` | Declares cold allowed files, hot forbidden scopes and required operator behavior. |
| `--phase-ledger --json` | Summarizes phase status, hard blocks and promotion boundaries. |

Every surface above remains read-only: it does not execute, approve, sign,
persist approval, mark completion or enable self-programming.

Goal:

```text
Atlas can execute low-risk self-construction tasks with evidence.
```

## Phase 6 - Restricted Runtime Patches

Deliver:

- code patching for low/medium risk slices;
- test repair inside receipt scope;
- rollback support;
- proposal-first learning.

Goal:

```text
Atlas can improve its own implementation safely.
```

## Phase 7 - Strategic Self-Construction

Deliver:

- priority engine selects next work;
- repeated-run metrics;
- maturity promotion evidence;
- human review for high-risk changes.

Goal:

```text
Atlas can choose and execute the highest-leverage next construction step.
```

## Phase Gate

No phase may start until the previous phase has:

- tests;
- docs;
- evidence;
- architecture validation;
- explicit residual risk.
