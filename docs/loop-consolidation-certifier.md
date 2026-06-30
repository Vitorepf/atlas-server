# Loop Consolidation Certifier

CLI gate that proves the AutonomousEvolution subtree is monotonically shrinking.

## Usage

```bash
php artisan atlas:loop:self-architecture:certify
php artisan atlas:loop:self-architecture:certify --baseline=docs/loop-self-architecture-baseline.json
```

Exit 0 = certified (all axes strictly decreased vs baseline).
Exit 1 = not certified or baseline missing/malformed.

## Axes (FACT, not score)

| Axis | What it measures |
|------|-----------------|
| totalLoc | Non-blank lines in `app/Services/Ai/AutonomousEvolution/**/*.php` |
| refillerLoc | Non-blank lines in `AtlasLoopQueueRefiller.php` specifically |
| edgeCount | Count of `use App\Services\Ai\AutonomousEvolution\*` imports across all files |
| cycleCount | Strongly-connected components (Tarjan) with >1 node in the dependency graph |

All four must be strictly lower than baseline to certify.

## Components

- `AtlasLoopSelfArchitectureScanner` — walks the subtree, emits per-file LOC + edges
- `AtlasLoopSelfDependencyGraphReporter` — builds directed graph from edges, runs Tarjan SCC
- `AtlasLoopConsolidationCertifier` — compares current snapshot vs baseline JSON
