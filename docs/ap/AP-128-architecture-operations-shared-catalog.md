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
  kernel pipeline, repair, provider performance, self-improvement schedule
  replay, and Inbox action report.
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
