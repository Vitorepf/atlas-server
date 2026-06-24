# Atlas Cortex Universal Facts Schema (`atlas.cortex.facts.v1`)

Canonical, versioned schema for the FACTS payload returned by `AtlasCortexUniversalContract::comprehend()`. Authored as a hand-written validator (no external JSON-schema library) so the schema stays portable across non-Atlas repos.

## Required top-level keys

| Key | Type |
|---|---|
| `snapshot_id` | string |
| `inventory` | object |
| `orphans` | array |
| `clone_clusters` | array |
| `forbidden` | array |
| `doc_stated_gaps` | array |

## Optional top-level keys

`scope_root`, `units`, `edges`, `doc_purposes`, `doc_purposes_provenance`, `doc_stated_gaps_provenance`, `schema_version`.

## Per-unit shape (when `units` is present)

```json
{
  "App\\Foo": {
    "level_vector": [true, true, false, true, false, true],
    "transitions": [{"name": "promote"}, {"name": "deprecate"}]
  }
}
```

- `level_vector` MUST be an array of exactly 6 booleans
- `transitions` MUST be a list of `{name: <non-empty string>}` objects

## Pétreo invariant (byte-level)

Any unit key matching `/^(score|rank|grade)$/i` is a **validation error** with the message containing the literal substring `never a score`. This blocks any future change that would smuggle a hidden scoring rig into the comprehension model.

## SCHEMA_ID

`AtlasCortexUniversalFactsSchema::SCHEMA_ID === 'atlas.cortex.facts.v1'` — the single source of truth referenced by `AtlasCortexUniversalContract::contractSchemaId()`.
