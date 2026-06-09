# Code Graph tests (AP-815)

Feature tests for the Atlas Code Graph: the symbol-level graph built by
`EngineeringCodeIntelligenceService::index()` →
`CodeGraphSymbolBuilder::build($workspaceId)` and persisted into the
`ai_codebase_world_model_*` tables.

## How to run

The code-graph index path clamps its own memory budget and boots several tables,
so run with a raised PHP memory limit:

```bash
# one file
php -d memory_limit=3072M artisan test tests/Feature/CodeGraph/CodeGraphGoldenBaselineTest.php

# the whole code-graph suite
php -d memory_limit=3072M artisan test tests/Feature/CodeGraph
```

These tests boot only the tables they touch in `setUp()` (they do **not** use
`RefreshDatabase`, which is unreliable for this path). The canonical boot pattern
lives in `CodeGraphIndexAllCommandTest.php` — copy it for any new test here. The
table set is:

```
atlas_engineering_doc_links, atlas_engineering_code_symbols,
atlas_engineering_code_modules, atlas_engineering_code_file_snapshots,
ai_codebase_world_model_edges, ai_codebase_world_model_nodes,
ai_codebase_world_models
```

plus, for tests that exercise the current schema, the two newest migrations:

```
2026_06_09_120000_add_composite_indexes_to_ai_codebase_world_model_edges.php
2026_06_09_121000_add_mtime_and_file_hash_to_code_file_snapshots.php
```

All real-edge tests must set `config(['atlas.code_graph.real_edges' => true])`
in `setUp()` — the builder is gated off otherwise and returns `status=disabled`.

## The golden baseline guard — `CodeGraphGoldenBaselineTest.php` (block D2)

This is a **characterization / golden-master** test. It freezes a tiny 6-file
fixture workspace (written to a temp dir in `setUp()` — fully self-contained, no
shared fixture files), indexes it through the real production pipeline, and reads
the resulting graph back from the world-model tables. It then asserts the graph
**shape** against a hard-coded baseline:

| Quantity                | Baseline | Source of truth                    |
| ----------------------- | -------- | ---------------------------------- |
| node count              | 6        | `ai_codebase_world_model_nodes`    |
| edge count              | 5        | `ai_codebase_world_model_edges`    |
| per-edge-type counts    | `depends_on => 5` | `edge_type` column        |
| EXTRACTED edges         | 4        | `metadata.confidence`              |
| INFERRED edges          | 1        | `metadata.confidence`              |
| EXTRACTED : INFERRED    | 0.8      | derived (extracted / total)        |

### Why literal numbers (not "> 0")

The graph is rebuilt on every index. A rebuild can silently **shrink** for the
wrong reasons — a parser regressed, a glob excluded a tree, an extractor crashed
mid-run, import-evidence promotion broke — and the graph still looks "fresh"
while its EXTRACTED edges quietly collapse toward 0 and poison every downstream
context query. Hard-coding the expected counts turns that silent degradation into
a hard failure at the seam. **Do not relax these to `assertGreaterThan(0, ...)`** —
that defeats the entire purpose.

The **key scenario** guarded is a *silent collapse of extracted edges*:
`test_silent_extracted_edge_collapse_is_caught` feeds the live baseline as the
`before` snapshot and a zeroed-edge rebuild as the `after` snapshot into the real
`CodeGraphRegressionDetector::diff()`, and asserts it emits
`graph emptied: edges fell to 0 (was 5)`.

### Characterization note (the legacy is the oracle)

Every edge this fixture produces is `depends_on`. The resolver only emits a
`tests` edge from the `test_targets` bucket, which the parser populates **only**
from a `Symbol::class` reference inside a `tests/` file — *not* from a `use`
import + `new`. So the test file's `use Fixture\Services\UserService;` is graded
as an ordinary EXTRACTED `depends_on` (import evidence), not a `tests` edge. The
test asserts what the code **actually does**, not what the type names imply. If
you believe that grading is wrong, that is a separate bug-fix decision — file it;
do not "fix" the test to assert aspirational behavior.

## Adding a new case

1. Copy the `setUp()` / `tearDown()` table-boot block from
   `CodeGraphIndexAllCommandTest.php` (and add the two newest migrations above if
   your test touches composite edge indexes or snapshot `mtime`/`file_hash`).
2. Set `config(['atlas.code_graph.real_edges' => true])`.
3. Write a small, **frozen** fixture (a temp dir with a `.git/` marker + a few
   `.php` files; keep it deterministic — no clocks, no randomness in the source).
4. Index + build:
   ```php
   $wid = app(CodeGraphWorkspaceIdentity::class)->resolve($fixtureRoot);
   app(EngineeringCodeIntelligenceService::class)->index(['workspace' => $fixtureRoot, 'prune' => true]);
   $build = app(CodeGraphSymbolBuilder::class)->build($wid);
   ```
   (Resolve the workspace id the same way the index path does, so `index()` —
   which resolves internally — and `build($wid)` agree on the scope.)
5. Read the graph back from `ai_codebase_world_model_nodes` /
   `ai_codebase_world_model_edges` filtered by `$build['world_model_id']`.

### Re-baselining the golden test

When a legitimate fixture or parser change moves the golden numbers:

1. Run the test; read the actual values from the assertion-diff output.
2. **Eyeball the new graph** — confirm the change is intended and not a silent
   regression (a drop in EXTRACTED edges is the red flag).
3. Update the `EXPECTED_*` literals at the top of
   `CodeGraphGoldenBaselineTest.php`. The hard-coded constants ARE the deliberate
   human gate — updating them is a conscious decision, recorded in the diff.
