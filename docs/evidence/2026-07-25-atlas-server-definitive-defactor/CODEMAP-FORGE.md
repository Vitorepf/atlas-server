# CODEMAP — Forge homes (D7 PATH_CORE)

## Canonical clusters (live)

| Cluster | Location | Role |
|---|---|---|
| Programming Forge OS | `app/Services/Ai/Programming/AtlasForge*.php` + `Programming/Forge/` | intake, long-horizon, kanban, elite adapter |
| HTTP surfaces | `AtlasCodeForge*Controller` | thin HTTP |
| CLI | `AtlasForge*Command` | operator |
| Kernel adapter | `EngineeringKernel/Adapters/AtlasForgeGateAdapter` | mode gate |
| Programming Kernel | `Programming/Kernel/AtlasForgeHandoffAdapter` | handoff |

## Residual dual / doc-only

- PRE `@see` for Hermes execution → use `PipelineRun/ProviderExecutionSection` (fixed soft deps)
- Zero-ref kill requires automated unused class census (next slice)

## Policy

New Forge capability code **must** land under `Programming/` or explicit CODEMAP amendment — not new `Ai/Forge` twin.
