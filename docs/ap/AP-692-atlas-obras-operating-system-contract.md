# AP-692 Atlas Obras Operating System Contract

Status: proposed
Owner: atlas-ai
Area: obras
Risk: high

## Problem

Atlas has memory, documentation, governance, SDD, Evidence, Postgres and
Obsidian/Vault, but still needs a product primitive that organizes long work
into validated deliverables. Chats, notes, tasks and folders are too weak for
TCCs, books, strategic plans, technical architectures, products or the
construction of Atlas itself.

## Goal

Document Obras as the Atlas layer that transforms intention into validated,
traceable and compounding assets.

Obras must define the full maturity ladder from a basic screen to a sovereign
operating system:

- L0 Tela Obras;
- L1 Workspace Vivo;
- L2 Obras Enterprise;
- L3 ObraOS;
- L4 Atlas Foundry;
- L5 Atlas Sovereign OS.

## Non Goals

- No runtime implementation in this AP.
- No database migration in this AP.
- No replacement of Documentation OS, Evidence Ledger, Obsidian/Vault,
  Postgres, SDD, APs or Self-Construction OS.
- No TCC-only product scope.
- No folder-only architecture.

## Required Docs

- `docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md`
- `docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md`
- `docs/engineering-knowledge-base/obras/patamares-l0-l5.md`
- `docs/engineering-knowledge-base/obras/contracts-and-invariants.md`
- `docs/engineering-knowledge-base/obras/data-model-and-production-graph.md`
- `docs/engineering-knowledge-base/obras/ai-harness-governance-and-quality.md`
- `docs/engineering-knowledge-base/obras/product-ux-and-use-cases.md`
- `docs/engineering-knowledge-base/obras/metrics-risks-and-excellence.md`
- `docs/engineering-knowledge-base/obras/implementation-roadmap.md`

## Acceptance Criteria

- Docs define Obras as a transversal production primitive, not a TCC feature.
- Docs define Obras Shared Workspace as the canonical shared production office
  for multi-provider work, and Forge Workspace as its Programming/Atlas Forge
  specialization.
- Docs define the official short and strong definitions of Obra.
- Docs preserve all L0-L5 maturity levels and their completion criteria.
- Docs define implementation invariants, MVP acceptance, anti-patterns and
  level promotion rules.
- Docs explain how Obras complements, not replaces, docs, memory, Postgres,
  Obsidian/Vault, APs, governance and Evidence Ledger.
- Docs define Obra as a production graph, not a folder of Markdown files.
- Docs define the five implementation layers: Product UI, Core Backend, Atlas
  AI Harness, Execution Runtime and Governance.
- Docs define required entities, relationships, lifecycle states, outputs and
  metrics.
- Docs preserve Foundry examples, Strategic Scoring criteria, spin-off
  examples, kill/pause/scale decisions and Sovereign Strategic Review
  questions.
- Docs define product UX, example cards, workspace sections, quick actions and
  concrete validation cases including TCC and Atlas Self-Construction OS.
- Docs define risks, mitigations, excellence criteria and maturity claim rules.
- Docs define Universal Quality Gates and per-level quality gates.
- Docs define ObraOS agentic flow from intention to delivery.
- Docs define Foundry portfolio strategy and Sovereign autonomy governance.
- Docs define implementation order from foundation to Sovereign OS.

## Validation

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
git diff --check
```
