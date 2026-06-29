# Complexity hotspots

The codebase has ~7,116 PHP files and ~1.73M lines of application code, written in roughly two months. Some files are very large. This page documents the hotspots so a reader knows what they are encountering.

## The largest files

| File | Lines | Notes |
|------|-------|-------|
| `app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php` | ~52,717 | The largest file in the codebase. Self-construction readiness checks across all organs. |
| `app/Services/Ai/Holding/ExternalActionMandateRegistryService.php` | ~22,989 | External action mandate registry. Lives under Holding/, the company/strategic stack. |
| `app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php` | ~19,333 | Static architecture scanner for the kernel. |

These are large because they aggregate many checks, registry entries, or scan rules into a single service. Some large files in this codebase are fixture or registry-heavy rather than hand-written logic. The `Holding/` directory in particular contains many data-heavy files. File size is not a refactoring priority here. The autonomous loop handles structural evolution, and splitting a file without changing behavior is zero improvement under the anti-Goodhart doctrine.

## The 330 migrations

The database has 330 migrations in `database/migrations/`. This is a high count for a two-month-old codebase, reflecting the rapid pace of development and the autonomous loop adding schema changes as it evolves scopes.

Migrations are idempotent. The convention is never to manually insert rows into the `migrations` table. If the local database diverges, the fix is `migrate:fresh` (rebuild from scratch), not a repair migration.

See [patterns and conventions](../how-to-contribute/patterns-and-conventions.md) for the full DB conventions.

## The 1,227 commands

There are ~1,211 command classes (1,227 signatures including aliases), all under the `atlas:*` namespace. The command prefix taxonomy:

| Prefix | Approximate count | What it is |
|--------|-------------------|------------|
| `atlas:aaeos:*` | ~317 | Atlas Agentic Engineering OS (largest subsystem) |
| `atlas:ai:*` | ~146 | Core AI runtime |
| `atlas:loop:*` | ~141 | Autonomous Evolution Loop |
| `atlas:frontend:*` | ~44 | Frontend engineering surface |
| `atlas:self-construction:*` | ~34 | Self-Construction OS |
| `atlas:task:*` | ~32 | Task fabric / task serving |
| `atlas:cli:*` | ~32 | The CLI command classes |
| `atlas:programming:*` | ~27 | Programming orchestrator surface |
| `atlas:engineering:*` | ~21 | Engineering harness/benchmark/quality |
| `atlas:context:*` | ~21 | Context packs / injection |
| `atlas:brain:*` | ~21 | External brain cycle |

The `atlas:aaeos:*` family is the largest at ~317 commands. This is the Atlas Agentic Engineering OS, which spans mission control, phase handoff, and the HTTP-path facade. The long tail of named subsystems (3-9 commands each) covers reality graph AURG, decide, semantic RAG, voice/vox, foundry, swarm, scheduler, open-brain MCP, and code-graph.

See [command taxonomy](../systems/cli-operator/command-taxonomy.md) for the full map.

## Related pages

- [Dead ends and TODOs](dead-ends-and-todos.md) — probe scripts, legacy subsystems, TODO comments
- [By the numbers](../by-the-numbers.md) — full statistics snapshot
- [Patterns and conventions](../how-to-contribute/patterns-and-conventions.md) — DB and service conventions
- [Command taxonomy](../systems/cli-operator/command-taxonomy.md) — the 1,227 command map
- [Anti-Goodhart and no-proxy](../concepts/anti-goodhart.md) — why file size is not a refactoring priority
