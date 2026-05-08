---
title: AP-177 Architecture Readiness MCP Tool
status: implemented
layer: open-brain
owner: memory_open_brain
line_limit: 220
related_paths:
  - app/Services/Ai/AtlasOpenBrainMcpService.php
  - app/Services/Ai/Kernel/Architecture/AtlasArchitectureReadinessService.php
  - app/Services/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalog.php
  - app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php
  - tests/Feature/Ai/AtlasOpenBrainMcpServiceTest.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasArchitectureOperationsCatalogTest.php
---

# AP-177 Architecture Readiness MCP Tool

## Objective

Expose Architecture Readiness to Open Brain/MCP so any external AI session can
ask Atlas whether the mother-architecture is safe to extend before writing code.

## Why This Exists

AP-176 created the canonical readiness snapshot for CLI/API. AP-177 makes the
same contract reachable by MCP without shelling commands, copying runbooks or
recreating governance logic inside agents.

This closes the "new AI session starts blind" failure mode.

## Tool Contract

MCP tool:

```text
atlas_architecture_readiness
```

Input schema:

- `workspace`: optional local workspace path used by provider projection checks;
- `owner`: optional documentation owner, for example `kernel_architecture`,
  `programming_domain` or `memory_open_brain`.

Output:

- `ok`: true only when readiness status is `ready`;
- `tool`: `atlas_architecture_readiness`;
- `architecture_readiness`: payload `atlas.architecture_readiness.v1`;
- `writes`: false.

## Authority

The tool must call:

```php
AtlasArchitectureReadinessService::snapshot()
```

It must not duplicate checks from:

- `AtlasAiArchitectureValidationService`;
- `AtlasDocumentationSplitPlanService`;
- `AtlasProviderProjectionService`;
- `AtlasArchitectureOperationsCatalog`.

## Catalog Metadata

`AtlasArchitectureOperationsCatalog` must declare:

```php
'mcp_tool' => 'atlas_architecture_readiness'
```

for operation id `architecture_readiness`. This lets `atlas_architecture_operations`
teach agents that the direct MCP tool exists.

## Non-Goals

- No writes.
- No migrations.
- No docs sync.
- No code indexing.
- No provider projection write.
- No replacement for `atlas_session_bootstrap` or `atlas_feature_placement`.

## Definition Of Done

- `AtlasOpenBrainMcpService::tools()` lists `atlas_architecture_readiness`.
- `tools/call` routes to `architectureReadiness()`.
- The tool returns `writes=false`.
- `atlas_capabilities` includes the tool in inventory.
- `AtlasArchitectureOperationsCatalog` maps the operation to the MCP tool.
- Feature tests cover the full MCP snapshot.
- Catalog tests lock the `mcp_tool` metadata.
- Static scan key `ap177_architecture_readiness_mcp_tool` protects the contract.
