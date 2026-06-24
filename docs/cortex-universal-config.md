# Cortex Universal Config (`cortex.yaml`)

`AtlasCortexUniversalConfigLoader` reads a **per-repo** declarative recipe a foreign repository ships to be cortex-able. It is the input to `AtlasCortexUniversalContract::comprehend()` — the consumer says "comprehend THIS repo using THIS recipe".

## File format

The loader looks at `$repoRoot/cortex.yaml`, then `cortex.yml`, then `cortex.toml`. The supplied YAML may also be JSON (YAML is a superset of JSON).

```yaml
schema_id: atlas.cortex.facts.v1

scope_roots:
  - src
  - lib

doc_roots:
  - docs

forbidden_globs:
  - vendor/**
  - node_modules/**

clone_min_lines: 30
```

## Canonical fields

| Field | Type | Notes |
|---|---|---|
| `schema_id` | string | MUST equal `atlas.cortex.facts.v1` |
| `scope_roots` | list<string> | repo-relative dirs to comprehend (non-empty) |
| `doc_roots` | list<string> | repo-relative doc roots for doc-stated-gap detection |
| `forbidden_globs` | list<string> | repo-relative globs to flag forbidden |
| `clone_min_lines` | int | minimum lines for clone detection |

## Pétreo invariants

- Loader is PURE: no Laravel container, no `config()`, no `env()`, no `base_path()`, no `storage_path()`, no `app()`. Verified by grep in the loader's test.
- Loader accepts a `string $rawOverride = null` arg so tests inject raw YAML without writing files.
- Loader throws `InvalidArgumentException` when `schema_id` is missing/wrong OR `scope_roots` is empty.

## Why a separate file from `config/atlas.php`

`config/atlas.php` carries the **Atlas runtime flags** (pétreo gates, feature switches). `cortex.yaml` is the **foreign-repo declarative recipe** — different lifecycle, different ownership, different distribution model.
