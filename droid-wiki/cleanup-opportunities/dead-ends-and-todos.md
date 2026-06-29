# Dead ends and TODOs

This page documents artifacts that appear to be exploratory, legacy, or incomplete. They are part of the codebase's history. Knowing they exist helps a reader avoid confusing them with active subsystems.

## TODO, FIXME, and HACK comments

Approximately 33 files in `app/` contain `TODO`, `FIXME`, or `HACK` comments. These are scattered across subsystems:

- `app/Services/Ai/AutonomousEvolution/` — the loop (task grinder, harness guard, auto-merge, certifier)
- `app/Services/Ai/SelfConstruction/` — self-construction (detector, loop service, relevance gate)
- `app/Services/Ai/MarketingDomain/` — marketing (keyword knowledge, bridge page composer, playbook)
- `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/` — area focus loop services
- `app/Services/Vault/GraphAssembler.php` — vault graph assembly
- `app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php` — code reality usage

These are markers left during development. Some mark known limitations; others mark future work. They are not necessarily bugs. The autonomous loop may address them as it evolves the relevant scopes.

## Root-level probe scripts

Several PHP scripts sit at the repository root with underscore or `ast_` prefixes:

| File | Purpose |
|------|---------|
| `_probe_nts.php` | Exploratory probe (nature of the system) |
| `_probe_nts2.php` | Follow-up probe |
| `_smoke.php` | Smoke test script |
| `ast_count.php` | AST node counting |
| `ast_sim.php` | AST similarity analysis |
| `ast_sim2.php` | Follow-up AST similarity |
| `ast_struct.php` | AST structural analysis |

These appear to be exploratory scripts written during development. They are not part of the application or test suite. They are not referenced by artisan commands or CI workflows. A reader encountering them should treat them as scratch files, not production code.

## Legacy self-improvement subsystems

Three directories under `app/Services/Ai/` predate the live AutonomousEvolution brain:

| Directory | Status |
|-----------|--------|
| `app/Services/Ai/SelfImprovement/` | Legacy self-improvement subsystem |
| `app/Services/Ai/Rsi/` | Legacy recursive self-improvement |
| `app/Services/Ai/SelfDirectedEvolution/` | Legacy self-directed evolution |

The live autonomous evolution engine lives in `app/Services/Ai/AutonomousEvolution/` (~260 directories, the crown jewel). The legacy directories represent earlier approaches that were superseded. They may still be referenced by older commands or tests, but they are not the active evolution path.

The `atlas:self-improvement:*` command family (~12 commands) may still surface these subsystems. The active loop uses `atlas:loop:*` and `atlas:brain:*` commands instead.

## Related pages

- [Complexity hotspots](complexity-hotspots.md) — the largest files, migrations, and commands
- [Autonomous Evolution Loop](../systems/evolution-loop/index.md) — the active evolution engine
- [By the numbers](../by-the-numbers.md) — codebase statistics
- [Anti-Goodhart and no-proxy](../concepts/anti-goodhart.md) — why cleanup is not a priority
