# Full-Pass FILE-BY-FILE — LEDGER

## State

```yaml
program: atlas-server-full-pass-file-by-file
plan: docs/superpowers/plans/2026-07-24-atlas-server-full-pass-FILE-BY-FILE.md
branch: main
execution: in_progress
started: 2026-07-24
hours_note: continuous full-pass hygiene batches on main
inventory_files: 13399
pending_full_file_receipts: still_open_corpus_wide
```

## Batches landed (scoped commits)

| Commit focus | Areas | Proof |
|---|---|---|
| LoadsFactsJsonOption + GitWorkspaceStateReader + loop config honesty | reuse, operate_vs_legacy, honesty | unit tests green |
| ResolvesSilentJsonOption + MemoryLimitBytes + OpenBrain intOpt | reuse, standardize | unit tests green |
| ResolvesGitProjectRoot (CLI) | reuse, surface_std | unit tests green |
| CliInvocationModel + ParsesKeyValueMetadataOption | reuse | unit tests green |
| MemoryEntrySafetySummary + CodeGraphIntOrNull | reuse, honesty | unit tests green |

## Next queued (from FINDINGS P0)

1. MCP `tools()` catalog extract (density) — large/risky; stage carefully
2. Readiness peel collapse / OneShotTick registry
3. PipelineRunExecutor out of Http
4. EnterpriseReportDashboardHtml::render split
5. AiWorker completeAttempt peel
6. config/atlas.php physical split (ai/aobg/loop quarantine file)

## Floors blocked (do not force)

- AutonomousEvolutionSession RSI-core redesign (operator-present)
- Evidence LedgerReplay API redesign
- Readiness probe-mechanism hub redesign
- Prefix-delete AtlasLoop* keep-list

## Anti-goodhart

No vanity residual-pass commits. Each batch is real code + unit proof.
