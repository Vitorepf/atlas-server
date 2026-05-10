---
title: AP-176 Architecture Readiness Snapshot
status: implemented
layer: kernel
owner: atlas-ai
line_limit: 220
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasArchitectureReadinessService.php
  - app/Console/Commands/AtlasAiArchitectureReadinessCommand.php
  - app/Http/Controllers/AtlasAiGovernanceController.php
  - app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php
  - tests/Feature/Ai/AtlasAiArchitectureReadinessCommandTest.php
  - tests/Feature/Ai/AtlasAiGovernanceApiTest.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php
---

# AP-176 Architecture Readiness Snapshot

## Objective

Expose one read-only readiness packet that tells any new human or AI session
whether Atlas AI mother-architecture is safe to extend.

## Contract

The snapshot must aggregate existing authorities instead of inventing a new
validator:

- `AtlasAiArchitectureValidationService::payload()`
- `AtlasDocumentationSplitPlanService::plan()`
- `AtlasProviderProjectionService::status('all')`
- `AtlasArchitectureOperationsCatalog::summary()`

The command is:

```bash
php artisan atlas:ai:architecture-readiness --owner=<owner_area> --json
```

The API is:

```http
GET /ai/architecture/readiness?owner=<owner_area>
```

## Output Shape

The payload schema is `atlas.architecture_readiness.v1` and includes:

- `status`: `ready` or `attention`;
- `checks.architecture_validate`;
- `checks.documentation_health`;
- `checks.provider_projection`;
- `checks.architecture_operations`;
- focused `docs_split_plan`;
- `coverage_boundary` from the implemented-vs-scaffold matrix as diagnostic
  read model, never backlog authority;
- `safe_next_blocks` parsed from the matrix for handoff guidance;
- focused `architecture_operations`;
- `architecture_operations.owner_layer_operations.runtime`, exposing
  `runtime_language_boundary` as pre-implementation gate;
- `review_signal.required_next_commands`.

## Non-Goals

- It must not run migrations, sync docs, index code or write provider
  projections.
- It must not replace `architecture-validate`, `docs-health`,
  `docs-split-plan` or provider projection status.
- It must not hide split-required docs; legacy oversized docs remain visible as
  governed debt.

## Definition Of Done

- CLI returns JSON and human summary.
- API requires Atlas token and returns the same contract.
- Architecture Operations Catalog includes `architecture_readiness`.
- Static scan key `ap176_architecture_readiness_snapshot` validates service,
  command, API, catalog, tests and docs.
- `architecture-validate`, focused tests and docs health pass.
