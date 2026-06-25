---
id: atlas-ai-self-construction-runtime-implementation-roadmap-waves
type: engineering_knowledge
title: Atlas Self-Construction Runtime Implementation Roadmap - Wave Catalog
status: active
category: architecture
priority: 97
summary: Detailed phase / wave catalog for the Self-Construction runtime implementation roadmap.
tags:
  - atlas-ai
  - self-construction
  - roadmap
  - waves
capabilities:
  - self_construction_runtime_implementation_roadmap_wave_catalog
decisions:
  - Wave catalog lives in its own doc so each implementation slice is readable without loading the full roadmap.
  - Parent doc stays compact; wave-level detail must only live here.
  - Promoting a wave still requires the parent roadmap Phase Gate (tests + docs + evidence + architecture validation + residual risk).
maintenance:
  - Update only when a phase or wave changes; keep parent roadmap in sync with the same change.
related_paths:
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md
owner: atlas-ai
layer: 0.8-self-construction
line_limit: 600
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-self-construction-runtime-implementation-roadmap-waves

graph_title: Atlas Self-Construction Runtime Implementation Roadmap - Wave Catalog

graph_world: atlas

graph_layer: gear

graph_kind: module

graph_parent: atlas-ai-self-construction-runtime-implementation-roadmap

graph_status: active

graph_source: repo
human_name: Atlas Self-Construction Runtime Implementation Roadmap - Wave Catalog
canonical_name: Atlas Self-Construction Runtime Implementation Roadmap - Wave Catalog
technical_name: atlas-ai-self-construction-runtime-implementation-roadmap-waves
cartography_type: module
canonical_source: docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap-waves.md

repo_paths:
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap-waves.md

allowed_changes:
  - Atualizar este doc quando uma fase ou onda mudar; manter o pai sincronizado.

forbidden_changes:
  - Declarar fase ou onda como pronta sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-self-construction-runtime-implementation-roadmap

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - self-construction

evidence:
  - docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap-waves.md
evidence_refs:
  - symbol: AtlasRuntimeImplementationRoadmapService
  - command: atlas:aaeos:runtime-implementation-roadmap

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - gear
  - module
  - self-construction
  - waves

ai_entrypoints:
  - Use as fases (Phase 1 ... Phase 10) como contexto detalhado quando estiver implementando uma slice especifica.

ai_usage_notes:
  - Cada fase carrega entregaveis e gates; promover uma fase exige todos eles + o Phase Gate do roadmap pai.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Catalogo de fases divergente do roadmap pai ou do codigo real.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter sincronizado com o roadmap pai e com a implementacao real.
---
# Atlas Self-Construction Runtime Implementation Roadmap - Wave Catalog

This document holds the **detailed phase / wave catalog** for the Self-Construction
runtime implementation roadmap. The parent overview lives in
[runtime-implementation-roadmap.md](runtime-implementation-roadmap.md) and contains
the phase goals, gate summary and pointers; this document carries the per-phase
deliverables. Splitting the catalog keeps each implementation slice readable
without forcing workers to load the full roadmap for a single wave.

Promoting any wave still requires the parent roadmap's [Phase Gate](runtime-implementation-roadmap.md#phase-gate).

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

## Phase 1.5 - Government Architecture Lock

Deliver:

- canonical `Atlas Autonomous Engineering Government` doc;
- explicit position of Loop as Autopoiesis / Evolution Engine;
- authority boundaries for Cortex, Strategy, Architecture, Task Fabric,
  Maestro, Workers, Verification Court, Merge Governor and Learning Transfer;
- multi-project stewardship contract;
- bridges from legacy Loop/AAEL/task-serving docs to the new parent.

Goal:

```text
Any AI can understand that final 24/7 autonomy is a separation-of-powers OS,
not the old Loop monolith.
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

## Phase 4.2 - Task Fabric And Maestro Contract

Deliver:

- packet schema for objective, owner scope, allowed/forbidden files,
  dependencies, wave, risk class, expected gates, rollback and blocker policy;
- structured give-back classes;
- servable-vs-claimable health model;
- worker affinity ledger;
- dead-prereq repair/cancel/reorigin decision path;
- token-waste metrics.

Goal:

```text
Atlas can turn architecture into small, self-sufficient, schedulable work
without giving workers authority over scope or final verification.
```

## Phase 4.4 - Verification Court Contract

Deliver:

- server-side re-run policy by task risk class;
- anti-Goodhart checks before and after worker execution;
- worker output treated as allegation until verified;
- holdout, netdiff, regression, docs and scope gates;
- false-green rejection receipts.

Goal:

```text
Atlas can verify completed packets independently from the worker that authored
them.
```

## Phase 4.6 - Merge / Release Governor Contract

Deliver:

- branch/worktree/lane policy by risk class;
- main/release entry policy;
- rollback command or inverse patch requirement;
- canary and post-merge health sentinel;
- human gate classes for autonomy/security/kernel/provider/memory/merge policy.

Goal:

```text
Atlas can integrate verified work without trusting worker self-report or
shared-main luck.
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

## Phase 6.5 - Learning Transfer Runtime

Deliver:

- Learning Transfer Receipt for every reusable failure/success;
- separation between completion receipts, evidence receipts and learning
  receipts;
- docs/memory/context-pack update path;
- freshness gate before serving workers;
- known failure modes carried into future packets.

Goal:

```text
Atlas can stop repeating operational mistakes and make every resolved task
improve future task quality.
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

## Phase 8 - Atlas 24/7 Stewardship Lane

Deliver:

- one bounded Atlas scope, initially Loop / AutonomousEvolution;
- project objective and value contract;
- dedicated task economy and Maestro lane;
- server-side Verification Court;
- Merge Governor policy;
- learning transfer and knowledge sync;
- 24h supervisor, kill switch, backlog-depth gate and recovery ledger.

Goal:

```text
Atlas can improve one Atlas scope 24/7 with low waste, real value, rollback and
auditable learning.
```

## Phase 9 - External Project Stewardship

Deliver:

- project admission packet;
- repository/workspace boundary;
- project-specific Cortex model;
- project task queue/lane;
- project gates and merge/release policy;
- cross-project memory boundary;
- operator interface for starting, pausing and auditing project stewardship.

Goal:

```text
Atlas can run the same high-leverage continuous engineering system on one
external project without mixing authority with Atlas Self-Construction.
```

## Phase 10 - Multi-Project Engineering Company Runtime

Deliver:

- multiple simultaneous stewardship lanes;
- global budget/risk governor;
- worker allocation across projects;
- shared pattern learning without secret or scope leakage;
- portfolio view of value, risk, throughput and incidents;
- per-project release governors and rollback.

Goal:

```text
Atlas can act like a governed software engineering company: building and
improving multiple products continuously with high quality and speed.
```


## Resumo
Catalogo detalhado de fases para implementacao do runtime de Self-Construction.
## Papel no Atlas
Carrega o conteudo wave-a-wave do roadmap pai, preservando legibilidade.
## Onde Se Encaixa
Filho de runtime-implementation-roadmap.md; usado para slice especifica.
## Contratos
Cada fase carrega entregaveis, gates e evidencias; nenhuma fase pode ser declarada pronta sem o Phase Gate do pai.
## Fluxo
Implementador abre este doc para a fase em foco, valida via Phase Gate do pai e atualiza ambos juntos.
## Regras para IA
Agentes respeitam escopo da slice, evidencias e proibicoes; alteracao de fase sincroniza com o pai.
## Escopo de Implementacao
Edicoes limitadas a este arquivo e ao roadmap pai.
## Dependencias
Depende do roadmap pai e dos contratos Self-Construction canonicos.
## Evidencias
Aceitas incluem docs-health verde, lint-file verde, testes e receipts citados por fase.
## Riscos
Catalogo desatualizado em relacao ao pai ou ao codigo; mitigado por Phase Gate e docs-health.
## Exemplos
Cada fase abaixo serve como exemplo concreto da slice associada.
## Proximas Acoes
Sincronizar com a implementacao real conforme as fases avancam.
