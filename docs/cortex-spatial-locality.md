# Cortex Spatial Locality CLI

`atlas:loop:cortex:spatial` is the operator's read-only observability surface over the
three spatial reporters and their persisted history.

## Modes

| Mode | Effect |
| --- | --- |
| `fs` | Invokes `AtlasCortexFsLocalityReporter`. One record per (file, depth) with FQCN neighbor lists. |
| `callgraph` | Invokes `AtlasCortexCallGraphLocalityReporter` over the latest adjacency snapshot. |
| `intersect` | Invokes `AtlasCortexLocalityIntersectionEmitter` over fs ∩ callgraph. |
| `history --fqcn=FQCN --limit=N` | Tails the most recent N JSONL records under `storage/atlas/cortex/spatial/{fs,callgraph,intersection}/`, filtered by FQCN substring. |

## Anti-Goodhart

The CLI emits FACTS only. It never proposes a change, never mutates code, never emits a
score, severity, or recommendation. Receipts go through the existing reporter JSONL
spools; the CLI is purely a reader + invoker.

## Master switch

When `ATLAS_LOOP_MASTER_ENABLED=false`, every mode is a byte-identical no-op: exit 0 with
the literal message `loop master switch is OFF — spatial cortex disabled` and zero bytes
written under `storage/`.
