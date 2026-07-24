# Full-Pass FILE-BY-FILE — LEDGER

## State

```yaml
program: atlas-server-full-pass-file-by-file
plan: docs/superpowers/plans/2026-07-24-atlas-server-full-pass-FILE-BY-FILE.md
branch: main
execution: in_progress
started: 2026-07-24
inventory_files: 13399
file_receipts_corpus_wide: not_complete
hours_note: continuous hygiene batches on main; corpus-wide pending remains
next_item: MCP tools catalog extract OR PipelineRunExecutor Http exit OR Readiness peel collapse
```

## Batches landed (scoped commits, real code)

| Theme | Areas | Proof |
|---|---|---|
| LoadsFactsJsonOption + GitWorkspaceStateReader + loop config honesty | reuse, operate_vs_legacy, honesty | unit green |
| ResolvesSilentJsonOption + MemoryLimitBytes + OpenBrain intOpt | reuse, standardize | unit green |
| ResolvesGitProjectRoot (CLI start/continue/interrupt/dev) | reuse, surface_std | unit green |
| CliInvocationModel + ParsesKeyValueMetadataOption | reuse | unit green |
| MemoryEntrySafetySummary + CodeGraphIntOrNull | reuse, honesty | unit green |
| Benchmark git shape + ReadsNonEmptyStringOption | reuse | unit green |
| ControlPlaneStatusSection (Cyber/Strategy) | reuse, architecture | unit green |
| AcosDeltaSeriesJsonl | reuse | unit green |
| SchemaVersionedJsonBlockParser + DiskJsonIndexLoader | reuse | unit green |

## Next queued (FINDINGS P0 residual)

1. density: AtlasOpenBrainMcpService::tools catalog (1084L)
2. architecture: PipelineRunExecutor leave Http
3. density: EnterpriseReportDashboardHtml::render
4. density: Readiness PartN / OneShotTick registry
5. elevate: AiWorker::completeAttempt
6. config: physical split atlas.php / loop legacy file
7. surface: thin Aaeos/Mother commands + god tests

## Floors blocked

- Session RSI-core, Evidence LedgerReplay redesign, Readiness probe hub, keep-list AtlasLoop*

## Anti-goodhart

No vanity residual-pass commits. Each batch = code + unit proof.
