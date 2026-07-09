# MCP Tools Expansion — Test + Code Policy

> **Status:** Active reference for the MCP expansion plan test+code pairing.
> **Supersedes:** `docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md` (archived plan).
> **Last updated:** 2026-07-09

## Purpose

This document satisfies the test+code policy for the MCP `atlas-open-brain` expansion: every implementation class must have a corresponding test class, and every test class must reference an existing implementation.

## Test + Implementation Pairs

| Test class | Implementation class | Path |
|---|---|---|
| `AtlasOpenBrainMcpBenchmarkTest` | `AtlasOpenBrainMcpService` | `tests/Feature/Ai/AtlasOpenBrainMcpBenchmarkTest.php` ↔ `app/Services/Ai/AtlasOpenBrainMcpService.php` |
| `LedgerReplayServiceTest` | `AtlasLedgerReplayService` | `tests/Unit/Ai/Kernel/LedgerReplayServiceTest.php` ↔ `app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php` |
| `AtlasLedgerReplayCommandTest` | `AtlasLedgerReplayService` | `tests/Feature/Ai/AtlasLedgerReplayCommandTest.php` ↔ `app/Services/Ai/Kernel/Evidence/AtlasLedgerReplayService.php` |

## Notes

- `AtlasOpenBrainMcpBenchmarkTest` was NOT renamed during AOBG consolidation; the class name and file path remain stable at `tests/Feature/Ai/AtlasOpenBrainMcpBenchmarkTest.php`.
- `AtlasLedgerReplayService` is the implementation companion for ledger replay surfaces (SLO, repair, kernel pipeline, decision receipt, agent behavior, inbox action, self-improvement schedule). It is tested by both `LedgerReplayServiceTest` (unit) and `AtlasLedgerReplayCommandTest` (feature).
- The archived plan at `docs/superpowers/plans/2026-05-03-mcp-tools-expansion.md` is historical; this document is the canonical test+code pairing reference.
