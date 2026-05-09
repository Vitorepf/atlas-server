# AP-128 — Architecture Operations Shared Catalog

## Problem

AP-127 made architecture operations discoverable in `atlas:cli:help`, but the
command list lived directly inside a surface. That can drift from App/API,
Observability, MCP, or future control-plane surfaces.

## Contract

- `AtlasArchitectureOperationsCatalog` is the source of truth for the
  `arquitetura_mae` command list.
- `atlas:cli:help` must consume `sectionKey()` and `commands()`.
- `/ai/observability` must expose `architecture_operations` from `summary()`.
- The catalog must include the core operational commands: architecture
  validation, documentation health, KB sync, Code Intelligence index, SLO,
  kernel pipeline, repair, provider performance, agent behavior report,
  Dynamic Compute Market report, provider performance Curator review, provider
  cost-rate gaps, provider cost-rate human upsert, self-improvement schedule
  replay, and Inbox action report.
- Agent behavior must be exposed as an evidence operation:
  `php artisan atlas:ai:agent-behavior-report --hours=24 --json`.
- Dynamic Compute Market must be exposed as a read-only operation:
  `php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json`.
- Provider performance Curator review must be exposed as proposal-only operation:
  `php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json`.
- Agent behavior Curator review must be exposed as proposal-only operation:
  `php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json`.
- Provider cost-rate operations are part of the same catalog because AP-99 and
  AP-146 depend on humans and agents discovering exactly how to close unknown
  cost evidence:
  - `php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json`
  - `php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json`
- The contract is enforced by static scan key
  `ap128_architecture_operations_shared_catalog`.

## Implementation

- `AtlasArchitectureOperationsCatalog`
- `AtlasCliHelpCommand`
- `AiObservabilityController`
- `AtlasArchitectureOperationsCatalogTest`
- `AiObservabilityKernelSloTest`

## Value

The Atlas AI control plane now has a shared operations catalog. CLI and App/API
surface discovery no longer need to remember the same command list separately,
which keeps future architecture operations visible and consistent.
